<?php
/**
 * Mamboleo Admin — Location Tools
 *
 * Keeps published incident coordinates strictly inside Kenya by letting
 * admins pick a County (and optional Subcounty) to snap the point into
 * the right polygon.
 *
 * Surfaces:
 *   - Bulk page:  Mamboleo → Fix Locations
 *   - Meta box on the incident edit screen
 *   - REST:       POST /mamboleo/v1/admin/incidents/{id}/fix-location
 *                 GET  /mamboleo/v1/admin/counties
 *   - WP-CLI:     wp mamboleo backfill-locations [--dry-run]
 *   - Hook:       save_post_incident auto-snaps out-of-Kenya points
 *
 * Polygon check uses the lightweight bbox included in data/counties.php.
 * Drop a real GeoJSON into data/kenya-counties.geojson later and upgrade
 * mamboleo_point_in_county() to polygon ray-casting; callers won't change.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once MAMBOLEO_PLUGIN_DIR . 'data/counties.php';

// ──────────────────────────────────────────────────────────────────────────
// Core helpers
// ──────────────────────────────────────────────────────────────────────────

/** Is the point inside Kenya's country-level bbox? */
function mamboleo_point_in_kenya( float $lat, float $lng ): bool {
    [ $minLat, $minLng, $maxLat, $maxLng ] = mamboleo_kenya_bbox();
    return $lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng;
}

/** Is the point inside the given county's bbox? */
function mamboleo_point_in_county( float $lat, float $lng, array $county ): bool {
    [ $minLat, $minLng, $maxLat, $maxLng ] = $county['bbox'];
    return $lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng;
}

/** Approximate distance between two points (degrees, squared). Good for ordering. */
function mamboleo_dist_sq( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
    $dLat = $lat1 - $lat2;
    $dLng = $lng1 - $lng2;
    return $dLat * $dLat + $dLng * $dLng;
}

/**
 * Find the closest county by centroid distance. Used to auto-assign a
 * county when the admin hasn't picked one and the point is already inside
 * Kenya.
 */
function mamboleo_nearest_county( float $lat, float $lng ): ?array {
    $best = null; $best_d = INF;
    foreach ( mamboleo_counties_data() as $c ) {
        [ $cLat, $cLng ] = $c['center'];
        $d = mamboleo_dist_sq( $lat, $lng, $cLat, $cLng );
        if ( $d < $best_d ) { $best_d = $d; $best = $c; }
    }
    return $best;
}

/**
 * Core snap: given admin-selected county/subcounty slugs, decide the
 * final (lat, lng, name, precision) tuple for the incident.
 *
 * Rules (per product decision):
 *   1. Subcounty chosen → always snap to subcounty centroid, precision=subcounty.
 *   2. County chosen, point inside county bbox → keep original, precision=exact.
 *   3. County chosen, point outside county bbox → REJECT (return null). The
 *      admin is asked to pick a subcounty. The only exception is when no
 *      subcounties are defined — then fall back to county centroid
 *      (precision=county).
 *
 * Returns: [ 'lat'=>float, 'lng'=>float, 'name'=>string,
 *            'precision'=>string, 'county'=>slug, 'subcounty'=>slug|'',
 *            'in_bounds'=>bool ]
 *          or null when a subcounty pick is required.
 */
