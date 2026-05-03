<?php
/**
 * Quick Edit support for incident posts.
 *
 * Adds custom fields to WordPress's built-in Quick Edit row so admins can
 * change Type (fire/accident/police/weather) and Location (country / county /
 * subcounty) without opening the full editor. Title and status are already
 * editable via core Quick Edit.
 *
 * The full inline-row column (rendered by admin-location-tools.php) and the
 * meta box on the edit screen still work — Quick Edit is an additional path.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/../data/counties.php';
require_once __DIR__ . '/../data/countries.php';

/**
 * Hidden span on each row so the Quick Edit JS can read current values.
 * Re-uses the `mamboleo_loc` column rendered by admin-location-tools.php —
 * we only inject extra hidden fields below the visible cell content.
 */
add_action( 'manage_incident_posts_custom_column', function ( $col, $post_id ) {
    if ( $col !== 'mamboleo_loc' ) return;
    $type      = (string) get_post_meta( $post_id, 'type', true ) ?: 'fire';
    $country   = (string) get_post_meta( $post_id, 'location_country', true ) ?: 'kenya';
    $county    = (string) get_post_meta( $post_id, 'location_county', true );
    $sub       = (string) get_post_meta( $post_id, 'location_subcounty', true );
    $lifecycle = (string) get_post_meta( $post_id, 'lifecycle', true ) ?: 'active';
    ?>
    <span class="mm-qe-data" style="display:none;"
          data-type="<?php echo esc_attr( $type ); ?>"
          data-country="<?php echo esc_attr( $country ); ?>"
          data-county="<?php echo esc_attr( $county ); ?>"
          data-subcounty="<?php echo esc_attr( $sub ); ?>"
          data-lifecycle="<?php echo esc_attr( $lifecycle ); ?>"></span>
    <?php
}, 20, 2 );

/**
 * Render the Quick Edit fieldset. Fires once per (column, post_type) pair —
 * we only render for the `mamboleo_loc` column so it appears next to where
 * the inline form is, and only for the incident post type.
 */
