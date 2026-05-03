<?php
/**
 * Mamboleo Admin — Social Sources viewer.
 *
 * Read-only view of `scraper/social_sources.yaml`. Admins can see which
 * X/Facebook/YouTube handles are wired up and which are enabled, without
 * SSH'ing into the server. Editing happens in the YAML file by design —
 * we don't want a live form changing scraper config behind the scraper's back.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    if ( ! isset( $GLOBALS['admin_page_hooks']['mamboleo-main'] ) ) return;
    add_submenu_page(
        'mamboleo-main',
        'Social Sources',
        'Social Sources',
        'manage_options',
        'mamboleo-social',
        'mamboleo_social_admin_page',
        9
    );
}, 27 );

/**
 * Register social-platform credentials. These live in WP options so they
 * never have to be copied into scraper/.env. The Python scraper reads them
 * at startup via /wp-json/mamboleo/v1/llm-config (auth: X-API-Key).
 */
add_action( 'admin_init', function () {
    register_setting( 'mamboleo_social', 'mamboleo_twitter_bearer', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
        'show_in_rest'      => false,
    ] );
    register_setting( 'mamboleo_social', 'mamboleo_rsshub_host', [
        'type'              => 'string',
        'sanitize_callback' => function ( $v ) { return rtrim( esc_url_raw( (string) $v ), '/' ); },
        'default'           => '',
        'show_in_rest'      => false,
    ] );
} );