function mamboleo_snap_location(
    float $lat, float $lng, string $county_slug, string $subcounty_slug = ''
): ?array {
    $county = mamboleo_county_by_slug( $county_slug );
    if ( ! $county ) return null;

    // Case 1: subcounty given → authoritative snap.
    if ( $subcounty_slug !== '' ) {
        $sub = mamboleo_subcounty_by_slug( $county, $subcounty_slug );
        if ( $sub ) {
            return [
                'lat'       => (float) $sub['center'][0],
                'lng'       => (float) $sub['center'][1],
                'name'      => $sub['name'] . ', ' . $county['name'],
                'precision' => 'subcounty',
                'county'    => $county['slug'],
                'subcounty' => $sub['slug'],
                'in_bounds' => true,
            ];
        }
    }

    // Case 2: point already inside county bbox → keep it.
    if ( mamboleo_point_in_county( $lat, $lng, $county ) ) {
        return [
            'lat'       => $lat,
            'lng'       => $lng,
            'name'      => $county['name'],
            'precision' => 'exact',
            'county'    => $county['slug'],
            'subcounty' => '',
            'in_bounds' => true,
        ];
    }

    // Case 3: outside county bbox.
    if ( empty( $county['subs'] ) ) {
        // No subcounties defined → fall back to county centroid.
        return [
            'lat'       => (float) $county['center'][0],
            'lng'       => (float) $county['center'][1],
            'name'      => $county['name'],
            'precision' => 'county',
            'county'    => $county['slug'],
            'subcounty' => '',
            'in_bounds' => false,
        ];
    }

    // Needs admin to narrow via subcounty.
    return null;
}

/**
 * Apply a snap result to an incident post. Updates meta + optionally
 * clears needs_review when precision resolves to exact/subcounty.
 * Returns the snap result on success, null otherwise.
 */
function mamboleo_apply_snap_to_incident( int $post_id, array $snap ): array {
    update_post_meta( $post_id, 'latitude',  $snap['lat'] );
    update_post_meta( $post_id, 'longitude', $snap['lng'] );
    update_post_meta( $post_id, 'location_name',      $snap['name'] );
    update_post_meta( $post_id, 'location_county',    $snap['county'] );
    update_post_meta( $post_id, 'location_subcounty', $snap['subcounty'] );
    update_post_meta( $post_id, 'location_precision', $snap['precision'] );

    // Resolved precisely → clear the review flag automatically.
    if ( in_array( $snap['precision'], [ 'exact', 'subcounty' ], true ) ) {
        update_post_meta( $post_id, 'needs_review', false );
    }

    do_action( 'mamboleo_incident_relocated', $post_id, $snap );
    return $snap;
}

// ──────────────────────────────────────────────────────────────────────────
// Auto-snap hook: run on every save of an incident
// ──────────────────────────────────────────────────────────────────────────

add_action( 'save_post_incident', function ( $post_id, $post, $update ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    $lat = (float) get_post_meta( $post_id, 'latitude', true );
    $lng = (float) get_post_meta( $post_id, 'longitude', true );

    // Both zero → not-yet-set, skip.
    if ( $lat === 0.0 && $lng === 0.0 ) return;

    if ( mamboleo_point_in_kenya( $lat, $lng ) ) return; // already inside → nothing to do

    // Outside Kenya → snap to nearest county centroid, flag for review.
    $nearest = mamboleo_nearest_county( $lat, $lng );
    if ( ! $nearest ) return;
    update_post_meta( $post_id, 'latitude',  $nearest['center'][0] );
    update_post_meta( $post_id, 'longitude', $nearest['center'][1] );
    update_post_meta( $post_id, 'location_name',      $nearest['name'] );
    update_post_meta( $post_id, 'location_county',    $nearest['slug'] );
    update_post_meta( $post_id, 'location_precision', 'county' );
    update_post_meta( $post_id, 'needs_review',       true );
}, 20, 3 );

