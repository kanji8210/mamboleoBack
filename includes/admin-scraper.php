<?php
/**
 * Mamboleo Admin: Manual Scraper Trigger
 * Adds a button to the WP admin to trigger backend scraping.
 */

// Register a top-level Mamboleo menu if not already present
add_action('admin_menu', function() {
    // Top-level menu
    if (!isset($GLOBALS['admin_page_hooks']['mamboleo-main'])) {
        add_menu_page(
            'Mamboleo',
            'Mamboleo',
            'manage_options',
            'mamboleo-main',
            '__return_null',
            'dashicons-shield-alt',
            80
        );
    }
    // Scraper submenu
    add_submenu_page(
        'mamboleo-main',
        'Scraper Trigger',
        'Scraper Trigger',
        'manage_options',
        'mamboleo-scraper',
        'mamboleo_scraper_admin_page',
        1
    );
});

// AJAX Handlers
add_action('wp_ajax_mamboleo_run_scraper', 'mamboleo_run_scraper_ajax');
add_action('wp_ajax_mamboleo_install_deps', 'mamboleo_install_deps_ajax');

function mamboleo_run_scraper_ajax() {
    check_ajax_referer('mamboleo_scraper_nonce', 'security');
    if (!current_user_can('manage_options')) wp_die('Forbidden');

    $scraper_dir = MAMBOLEO_PLUGIN_DIR . 'scraper';
    if (function_exists('set_time_limit')) @set_time_limit(300);

    $python_cmd = 'python3';
    $check_py3 = (string) shell_exec('python3 --version 2>&1');
    if (strpos($check_py3, 'Python 3') === false) $python_cmd = 'python';

    $command = 'cd ' . escapeshellarg($scraper_dir) . ' && ' . $python_cmd . ' run_all_scrapers.py 2>&1';
    $output = (string) shell_exec($command);
    
    wp_send_json_success(['output' => $output]);
}

function mamboleo_install_deps_ajax() {
    check_ajax_referer('mamboleo_scraper_nonce', 'security');
    if (!current_user_can('manage_options')) wp_die('Forbidden');

    $scraper_dir = MAMBOLEO_PLUGIN_DIR . 'scraper';
    $command = 'cd ' . escapeshellarg($scraper_dir) . ' && pip install -r requirements.txt 2>&1';
    $output = (string) shell_exec($command);
    
    wp_send_json_success(['output' => $output]);
}

function mamboleo_scraper_admin_page() {
    ?>
    <style>
        .mamboleo-admin-card { background: #fff; border: 1px solid #ccd0d4; padding: 20px; max-width: 800px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; }
        .mamboleo-terminal { background: #1e1e1e; color: #d4d4d4; font-family: 'Consolas', 'Monaco', monospace; padding: 15px; border-radius: 4px; height: 400px; overflow-y: auto; margin-top: 20px; line-height: 1.5; font-size: 13px; border: 1px solid #333; }
        .mamboleo-terminal-line { margin-bottom: 4px; }
        .mamboleo-status-bar { display: flex; align-items: center; margin-top: 15px; }
        .mamboleo-loader { border: 3px solid #f3f3f3; border-top: 3px solid #2271b1; border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; display: none; margin-right: 10px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .mamboleo-btn-group { display: flex; gap: 10px; margin-top: 10px; }
        .terminal-info { color: #569cd6; }
        .terminal-warning { color: #ce9178; }
        .terminal-error { color: #f44747; }
        .terminal-success { color: #6a9955; }
    </style>

    <div class="wrap">
        <h1>Mamboleo Scraper Trigger</h1>
        <div class="mamboleo-admin-card">
            <p>Control the backend Python scraper. Use "Install Dependencies" first if running on a new server.</p>
            
            <div class="mamboleo-btn-group">
                <button id="run-scraper-btn" class="button button-primary">Run Scraper Now</button>
                <button id="install-deps-btn" class="button button-secondary">Install Dependencies</button>
            </div>

            <div class="mamboleo-status-bar">
                <div id="mamboleo-loader" class="mamboleo-loader"></div>
                <span id="mamboleo-status-text">Ready.</span>
            </div>

            <div id="mamboleo-terminal" class="mamboleo-terminal">
                <div class="mamboleo-terminal-line">Waiting for command...</div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        const $terminal = $('#mamboleo-terminal');
        const $loader = $('#mamboleo-loader');
        const $status = $('#mamboleo-status-text');

        function logToTerminal(text, type = '') {
            const lines = text.split('\n');
            lines.forEach(line => {
                if (line.trim()) {
                    let className = '';
                    if (line.includes('INFO')) className = 'terminal-info';
                    if (line.includes('WARNING')) className = 'terminal-warning';
                    if (line.includes('ERROR') || line.includes('Traceback')) className = 'terminal-error';
                    if (line.includes('Done') || line.includes('success')) className = 'terminal-success';
                    
                    $terminal.append(`<div class="mamboleo-terminal-line ${className}">${line}</div>`);
                }
            });
            $terminal.scrollTop($terminal[0].scrollHeight);
        }

        function runAction(action, btnId) {
            const $btn = $(btnId);
            $btn.prop('disabled', true);
            $loader.show();
            $status.text('Processing... this may take a few minutes.');
            $terminal.html('<div class="mamboleo-terminal-line">--- Starting ' + action + ' ---</div>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mamboleo_' + action,
                    security: '<?php echo wp_create_nonce("mamboleo_scraper_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        logToTerminal(response.data.output);
                        $status.text('Completed.');
                    } else {
                        logToTerminal('Error: ' + response.data, 'error');
                        $status.text('Failed.');
                    }
                },
                error: function() {
                    logToTerminal('Critical Error: Request failed.', 'error');
                    $status.text('Server error.');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $loader.hide();
                }
            });
        }

        $('#run-scraper-btn').on('click', function() {
            runAction('run_scraper', '#run-scraper-btn');
        });

        $('#install-deps-btn').on('click', function() {
            runAction('install_deps', '#install-deps-btn');
        });
    });
    </script>
    <?php
}