function mamboleo_social_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $yaml_path = MAMBOLEO_PLUGIN_DIR . 'scraper/social_sources.yaml';
    $entries   = mamboleo_parse_social_yaml( $yaml_path );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Social Sources', 'mamboleo' ); ?></h1>
        <p class="description">
            <?php esc_html_e( 'Tier-1/2/3 Kenyan social handles the scraper polls for breaking-news signal. Edit by editing scraper/social_sources.yaml — the change picks up on the next scraper run.', 'mamboleo' ); ?>
        </p>
        <p>
            <code><?php echo esc_html( $yaml_path ); ?></code>
        </p>

        <?php
        // ── Credential form (Twitter bearer + RSSHub host) ───────────────
        // Stored as WP options, served to the Python scraper via the
        // authenticated /wp-json/mamboleo/v1/llm-config endpoint, never
        // written to scraper/.env. Same pattern as the LLM API key.
        $twitter_bearer = (string) get_option( 'mamboleo_twitter_bearer', '' );
        $rsshub_host    = (string) get_option( 'mamboleo_rsshub_host', '' );
        $tw_masked      = $twitter_bearer
            ? substr( $twitter_bearer, 0, 6 ) . str_repeat( '•', max( 0, strlen( $twitter_bearer ) - 10 ) ) . substr( $twitter_bearer, -4 )
            : '';
        ?>
        <h2 style="margin-top:24px;"><?php esc_html_e( 'Platform credentials', 'mamboleo' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Stored in the WordPress database, fetched by the scraper over HTTPS. Leave blank to disable a platform.', 'mamboleo' ); ?>
        </p>
        <form method="post" action="options.php" style="background:#fff;border:1px solid #c3c4c7;padding:14px 18px;max-width:760px;">
            <?php settings_fields( 'mamboleo_social' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="mamboleo_twitter_bearer"><?php esc_html_e( 'X / Twitter Bearer Token', 'mamboleo' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="mamboleo_twitter_bearer"
                            name="mamboleo_twitter_bearer"
                            value="<?php echo esc_attr( $twitter_bearer ); ?>"
                            class="regular-text"
                            autocomplete="off"
                            placeholder="<?php echo esc_attr( $tw_masked ?: 'AAAAAAAAAAAAAAAAAAAAAA…' ); ?>"
                        />
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: link to X developer portal */
                                esc_html__( 'Free tier from %s — keep usage under 100 reads/month.', 'mamboleo' ),
                                '<a href="https://developer.x.com/en/portal/dashboard" target="_blank" rel="noopener">developer.x.com</a>'
                            );
                            ?>
                            <?php if ( $tw_masked ) : ?>
                                <br><strong><?php esc_html_e( 'Currently set:', 'mamboleo' ); ?></strong> <code><?php echo esc_html( $tw_masked ); ?></code>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mamboleo_rsshub_host"><?php esc_html_e( 'RSSHub Host (Facebook bridge)', 'mamboleo' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="url"
                            id="mamboleo_rsshub_host"
                            name="mamboleo_rsshub_host"
                            value="<?php echo esc_attr( $rsshub_host ); ?>"
                            class="regular-text"
                            placeholder="http://localhost:1200"
                        />
                        <p class="description">
                            <?php esc_html_e( 'Self-hosted RSSHub instance used to consume Facebook page feeds. Without it, fb_* entries below are skipped.', 'mamboleo' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save credentials', 'mamboleo' ) ); ?>
        </form>

        <h2 style="margin-top:24px;"><?php esc_html_e( 'Configured handles', 'mamboleo' ); ?></h2>

        <?php if ( ! $entries ) : ?>
            <div class="notice notice-warning"><p><?php esc_html_e( 'No social sources configured.', 'mamboleo' ); ?></p></div>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped" style="margin-top:14px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'mamboleo' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Platform', 'mamboleo' ); ?></th>
                    <th><?php esc_html_e( 'Handle', 'mamboleo' ); ?></th>
                    <th style="width:60px;"><?php esc_html_e( 'Tier', 'mamboleo' ); ?></th>
                    <th style="width:100px;"><?php esc_html_e( 'Cadence', 'mamboleo' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Per run', 'mamboleo' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Status', 'mamboleo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $entries as $e ) :
                    $enabled  = ! empty( $e['enabled'] );
                    $platform = strtolower( $e['platform'] ?? '—' );
                    $href     = mamboleo_social_handle_url( $platform, (string) ( $e['handle'] ?? '' ) );
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( $e['name'] ?? $e['id'] ?? '—' ); ?></strong></td>
                        <td><code><?php echo esc_html( $platform ); ?></code></td>
                        <td>
                            <?php if ( $href ) : ?>
                                <a href="<?php echo esc_url( $href ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $e['handle'] ?? '—' ); ?> ↗</a>
                            <?php else : ?>
                                <?php echo esc_html( $e['handle'] ?? '—' ); ?>
                            <?php endif; ?>
                        </td>
                        <td>T<?php echo (int) ( $e['tier'] ?? 0 ); ?></td>
                        <td><?php echo esc_html( $e['cadence'] ?? '—' ); ?></td>
                        <td><?php echo (int) ( $e['max_per_run'] ?? 0 ); ?></td>
                        <td>
                            <?php if ( $enabled ) : ?>
                                <span style="color:#00a32a;font-weight:600;">● Enabled</span>
                            <?php else : ?>
                                <span style="color:#646970;">● Disabled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2 style="margin-top:24px;"><?php esc_html_e( 'Notes', 'mamboleo' ); ?></h2>
        <ul style="list-style:disc;margin-left:18px;font-size:13px;">
            <li><?php esc_html_e( 'Twitter/X handles require TWITTER_BEARER_TOKEN in scraper/.env (free Basic tier is enough).', 'mamboleo' ); ?></li>
            <li><?php esc_html_e( 'Facebook pages require a self-hosted RSSHub bridge (set RSSHUB_HOST in .env). Without it, FB entries are silently skipped.', 'mamboleo' ); ?></li>
            <li><?php esc_html_e( 'YouTube channels use the public RSS feed — no auth required.', 'mamboleo' ); ?></li>
            <li><?php esc_html_e( 'Posts are processed by the same pipeline as articles: NLP enrichment → AI intelligence → location → optional incident creation.', 'mamboleo' ); ?></li>
        </ul>
    </div>
    <?php
}

/**
 * Minimal YAML reader — we only support the subset we emit, so we don't
 * pull in the Symfony YAML dependency just for this admin page.
 */
function mamboleo_parse_social_yaml( string $path ): array {
    if ( ! is_readable( $path ) ) return [];
    $lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( ! $lines ) return [];

    $entries = [];
    $cur     = null;
    foreach ( $lines as $line ) {
        if ( preg_match( '/^\s*#/', $line ) ) continue;
        if ( preg_match( '/^- /', $line ) ) {
            if ( $cur ) $entries[] = $cur;
            $cur = [];
            $line = preg_replace( '/^- /', '  ', $line );
        }
        if ( $cur !== null && preg_match( '/^\s+([a-z_]+):\s*(.*)$/i', $line, $m ) ) {
            $val = trim( $m[2] );
            if ( $val === '' ) continue;
            // Strip surrounding quotes
            if ( ( $val[0] ?? '' ) === '"' ) $val = trim( $val, '"' );
            if ( $val === 'true'  ) $val = true;
            elseif ( $val === 'false' ) $val = false;
            elseif ( ctype_digit( $val ) ) $val = (int) $val;
            $cur[ $m[1] ] = $val;
        }
    }
    if ( $cur ) $entries[] = $cur;
    return $entries;
}

function mamboleo_social_handle_url( string $platform, string $handle ): string {
    if ( $handle === '' ) return '';
    return match ( $platform ) {
        'twitter', 'x' => 'https://twitter.com/' . rawurlencode( $handle ),
        'facebook'     => ctype_digit( $handle )
            ? 'https://www.facebook.com/profile.php?id=' . $handle
            : 'https://www.facebook.com/' . rawurlencode( $handle ),
        'youtube'      => 'https://www.youtube.com/channel/' . rawurlencode( $handle ),
        default        => '',
    };
}