// ──────────────────────────────────────────────────────────────────────────
// REST API
// ──────────────────────────────────────────────────────────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'mamboleo/v1', '/admin/counties', [
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        'callback'            => function () {
            // Strip bbox for UI payload — UI only needs name/slug/center/subs.
            $out = [];
            foreach ( mamboleo_counties_data() as $c ) {
                $out[] = [
                    'name'   => $c['name'],
                    'slug'   => $c['slug'],
                    'center' => $c['center'],
                    'subs'   => $c['subs'],
                ];
            }
            return $out;
        },
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/incidents/(?P<id>\d+)/fix-location', [
        'methods'             => 'POST',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        'args'                => [
            'county'    => [ 'required' => true, 'type' => 'string' ],
            'subcounty' => [ 'required' => false, 'type' => 'string', 'default' => '' ],
        ],
        'callback'            => function ( WP_REST_Request $req ) {
            $id = (int) $req['id'];
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'incident' ) {
                return new WP_Error( 'not_found', 'Incident not found', [ 'status' => 404 ] );
            }
            $lat = (float) get_post_meta( $id, 'latitude', true );
            $lng = (float) get_post_meta( $id, 'longitude', true );
            $snap = mamboleo_snap_location(
                $lat, $lng,
                sanitize_title( $req->get_param( 'county' ) ),
                sanitize_title( $req->get_param( 'subcounty' ) ?: '' )
            );
            if ( ! $snap ) {
                return new WP_Error(
                    'needs_subcounty',
                    'Point is outside the chosen county — please pick a subcounty to snap to.',
                    [ 'status' => 422 ]
                );
            }
            mamboleo_apply_snap_to_incident( $id, $snap );
            return $snap;
        },
    ] );
} );

// ──────────────────────────────────────────────────────────────────────────
// Bulk admin page
// ──────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    $parent = isset( $GLOBALS['admin_page_hooks']['mamboleo-main'] ) ? 'mamboleo-main' : null;
    if ( ! $parent ) return;
    add_submenu_page(
        $parent,
        'Fix Locations',
        'Fix Locations',
        'manage_options',
        'mamboleo-fix-locations',
        'mamboleo_fix_locations_page',
        5
    );
}, 26 );

/**
 * List incidents that need a location fix: lat/lng outside Kenya OR
 * precision is country OR needs_review flag set.
 */
function mamboleo_fix_locations_query( int $page = 1, int $per_page = 25 ): array {
    $args = [
        'post_type'      => 'incident',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => 'needs_review',       'value' => '1',       'compare' => '=' ],
            [ 'key' => 'location_precision', 'value' => 'country', 'compare' => '=' ],
        ],
    ];
    $q = new WP_Query( $args );
    $rows = [];
    foreach ( $q->posts as $p ) {
        $rows[] = [
            'id'        => $p->ID,
            'title'     => get_the_title( $p ),
            'lat'       => (float) get_post_meta( $p->ID, 'latitude', true ),
            'lng'       => (float) get_post_meta( $p->ID, 'longitude', true ),
            'name'      => (string) get_post_meta( $p->ID, 'location_name', true ),
            'county'    => (string) get_post_meta( $p->ID, 'location_county', true ),
            'subcounty' => (string) get_post_meta( $p->ID, 'location_subcounty', true ),
            'precision' => (string) get_post_meta( $p->ID, 'location_precision', true ) ?: 'exact',
            'edit_url'  => get_edit_post_link( $p->ID, '' ),
        ];
    }
    return [ 'rows' => $rows, 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages ];
}

