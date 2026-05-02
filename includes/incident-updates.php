<?php
/**
 * Incident Updates — community-driven follow-ups.
 *
 * Updates are short text posts attached to an existing incident. They:
 *   • extend the incident's `last_update_at` (refreshes lifecycle, defers expiry)
 *   • can be submitted by admins (auto-approved) OR the public (rate-limited, queued)
 *   • are stored as a custom post type `incident_update` so they're queryable,
 *     moderate-able and rev-able the WP-native way
 *
 * Why a CPT and not post-meta? Because moderation, authorship, search and the
 * existing comments-system don't compose cleanly with serialised meta.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MAMBOLEO_UPDATE_CPT          = 'incident_update';
const MAMBOLEO_UPDATE_RATE_SECONDS = 5 * MINUTE_IN_SECONDS;
const MAMBOLEO_UPDATE_SOURCES      = [ 'community', 'admin', 'scraper', 'witness' ];

/* ─────────────────────────  CPT registration  ───────────────────────── */

add_action( 'init', function () {
    register_post_type( MAMBOLEO_UPDATE_CPT, [
        'label'        => 'Incident Updates',
        'labels'       => [
            'name'          => 'Incident Updates',
            'singular_name' => 'Incident Update',
            'add_new_item'  => 'Add Update',
            'edit_item'     => 'Edit Update',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false, // surfaced inside the Mamboleo top-level menu
        'show_in_rest' => true,
        'supports'     => [ 'title', 'editor', 'author' ],
        'menu_icon'    => 'dashicons-update',
        'capability_type' => 'post',
        'map_meta_cap' => true,
    ] );
} );

/* ─────────────────────────  Helpers  ────────────────────────────────── */

function mamboleo_create_update( int $incident_id, array $args ): int|WP_Error {
    if ( get_post_type( $incident_id ) !== 'incident' ) {
        return new WP_Error( 'invalid_incident', 'Incident not found.', [ 'status' => 404 ] );
    }
    $body   = trim( wp_strip_all_tags( $args['body'] ?? '' ) );
    if ( $body === '' ) {
        return new WP_Error( 'missing_body', 'Update body is required.', [ 'status' => 400 ] );
    }
    if ( strlen( $body ) > 2000 ) {
        $body = substr( $body, 0, 2000 );
    }
    $source = in_array( $args['source'] ?? '', MAMBOLEO_UPDATE_SOURCES, true )
        ? $args['source'] : 'community';

    $auto_approve = ! empty( $args['auto_approve'] );
    $status       = $auto_approve ? 'publish' : 'pending';

    $title = sanitize_text_field(
        $args['title'] ?? wp_trim_words( $body, 10, '…' )
    );

    $update_id = wp_insert_post( [
        'post_type'    => MAMBOLEO_UPDATE_CPT,
        'post_status'  => $status,
        'post_title'   => $title ?: 'Update',
        'post_content' => wp_kses_post( $body ),
        'post_parent'  => $incident_id,
        'post_author'  => (int) ( $args['author_id'] ?? get_current_user_id() ),
    ], true );
    if ( is_wp_error( $update_id ) ) return $update_id;

    update_post_meta( $update_id, 'incident_id',  $incident_id );
    update_post_meta( $update_id, 'source',       $source );
    update_post_meta( $update_id, 'reporter',     sanitize_text_field( $args['reporter'] ?? '' ) );
    update_post_meta( $update_id, 'severity_hint', sanitize_text_field( $args['severity_hint'] ?? '' ) );
    update_post_meta( $update_id, 'ip_hash',      hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() ) );

    // Refresh parent incident lifecycle so it stays "active" and resets expiry.
    if ( $auto_approve ) {
        mamboleo_apply_update_to_incident( $update_id, $incident_id );
    }

    do_action( 'mamboleo_update_created', $update_id, $incident_id, $source );

    return (int) $update_id;
}

/**
 * When an update is approved/published, reflect it on the parent incident:
 * bump update_count + last_update_at, reset expiry, force lifecycle=active.
 */
function mamboleo_apply_update_to_incident( int $update_id, int $incident_id ): void {
    $count = (int) get_post_meta( $incident_id, 'update_count', true );
    update_post_meta( $incident_id, 'update_count',   $count + 1 );
    update_post_meta( $incident_id, 'last_update_at', gmdate( 'c' ) );
    update_post_meta( $incident_id, 'lifecycle',      'active' );

    // Reset 7-day expiry from now.
    if ( function_exists( 'mamboleo_reset_incident_expiry' ) ) {
        mamboleo_reset_incident_expiry( $incident_id );
    }
}

// Hook approval (transition pending → publish) to re-apply.
add_action( 'transition_post_status', function ( $new, $old, $post ) {
    if ( $post->post_type !== MAMBOLEO_UPDATE_CPT ) return;
    if ( $new !== 'publish' || $old === 'publish' )  return;
    $incident_id = (int) ( $post->post_parent ?: get_post_meta( $post->ID, 'incident_id', true ) );
    if ( $incident_id ) mamboleo_apply_update_to_incident( $post->ID, $incident_id );
}, 10, 3 );

