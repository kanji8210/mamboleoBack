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

// ── Data helpers ──────────────────────────────────────────────────────────────
/**
 * Map window key → cutoff timestamp.
 */
function mamboleo_mm_window_cutoff( string $window ): int {
    switch ( $window ) {
        case '1h':  return time() - 3600;
        case '7d':  return time() - 7 * DAY_IN_SECONDS;
        case '30d': return time() - 30 * DAY_IN_SECONDS;
        case '24h':
        default:    return time() - DAY_IN_SECONDS;
    }
}

/**
 * Per-source health: last-ingested timestamp + article count in given window.
 * Returns list of rows sorted by most-recently-seen descending.
 */
function mamboleo_mm_source_health( string $window ): array {
    global $wpdb;
    $cutoff_sql = gmdate( 'Y-m-d H:i:s', mamboleo_mm_window_cutoff( $window ) );

    // Join posts with their `source` meta; aggregate count + MAX(post_date_gmt) per source.
    $sql = $wpdb->prepare(
        "SELECT pm.meta_value AS source,
                COUNT(p.ID)     AS total,
                SUM(CASE WHEN p.post_date_gmt >= %s THEN 1 ELSE 0 END) AS in_window,
                MAX(p.post_date_gmt) AS last_seen
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm
                 ON pm.post_id = p.ID AND pm.meta_key = 'source'
         WHERE p.post_type   = 'article'
           AND p.post_status = 'publish'
         GROUP BY pm.meta_value
         ORDER BY last_seen DESC
         LIMIT 60",
        $cutoff_sql
    );
    $rows = $wpdb->get_results( $sql, ARRAY_A ) ?: [];

    $now = time();
    foreach ( $rows as &$r ) {
        $ts            = strtotime( $r['last_seen'] . ' UTC' ) ?: 0;
        $age_seconds   = max( 0, $now - $ts );
        $r['last_ts']  = $ts;
        $r['age_sec']  = $age_seconds;
        $r['status']   = $age_seconds < 7200   ? 'fresh'   // <2h
                       : ( $age_seconds < 86400 ? 'stale'  // <24h
                       : 'down' );                          // ≥24h
    }
    return $rows;
}

/**
 * Fetch recent article rows with optional source/topic filter.
 */
function mamboleo_mm_recent_articles( string $source = '', string $topic = '', int $limit = 25 ): array {
    $args = [
        'post_type'      => 'article',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [],
    ];
    if ( $source !== '' ) {
        $args['meta_query'][] = [ 'key' => 'source', 'value' => $source, 'compare' => '=' ];
    }
    if ( $topic !== '' ) {
        $args['meta_query'][] = [ 'key' => 'topics', 'value' => $topic, 'compare' => 'LIKE' ];
    }

    $q = new WP_Query( $args );
    $out = [];
    foreach ( $q->posts as $p ) {
        $out[] = [
            'id'        => $p->ID,
            'title'     => get_the_title( $p ),
            'permalink' => get_permalink( $p ),
            'source'    => (string) get_post_meta( $p->ID, 'source', true ),
            'sentiment' => (string) get_post_meta( $p->ID, 'sentiment', true ),
            'topics'    => (array)  get_post_meta( $p->ID, 'topics', true ),
            'tier'      => (int)    get_post_meta( $p->ID, 'source_tier', true ),
            'date_ts'   => get_post_time( 'U', true, $p ),
        ];
    }
    return $out;
}

