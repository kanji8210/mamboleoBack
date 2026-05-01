<?php
/**
 * Incident lifecycle: keep recent incidents active, auto-age stale ones,
 * and let admins pin "developing" stories that should stay surfaced.
 *
 * Stages:
 *   active     – default; recent or recently updated
 *   developing – admin-pinned; never auto-aged, sorted to top of feeds
 *   resolved   – no fresh activity for 7+ days OR admin-marked
 *   archived   – no fresh activity for 30+ days; hidden from default map view
 *
 * Mechanics:
 *   - Every save bumps `last_update_at` + `update_count` (unless skipped).
 *   - Daily WP-Cron sweeps active → resolved → archived based on age.
 *   - Developing is sticky (cron never touches it).
 *   - Admin column + Quick Edit dropdown + REST query var (?lifecycle=) +
 *     WP-CLI command for manual runs.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MAMBOLEO_LIFECYCLE_RESOLVE_DAYS  = 7;
const MAMBOLEO_LIFECYCLE_ARCHIVE_DAYS  = 30;
const MAMBOLEO_LIFECYCLE_CRON_HOOK     = 'mamboleo_age_incidents';
const MAMBOLEO_LIFECYCLE_STAGES        = [ 'active', 'developing', 'resolved', 'archived' ];

/**
 * Internal: best-effort timestamp for "when did this incident last change".
 * Falls back through last_update_at → incident_time → post_modified_gmt.
 */
function mamboleo_lifecycle_last_ts( int $post_id ): int {
    $iso = (string) get_post_meta( $post_id, 'last_update_at', true );
    if ( $iso ) {
        $t = strtotime( $iso );
        if ( $t ) return $t;
    }
    $iso = (string) get_post_meta( $post_id, 'incident_time', true );
    if ( $iso ) {
        $t = strtotime( $iso );
        if ( $t ) return $t;
    }
    $modified = get_post_field( 'post_modified_gmt', $post_id );
    return $modified ? (int) strtotime( $modified . ' UTC' ) : 0;
}

/**
 * Bump last_update_at + update_count on every save.
 * Skipped on autosaves, revisions, and bulk-cron updates (which set a flag).
 */
add_action( 'save_post_incident', function ( $post_id, $post, $update ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( ! empty( $GLOBALS['mamboleo_skip_lifecycle_bump'] ) ) return;

    update_post_meta( $post_id, 'last_update_at', gmdate( 'c' ) );

    if ( $update ) {
        $count = (int) get_post_meta( $post_id, 'update_count', true );
        update_post_meta( $post_id, 'update_count', $count + 1 );
    }

    // First time: seed lifecycle if missing so old rows get back-filled lazily.
    $current = (string) get_post_meta( $post_id, 'lifecycle', true );
    if ( ! in_array( $current, MAMBOLEO_LIFECYCLE_STAGES, true ) ) {
        update_post_meta( $post_id, 'lifecycle', 'active' );
    }
}, 30, 3 );

/**
 * Daily cron: sweep active → resolved (>7d) and any non-developing → archived (>30d).
 * Runs in batches of 200 so it never times out on big sites.
 */
add_action( MAMBOLEO_LIFECYCLE_CRON_HOOK, 'mamboleo_age_incidents_run' );

function mamboleo_age_incidents_run(): array {
    $now            = time();
    $resolve_before = $now - ( MAMBOLEO_LIFECYCLE_RESOLVE_DAYS * DAY_IN_SECONDS );
    $archive_before = $now - ( MAMBOLEO_LIFECYCLE_ARCHIVE_DAYS * DAY_IN_SECONDS );

    $resolved = 0;
    $archived = 0;

    // Skip lifecycle-bump while cron writes to avoid feedback loops.
    $GLOBALS['mamboleo_skip_lifecycle_bump'] = true;

    // 1) active → resolved after RESOLVE_DAYS of silence.
    $q = new WP_Query( [
        'post_type'      => 'incident',
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'fields'         => 'ids',
        'meta_query'     => [
            [ 'key' => 'lifecycle', 'value' => 'active', 'compare' => '=' ],
        ],
        'no_found_rows'  => true,
    ] );
    foreach ( $q->posts as $id ) {
        if ( mamboleo_lifecycle_last_ts( (int) $id ) < $resolve_before ) {
            update_post_meta( (int) $id, 'lifecycle', 'resolved' );
            $resolved++;
        }
    }

    // 2) resolved (or stuck active) → archived after ARCHIVE_DAYS.
    //    Developing is excluded — it's admin-pinned and never auto-aged.
    $q2 = new WP_Query( [
        'post_type'      => 'incident',
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => 'lifecycle', 'value' => 'resolved', 'compare' => '=' ],
            [ 'key' => 'lifecycle', 'value' => 'active',   'compare' => '=' ],
        ],
        'no_found_rows'  => true,
    ] );
    foreach ( $q2->posts as $id ) {
        if ( mamboleo_lifecycle_last_ts( (int) $id ) < $archive_before ) {
            update_post_meta( (int) $id, 'lifecycle', 'archived' );
            $archived++;
        }
    }

    unset( $GLOBALS['mamboleo_skip_lifecycle_bump'] );

    update_option( 'mamboleo_lifecycle_last_run', [
        'ts'       => gmdate( 'c' ),
        'resolved' => $resolved,
        'archived' => $archived,
    ], false );

    return [ 'resolved' => $resolved, 'archived' => $archived ];
}

