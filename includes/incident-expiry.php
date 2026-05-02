<?php
/**
 * Incident Expiry — 7-day rolling lifetime for non-developing incidents.
 *
 * Rules:
 *   • Every incident gets `expires_at = last_update_at + 7 days`.
 *   • Posting an Incident Update (admin or community) resets the expiry.
 *   • 24h before expiry, the incident is flagged `expiry_warned=1` and shown
 *     on the admin "Expiring Soon" screen so a moderator can act.
 *   • At expiry, the incident is moved to Trash (recoverable for 30 days
 *     via WP's default trash retention).
 *   • `developing` incidents are immune (admin-pinned breaking stories).
 *
 * This stacks on top of the existing lifecycle stages — it does NOT replace
 * resolved/archived. Think of it as a hard backstop: stale rows don't pile up.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MAMBOLEO_EXPIRY_DAYS         = 7;
const MAMBOLEO_EXPIRY_WARN_HOURS   = 24;
const MAMBOLEO_EXPIRY_CRON_HOOK    = 'mamboleo_expire_incidents';

/**
 * Reset (or set) the expires_at timestamp to now + EXPIRY_DAYS.
 * Called by:
 *   • mamboleo_apply_update_to_incident() when a new update is approved
 *   • the admin "Extend lifetime" action
 *   • the cron back-fill when expires_at is missing
 */
function mamboleo_reset_incident_expiry( int $incident_id ): string {
    $iso = gmdate( 'c', time() + ( MAMBOLEO_EXPIRY_DAYS * DAY_IN_SECONDS ) );
    update_post_meta( $incident_id, 'expires_at', $iso );
    delete_post_meta( $incident_id, 'expiry_warned' );
    return $iso;
}

/* On every incident save, ensure expires_at exists. */
add_action( 'save_post_incident', function ( $post_id, $post, $update ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( ! empty( $GLOBALS['mamboleo_skip_lifecycle_bump'] ) ) return;
    if ( ! get_post_meta( $post_id, 'expires_at', true ) ) {
        mamboleo_reset_incident_expiry( $post_id );
    }
}, 40, 3 );

/* ─────────────────────────  Cron  ─────────────────────────────────── */

add_action( MAMBOLEO_EXPIRY_CRON_HOOK, 'mamboleo_expire_incidents_run' );

function mamboleo_expire_incidents_run(): array {
    $now      = time();
    $warn_cut = $now + ( MAMBOLEO_EXPIRY_WARN_HOURS * HOUR_IN_SECONDS );
    $expired  = 0;
    $warned   = 0;

    $GLOBALS['mamboleo_skip_lifecycle_bump'] = true;

    // 1) HARD EXPIRY → trash.
    $q = new WP_Query( [
        'post_type'      => 'incident',
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => 'expires_at',
                'value'   => gmdate( 'c', $now ),
                'compare' => '<',
                'type'    => 'DATETIME',
            ],
            [ 'key' => 'lifecycle', 'value' => 'developing', 'compare' => '!=' ],
        ],
        'no_found_rows'  => true,
    ] );
    foreach ( $q->posts as $id ) {
        wp_trash_post( (int) $id );
        $expired++;
    }

    // 2) WARNING WINDOW → flag expiry_warned=1, surface on admin screen.
    $q2 = new WP_Query( [
        'post_type'      => 'incident',
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => 'expires_at',
                'value'   => [ gmdate( 'c', $now ), gmdate( 'c', $warn_cut ) ],
                'compare' => 'BETWEEN',
                'type'    => 'DATETIME',
            ],
            [ 'key' => 'lifecycle', 'value' => 'developing', 'compare' => '!=' ],
            [
                'relation' => 'OR',
                [ 'key' => 'expiry_warned', 'compare' => 'NOT EXISTS' ],
                [ 'key' => 'expiry_warned', 'value'   => '0', 'compare' => '=' ],
            ],
        ],
        'no_found_rows'  => true,
    ] );
    foreach ( $q2->posts as $id ) {
        update_post_meta( (int) $id, 'expiry_warned', 1 );
        $warned++;
    }

    unset( $GLOBALS['mamboleo_skip_lifecycle_bump'] );

    update_option( 'mamboleo_expiry_last_run', [
        'ts'      => gmdate( 'c' ),
        'expired' => $expired,
        'warned'  => $warned,
    ], false );

    return [ 'expired' => $expired, 'warned' => $warned ];
}

/* Schedule cron — hourly so the warning window is accurate. */
add_action( 'init', function () {
    if ( ! wp_next_scheduled( MAMBOLEO_EXPIRY_CRON_HOOK ) ) {
        wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', MAMBOLEO_EXPIRY_CRON_HOOK );
    }
} );
register_deactivation_hook( MAMBOLEO_PLUGIN_DIR . 'mamboleoBack.php', function () {
    $ts = wp_next_scheduled( MAMBOLEO_EXPIRY_CRON_HOOK );
    if ( $ts ) wp_unschedule_event( $ts, MAMBOLEO_EXPIRY_CRON_HOOK );
} );

/* ─────────────────────────  Helpers  ──────────────────────────────── */

function mamboleo_get_expiring_incidents( int $hours = 24, int $limit = 50 ): array {
    $now = time();
    $cut = $now + ( $hours * HOUR_IN_SECONDS );
    $q   = new WP_Query( [
        'post_type'      => 'incident',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => 'expires_at',
                'value'   => [ gmdate( 'c', $now - DAY_IN_SECONDS ), gmdate( 'c', $cut ) ],
                'compare' => 'BETWEEN',
                'type'    => 'DATETIME',
            ],
            [ 'key' => 'lifecycle', 'value' => 'developing', 'compare' => '!=' ],
        ],
        'orderby'        => 'meta_value',
        'meta_key'       => 'expires_at',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ] );
    return $q->posts;
}

/* ─────────────────────────  WP-CLI  ────────────────────────────────── */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'mamboleo expire-incidents', function () {
        $r = mamboleo_expire_incidents_run();
        WP_CLI::success( sprintf( 'Expiry run: %d trashed, %d warned.', $r['expired'], $r['warned'] ) );
    } );
}