// ── Page renderer ─────────────────────────────────────────────────────────────
function mamboleo_media_monitor_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Insufficient permissions.', 'mamboleo' ) );
    }

    $window = isset( $_GET['window'] ) ? sanitize_key( $_GET['window'] ) : '24h';
    if ( ! in_array( $window, [ '1h', '24h', '7d', '30d' ], true ) ) {
        $window = '24h';
    }

    $filter_source = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
    $filter_topic  = isset( $_GET['topic'] )  ? sanitize_text_field( wp_unslash( $_GET['topic'] ) )  : '';

    // Call the REST handler directly — avoids an HTTP round-trip and the
    // host's WAF/proxy chain while rendering admin UI.
    $req   = new WP_REST_Request( 'GET', '/mamboleo/v1/trends' );
    $req->set_param( 'window', $window );
    $trends = mamboleo_get_trends( $req );

    $source_health = mamboleo_mm_source_health( $window );
    $recent        = mamboleo_mm_recent_articles( $filter_source, $filter_topic, 25 );

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

        <!-- Filters (apply to Recent articles + Source health) -->
        <form method="get" style="display:flex;gap:10px;align-items:center;margin:0 0 16px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;flex-wrap:wrap;">
            <input type="hidden" name="page"   value="mamboleo-media-monitor">
            <input type="hidden" name="window" value="<?php echo esc_attr( $window ); ?>">
            <label style="font-weight:600;">Filter:</label>
            <select name="source" style="min-width:180px;">
                <option value="">All sources</option>
                <?php foreach ( $trends['by_source'] as $s ):
                    $name = (string) ( $s['name'] ?? '' );
                    if ( $name === '' ) continue; ?>
                    <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $filter_source, $name ); ?>>
                        <?php echo esc_html( $name ); ?> (<?php echo (int) $s['count']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="topic" style="min-width:160px;">
                <option value="">All topics</option>
                <?php foreach ( $trends['by_topic'] as $t ):
                    $name = (string) ( $t['name'] ?? '' );
                    if ( $name === '' ) continue; ?>
                    <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $filter_topic, $name ); ?>>
                        <?php echo esc_html( $name ); ?> (<?php echo (int) $t['count']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-primary">Apply</button>
            <?php if ( $filter_source || $filter_topic ): ?>
                <a class="button" href="<?php echo esc_url( add_query_arg( [ 'window' => $window, 'source' => false, 'topic' => false ] ) ); ?>">Clear</a>
            <?php endif; ?>
        </form>

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

        <!-- ── Source health ─────────────────────────────────────────────── -->
        <div style="background:#fff;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-top:20px;">
            <h2 style="margin-top:0;">Source health
                <span style="font-weight:400;font-size:13px;color:#666;">(last-ingested per source · <?php echo count( $source_health ); ?> tracked)</span>
            </h2>
            <?php if ( empty( $source_health ) ): ?>
                <p style="color:#999;">No sources tracked yet. Run the scraper to populate this panel.</p>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;">
                    <?php foreach ( $source_health as $s ):
                        $color = $s['status'] === 'fresh' ? '#22c55e' : ( $s['status'] === 'stale' ? '#eab308' : '#ef4444' );
                        $label = $s['status'] === 'fresh' ? 'FRESH'  : ( $s['status'] === 'stale' ? 'STALE'  : 'DOWN' );
                        $ago   = $s['last_ts'] ? human_time_diff( $s['last_ts'], time() ) . ' ago' : '—';
                        $link  = add_query_arg( [ 'window' => $window, 'source' => $s['source'], 'topic' => $filter_topic ?: false ] );
                    ?>
                        <a href="<?php echo esc_url( $link ); ?>" style="display:block;text-decoration:none;color:inherit;padding:10px 12px;background:#fafafa;border-left:4px solid <?php echo esc_attr( $color ); ?>;border-radius:2px;transition:background .15s;"
                           onmouseover="this.style.background='#f0f0f1'" onmouseout="this.style.background='#fafafa'">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                <strong style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $s['source'] ?: '(unknown)' ); ?></strong>
                                <span style="font-size:10px;font-weight:700;color:<?php echo esc_attr( $color ); ?>;letter-spacing:.05em;"><?php echo esc_html( $label ); ?></span>
                            </div>
                            <div style="font-size:12px;color:#666;margin-top:4px;">
                                <?php echo esc_html( $ago ); ?> · <?php echo (int) $s['in_window']; ?> in window · <?php echo (int) $s['total']; ?> total
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Recent articles ───────────────────────────────────────────── -->
        <div style="background:#fff;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-top:20px;">
            <h2 style="margin-top:0;">Recent articles
                <?php if ( $filter_source || $filter_topic ): ?>
                    <span style="font-weight:400;font-size:13px;color:#666;">
                        — filtered by
                        <?php if ( $filter_source ): ?><code><?php echo esc_html( $filter_source ); ?></code><?php endif; ?>
                        <?php if ( $filter_source && $filter_topic ): ?> + <?php endif; ?>
                        <?php if ( $filter_topic ): ?><code><?php echo esc_html( $filter_topic ); ?></code><?php endif; ?>
                    </span>
                <?php endif; ?>
            </h2>
            <?php if ( empty( $recent ) ): ?>
                <p style="color:#999;">No articles match this filter.</p>
            <?php else: ?>
                <table class="widefat striped" style="margin-top:8px;">
                    <thead>
                        <tr>
                            <th style="width:42%;">Title</th>
                            <th style="width:14%;">Source</th>
                            <th style="width:18%;">Topics</th>
                            <th style="width:10%;">Sentiment</th>
                            <th style="width:8%;">Tier</th>
                            <th style="width:8%;">When</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $recent as $a ):
                        $sent_color = $a['sentiment'] === 'positive' ? '#22c55e' : ( $a['sentiment'] === 'negative' ? '#ef4444' : '#94a3b8' );
                        $ago        = $a['date_ts'] ? human_time_diff( $a['date_ts'], time() ) . ' ago' : '—';
                        $edit_url   = get_edit_post_link( $a['id'] );
                    ?>
                        <tr>
                            <td>
                                <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $a['title'] ?: '(untitled)' ); ?></a></strong>
                                <?php if ( $a['permalink'] ): ?>
                                    <br><a href="<?php echo esc_url( $a['permalink'] ); ?>" target="_blank" rel="noopener" style="font-size:11px;color:#2271b1;">view ↗</a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( add_query_arg( [ 'window' => $window, 'source' => $a['source'], 'topic' => $filter_topic ?: false ] ) ); ?>">
                                    <?php echo esc_html( $a['source'] ?: '—' ); ?>
                                </a>
                            </td>
                            <td>
                                <?php foreach ( array_slice( $a['topics'], 0, 4 ) as $t ):
                                    $t_link = add_query_arg( [ 'window' => $window, 'topic' => $t, 'source' => $filter_source ?: false ] ); ?>
                                    <a href="<?php echo esc_url( $t_link ); ?>" style="display:inline-block;padding:1px 6px;margin:1px 2px 1px 0;background:#eef2ff;color:#4338ca;border-radius:3px;font-size:11px;text-decoration:none;"><?php echo esc_html( $t ); ?></a>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $sent_color ); ?>;margin-right:4px;vertical-align:middle;"></span>
                                <?php echo esc_html( $a['sentiment'] ?: '—' ); ?>
                            </td>
                            <td>T<?php echo (int) ( $a['tier'] ?: 0 ) ?: '—'; ?></td>
                            <td style="color:#666;font-size:12px;"><?php echo esc_html( $ago ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
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
