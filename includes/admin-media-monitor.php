<?php
/**
 * Mamboleo Admin — Media Monitor Dashboard
 *
 * Visual overview of ingested articles from 40+ Kenyan + international
 * outlets. Widgets:
 *   - Articles over time (14-day line chart)
 *   - Top sources by volume (horizontal bar)
 *   - Sentiment distribution (pie)
 *   - Source tier breakdown (pie)
 *   - Trending topics + entities (ranked lists)
 *
 * All data is pulled from the public /wp-json/mamboleo/v1/trends endpoint
 * (same cache as the React frontend — keeps numbers consistent).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Menu ──────────────────────────────────────────────────────────────────────
add_action( 'admin_menu', function () {
    $parent = isset( $GLOBALS['admin_page_hooks']['mamboleo-main'] ) ? 'mamboleo-main' : null;
    if ( ! $parent ) {
        add_menu_page( 'Mamboleo', 'Mamboleo', 'manage_options', 'mamboleo-main', '__return_null', 'dashicons-shield-alt', 80 );
        $parent = 'mamboleo-main';
    }

    add_submenu_page(
        $parent,
        'Media Monitor',
        'Media Monitor',
        'manage_options',
        'mamboleo-media-monitor',
        'mamboleo_media_monitor_page',
        3
    );
}, 25 );

// ── Assets (Chart.js via CDN + small inline script) ───────────────────────────
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( strpos( $hook, 'mamboleo-media-monitor' ) === false ) {
        return;
    }
    wp_enqueue_script(
        'mamboleo-chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
        [],
        '4.4.1',
        true
    );
} );

// ── Page renderer ─────────────────────────────────────────────────────────────
function mamboleo_media_monitor_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Insufficient permissions.', 'mamboleo' ) );
    }

    $window = isset( $_GET['window'] ) ? sanitize_key( $_GET['window'] ) : '24h';
    if ( ! in_array( $window, [ '1h', '24h', '7d', '30d' ], true ) ) {
        $window = '24h';
    }

    // Call the REST handler directly — avoids an HTTP round-trip and the
    // host's WAF/proxy chain while rendering admin UI.
    $req   = new WP_REST_Request( 'GET', '/mamboleo/v1/trends' );
    $req->set_param( 'window', $window );
    $trends = mamboleo_get_trends( $req );

    ?>
    <div class="wrap">
        <h1>Media Monitor <span class="title-count">(<?php echo (int) $trends['total']; ?> articles)</span></h1>

        <p class="description">
            Automated ingestion from 40+ Kenyan + international outlets via RSS feeds and web scraping.
            Data refreshes every 5 minutes.
        </p>

        <!-- Window switcher -->
        <ul class="subsubsub" style="margin: 10px 0 20px;">
            <?php foreach ( [ '1h' => 'Last hour', '24h' => 'Last 24h', '7d' => 'Last 7 days', '30d' => 'Last 30 days' ] as $key => $label ): ?>
                <li>
                    <a href="<?php echo esc_url( add_query_arg( 'window', $key ) ); ?>"
                       class="<?php echo $window === $key ? 'current' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                    <?php if ( $key !== '30d' ): ?>|<?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <!-- Stat strip -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 24px;">
            <?php
            $pos = (int) ( $trends['by_sentiment']['positive'] ?? 0 );
            $neu = (int) ( $trends['by_sentiment']['neutral']  ?? 0 );
            $neg = (int) ( $trends['by_sentiment']['negative'] ?? 0 );
            $stat_card = function ( $label, $value, $color ) {
                printf(
                    '<div style="background:#fff;padding:16px;border-left:4px solid %s;box-shadow:0 1px 2px rgba(0,0,0,.04);">
                        <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.05em;">%s</div>
                        <div style="font-size:28px;font-weight:600;margin-top:4px;">%s</div>
                    </div>',
                    esc_attr( $color ), esc_html( $label ), esc_html( $value )
                );
            };
            $stat_card( 'Total articles',     number_format_i18n( $trends['total'] ), '#2271b1' );
            $stat_card( 'Positive',           number_format_i18n( $pos ),             '#22c55e' );
            $stat_card( 'Neutral',            number_format_i18n( $neu ),             '#94a3b8' );
            $stat_card( 'Negative',           number_format_i18n( $neg ),             '#ef4444' );
            $stat_card( 'Unique sources',     number_format_i18n( count( $trends['by_source'] ) ), '#9333ea' );
            ?>
        </div>

        <!-- Charts grid -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div style="background:#fff;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04);">
                <h2 style="margin-top:0;">Articles over time</h2>
                <canvas id="mm-timeline" height="80"></canvas>
            </div>
            <div style="background:#fff;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04);">
                <h2 style="margin-top:0;">Sentiment</h2>
                <canvas id="mm-sentiment" height="180"></canvas>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div style="background:#fff;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04);">
                <h2 style="margin-top:0;">Top sources</h2>
                <canvas id="mm-sources" height="140"></canvas>
            </div>
            <div style="background:#fff;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04);">
                <h2 style="margin-top:0;">Source tier</h2>
                <canvas id="mm-tier" height="180"></canvas>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div style="background:#fff;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04);">
                <h2 style="margin-top:0;">Trending topics</h2>
                <canvas id="mm-topics" height="180"></canvas>
            </div>
            <div style="background:#fff;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04);">
                <h2 style="margin-top:0;">Top entities</h2>
                <?php
                $render_list = function ( $title, $rows ) {
                    echo '<h4 style="margin:8px 0 4px;color:#555;">' . esc_html( $title ) . '</h4>';
                    if ( empty( $rows ) ) {
                        echo '<p style="color:#999;margin:0 0 12px;">No data yet.</p>';
                        return;
                    }
                    echo '<ol style="margin:0 0 12px 20px;padding:0;font-size:13px;">';
                    foreach ( array_slice( $rows, 0, 8 ) as $r ) {
                        printf(
                            '<li><strong>%s</strong> <span style="color:#888;">(%d)</span></li>',
                            esc_html( $r['name'] ), (int) $r['count']
                        );
                    }
                    echo '</ol>';
                };
                $render_list( 'Persons', $trends['top_entities']['persons'] ?? [] );
                $render_list( 'Organisations', $trends['top_entities']['orgs'] ?? [] );
                $render_list( 'Places', $trends['top_entities']['places'] ?? [] );
                ?>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const data = <?php echo wp_json_encode( $trends ); ?>;
        const palette = ['#2271b1','#3b82f6','#60a5fa','#9333ea','#c084fc','#e879f9','#f472b6','#fb7185','#f97316','#eab308','#84cc16','#22c55e','#10b981','#14b8a6','#06b6d4'];

        function render() {
            if (typeof Chart === 'undefined') { setTimeout(render, 100); return; }

            // Timeline line chart
            const tl = document.getElementById('mm-timeline');
            if (tl && data.timeline && data.timeline.length) {
                new Chart(tl, {
                    type: 'line',
                    data: {
                        labels: data.timeline.map(d => d.date),
                        datasets: [{
                            label: 'Articles',
                            data: data.timeline.map(d => d.count),
                            borderColor: '#2271b1',
                            backgroundColor: 'rgba(34,113,177,0.15)',
                            fill: true,
                            tension: 0.3,
                        }]
                    },
                    options: { plugins: { legend: { display: false } }, responsive: true, maintainAspectRatio: false }
                });
            }

            // Sentiment pie
            new Chart(document.getElementById('mm-sentiment'), {
                type: 'doughnut',
                data: {
                    labels: ['Positive', 'Neutral', 'Negative'],
                    datasets: [{
                        data: [data.by_sentiment.positive, data.by_sentiment.neutral, data.by_sentiment.negative],
                        backgroundColor: ['#22c55e', '#94a3b8', '#ef4444']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });

            // Top sources horizontal bar
            const srcTop = data.by_source.slice(0, 12);
            new Chart(document.getElementById('mm-sources'), {
                type: 'bar',
                data: {
                    labels: srcTop.map(s => s.name),
                    datasets: [{
                        label: 'Articles',
                        data: srcTop.map(s => s.count),
                        backgroundColor: palette
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } }
                }
            });

            // Tier pie
            new Chart(document.getElementById('mm-tier'), {
                type: 'pie',
                data: {
                    labels: ['T1 Official', 'T2 Mainstream', 'T3 Digital', 'T4 Regional', 'T5 International'],
                    datasets: [{
                        data: [data.by_tier[1], data.by_tier[2], data.by_tier[3], data.by_tier[4], data.by_tier[5]],
                        backgroundColor: ['#0ea5e9', '#2271b1', '#6366f1', '#a855f7', '#ec4899']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });

            // Topics horizontal bar
            new Chart(document.getElementById('mm-topics'), {
                type: 'bar',
                data: {
                    labels: data.by_topic.map(t => t.name),
                    datasets: [{
                        label: 'Mentions',
                        data: data.by_topic.map(t => t.count),
                        backgroundColor: '#6366f1'
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } }
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', render);
        } else {
            render();
        }
    })();
    </script>
    <?php
}
