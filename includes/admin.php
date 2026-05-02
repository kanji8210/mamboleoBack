<?php
/**
 * Admin meta box for incident fields.
 */

add_action( 'add_meta_boxes', 'mamboleo_add_meta_box' );
function mamboleo_add_meta_box(): void {
    add_meta_box(
        'mamboleo_incident_fields',
        __( 'Incident Details', 'mamboleo' ),
        'mamboleo_meta_box_cb',
        'incident',
        'normal',
        'high'
    );
}

function mamboleo_meta_box_cb( WP_Post $post ): void {
    wp_nonce_field( 'mamboleo_save_meta', 'mamboleo_nonce' );

    $type        = get_post_meta( $post->ID, 'type',          true ) ?: 'fire';
    $lat         = get_post_meta( $post->ID, 'latitude',      true ) ?: '';
    $lng         = get_post_meta( $post->ID, 'longitude',     true ) ?: '';
    $severity    = get_post_meta( $post->ID, 'severity',      true ) ?: 'low';
    $status      = get_post_meta( $post->ID, 'status',        true ) ?: 'unsafe';
    $inc_time    = get_post_meta( $post->ID, 'incident_time', true ) ?: '';
    $video_url   = get_post_meta( $post->ID, 'video_url',     true ) ?: '';
    $is_verified = get_post_meta( $post->ID, 'is_verified',   true );
    ?>
    <style>
        .mamboleo-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 20px; padding:4px 0; }
        .mamboleo-grid label { font-weight:600; font-size:13px; display:block; margin-bottom:4px; }
        .mamboleo-grid input, .mamboleo-grid select { width:100%; }
        .mamboleo-map-hint { margin-top:10px; font-size:12px; color:#666; }
    </style>
    <div class="mamboleo-grid">
        <div>
            <label for="mamboleo_type"><?php esc_html_e( 'Type', 'mamboleo' ); ?></label>
            <select id="mamboleo_type" name="mamboleo_type">
                <?php foreach ( [ 'fire', 'accident', 'police', 'weather', 'protest', 'flood', 'medical', 'military', 'info', 'health', 'environmental' ] as $opt ) : ?>
                    <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $type, $opt ); ?>>
                        <?php echo esc_html( ucfirst( $opt ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="mamboleo_severity"><?php esc_html_e( 'Severity', 'mamboleo' ); ?></label>
            <select id="mamboleo_severity" name="mamboleo_severity">
                <?php foreach ( [ 'low', 'medium', 'high' ] as $opt ) : ?>
                    <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $severity, $opt ); ?>>
                        <?php echo esc_html( ucfirst( $opt ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="mamboleo_status"><?php esc_html_e( 'Status', 'mamboleo' ); ?></label>
            <select id="mamboleo_status" name="mamboleo_status">
                <?php foreach ( [ 'unsafe', 'all_clear', 'police_operating', 'police_aggressive', 'unknown' ] as $opt ) : ?>
                    <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $status, $opt ); ?>>
                        <?php echo esc_html( str_replace( '_', ' ', ucfirst( $opt ) ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="mamboleo_lat"><?php esc_html_e( 'Latitude', 'mamboleo' ); ?></label>
            <input type="number" id="mamboleo_lat" name="mamboleo_lat"
                   step="0.000001" value="<?php echo esc_attr( $lat ); ?>"
                   placeholder="-1.286389" />
        </div>
        <div>
            <label for="mamboleo_lng"><?php esc_html_e( 'Longitude', 'mamboleo' ); ?></label>
            <input type="number" id="mamboleo_lng" name="mamboleo_lng"
                   step="0.000001" value="<?php echo esc_attr( $lng ); ?>"
                   placeholder="36.817223" />
        </div>
        <div>
            <label for="mamboleo_incident_time"><?php esc_html_e( 'Incident Time', 'mamboleo' ); ?></label>
            <input type="datetime-local" id="mamboleo_incident_time" name="mamboleo_incident_time"
                   value="<?php echo esc_attr( $inc_time ); ?>" />
        </div>
        <div>
            <label for="mamboleo_video_url"><?php esc_html_e( 'Video URL', 'mamboleo' ); ?></label>
            <input type="url" id="mamboleo_video_url" name="mamboleo_video_url"
                   value="<?php echo esc_attr( $video_url ); ?>" placeholder="https://rumble.com/..." />
        </div>
        <div style="grid-column:1/-1;display:flex;align-items:center;gap:8px;">
            <input type="checkbox" id="mamboleo_is_verified" name="mamboleo_is_verified" value="1"
                   <?php checked( $is_verified === '' ? true : (bool) $is_verified ); ?> />
            <label for="mamboleo_is_verified" style="margin:0;font-weight:600;font-size:13px;">
                <?php esc_html_e( 'Verified', 'mamboleo' ); ?>
            </label>
        </div>
    </div>
    <p class="mamboleo-map-hint">
        <?php esc_html_e( 'Find coordinates: right-click any location on Google Maps → "What\'s here?"', 'mamboleo' ); ?>
    </p>
    <?php
}

add_action( 'save_post_incident', 'mamboleo_save_meta' );
function mamboleo_save_meta( int $post_id ): void {
    if ( ! isset( $_POST['mamboleo_nonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mamboleo_nonce'] ) ), 'mamboleo_save_meta' )
    ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $allowed_types    = [ 'fire', 'accident', 'police', 'weather', 'protest', 'flood', 'medical', 'military', 'info', 'health', 'environmental' ];
    $allowed_severities = [ 'low', 'medium', 'high' ];
    $allowed_statuses = [ 'unsafe', 'all_clear', 'police_operating', 'police_aggressive', 'unknown' ];

    if ( isset( $_POST['mamboleo_type'] ) ) {
        $type = sanitize_text_field( wp_unslash( $_POST['mamboleo_type'] ) );
        update_post_meta( $post_id, 'type', in_array( $type, $allowed_types, true ) ? $type : 'fire' );
    }

    if ( isset( $_POST['mamboleo_severity'] ) ) {
        $severity = sanitize_text_field( wp_unslash( $_POST['mamboleo_severity'] ) );
        update_post_meta( $post_id, 'severity', in_array( $severity, $allowed_severities, true ) ? $severity : 'low' );
    }

    if ( isset( $_POST['mamboleo_status'] ) ) {
        $status = sanitize_text_field( wp_unslash( $_POST['mamboleo_status'] ) );
        update_post_meta( $post_id, 'status', in_array( $status, $allowed_statuses, true ) ? $status : 'unsafe' );
    }

    if ( isset( $_POST['mamboleo_lat'] ) ) {
        $lat = filter_var( wp_unslash( $_POST['mamboleo_lat'] ), FILTER_VALIDATE_FLOAT );
        if ( $lat !== false && $lat >= -90 && $lat <= 90 ) {
            update_post_meta( $post_id, 'latitude', $lat );
        }
    }

    if ( isset( $_POST['mamboleo_lng'] ) ) {
        $lng = filter_var( wp_unslash( $_POST['mamboleo_lng'] ), FILTER_VALIDATE_FLOAT );
        if ( $lng !== false && $lng >= -180 && $lng <= 180 ) {
            update_post_meta( $post_id, 'longitude', $lng );
        }
    }

    if ( isset( $_POST['mamboleo_incident_time'] ) ) {
        update_post_meta( $post_id, 'incident_time', sanitize_text_field( wp_unslash( $_POST['mamboleo_incident_time'] ) ) );
    }

    if ( isset( $_POST['mamboleo_video_url'] ) ) {
        update_post_meta( $post_id, 'video_url', esc_url_raw( wp_unslash( $_POST['mamboleo_video_url'] ) ) );
    }

    update_post_meta( $post_id, 'is_verified', isset( $_POST['mamboleo_is_verified'] ) ? 1 : 0 );
}

/* ─────────────────────────────────────────────────────────────────────────
 * AI Intelligence side meta-box on the incident edit screen.
 * Surfaces what the local LLM said about this incident + a Re-analyse button.
 * ────────────────────────────────────────────────────────────────────── */
add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'mamboleo_ai_panel',
        __( 'AI Intelligence', 'mamboleo' ),
        'mamboleo_ai_meta_box_cb',
        'incident',
        'side',
        'default'
    );
} );

