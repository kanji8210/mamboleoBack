<?php
/**
 * Mamboleo Admin — Reset / Purge tool.
 *
 * Lets an administrator wipe content types so the operator can start fresh.
 * Destructive by design — gated behind:
 *   • manage_options capability
 *   • a nonce
 *   • typed confirmation ("RESET")
 *   • per-type checkboxes (no "delete everything" footgun)
 *
 * Optionally also clears the scraper's local seen.db so previously-ingested
 * URLs are eligible for re-scraping. Without that, the scraper will skip
 * anything it's seen before, even after a WP-side wipe.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    if ( ! isset( $GLOBALS['admin_page_hooks']['mamboleo-main'] ) ) return;
    add_submenu_page(
        'mamboleo-main',
        'Reset / Purge',
        'Reset / Purge',
        'manage_options',
        'mamboleo-reset',
        'mamboleo_reset_admin_page',
        99
    );
}, 30 );

const MAMBOLEO_RESET_TYPES = [
    'incident'        => 'Incidents',
    'incident_update' => 'Incident updates (community)',
    'article'         => 'Articles (Media Monitor)',
    'social_post'     => 'Social posts',
];

add_action( 'admin_post_mamboleo_reset', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    check_admin_referer( 'mamboleo_reset' );

    $confirm = sanitize_text_field( wp_unslash( $_POST['confirm'] ?? '' ) );
    if ( $confirm !== 'RESET' ) {
        wp_safe_redirect( add_query_arg( 'mb_reset', 'bad-confirm', admin_url( 'admin.php?page=mamboleo-reset' ) ) );
        exit;
    }

    $selected = array_map( 'sanitize_key', (array) ( $_POST['types'] ?? [] ) );
    $force    = ! empty( $_POST['force'] ); // hard delete vs trash
    $clear_seen = ! empty( $_POST['clear_seen'] );

    $stats = [];
    foreach ( $selected as $type ) {
        if ( ! isset( MAMBOLEO_RESET_TYPES[ $type ] ) ) continue;
        $stats[ $type ] = mamboleo_reset_purge_type( $type, $force );
    }

    $seen_msg = '';
    if ( $clear_seen ) {
        $seen_msg = mamboleo_reset_clear_seen_db();
    }

    set_transient( 'mamboleo_reset_result', [
        'stats'     => $stats,
        'force'     => $force,
        'seen_msg'  => $seen_msg,
        'when'      => current_time( 'mysql' ),
        'user'      => wp_get_current_user()->user_login,
    ], 5 * MINUTE_IN_SECONDS );

    wp_safe_redirect( admin_url( 'admin.php?page=mamboleo-reset' ) );
    exit;
} );

/**
 * Delete every post of `$type` in batches of 200 to avoid OOM on large sites.
 * Returns count of deleted posts.
 */
function mamboleo_reset_purge_type( string $type, bool $force ): int {
    $deleted = 0;
    while ( true ) {
        $ids = get_posts( [
            'post_type'        => $type,
            'post_status'      => 'any',
            'numberposts'      => 200,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ] );
        if ( ! $ids ) break;
        foreach ( $ids as $id ) {
            if ( wp_delete_post( $id, $force ) ) $deleted++;
        }
    }
    // Also bust the AI/health caches that count by post type.
    delete_transient( 'mamboleo_ollama_health' );
    return $deleted;
}

function mamboleo_reset_clear_seen_db(): string {
    if ( ! defined( 'MAMBOLEO_PLUGIN_DIR' ) ) return 'plugin dir constant missing';
    $path = MAMBOLEO_PLUGIN_DIR . 'scraper/data/seen.db';
    if ( ! file_exists( $path ) ) return 'no seen.db found (already clean)';
    if ( ! is_writable( $path ) ) return 'seen.db not writable by web user';
    return @unlink( $path ) ? 'seen.db deleted — scraper will re-ingest from scratch' : 'failed to delete seen.db';
}

