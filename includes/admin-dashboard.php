<?php
/**
 * Mamboleo Admin Dashboard — single source of truth for the parent menu.
 *
 * Centralises the top-level "Mamboleo" menu so submenus from other files
 * (Scraper, Review, Media Monitor, AI, Updates, Expiring) all attach in
 * a predictable order via `priority` arguments.
 *
 * The Dashboard page itself shows a one-glance operational view:
 *   • Pending review queue size
 *   • Pending community updates
 *   • Incidents expiring within 24h
 *   • AI intelligence health (Ollama reachable?, % incidents analysed)
 *   • Last lifecycle / expiry / scraper cron runs
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* Register the parent menu with priority=1 so it always exists first. */
add_action( 'admin_menu', function () {
    if ( isset( $GLOBALS['admin_page_hooks']['mamboleo-main'] ) ) return;
    add_menu_page(
        'Mamboleo',
        'Mamboleo',
        'manage_options',
        'mamboleo-main',
        'mamboleo_dashboard_page',
        'dashicons-shield-alt',
        80
    );
    // Rename the auto-duplicated first submenu to "Dashboard".
    add_submenu_page(
        'mamboleo-main',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'mamboleo-main',
        'mamboleo_dashboard_page',
        0
    );
}, 1 );

function mamboleo_dashboard_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $pending_review  = function_exists( 'mamboleo_pending_review_count' ) ? mamboleo_pending_review_count() : 0;
    $pending_updates = (int) ( new WP_Query( [
        'post_type'      => MAMBOLEO_UPDATE_CPT,
        'post_status'    => 'pending',
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] ) )->found_posts;
    $expiring        = count( function_exists( 'mamboleo_get_expiring_incidents' ) ? mamboleo_get_expiring_incidents( 24, 100 ) : [] );

    $total_incidents = wp_count_posts( 'incident' )->publish ?? 0;
    $analysed        = (int) ( new WP_Query( [
        'post_type'      => 'incident',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => 'ai_model', 'compare' => 'EXISTS' ], [ 'key' => 'ai_model', 'value' => '', 'compare' => '!=' ] ],
    ] ) )->found_posts;
    $ai_pct          = $total_incidents > 0 ? round( ( $analysed / $total_incidents ) * 100 ) : 0;

    $lifecycle_run = get_option( 'mamboleo_lifecycle_last_run', null );
    $expiry_run    = get_option( 'mamboleo_expiry_last_run', null );
    $llm_provider  = get_option( 'mamboleo_llm_provider', 'ollama' );
    $llm_endpoint  = $llm_provider === 'openai'
        ? get_option( 'mamboleo_openai_base_url', 'https://api.openai.com/v1' )
        : get_option( 'mamboleo_ollama_host', 'http://localhost:11434' );

    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Mamboleo Operations', 'mamboleo' ); ?></h1>
        <p class="description">
            <?php esc_html_e( 'Single-pane view of the situational platform: moderation, lifecycle, AI intelligence and ingestion.', 'mamboleo' ); ?>
        </p>

        <style>
            .mb-card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin:18px 0 28px; }
            .mb-card { background:#fff; border:1px solid #dcdcde; border-left:4px solid #2271b1; border-radius:4px; padding:14px 16px; }
            .mb-card.warn { border-left-color:#dba617; }
            .mb-card.crit { border-left-color:#d63638; }
            .mb-card .num { font-size:28px; font-weight:700; line-height:1; }
            .mb-card .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#646970; margin-top:4px; }
            .mb-card a { display:inline-block; margin-top:10px; font-size:12px; }
            .mb-row { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
            .mb-panel { background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:16px; }
            .mb-panel h2 { margin-top:0; font-size:14px; }
            .mb-kv { font-size:13px; color:#3c434a; }
            .mb-kv b { display:inline-block; min-width:140px; color:#1d2327; }
        </style>

        <div class="mb-card-grid">
            <div class="mb-card <?php echo $pending_review > 0 ? 'warn' : ''; ?>">
                <div class="num"><?php echo esc_html( $pending_review ); ?></div>
                <div class="lbl"><?php esc_html_e( 'Pending Review', 'mamboleo' ); ?></div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mamboleo-review-queue' ) ); ?>"><?php esc_html_e( 'Open queue →', 'mamboleo' ); ?></a>
            </div>
            <div class="mb-card <?php echo $pending_updates > 0 ? 'warn' : ''; ?>">
                <div class="num"><?php echo esc_html( $pending_updates ); ?></div>
                <div class="lbl"><?php esc_html_e( 'Community Updates', 'mamboleo' ); ?></div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mamboleo-updates' ) ); ?>"><?php esc_html_e( 'Moderate →', 'mamboleo' ); ?></a>
            </div>
            <div class="mb-card <?php echo $expiring > 0 ? 'crit' : ''; ?>">
                <div class="num"><?php echo esc_html( $expiring ); ?></div>
                <div class="lbl"><?php esc_html_e( 'Expiring Soon (24h)', 'mamboleo' ); ?></div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mamboleo-expiring' ) ); ?>"><?php esc_html_e( 'Extend lifetime →', 'mamboleo' ); ?></a>
            </div>
            <div class="mb-card">
                <div class="num"><?php echo esc_html( $ai_pct ); ?>%</div>
                <div class="lbl"><?php esc_html_e( 'AI Coverage', 'mamboleo' ); ?></div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mamboleo-ai' ) ); ?>"><?php esc_html_e( 'AI Intelligence →', 'mamboleo' ); ?></a>
            </div>
        </div>

        <div class="mb-row">
            <div class="mb-panel">
                <h2><?php esc_html_e( 'Lifecycle automation', 'mamboleo' ); ?></h2>
                <p class="mb-kv"><b><?php esc_html_e( 'Active retention', 'mamboleo' ); ?>:</b> 7 days from last update</p>
                <p class="mb-kv"><b><?php esc_html_e( 'Auto-trash after', 'mamboleo' ); ?>:</b> <?php echo (int) MAMBOLEO_EXPIRY_DAYS; ?> days idle</p>
                <p class="mb-kv"><b><?php esc_html_e( 'Last lifecycle run', 'mamboleo' ); ?>:</b>
                    <?php echo $lifecycle_run ? esc_html( human_time_diff( strtotime( $lifecycle_run['ts'] ), time() ) . ' ago' ) : '—'; ?>
                </p>
                <p class="mb-kv"><b><?php esc_html_e( 'Last expiry run', 'mamboleo' ); ?>:</b>
                    <?php echo $expiry_run ? esc_html( human_time_diff( strtotime( $expiry_run['ts'] ), time() ) . ' ago' ) : '—'; ?>
                    <?php if ( $expiry_run ) : ?>
                        <span style="color:#646970;">(<?php echo (int) $expiry_run['expired']; ?> trashed, <?php echo (int) $expiry_run['warned']; ?> warned)</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="mb-panel">
                <h2><?php esc_html_e( 'AI Intelligence layer', 'mamboleo' ); ?></h2>
                <p class="mb-kv"><b><?php esc_html_e( 'Provider', 'mamboleo' ); ?>:</b> <code><?php echo esc_html( $llm_provider ); ?></code></p>
                <p class="mb-kv"><b><?php esc_html_e( 'Endpoint', 'mamboleo' ); ?>:</b> <code><?php echo esc_html( $llm_endpoint ); ?></code></p>
                <p class="mb-kv"><b><?php esc_html_e( 'Incidents analysed', 'mamboleo' ); ?>:</b>
                    <?php printf( '%d / %d', (int) $analysed, (int) $total_incidents ); ?>
                </p>
                <p class="mb-kv"><b><?php esc_html_e( 'Backfill', 'mamboleo' ); ?>:</b>
                    <code>python scraper/backfill_ai.py</code>
                </p>
                <p style="margin-top:14px;">
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mamboleo-ai' ) ); ?>">
                        <?php esc_html_e( 'Manage intelligence →', 'mamboleo' ); ?>
                    </a>
                </p>
            </div>
        </div>
    </div>
    <?php
}