function mamboleo_ai_meta_box_cb( WP_Post $post ): void {
    $model      = get_post_meta( $post->ID, 'ai_model', true );
    $summary    = get_post_meta( $post->ID, 'ai_summary', true );
    $reasoning  = get_post_meta( $post->ID, 'ai_severity_reasoning', true );
    $flags      = get_post_meta( $post->ID, 'ai_flags', true );
    $followup   = (int) get_post_meta( $post->ID, 'ai_is_followup', true );
    $processed  = get_post_meta( $post->ID, 'ai_processed_at', true );
    $expires    = get_post_meta( $post->ID, 'expires_at', true );
    $updates    = (int) get_post_meta( $post->ID, 'update_count', true );
    ?>
    <style>
        .mb-ai-row { margin:6px 0; font-size:12px; }
        .mb-ai-row b { display:inline-block; min-width:78px; color:#646970; font-weight:600; }
        .mb-ai-chip { display:inline-block; padding:2px 6px; border-radius:3px; background:#f0f0f1; font-size:11px; margin-right:4px; }
        .mb-ai-summary { background:#f6f7f7; padding:8px; border-radius:4px; font-size:12px; line-height:1.5; }
    </style>
    <?php if ( $model ) : ?>
        <div class="mb-ai-row"><b><?php esc_html_e( 'Model', 'mamboleo' ); ?>:</b> <code><?php echo esc_html( $model ); ?></code></div>
        <?php if ( $followup ) : ?>
            <div class="mb-ai-row"><span class="mb-ai-chip" style="background:#fcf0a8;"><?php esc_html_e( 'Follow-up story', 'mamboleo' ); ?></span></div>
        <?php endif; ?>
        <?php if ( $flags ) : ?>
            <div class="mb-ai-row"><b><?php esc_html_e( 'Flags', 'mamboleo' ); ?>:</b>
                <?php foreach ( array_filter( array_map( 'trim', explode( ',', $flags ) ) ) as $f ) : ?>
                    <span class="mb-ai-chip" style="background:#fde2e2;color:#8a1f1f;"><?php echo esc_html( $f ); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ( $summary ) : ?>
            <div class="mb-ai-row"><b><?php esc_html_e( 'Summary', 'mamboleo' ); ?>:</b></div>
            <div class="mb-ai-summary"><?php echo esc_html( $summary ); ?></div>
        <?php endif; ?>
        <?php if ( $reasoning ) : ?>
            <div class="mb-ai-row" style="margin-top:8px;"><b><?php esc_html_e( 'Severity', 'mamboleo' ); ?>:</b></div>
            <div class="mb-ai-summary"><?php echo esc_html( $reasoning ); ?></div>
        <?php endif; ?>
        <?php if ( $processed ) : ?>
            <div class="mb-ai-row" style="margin-top:8px;color:#646970;">
                <?php printf( esc_html__( 'Analysed %s ago', 'mamboleo' ), esc_html( human_time_diff( strtotime( $processed ), time() ) ) ); ?>
            </div>
        <?php endif; ?>
    <?php else : ?>
        <p style="font-size:12px;color:#646970;"><?php esc_html_e( 'Not yet analysed by the AI layer.', 'mamboleo' ); ?></p>
    <?php endif; ?>

    <hr style="margin:12px 0;" />
    <div class="mb-ai-row"><b><?php esc_html_e( 'Updates', 'mamboleo' ); ?>:</b> <?php echo (int) $updates; ?></div>
    <?php if ( $expires ) : ?>
        <div class="mb-ai-row"><b><?php esc_html_e( 'Expires', 'mamboleo' ); ?>:</b>
            <?php
            $ts = strtotime( $expires );
            echo $ts > time()
                ? esc_html( 'in ' . human_time_diff( time(), $ts ) )
                : '<span style="color:#d63638;">' . esc_html__( 'overdue', 'mamboleo' ) . '</span>';
            ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
        <?php wp_nonce_field( 'mamboleo_reanalyse' ); ?>
        <input type="hidden" name="action"      value="mamboleo_reanalyse" />
        <input type="hidden" name="incident_id" value="<?php echo (int) $post->ID; ?>" />
        <button class="button button-secondary" style="width:100%;"><?php esc_html_e( 'Re-analyse with AI', 'mamboleo' ); ?></button>
    </form>
    <p style="font-size:11px;color:#646970;margin:6px 0 0;">
        <?php esc_html_e( 'Re-analysis runs on the next scraper / backfill pass.', 'mamboleo' ); ?>
    </p>
    <?php
}