function mamboleo_reset_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $bad     = ( $_GET['mb_reset'] ?? '' ) === 'bad-confirm';
    $result  = get_transient( 'mamboleo_reset_result' );
    if ( $result ) delete_transient( 'mamboleo_reset_result' );

    // Live counts so the operator sees what they're about to delete.
    $counts = [];
    foreach ( MAMBOLEO_RESET_TYPES as $type => $label ) {
        $c = wp_count_posts( $type );
        $counts[ $type ] = (int) ( ( $c->publish ?? 0 ) + ( $c->draft ?? 0 ) + ( $c->pending ?? 0 ) + ( $c->trash ?? 0 ) );
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Reset / Purge', 'mamboleo' ); ?></h1>
        <p class="description">
            <?php esc_html_e( 'Wipe Mamboleo content so you can start with a clean slate. This deletes posts, all their meta and taxonomy assignments. Authors, plugin settings and AI provider configuration are NOT touched.', 'mamboleo' ); ?>
        </p>

        <?php if ( $bad ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Confirmation text did not match — nothing was deleted. Type RESET (uppercase) exactly.', 'mamboleo' ); ?></p></div>
        <?php endif; ?>

        <?php if ( is_array( $result ) ) :
            $total = array_sum( $result['stats'] ); ?>
            <div class="notice notice-<?php echo $total > 0 ? 'success' : 'warning'; ?>">
                <p>
                    <b><?php printf( esc_html__( 'Reset completed by %s at %s', 'mamboleo' ), esc_html( $result['user'] ), esc_html( $result['when'] ) ); ?>.</b>
                    <?php echo $result['force'] ? '<code>force=true</code>' : '<code>moved to trash</code>'; ?>
                </p>
                <ul style="margin-left:18px;list-style:disc;">
                    <?php foreach ( $result['stats'] as $type => $n ) : ?>
                        <li><?php echo esc_html( MAMBOLEO_RESET_TYPES[ $type ] ?? $type ); ?>: <b><?php echo (int) $n; ?></b> deleted</li>
                    <?php endforeach; ?>
                    <?php if ( $result['seen_msg'] ) : ?>
                        <li>seen.db: <?php echo esc_html( $result['seen_msg'] ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:18px;max-width:780px;margin-top:14px;">
            <?php wp_nonce_field( 'mamboleo_reset' ); ?>
            <input type="hidden" name="action" value="mamboleo_reset" />

            <h2 style="margin-top:0;"><?php esc_html_e( 'Select what to wipe', 'mamboleo' ); ?></h2>
            <fieldset style="margin-bottom:16px;">
                <?php foreach ( MAMBOLEO_RESET_TYPES as $type => $label ) : ?>
                    <label style="display:block;margin:6px 0;">
                        <input type="checkbox" name="types[]" value="<?php echo esc_attr( $type ); ?>" />
                        <?php echo esc_html( $label ); ?>
                        <span style="color:#646970;">(<?php echo (int) $counts[ $type ]; ?> records)</span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <h2><?php esc_html_e( 'Options', 'mamboleo' ); ?></h2>
            <label style="display:block;margin:6px 0;">
                <input type="checkbox" name="force" value="1" checked />
                <?php esc_html_e( 'Hard delete (skip Trash). Leave checked unless you want to undo via Trash.', 'mamboleo' ); ?>
            </label>
            <label style="display:block;margin:6px 0;">
                <input type="checkbox" name="clear_seen" value="1" checked />
                <?php esc_html_e( 'Also clear scraper/data/seen.db so the scraper re-ingests previously seen URLs. Recommended if you want a true fresh start.', 'mamboleo' ); ?>
            </label>

            <h2><?php esc_html_e( 'Confirm', 'mamboleo' ); ?></h2>
            <p>
                <?php esc_html_e( 'Type', 'mamboleo' ); ?>
                <code>RESET</code>
                <?php esc_html_e( 'to enable the button:', 'mamboleo' ); ?>
            </p>
            <input type="text" name="confirm" autocomplete="off" placeholder="RESET" required style="font-family:monospace;letter-spacing:.1em;width:160px;" />

            <p style="margin-top:18px;">
                <button type="submit" class="button button-primary" style="background:#d63638;border-color:#b32d2e;">
                    <?php esc_html_e( 'Wipe selected content', 'mamboleo' ); ?>
                </button>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mamboleo-main' ) ); ?>"><?php esc_html_e( 'Cancel', 'mamboleo' ); ?></a>
            </p>
        </form>

        <p style="color:#646970;font-size:12px;margin-top:14px;">
            <?php esc_html_e( 'After running a reset, restart any running scraper processes and click "Run test now" on the AI Intelligence page to verify everything is healthy.', 'mamboleo' ); ?>
        </p>
    </div>
    <?php
}