function mamboleo_fix_locations_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'mamboleo' ) );
    }

    $page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $data = mamboleo_fix_locations_query( $page, 25 );
    $counties = mamboleo_counties_data();

    // Build a JSON payload for the JS subcounty dropdown.
    $counties_js = [];
    foreach ( $counties as $c ) {
        $counties_js[ $c['slug'] ] = array_map(
            function ( $s ) { return [ 'name' => $s['name'], 'slug' => $s['slug'] ]; },
            $c['subs']
        );
    }
    ?>
    <div class="wrap">
        <h1>Fix Locations
            <span class="title-count">(<?php echo (int) $data['total']; ?> flagged)</span>
        </h1>
        <p class="description">
            Incidents whose GPS falls outside Kenya, is the country-level fallback, or is flagged for review.
            Pick a county (and subcounty if needed) to snap the coordinates inside the right polygon.
        </p>

        <?php if ( empty( $data['rows'] ) ): ?>
            <p style="background:#fff;padding:16px;border-left:4px solid #22c55e;">
                Nothing to fix — all published incidents look good.
            </p>
        <?php else: ?>
        <table class="widefat striped" id="mm-fix-table">
            <thead>
                <tr>
                    <th style="width:30%;">Title</th>
                    <th style="width:13%;">Current coords</th>
                    <th style="width:15%;">Location</th>
                    <th style="width:10%;">Precision</th>
                    <th style="width:14%;">County</th>
                    <th style="width:14%;">Subcounty</th>
                    <th style="width:auto;">Apply</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $data['rows'] as $r ): ?>
                <tr data-id="<?php echo (int) $r['id']; ?>">
                    <td>
                        <strong><a href="<?php echo esc_url( $r['edit_url'] ); ?>">
                            <?php echo esc_html( $r['title'] ?: '(untitled)' ); ?>
                        </a></strong>
                    </td>
                    <td style="font-family:monospace;font-size:12px;color:#444;">
                        <?php echo esc_html( number_format( $r['lat'], 4 ) . ', ' . number_format( $r['lng'], 4 ) ); ?>
                    </td>
                    <td><?php echo esc_html( $r['name'] ?: '—' ); ?></td>
                    <td>
                        <?php
                        $pcol = [ 'exact' => '#22c55e', 'subcounty' => '#0ea5e9', 'county' => '#eab308', 'country' => '#ef4444' ][ $r['precision'] ] ?? '#94a3b8';
                        printf(
                            '<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:%s;color:#fff;font-size:11px;font-weight:600;">%s</span>',
                            esc_attr( $pcol ), esc_html( $r['precision'] )
                        );
                        ?>
                    </td>
                    <td>
                        <select class="mm-county" style="width:100%;">
                            <option value="">— county —</option>
                            <?php foreach ( $counties as $c ): ?>
                                <option value="<?php echo esc_attr( $c['slug'] ); ?>" <?php selected( $r['county'], $c['slug'] ); ?>>
                                    <?php echo esc_html( $c['name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select class="mm-subcounty" style="width:100%;" disabled>
                            <option value="">—</option>
                        </select>
                    </td>
                    <td>
                        <button type="button" class="button button-primary mm-apply" disabled>Apply</button>
                        <span class="mm-status" style="margin-left:8px;font-size:12px;"></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $data['pages'] > 1 ): ?>
            <div class="tablenav" style="margin-top:12px;">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( [
                        'base'    => add_query_arg( 'paged', '%#%' ),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $data['pages'],
                    ] );
                    ?>
                </div>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        const SUBS    = <?php echo wp_json_encode( $counties_js ); ?>;
        const REST    = <?php echo wp_json_encode( esc_url_raw( rest_url( 'mamboleo/v1/admin/incidents/' ) ) ); ?>;
        const NONCE   = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

        document.querySelectorAll('#mm-fix-table tr[data-id]').forEach(row => {
            const id       = row.dataset.id;
            const countyEl = row.querySelector('.mm-county');
            const subEl    = row.querySelector('.mm-subcounty');
            const applyEl  = row.querySelector('.mm-apply');
            const statusEl = row.querySelector('.mm-status');

            function refreshSubs() {
                const slug = countyEl.value;
                subEl.innerHTML = '<option value="">—</option>';
                if (!slug) { subEl.disabled = true; applyEl.disabled = true; return; }
                const list = SUBS[slug] || [];
                if (list.length === 0) {
                    subEl.disabled = true;
                } else {
                    subEl.disabled = false;
                    list.forEach(s => {
                        const opt = document.createElement('option');
                        opt.value = s.slug;
                        opt.textContent = s.name;
                        subEl.appendChild(opt);
                    });
                }
                applyEl.disabled = false;
            }
            refreshSubs();
            countyEl.addEventListener('change', refreshSubs);

            applyEl.addEventListener('click', async () => {
                applyEl.disabled = true;
                statusEl.textContent = 'Saving…';
                statusEl.style.color = '#666';
                try {
                    const res = await fetch(REST + id + '/fix-location', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': NONCE,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            county:    countyEl.value,
                            subcounty: subEl.value || '',
                        }),
                    });
                    const body = await res.json();
                    if (!res.ok) {
                        statusEl.textContent = body.message || ('Error ' + res.status);
                        statusEl.style.color = '#b91c1c';
                        applyEl.disabled = false;
                        // Hint admin to pick subcounty on 422.
                        if (res.status === 422 && subEl.options.length > 1) subEl.focus();
                        return;
                    }
                    statusEl.textContent = '✓ ' + body.precision + ' → ' + body.name;
                    statusEl.style.color = '#15803d';
                    // Fade out the row so admin sees progress on long lists.
                    row.style.transition = 'opacity .4s ease';
                    row.style.opacity = '0.45';
                } catch ( e ) {
                    statusEl.textContent = 'Network error';
                    statusEl.style.color = '#b91c1c';
                    applyEl.disabled = false;
                }
            });
        });
    })();
    </script>
    <?php
}

