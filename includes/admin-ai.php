<?php
/**
 * Mamboleo Admin — AI Intelligence panel.
 *
 * Surfaces the local-LLM (Ollama) layer as a first-class admin feature so
 * editors understand what the AI is doing and can trigger re-analysis.
 *
 * Capabilities:
 *   • Live Ollama health-check (GET /api/tags)
 *   • Per-incident "Re-analyse" action (queues a flag the scraper picks up)
 *   • Bulk backfill stats and one-click trigger of backfill_ai.py
 *   • Settings: Ollama host, model, timeout (stored in options)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    if ( ! isset( $GLOBALS['admin_page_hooks']['mamboleo-main'] ) ) return;
    add_submenu_page(
        'mamboleo-main',
        'AI Intelligence',
        'AI Intelligence',
        'manage_options',
        'mamboleo-ai',
        'mamboleo_ai_admin_page',
        4
    );
}, 25 );

/* Settings storage */
add_action( 'admin_init', function () {
    register_setting( 'mamboleo_ai', 'mamboleo_ollama_host',  [ 'type' => 'string', 'default' => 'http://localhost:11434' ] );
    register_setting( 'mamboleo_ai', 'mamboleo_ollama_model', [ 'type' => 'string', 'default' => 'llama3.1:8b' ] );
    register_setting( 'mamboleo_ai', 'mamboleo_ollama_timeout', [ 'type' => 'integer', 'default' => 45 ] );
} );

/**
 * Live ping to Ollama. Cached 60s so the dashboard doesn't hammer the host.
 */
function mamboleo_ollama_health(): array {
    $cached = get_transient( 'mamboleo_ollama_health' );
    if ( is_array( $cached ) ) return $cached;

    $host = rtrim( get_option( 'mamboleo_ollama_host', 'http://localhost:11434' ), '/' );
    $resp = wp_remote_get( $host . '/api/tags', [ 'timeout' => 3 ] );
    $ok   = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200;

    $models = [];
    if ( $ok ) {
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! empty( $body['models'] ) ) {
            $models = array_column( $body['models'], 'name' );
        }
    }

    $result = [
        'ok'     => $ok,
        'models' => $models,
        'error'  => is_wp_error( $resp ) ? $resp->get_error_message() : '',
    ];
    set_transient( 'mamboleo_ollama_health', $result, MINUTE_IN_SECONDS );
    return $result;
}

/* Action: queue an incident for re-analysis. The scraper picks up the flag
 * during its next run (or via the manual trigger). */
add_action( 'admin_post_mamboleo_reanalyse', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    check_admin_referer( 'mamboleo_reanalyse' );

    $id = absint( $_POST['incident_id'] ?? 0 );
    if ( $id && get_post_type( $id ) === 'incident' ) {
        // Strip ai_model so backfill_ai.py picks it up via /needs-ai.
        delete_post_meta( $id, 'ai_model' );
        delete_post_meta( $id, 'ai_processed_at' );
        update_post_meta( $id, 'needs_reanalysis', 1 );
    }
    wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=mamboleo-ai' ) );
    exit;
} );

