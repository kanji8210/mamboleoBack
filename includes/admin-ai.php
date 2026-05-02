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
    register_setting( 'mamboleo_ai', 'mamboleo_llm_provider', [
        'type' => 'string', 'default' => 'ollama',
        'sanitize_callback' => static fn( $v ) => in_array( $v, [ 'ollama', 'openai' ], true ) ? $v : 'ollama',
    ] );
    register_setting( 'mamboleo_ai', 'mamboleo_ollama_host',  [ 'type' => 'string', 'default' => 'http://localhost:11434' ] );
    register_setting( 'mamboleo_ai', 'mamboleo_ollama_model', [ 'type' => 'string', 'default' => 'llama3.1:8b' ] );
    register_setting( 'mamboleo_ai', 'mamboleo_ollama_timeout', [ 'type' => 'integer', 'default' => 45 ] );
    register_setting( 'mamboleo_ai', 'mamboleo_openai_base_url', [ 'type' => 'string', 'default' => 'https://api.openai.com/v1' ] );
    register_setting( 'mamboleo_ai', 'mamboleo_openai_model',    [ 'type' => 'string', 'default' => 'gpt-4o-mini' ] );
    register_setting( 'mamboleo_ai', 'mamboleo_openai_api_key',  [
        'type' => 'string', 'default' => '',
        'sanitize_callback' => static fn( $v ) => trim( (string) $v ),
    ] );
} );

/**
 * Live ping to the active provider. Cached 60s.
 */
function mamboleo_ollama_health(): array {
    $cached = get_transient( 'mamboleo_ollama_health' );
    if ( is_array( $cached ) ) return $cached;

    $provider = get_option( 'mamboleo_llm_provider', 'ollama' );
    $result   = $provider === 'openai'
        ? mamboleo_health_openai()
        : mamboleo_health_ollama();

    set_transient( 'mamboleo_ollama_health', $result, MINUTE_IN_SECONDS );
    return $result;
}

function mamboleo_health_ollama(): array {
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
    return [
        'provider' => 'ollama',
        'endpoint' => $host,
        'ok'       => $ok,
        'models'   => $models,
        'error'    => is_wp_error( $resp ) ? $resp->get_error_message() : ( $ok ? '' : 'HTTP ' . wp_remote_retrieve_response_code( $resp ) ),
    ];
}

function mamboleo_health_openai(): array {
    $base = rtrim( get_option( 'mamboleo_openai_base_url', 'https://api.openai.com/v1' ), '/' );
    $key  = (string) get_option( 'mamboleo_openai_api_key', '' );
    $is_local = ( strpos( $base, 'localhost' ) !== false || strpos( $base, '127.0.0.1' ) !== false );

    if ( $key === '' && ! $is_local ) {
        return [
            'provider' => 'openai',
            'endpoint' => $base,
            'ok'       => false,
            'models'   => [],
            'error'    => 'API key not set — add it below or use a local endpoint.',
        ];
    }

    $headers = [];
    if ( $key !== '' ) $headers['Authorization'] = 'Bearer ' . $key;
    $resp = wp_remote_get( $base . '/models', [ 'timeout' => 4, 'headers' => $headers ] );
    $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
    // Some providers reject GET /models with 401/403 even when usable; treat <500 as up.
    $ok   = ! is_wp_error( $resp ) && $code > 0 && $code < 500;

    $models = [];
    if ( $ok && $code === 200 ) {
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! empty( $body['data'] ) && is_array( $body['data'] ) ) {
            $models = array_slice( array_column( $body['data'], 'id' ), 0, 25 );
        }
    }
    return [
        'provider' => 'openai',
        'endpoint' => $base,
        'ok'       => $ok,
        'models'   => $models,
        'error'    => is_wp_error( $resp ) ? $resp->get_error_message() : ( $ok ? '' : 'HTTP ' . $code ),
    ];
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

/* Action: live "Test connection" — does a real chat-completion round-trip
 * with the saved provider settings. Stashes the result in a transient so it
 * survives the redirect back to the AI page. */