// ──────────────────────────────────────────────────────────────────────────
// Meta box on the incident edit screen
// ──────────────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'mamboleo-location-fix',
        'Location — Snap to County',
        'mamboleo_location_meta_box',
        'incident',
        'side',
        'default'
    );
} );

function mamboleo_location_meta_box( WP_Post $post ): void {
    $lat  = (float) get_post_meta( $post->ID, 'latitude', true );
    $lng  = (float) get_post_meta( $post->ID, 'longitude', true );
    $cur_county = (string) get_post_meta( $post->ID, 'location_county', true );
    $cur_sub    = (string) get_post_meta( $post->ID, 'location_subcounty', true );
    $precision  = (string) get_post_meta( $post->ID, 'location_precision', true ) ?: 'exact';
    $counties   = mamboleo_counties_data();
    $in_kenya   = mamboleo_point_in_kenya( $lat, $lng );

    $counties_js = [];
    foreach ( $counties as $c ) {
        $counties_js[ $c['slug'] ] = array_map(
            function ( $s ) { return [ 'name' => $s['name'], 'slug' => $s['slug'] ]; },
            $c['subs']
        );
    }
    ?>
    <p style="margin:0 0 6px;">
        <strong>Current:</strong>
        <code style="font-size:11px;"><?php echo esc_html( number_format( $lat, 4 ) . ', ' . number_format( $lng, 4 ) ); ?></code>
        <br>
        <small>
            <?php echo $in_kenya
                ? '<span style="color:#15803d;">✓ inside Kenya</span>'
                : '<span style="color:#b91c1c;">⚠ outside Kenya</span>'; ?>
            · precision: <code><?php echo esc_html( $precision ); ?></code>
        </small>
    </p>

    <p style="margin:10px 0 4px;"><label for="mm-mb-county"><strong>County</strong></label></p>
    <select id="mm-mb-county" style="width:100%;">
        <option value="">— county —</option>
        <?php foreach ( $counties as $c ): ?>
            <option value="<?php echo esc_attr( $c['slug'] ); ?>" <?php selected( $cur_county, $c['slug'] ); ?>>
                <?php echo esc_html( $c['name'] ); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <p style="margin:10px 0 4px;"><label for="mm-mb-sub"><strong>Subcounty</strong></label></p>
    <select id="mm-mb-sub" style="width:100%;" disabled>
        <option value="">—</option>
    </select>

    <p style="margin-top:10px;">
        <button type="button" class="button button-primary" id="mm-mb-apply" disabled>Snap to boundary</button>
    </p>
    <p id="mm-mb-status" style="margin:6px 0 0;font-size:12px;min-height:16px;"></p>

    <script>
    (function () {
        const SUBS  = <?php echo wp_json_encode( $counties_js ); ?>;
        const CUR_SUB = <?php echo wp_json_encode( $cur_sub ); ?>;
        const REST  = <?php echo wp_json_encode( esc_url_raw( rest_url( 'mamboleo/v1/admin/incidents/' . $post->ID . '/fix-location' ) ) ); ?>;
        const NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

        const countyEl = document.getElementById('mm-mb-county');
        const subEl    = document.getElementById('mm-mb-sub');
        const applyEl  = document.getElementById('mm-mb-apply');
        const statusEl = document.getElementById('mm-mb-status');

        function refreshSubs() {
            const slug = countyEl.value;
            subEl.innerHTML = '<option value="">—</option>';
            if (!slug) { subEl.disabled = true; applyEl.disabled = true; return; }
            const list = SUBS[slug] || [];
            subEl.disabled = list.length === 0;
            list.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.slug; opt.textContent = s.name;
                if (s.slug === CUR_SUB) opt.selected = true;
                subEl.appendChild(opt);
            });
            applyEl.disabled = false;
        }
        refreshSubs();
        countyEl.addEventListener('change', refreshSubs);

        applyEl.addEventListener('click', async () => {
            applyEl.disabled = true;
            statusEl.textContent = 'Saving…';
            statusEl.style.color = '#666';
            try {
                const res = await fetch(REST, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    credentials: 'same-origin',
                    body: JSON.stringify({ county: countyEl.value, subcounty: subEl.value || '' }),
                });
                const body = await res.json();
                if (!res.ok) {
                    statusEl.textContent = body.message || ('Error ' + res.status);
                    statusEl.style.color = '#b91c1c';
                    applyEl.disabled = false;
                    return;
                }
                statusEl.innerHTML = '✓ ' + body.precision + ' → ' + body.name
                    + '<br><code style="font-size:10px;">' + body.lat.toFixed(4) + ', ' + body.lng.toFixed(4) + '</code>';
                statusEl.style.color = '#15803d';
                // Nudge admin to reload to see updated meta in other boxes.
                setTimeout(() => { window.location.reload(); }, 1200);
            } catch ( e ) {
                statusEl.textContent = 'Network error';
                statusEl.style.color = '#b91c1c';
                applyEl.disabled = false;
            }
        });
    })();
    </script>
    <?php
}