add_action( 'quick_edit_custom_box', function ( $column_name, $post_type ) {
    if ( $post_type !== 'incident' || $column_name !== 'mamboleo_loc' ) return;

    $countries = mamboleo_countries_data();
    $counties  = mamboleo_counties_data();

    // JSON map for cascade — Quick Edit JS uses it to populate subcounty options.
    $subs_js = [];
    foreach ( $counties as $c ) {
        $subs_js[ $c['slug'] ] = array_map(
            function ( $s ) { return [ 'name' => $s['name'], 'slug' => $s['slug'] ]; },
            $c['subs']
        );
    }
    $types = [
        'fire'     => 'Fire',
        'accident' => 'Accident',
        'police'   => 'Police',
        'weather'  => 'Weather',
        'protest'  => 'Protest',
        'flood'    => 'Flood',
        'medical'  => 'Medical',
        'military' => 'Military Ops',
        'info'     => 'Info',
        'health'   => 'Public Health',
        'environmental' => 'Environmental',
        'homicide' => 'Homicide',
        'femicide' => 'Femicide',
    ];
    ?>
    <fieldset class="inline-edit-col-right inline-edit-mamboleo">
        <div class="inline-edit-col">
            <h4 style="margin:0 0 6px;">Mamboleo</h4>

            <label class="inline-edit-group">
                <span class="title">Type</span>
                <select name="mm_type" class="mm-qe-type">
                    <?php foreach ( $types as $k => $label ): ?>
                        <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="inline-edit-group">
                <span class="title">Lifecycle</span>
                <select name="mm_lifecycle" class="mm-qe-lifecycle">
                    <option value="active">Active</option>
                    <option value="developing">Developing (pin)</option>
                    <option value="resolved">Resolved</option>
                    <option value="archived">Archived</option>
                </select>
            </label>

            <label class="inline-edit-group">
                <span class="title">Country</span>
                <select name="mm_country" class="mm-qe-country">
                    <?php foreach ( $countries as $cc ): ?>
                        <option value="<?php echo esc_attr( $cc['slug'] ); ?>"><?php echo esc_html( $cc['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="inline-edit-group">
                <span class="title">County</span>
                <select name="mm_county" class="mm-qe-county">
                    <option value="">— county —</option>
                    <?php foreach ( $counties as $c ): ?>
                        <option value="<?php echo esc_attr( $c['slug'] ); ?>"><?php echo esc_html( $c['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="inline-edit-group">
                <span class="title">Subcounty</span>
                <select name="mm_subcounty" class="mm-qe-subcounty" disabled>
                    <option value="">—</option>
                </select>
            </label>

            <input type="hidden" name="mm_qe_nonce" value="<?php echo esc_attr( wp_create_nonce( 'mm_quick_edit' ) ); ?>">
        </div>
    </fieldset>
    <script type="text/javascript">
    /* Quick Edit cascade — runs once; safe to register repeatedly. */
    (function () {
        if (window.__mmQuickEditWired) return;
        window.__mmQuickEditWired = true;

        const SUBS = <?php echo wp_json_encode( $subs_js ); ?>;

        // Hijack inlineEditPost.edit so we can pre-fill the row's stored values.
        if (typeof inlineEditPost === 'undefined') return;
        const _edit = inlineEditPost.edit;
        inlineEditPost.edit = function ( id ) {
            const ret = _edit.apply( this, arguments );
            const postId = typeof id === 'object' ? this.getId( id ) : id;
            if (!postId) return ret;

            const row  = document.querySelector('#post-' + postId + ' .mm-qe-data');
            const form = document.querySelector('#edit-' + postId);
            if (!row || !form) return ret;

            const type      = row.dataset.type      || 'fire';
            const country   = row.dataset.country   || 'kenya';
            const county    = row.dataset.county    || '';
            const subcounty = row.dataset.subcounty || '';
            const lifecycle = row.dataset.lifecycle || 'active';

            const typeEl    = form.querySelector('.mm-qe-type');
            const countryEl = form.querySelector('.mm-qe-country');
            const countyEl  = form.querySelector('.mm-qe-county');
            const subEl     = form.querySelector('.mm-qe-subcounty');
            const lifeEl    = form.querySelector('.mm-qe-lifecycle');

            if (typeEl)    typeEl.value    = type;
            if (countryEl) countryEl.value = country;
            if (countyEl)  countyEl.value  = county;
            if (lifeEl)    lifeEl.value    = lifecycle;

            function refreshState() {
                const isKenya = countryEl && countryEl.value === 'kenya';
                if (countyEl) countyEl.disabled = !isKenya;
                if (!isKenya) {
                    if (subEl) { subEl.disabled = true; subEl.innerHTML = '<option value="">—</option>'; }
                    return;
                }
                refreshSubs();
            }
            function refreshSubs() {
                if (!subEl) return;
                const slug = countyEl ? countyEl.value : '';
                subEl.innerHTML = '<option value="">— subcounty —</option>';
                if (!slug) { subEl.disabled = true; return; }
                const list = SUBS[slug] || [];
                subEl.disabled = list.length === 0;
                list.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.slug; opt.textContent = s.name;
                    if (s.slug === subcounty) opt.selected = true;
                    subEl.appendChild(opt);
                });
            }
            refreshState();
            if (countryEl) countryEl.addEventListener('change', refreshState);
            if (countyEl)  countyEl.addEventListener('change', refreshSubs);

            return ret;
        };
    })();
    </script>
    <style>
        .inline-edit-mamboleo .inline-edit-group { display:block; margin:6px 0; }
        .inline-edit-mamboleo .inline-edit-group .title { display:inline-block; width:80px; font-weight:600; }
        .inline-edit-mamboleo select { min-width: 160px; }
    </style>
    <?php
}, 10, 2 );

/**
 * Persist Quick Edit submissions. Also runs the snap pipeline so coords stay
 * in sync with the chosen country/county/subcounty.
 */
add_action( 'save_post_incident', function ( $post_id, $post, $update ) {
    if ( ! $update ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    if ( empty( $_POST['mm_qe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mm_qe_nonce'] ) ), 'mm_quick_edit' ) ) {
        return;
    }

    // Type — accept whitelist only.
    if ( isset( $_POST['mm_type'] ) ) {
        $allowed_types = [ 'fire', 'accident', 'police', 'weather', 'protest', 'flood', 'medical', 'military', 'info', 'health', 'environmental', 'homicide', 'femicide' ];
        $type = sanitize_key( wp_unslash( $_POST['mm_type'] ) );
        if ( in_array( $type, $allowed_types, true ) ) {
            update_post_meta( $post_id, 'type', $type );
        }
    }

    // Lifecycle — whitelist; admins use this to mark a story 'developing'
    // (pinned, never auto-aged) or to manually resolve / archive it.
    if ( isset( $_POST['mm_lifecycle'] ) ) {
        $allowed_stages = [ 'active', 'developing', 'resolved', 'archived' ];
        $stage = sanitize_key( wp_unslash( $_POST['mm_lifecycle'] ) );
        if ( in_array( $stage, $allowed_stages, true ) ) {
            update_post_meta( $post_id, 'lifecycle', $stage );
        }
    }

    // Location — re-run the snap so coords + name + precision stay consistent.
    $country   = isset( $_POST['mm_country'] )   ? sanitize_title( wp_unslash( $_POST['mm_country'] ) )   : 'kenya';
    $county    = isset( $_POST['mm_county'] )    ? sanitize_title( wp_unslash( $_POST['mm_county'] ) )    : '';
    $subcounty = isset( $_POST['mm_subcounty'] ) ? sanitize_title( wp_unslash( $_POST['mm_subcounty'] ) ) : '';

    $lat = (float) get_post_meta( $post_id, 'latitude',  true );
    $lng = (float) get_post_meta( $post_id, 'longitude', true );

    if ( function_exists( 'mamboleo_snap_location' ) && function_exists( 'mamboleo_apply_snap_to_incident' ) ) {
        $snap = mamboleo_snap_location( $lat, $lng, $county, $subcounty, $country ?: 'kenya' );
        if ( $snap ) {
            mamboleo_apply_snap_to_incident( $post_id, $snap );
        } else {
            // Couldn't auto-snap (Kenya + ambiguous county). Still record the choices
            // so the admin sees their selection persist; coords stay as-is.
            update_post_meta( $post_id, 'location_country',   $country ?: 'kenya' );
            if ( $county )    update_post_meta( $post_id, 'location_county',    $county );
            if ( $subcounty ) update_post_meta( $post_id, 'location_subcounty', $subcounty );
        }
    }
}, 20, 3 );
