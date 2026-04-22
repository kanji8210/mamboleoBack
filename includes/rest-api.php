<?php
/**
 * REST API endpoints for Mamboleo Backend.
 * All meta is handled via update_post_meta() — no ACF dependency.
 */

// ── Route registration ────────────────────────────────────────────────────────
add_action( 'rest_api_init', 'mamboleo_register_rest_routes' );
function mamboleo_register_rest_routes(): void {

    // Public: citizen report
    register_rest_route( 'mamboleo/v1', '/report', [
        'methods'             => 'POST',
        'callback'            => 'mamboleo_handle_report',
        'permission_callback' => '__return_true',
        'args'                => [
            'title'     => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'type'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'latitude'  => [ 'required' => true,  'type' => 'number' ],
            'longitude' => [ 'required' => true,  'type' => 'number' ],
            'severity'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    // Public: corroborate an incident
    register_rest_route( 'mamboleo/v1', '/corroborate/(?P<id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'mamboleo_handle_corroborate',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ],
        ],
    ] );

    // API-key protected: ingestion endpoints
    register_rest_route( 'mamboleo/v1', '/incidents', [
        'methods'             => 'POST',
        'callback'            => 'mamboleo_create_incident',
        'permission_callback' => 'mamboleo_verify_api_key',
    ] );
    register_rest_route( 'mamboleo/v1', '/articles', [
        'methods'             => 'POST',
        'callback'            => 'mamboleo_create_article',
        'permission_callback' => 'mamboleo_verify_api_key',
    ] );
    register_rest_route( 'mamboleo/v1', '/social-posts', [
        'methods'             => 'POST',
        'callback'            => 'mamboleo_create_social_post',
        'permission_callback' => 'mamboleo_verify_api_key',
    ] );
}

// ── Auth helper ───────────────────────────────────────────────────────────────
function mamboleo_verify_api_key( WP_REST_Request $request ): bool|WP_Error {
    $api_key = defined( 'MAMBOLEO_API_KEY' ) ? MAMBOLEO_API_KEY : '';
    if ( empty( $api_key ) ) {
        return new WP_Error( 'rest_forbidden', __( 'API Key not configured on server.', 'mamboleo' ), [ 'status' => 403 ] );
    }
    return $request->get_header( 'X-API-Key' ) === $api_key;
}

// ── Public: citizen report ────────────────────────────────────────────────────
function mamboleo_handle_report( WP_REST_Request $request ): array|WP_Error {
    // Rate-limit: 1 report per 10 minutes per IP
    $ip          = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    $transient   = 'mamboleo_report_' . md5( $ip );
    if ( get_transient( $transient ) ) {
        return new WP_Error( 'rate_limited', __( 'Please wait 10 minutes before submitting another report.', 'mamboleo' ), [ 'status' => 429 ] );
    }

    $lat = (float) $request->get_param( 'latitude' );
    $lng = (float) $request->get_param( 'longitude' );

    // Kenya bounding box: lat -5..5, lng 33.5..42.5
    if ( $lat < -5 || $lat > 5 || $lng < 33.5 || $lng > 42.5 ) {
        return new WP_Error( 'out_of_bounds', __( 'Coordinates must be within Kenya.', 'mamboleo' ), [ 'status' => 422 ] );
    }

    $post_id = wp_insert_post( [
        'post_title'  => sanitize_text_field( $request->get_param( 'title' ) ),
        'post_type'   => 'incident',
        'post_status' => 'pending',
    ] );
    if ( is_wp_error( $post_id ) ) {
        return new WP_Error( 'insert_failed', $post_id->get_error_message(), [ 'status' => 500 ] );
    }

    $is_anonymous = (bool) $request->get_param( 'is_anonymous' );

    update_post_meta( $post_id, 'type',               sanitize_text_field( $request->get_param( 'type' )          ?: 'fire' ) );
    update_post_meta( $post_id, 'latitude',            $lat );
    update_post_meta( $post_id, 'longitude',           $lng );
    update_post_meta( $post_id, 'severity',            sanitize_text_field( $request->get_param( 'severity' )     ?: 'low' ) );
    update_post_meta( $post_id, 'status',              'unsafe' );
    update_post_meta( $post_id, 'incident_time',       sanitize_text_field( $request->get_param( 'incident_time' ) ?: '' ) );
    update_post_meta( $post_id, 'video_url',           esc_url_raw( $request->get_param( 'video_url' )            ?: '' ) );
    update_post_meta( $post_id, 'location_name',       sanitize_text_field( $request->get_param( 'location_name' ) ?: '' ) );
    update_post_meta( $post_id, 'is_anonymous',        $is_anonymous );
    update_post_meta( $post_id, 'reporter_name',       $is_anonymous ? '' : sanitize_text_field( $request->get_param( 'reporter_name' ) ?: '' ) );
    update_post_meta( $post_id, 'is_verified',         false );
    update_post_meta( $post_id, 'corroboration_count', 0 );

    if ( ! empty( $request->get_param( 'county' ) ) ) {
        wp_set_post_terms( $post_id, sanitize_text_field( $request->get_param( 'county' ) ), 'county' );
    }

    set_transient( $transient, 1, 10 * MINUTE_IN_SECONDS );

    return [ 'id' => $post_id, 'message' => __( 'Report submitted for review.', 'mamboleo' ) ];
}

// ── Public: corroborate ───────────────────────────────────────────────────────
function mamboleo_handle_corroborate( WP_REST_Request $request ): array|WP_Error {
    $post_id = (int) $request->get_param( 'id' );
    $post    = get_post( $post_id );

    if ( ! $post || $post->post_type !== 'incident' || $post->post_status !== 'publish' ) {
        return new WP_Error( 'not_found', __( 'Incident not found.', 'mamboleo' ), [ 'status' => 404 ] );
    }

    $count = (int) get_post_meta( $post_id, 'corroboration_count', true );
    update_post_meta( $post_id, 'corroboration_count', $count + 1 );

    return [ 'id' => $post_id, 'corroboration_count' => $count + 1 ];
}

// ── API-key ingestion: incident ───────────────────────────────────────────────
function mamboleo_create_incident( WP_REST_Request $request ): array|WP_Error {
    $params = $request->get_json_params();
    if ( empty( $params['title'] ) ) {
        return new WP_Error( 'missing_params', __( 'Title is required.', 'mamboleo' ), [ 'status' => 400 ] );
    }

    $post_id = wp_insert_post( [
        'post_title'   => sanitize_text_field( $params['title'] ),
        'post_content' => wp_kses_post( $params['content'] ?? '' ),
        'post_type'    => 'incident',
        'post_status'  => 'publish',
    ] );
    if ( is_wp_error( $post_id ) ) {
        return new WP_Error( 'insert_failed', $post_id->get_error_message(), [ 'status' => 500 ] );
    }

    if ( isset( $params['type'] ) )          update_post_meta( $post_id, 'type',          sanitize_text_field( $params['type'] ) );
    if ( isset( $params['latitude'] ) )      update_post_meta( $post_id, 'latitude',       (float) $params['latitude'] );
    if ( isset( $params['longitude'] ) )     update_post_meta( $post_id, 'longitude',      (float) $params['longitude'] );
    if ( isset( $params['severity'] ) )      update_post_meta( $post_id, 'severity',       sanitize_text_field( $params['severity'] ) );
    if ( isset( $params['status'] ) )        update_post_meta( $post_id, 'status',         sanitize_text_field( $params['status'] ) );
    if ( isset( $params['incident_time'] ) ) update_post_meta( $post_id, 'incident_time',  sanitize_text_field( $params['incident_time'] ) );
    if ( isset( $params['video_url'] ) )     update_post_meta( $post_id, 'video_url',      esc_url_raw( $params['video_url'] ) );
    if ( isset( $params['location_name'] ) ) update_post_meta( $post_id, 'location_name',  sanitize_text_field( $params['location_name'] ) );
    if ( isset( $params['is_verified'] ) )   update_post_meta( $post_id, 'is_verified',    (bool) $params['is_verified'] );

    if ( ! empty( $params['county'] ) ) {
        wp_set_post_terms( $post_id, $params['county'], 'county' );
    }

    return [ 'id' => $post_id, 'message' => 'Incident created successfully.' ];
}

// ── API-key ingestion: article ────────────────────────────────────────────────
function mamboleo_create_article( WP_REST_Request $request ): array|WP_Error {
    $params = $request->get_json_params();
    if ( empty( $params['title'] ) ) {
        return new WP_Error( 'missing_params', __( 'Title is required.', 'mamboleo' ), [ 'status' => 400 ] );
    }

    $post_id = wp_insert_post( [
        'post_title'   => sanitize_text_field( $params['title'] ),
        'post_content' => wp_kses_post( $params['content'] ?? '' ),
        'post_type'    => 'article',
        'post_status'  => 'publish',
    ] );
    if ( is_wp_error( $post_id ) ) {
        return new WP_Error( 'insert_failed', $post_id->get_error_message(), [ 'status' => 500 ] );
    }

    if ( isset( $params['source'] ) )      update_post_meta( $post_id, 'source',      sanitize_text_field( $params['source'] ) );
    if ( isset( $params['article_url'] ) ) update_post_meta( $post_id, 'article_url', esc_url_raw( $params['article_url'] ) );
    if ( isset( $params['bias_score'] ) )  update_post_meta( $post_id, 'bias_score',  (int) $params['bias_score'] );
    if ( isset( $params['sentiment'] ) )   update_post_meta( $post_id, 'sentiment',   sanitize_text_field( $params['sentiment'] ) );

    if ( ! empty( $params['county'] ) ) {
        wp_set_post_terms( $post_id, $params['county'], 'county' );
    }

    return [ 'id' => $post_id, 'message' => 'Article created successfully.' ];
}

// ── API-key ingestion: social post ────────────────────────────────────────────
function mamboleo_create_social_post( WP_REST_Request $request ): array|WP_Error {
    $params = $request->get_json_params();
    if ( empty( $params['title'] ) ) {
        return new WP_Error( 'missing_params', __( 'Title is required.', 'mamboleo' ), [ 'status' => 400 ] );
    }

    $post_id = wp_insert_post( [
        'post_title'   => sanitize_text_field( $params['title'] ),
        'post_content' => wp_kses_post( $params['content'] ?? '' ),
        'post_type'    => 'social_post',
        'post_status'  => 'publish',
    ] );
    if ( is_wp_error( $post_id ) ) {
        return new WP_Error( 'insert_failed', $post_id->get_error_message(), [ 'status' => 500 ] );
    }

    if ( isset( $params['platform'] ) )      update_post_meta( $post_id, 'platform',      sanitize_text_field( $params['platform'] ) );
    if ( isset( $params['author_handle'] ) ) update_post_meta( $post_id, 'author_handle',  sanitize_text_field( $params['author_handle'] ) );
    if ( isset( $params['post_url'] ) )      update_post_meta( $post_id, 'post_url',       esc_url_raw( $params['post_url'] ) );
    if ( isset( $params['engagement'] ) )    update_post_meta( $post_id, 'engagement',     (int) $params['engagement'] );

    if ( ! empty( $params['county'] ) ) {
        wp_set_post_terms( $post_id, $params['county'], 'county' );
    }

    return [ 'id' => $post_id, 'message' => 'Social post created successfully.' ];
}