add_action( 'admin_post_mamboleo_test_llm', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    check_admin_referer( 'mamboleo_test_llm' );

    delete_transient( 'mamboleo_ollama_health' ); // force fresh health on return
    $result = mamboleo_test_llm_chat();
    set_transient( 'mamboleo_test_llm_result', $result, 60 );

    wp_safe_redirect( admin_url( 'admin.php?page=mamboleo-ai' ) );
    exit;
} );

function mamboleo_test_llm_chat(): array {
    $provider = get_option( 'mamboleo_llm_provider', 'ollama' );
    $started  = microtime( true );

    if ( $provider === 'openai' ) {
        $base = rtrim( get_option( 'mamboleo_openai_base_url', 'https://api.openai.com/v1' ), '/' );
        $key  = (string) get_option( 'mamboleo_openai_api_key', '' );
        $model = (string) get_option( 'mamboleo_openai_model', 'gpt-4o-mini' );

        if ( $key === '' && strpos( $base, 'localhost' ) === false && strpos( $base, '127.0.0.1' ) === false ) {
            return [ 'ok' => false, 'error' => 'API key not set.' ];
        }

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $key !== '' ) $headers['Authorization'] = 'Bearer ' . $key;

        $resp = wp_remote_post( $base . '/chat/completions', [
            'timeout' => 15,
            'headers' => $headers,
            'body'    => wp_json_encode( [
                'model'       => $model,
                'temperature' => 0,
                'max_tokens'  => 16,
                'messages'    => [
                    [ 'role' => 'system', 'content' => 'Reply with the single word OK.' ],
                    [ 'role' => 'user',   'content' => 'ping' ],
                ],
            ] ),
        ] );
    } else {
        $host  = rtrim( get_option( 'mamboleo_ollama_host', 'http://localhost:11434' ), '/' );
        $model = (string) get_option( 'mamboleo_ollama_model', 'llama3.1:8b' );
        $resp  = wp_remote_post( $host . '/api/chat', [
            'timeout' => 30,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'model'    => $model,
                'stream'   => false,
                'messages' => [
                    [ 'role' => 'system', 'content' => 'Reply with the single word OK.' ],
                    [ 'role' => 'user',   'content' => 'ping' ],
                ],
            ] ),
        ] );
    }

    $elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

    if ( is_wp_error( $resp ) ) {
        return [ 'ok' => false, 'error' => $resp->get_error_message(), 'ms' => $elapsed_ms ];
    }
    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = wp_remote_retrieve_body( $resp );
    if ( $code !== 200 ) {
        return [ 'ok' => false, 'error' => "HTTP $code: " . substr( $body, 0, 240 ), 'ms' => $elapsed_ms ];
    }

    $json = json_decode( $body, true );
    $reply = $provider === 'openai'
        ? ( $json['choices'][0]['message']['content'] ?? '' )
        : ( $json['message']['content'] ?? '' );

    return [
        'ok'    => $reply !== '',
        'reply' => trim( (string) $reply ),
        'ms'    => $elapsed_ms,
        'error' => $reply === '' ? 'Empty response.' : '',
    ];
}

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
            <?php esc_html_e( 'An LLM classifies incident type, severity, follow-up nature and risk flags. You can run a fully offline model (Ollama) or any OpenAI-compatible cloud endpoint (Groq, OpenAI, OpenRouter, LM Studio…). Editors stay in control — every analysis is logged and re-runnable.', 'mamboleo' ); ?>
        </p>

        <h2><?php esc_html_e( 'Status', 'mamboleo' ); ?></h2>
        <table class="widefat striped" style="max-width:780px;">
            <tbody>
                <tr>
                    <th style="width:200px;"><?php esc_html_e( 'Active provider', 'mamboleo' ); ?></th>
                    <td>
                        <code><?php echo esc_html( $health['provider'] ?? '—' ); ?></code>
                        — <code><?php echo esc_html( $health['endpoint'] ?? '—' ); ?></code>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Reachable', 'mamboleo' ); ?></th>
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
        <?php $provider = get_option( 'mamboleo_llm_provider', 'ollama' ); ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'mamboleo_ai' ); ?>
            <table class="form-table" style="max-width:780px;">
                <tr>
                    <th scope="row"><label for="mamboleo_llm_provider"><?php esc_html_e( 'Provider', 'mamboleo' ); ?></label></th>
                    <td>
                        <select id="mamboleo_llm_provider" name="mamboleo_llm_provider">
                            <option value="ollama" <?php selected( $provider, 'ollama' ); ?>>Ollama (local, offline)</option>
                            <option value="openai" <?php selected( $provider, 'openai' ); ?>>OpenAI-compatible (cloud or LM Studio)</option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'These options must also be set in scraper/.env so the Python pipeline picks them up. WordPress only uses them for health checks.', 'mamboleo' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Ollama (local)', 'mamboleo' ); ?></h3>
            <table class="form-table" style="max-width:780px;">
                <tr>
                    <th scope="row"><label for="mamboleo_ollama_host"><?php esc_html_e( 'Host', 'mamboleo' ); ?></label></th>
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

            <h3><?php esc_html_e( 'OpenAI-compatible', 'mamboleo' ); ?></h3>
            <table class="form-table" style="max-width:780px;">
                <tr>
                    <th scope="row"><label for="mamboleo_openai_base_url"><?php esc_html_e( 'Base URL', 'mamboleo' ); ?></label></th>
                    <td>
                        <input type="url" id="mamboleo_openai_base_url" name="mamboleo_openai_base_url" value="<?php echo esc_attr( get_option( 'mamboleo_openai_base_url', 'https://api.openai.com/v1' ) ); ?>" class="regular-text" />
                        <p class="description">
                            Groq: <code>https://api.groq.com/openai/v1</code> ·
                            OpenAI: <code>https://api.openai.com/v1</code> ·
                            LM Studio: <code>http://localhost:1234/v1</code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mamboleo_openai_model"><?php esc_html_e( 'Model', 'mamboleo' ); ?></label></th>
                    <td>
                        <input type="text" id="mamboleo_openai_model" name="mamboleo_openai_model" value="<?php echo esc_attr( get_option( 'mamboleo_openai_model', 'gpt-4o-mini' ) ); ?>" class="regular-text" />
                        <p class="description">
                            <?php esc_html_e( 'e.g. gpt-4o-mini, llama-3.1-8b-instant (Groq), mistral-small-latest', 'mamboleo' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mamboleo_openai_api_key"><?php esc_html_e( 'API key', 'mamboleo' ); ?></label></th>
                    <td>
                        <?php $stored_key = (string) get_option( 'mamboleo_openai_api_key', '' ); ?>
                        <input type="password" id="mamboleo_openai_api_key" name="mamboleo_openai_api_key" value="<?php echo esc_attr( $stored_key ); ?>" class="regular-text" autocomplete="new-password" />
                        <?php if ( $stored_key ) : ?>
                            <p class="description"><?php esc_html_e( 'Stored. Replace to rotate, leave blank to clear.', 'mamboleo' ); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e( 'Required for cloud providers. Not needed for LM Studio / localhost.', 'mamboleo' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2 style="margin-top:24px;"><?php esc_html_e( 'Test connection', 'mamboleo' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Sends a real chat-completion request with the saved settings. Useful right after rotating an API key.', 'mamboleo' ); ?>
        </p>
        <?php $test = get_transient( 'mamboleo_test_llm_result' );
        if ( is_array( $test ) ) : delete_transient( 'mamboleo_test_llm_result' ); ?>
            <?php if ( $test['ok'] ) : ?>
                <div class="notice notice-success" style="max-width:780px;">
                    <p>
                        <b>✓ <?php esc_html_e( 'Live', 'mamboleo' ); ?></b>
                        — <?php echo (int) ( $test['ms'] ?? 0 ); ?> ms,
                        <?php esc_html_e( 'reply:', 'mamboleo' ); ?>
                        <code><?php echo esc_html( $test['reply'] ?? '' ); ?></code>
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-error" style="max-width:780px;">
                    <p><b>✗ <?php esc_html_e( 'Failed', 'mamboleo' ); ?></b> — <code><?php echo esc_html( $test['error'] ?? 'Unknown error' ); ?></code></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px;">
            <?php wp_nonce_field( 'mamboleo_test_llm' ); ?>
            <input type="hidden" name="action" value="mamboleo_test_llm" />
            <button class="button button-secondary"><?php esc_html_e( 'Run test now', 'mamboleo' ); ?></button>
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