function mamboleo_ai_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $health = mamboleo_ollama_health();

    $total    = (int) ( wp_count_posts( 'incident' )->publish ?? 0 );
    $analysed = (int) ( new WP_Query( [
        'post_type' => 'incident', 'post_status' => 'publish',
        'posts_per_page' => 1, 'fields' => 'ids',
        'meta_query' => [ [ 'key' => 'ai_model', 'compare' => 'EXISTS' ], [ 'key' => 'ai_model', 'value' => '', 'compare' => '!=' ] ],
    ] ) )->found_posts;
    $needs_ai = max( 0, $total - $analysed );

    $recent_q = new WP_Query( [
        'post_type'      => 'incident',
        'post_status'    => 'publish',
        'posts_per_page' => 15,
        'meta_query'     => [ [ 'key' => 'ai_model', 'compare' => 'EXISTS' ], [ 'key' => 'ai_model', 'value' => '', 'compare' => '!=' ] ],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'AI Intelligence', 'mamboleo' ); ?></h1>
        <p class="description">
            <?php esc_html_e( 'Local LLM (Ollama) classifies incident type, severity, follow-up nature and risk flags. Editors stay in control — every analysis is logged and re-runnable.', 'mamboleo' ); ?>
        </p>

        <h2><?php esc_html_e( 'Status', 'mamboleo' ); ?></h2>
        <table class="widefat striped" style="max-width:780px;">
            <tbody>
                <tr>
                    <th style="width:200px;"><?php esc_html_e( 'Ollama reachable', 'mamboleo' ); ?></th>
                    <td>
                        <?php if ( $health['ok'] ) : ?>
                            <span style="color:#00a32a;font-weight:600;">● Online</span>
                        <?php else : ?>
                            <span style="color:#d63638;font-weight:600;">● Offline</span>
                            <?php if ( $health['error'] ) : ?>
                                <code><?php echo esc_html( $health['error'] ); ?></code>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Available models', 'mamboleo' ); ?></th>
                    <td><code><?php echo esc_html( $health['models'] ? implode( ', ', $health['models'] ) : '—' ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Coverage', 'mamboleo' ); ?></th>
                    <td>
                        <?php printf( '<b>%d</b> of %d incidents analysed', $analysed, $total ); ?>
                        <?php if ( $needs_ai > 0 ) : ?>
                            — <?php echo (int) $needs_ai; ?> pending
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 style="margin-top:24px;"><?php esc_html_e( 'Settings', 'mamboleo' ); ?></h2>
        <form method="post" action="options.php">
            <?php settings_fields( 'mamboleo_ai' ); ?>
            <table class="form-table" style="max-width:780px;">
                <tr>
                    <th scope="row"><label for="mamboleo_ollama_host"><?php esc_html_e( 'Ollama host', 'mamboleo' ); ?></label></th>
                    <td><input type="url" id="mamboleo_ollama_host" name="mamboleo_ollama_host" value="<?php echo esc_attr( get_option( 'mamboleo_ollama_host', 'http://localhost:11434' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mamboleo_ollama_model"><?php esc_html_e( 'Model', 'mamboleo' ); ?></label></th>
                    <td><input type="text" id="mamboleo_ollama_model" name="mamboleo_ollama_model" value="<?php echo esc_attr( get_option( 'mamboleo_ollama_model', 'llama3.1:8b' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mamboleo_ollama_timeout"><?php esc_html_e( 'Timeout (seconds)', 'mamboleo' ); ?></label></th>
                    <td><input type="number" id="mamboleo_ollama_timeout" name="mamboleo_ollama_timeout" value="<?php echo esc_attr( get_option( 'mamboleo_ollama_timeout', 45 ) ); ?>" min="5" max="300" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2 style="margin-top:24px;"><?php esc_html_e( 'Recently analysed', 'mamboleo' ); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Incident', 'mamboleo' ); ?></th>
                    <th><?php esc_html_e( 'Model', 'mamboleo' ); ?></th>
                    <th><?php esc_html_e( 'Severity', 'mamboleo' ); ?></th>
                    <th><?php esc_html_e( 'Flags', 'mamboleo' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'mamboleo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $recent_q->posts as $p ) :
                    $model = get_post_meta( $p->ID, 'ai_model', true );
                    $sev   = get_post_meta( $p->ID, 'severity', true );
                    $flags = get_post_meta( $p->ID, 'ai_flags', true );
                ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>">
                                <?php echo esc_html( wp_trim_words( $p->post_title, 10 ) ); ?>
                            </a>
                        </td>
                        <td><code><?php echo esc_html( $model ?: '—' ); ?></code></td>
                        <td><?php echo esc_html( $sev ?: '—' ); ?></td>
                        <td><?php echo esc_html( $flags ?: '—' ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                                <?php wp_nonce_field( 'mamboleo_reanalyse' ); ?>
                                <input type="hidden" name="action" value="mamboleo_reanalyse" />
                                <input type="hidden" name="incident_id" value="<?php echo (int) $p->ID; ?>" />
                                <button class="button button-small"><?php esc_html_e( 'Re-analyse', 'mamboleo' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( ! $recent_q->posts ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No analysed incidents yet — run the scraper or backfill_ai.py.', 'mamboleo' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