/* ─────────────────────────  REST routes  ────────────────────────────── */

add_action( 'rest_api_init', function () {
    // Public read
    register_rest_route( 'mamboleo/v1', '/incidents/(?P<id>\d+)/updates', [
        [
            'methods'             => 'GET',
            'callback'            => 'mamboleo_list_incident_updates',
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ],
            ],
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'mamboleo_post_incident_update',
            'permission_callback' => '__return_true',
        ],
    ] );

    // Admin / API-key trusted post (auto-approved)
    register_rest_route( 'mamboleo/v1', '/incidents/(?P<id>\d+)/updates/trusted', [
        'methods'             => 'POST',
        'callback'            => 'mamboleo_post_trusted_update',
        'permission_callback' => function ( WP_REST_Request $r ) {
            if ( current_user_can( 'manage_options' ) ) return true;
            return mamboleo_verify_api_key( $r );
        },
    ] );
} );

function mamboleo_list_incident_updates( WP_REST_Request $request ): array {
    $id = (int) $request->get_param( 'id' );
    $q  = new WP_Query( [
        'post_type'      => MAMBOLEO_UPDATE_CPT,
        'post_status'    => 'publish',
        'post_parent'    => $id,
        'posts_per_page' => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ] );
    $items = array_map( function ( $p ) {
        return [
            'id'         => $p->ID,
            'title'      => $p->post_title,
            'body'       => wp_strip_all_tags( $p->post_content ),
            'created_at' => mysql2date( 'c', $p->post_date_gmt, false ),
            'source'     => get_post_meta( $p->ID, 'source', true ),
            'reporter'   => get_post_meta( $p->ID, 'reporter', true ),
        ];
    }, $q->posts );
    return [ 'items' => $items, 'count' => count( $items ) ];
}

function mamboleo_post_incident_update( WP_REST_Request $request ): array|WP_Error {
    // Per-IP rate limit
    $ip   = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    $key  = 'mb_upd_' . md5( $ip );
    if ( get_transient( $key ) ) {
        return new WP_Error( 'rate_limit', 'Please wait a few minutes before posting another update.', [ 'status' => 429 ] );
    }
    set_transient( $key, 1, MAMBOLEO_UPDATE_RATE_SECONDS );

    $params = $request->get_json_params() ?: $request->get_params();
    $update_id = mamboleo_create_update( (int) $request->get_param( 'id' ), [
        'body'          => (string) ( $params['body'] ?? '' ),
        'reporter'      => (string) ( $params['reporter'] ?? '' ),
        'severity_hint' => (string) ( $params['severity_hint'] ?? '' ),
        'source'        => 'community',
        'auto_approve'  => false,
    ] );
    if ( is_wp_error( $update_id ) ) return $update_id;

    return [
        'id'      => $update_id,
        'status'  => 'pending',
        'message' => 'Thanks — your update is queued for moderation.',
    ];
}

function mamboleo_post_trusted_update( WP_REST_Request $request ): array|WP_Error {
    $params = $request->get_json_params() ?: $request->get_params();
    $update_id = mamboleo_create_update( (int) $request->get_param( 'id' ), [
        'body'          => (string) ( $params['body'] ?? '' ),
        'reporter'      => (string) ( $params['reporter'] ?? '' ),
        'severity_hint' => (string) ( $params['severity_hint'] ?? '' ),
        'source'        => $params['source'] ?? 'admin',
        'auto_approve'  => true,
    ] );
    if ( is_wp_error( $update_id ) ) return $update_id;
    return [ 'id' => $update_id, 'status' => 'publish' ];
}

/* ─────────────────────────  GraphQL  ────────────────────────────────── */

add_action( 'graphql_register_types', function () {
    if ( ! function_exists( 'register_graphql_object_type' ) ) return;

    register_graphql_object_type( 'IncidentUpdate', [
        'fields' => [
            'id'        => [ 'type' => 'Int' ],
            'body'      => [ 'type' => 'String' ],
            'createdAt' => [ 'type' => 'String' ],
            'source'    => [ 'type' => 'String' ],
            'reporter'  => [ 'type' => 'String' ],
        ],
    ] );

    register_graphql_field( 'Incident', 'updates', [
        'type'        => [ 'list_of' => 'IncidentUpdate' ],
        'description' => 'Approved follow-up updates for this incident.',
        'resolve'     => function ( $post ) {
            $q = new WP_Query( [
                'post_type'      => MAMBOLEO_UPDATE_CPT,
                'post_status'    => 'publish',
                'post_parent'    => $post->ID,
                'posts_per_page' => 25,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ] );
            return array_map( function ( $p ) {
                return [
                    'id'        => $p->ID,
                    'body'      => wp_strip_all_tags( $p->post_content ),
                    'createdAt' => mysql2date( 'c', $p->post_date_gmt, false ),
                    'source'    => get_post_meta( $p->ID, 'source', true ),
                    'reporter'  => get_post_meta( $p->ID, 'reporter', true ),
                ];
            }, $q->posts );
        },
    ] );
} );