/* Schedule + unschedule with plugin activation lifecycle. */
register_activation_hook( MAMBOLEO_PLUGIN_DIR . 'mamboleoBack.php', function () {
    if ( ! wp_next_scheduled( MAMBOLEO_LIFECYCLE_CRON_HOOK ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', MAMBOLEO_LIFECYCLE_CRON_HOOK );
    }
} );
register_deactivation_hook( MAMBOLEO_PLUGIN_DIR . 'mamboleoBack.php', function () {
    $ts = wp_next_scheduled( MAMBOLEO_LIFECYCLE_CRON_HOOK );
    if ( $ts ) wp_unschedule_event( $ts, MAMBOLEO_LIFECYCLE_CRON_HOOK );
} );

// Self-heal: if cron isn't scheduled (plugin updated without re-activation), fix it.
add_action( 'init', function () {
    if ( ! wp_next_scheduled( MAMBOLEO_LIFECYCLE_CRON_HOOK ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', MAMBOLEO_LIFECYCLE_CRON_HOOK );
    }
} );

/* ─────────────────────────────  Admin UI  ───────────────────────────── */

/**
 * Lifecycle column on the incidents list.
 */
add_filter( 'manage_incident_posts_columns', function ( $cols ) {
    // Insert just after the title column for visibility.
    $new = [];
    foreach ( $cols as $k => $v ) {
        $new[ $k ] = $v;
        if ( $k === 'title' ) {
            $new['mamboleo_lifecycle'] = 'Lifecycle';
        }
    }
    return $new;
} );

add_action( 'manage_incident_posts_custom_column', function ( $col, $post_id ) {
    if ( $col !== 'mamboleo_lifecycle' ) return;
    $stage = (string) get_post_meta( $post_id, 'lifecycle', true ) ?: 'active';
    $colors = [
        'active'     => '#22c55e',
        'developing' => '#f97316', // pinned/orange — draws the eye
        'resolved'   => '#0ea5e9',
        'archived'   => '#94a3b8',
    ];
    $color = $colors[ $stage ] ?? '#94a3b8';
    $count = (int) get_post_meta( $post_id, 'update_count', true );
    $last  = mamboleo_lifecycle_last_ts( $post_id );
    $ago   = $last ? human_time_diff( $last, time() ) . ' ago' : '—';
    printf(
        '<span style="display:inline-block;padding:1px 6px;border-radius:3px;background:%s;color:#fff;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">%s</span>'
        . '<div style="font-size:11px;color:#666;margin-top:3px;">%s · %d update%s</div>',
        esc_attr( $color ),
        esc_html( $stage ),
        esc_html( $ago ),
        $count,
        $count === 1 ? '' : 's'
    );
}, 10, 2 );

/**
 * Status-tab filter row (All / Active / Developing / Resolved / Archived) above
 * the incidents list table.
 */
add_filter( 'views_edit-incident', function ( $views ) {
    $base = admin_url( 'edit.php?post_type=incident' );
    $current = isset( $_GET['lifecycle'] ) ? sanitize_key( wp_unslash( $_GET['lifecycle'] ) ) : '';

    foreach ( MAMBOLEO_LIFECYCLE_STAGES as $stage ) {
        $count = (int) ( new WP_Query( [
            'post_type'      => 'incident',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [ [ 'key' => 'lifecycle', 'value' => $stage, 'compare' => '=' ] ],
        ] ) )->found_posts;

        $url   = add_query_arg( 'lifecycle', $stage, $base );
        $class = $current === $stage ? 'current' : '';
        $views[ 'mm_lifecycle_' . $stage ] = sprintf(
            '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
            esc_url( $url ),
            esc_attr( $class ),
            esc_html( ucfirst( $stage ) ),
            $count
        );
    }
    return $views;
} );

/**
 * Apply the lifecycle filter to the admin query.
 */
add_action( 'pre_get_posts', function ( $q ) {
    if ( ! is_admin() || ! $q->is_main_query() ) return;
    if ( $q->get( 'post_type' ) !== 'incident' ) return;
    if ( empty( $_GET['lifecycle'] ) ) return;
    $stage = sanitize_key( wp_unslash( $_GET['lifecycle'] ) );
    if ( ! in_array( $stage, MAMBOLEO_LIFECYCLE_STAGES, true ) ) return;
    $q->set( 'meta_query', [ [ 'key' => 'lifecycle', 'value' => $stage, 'compare' => '=' ] ] );
} );

/* ─────────────────────────────  REST  ──────────────────────────────── */

/**
 * Allow ?lifecycle=active|developing|... on /wp/v2/incident.
 * Defaults: callers see active + developing; archived requires explicit opt-in.
 */
add_filter( 'rest_incident_query', function ( $args, $request ) {
    $stage = $request->get_param( 'lifecycle' );
    if ( $stage && in_array( $stage, MAMBOLEO_LIFECYCLE_STAGES, true ) ) {
        $args['meta_query'] = [ [ 'key' => 'lifecycle', 'value' => $stage, 'compare' => '=' ] ];
        return $args;
    }
    if ( $request->get_param( 'include_archived' ) ) {
        return $args;
    }
    // Default: hide archived from public API.
    $args['meta_query'] = [
        'relation' => 'OR',
        [ 'key' => 'lifecycle', 'value' => 'archived', 'compare' => '!=' ],
        [ 'key' => 'lifecycle', 'compare' => 'NOT EXISTS' ],
    ];
    return $args;
}, 10, 2 );

/* ─────────────────────────────  WP-CLI  ────────────────────────────── */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'mamboleo age-incidents', function () {
        $r = mamboleo_age_incidents_run();
        WP_CLI::success( sprintf( 'Aged incidents: %d resolved, %d archived.', $r['resolved'], $r['archived'] ) );
    } );
}