// ──────────────────────────────────────────────────────────────────────────
// WP-CLI backfill
// ──────────────────────────────────────────────────────────────────────────

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'mamboleo backfill-locations', function ( $args, $assoc ) {
        $dry = ! empty( $assoc['dry-run'] );
        $q = new WP_Query( [
            'post_type'      => 'incident',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );
        $fixed = 0; $flagged = 0; $ok = 0;
        foreach ( $q->posts as $id ) {
            $lat = (float) get_post_meta( $id, 'latitude', true );
            $lng = (float) get_post_meta( $id, 'longitude', true );
            if ( $lat === 0.0 && $lng === 0.0 ) continue;

            if ( mamboleo_point_in_kenya( $lat, $lng ) ) {
                // Attach nearest-county label if missing, precision=exact.
                if ( ! get_post_meta( $id, 'location_precision', true ) ) {
                    if ( ! $dry ) {
                        $near = mamboleo_nearest_county( $lat, $lng );
                        if ( $near ) {
                            update_post_meta( $id, 'location_county',    $near['slug'] );
                            update_post_meta( $id, 'location_precision', 'exact' );
                        }
                    }
                    $ok++;
                }
                continue;
            }

            // Outside Kenya → snap.
            $near = mamboleo_nearest_county( $lat, $lng );
            if ( ! $near ) continue;
            if ( $dry ) {
                WP_CLI::log( sprintf( '[dry] #%d %.4f,%.4f → %s', $id, $lat, $lng, $near['name'] ) );
            } else {
                update_post_meta( $id, 'latitude',  $near['center'][0] );
                update_post_meta( $id, 'longitude', $near['center'][1] );
                update_post_meta( $id, 'location_name',      $near['name'] );
                update_post_meta( $id, 'location_county',    $near['slug'] );
                update_post_meta( $id, 'location_precision', 'county' );
                update_post_meta( $id, 'needs_review',       true );
            }
            $fixed++; $flagged++;
        }
        WP_CLI::success( sprintf(
            '%s: scanned=%d  snapped=%d  flagged_for_review=%d  labelled_inside=%d',
            $dry ? 'DRY RUN' : 'DONE', count( $q->posts ), $fixed, $flagged, $ok
        ) );
    } );
}
