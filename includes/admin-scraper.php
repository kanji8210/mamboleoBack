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
add_action('wp_ajax_mamboleo_poll_scraper', 'mamboleo_poll_scraper_ajax');
add_action('wp_ajax_mamboleo_install_deps', 'mamboleo_install_deps_ajax');

function mamboleo_get_log_path() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['basedir'] . '/mamboleo_scraper.log';
}

function mamboleo_run_scraper_ajax() {
    check_ajax_referer('mamboleo_scraper_nonce', 'security');
    if (!current_user_can('manage_options')) wp_die('Forbidden');

    $scraper_dir = MAMBOLEO_PLUGIN_DIR . 'scraper';
    $log_file = mamboleo_get_log_path();
    
    // Clear old log
    file_put_contents($log_file, "--- Initialization ---\n");

    // Pre-determine python command (fast)
    $python_cmd = 'python3';
    // We'll skip the version check here to keep the response fast, 
    // or just assume python3/python based on common setups.
    
    // Build a fully detached background command
    // nohup keeps it running after the shell closes
    // </dev/null prevents it from waiting for input
    $command = sprintf(
        'nohup sh -c "cd %s && %s run_all_scrapers.py" > %s 2>&1 </dev/null &',
        escapeshellarg($scraper_dir),
        $python_cmd,
        escapeshellarg($log_file)
    );
    
    exec($command);
    
    // Give it a tiny bit of time to start and write the first line
    usleep(200000); 
    
    wp_send_json_success(['message' => 'Scraper started in background.']);
}

function mamboleo_poll_scraper_ajax() {
    check_ajax_referer('mamboleo_scraper_nonce', 'security');
    if (!current_user_can('manage_options')) wp_die('Forbidden');

    $log_file = mamboleo_get_log_path();
    if (!file_exists($log_file)) {
        wp_send_json_success(['output' => 'Waiting for log...']);
        return;
    }

    $output = file_get_contents($log_file);
    $is_done = (strpos($output, 'Done') !== false || strpos($output, 'Traceback') !== false);
    
    wp_send_json_success([
        'output' => $output,
        'done'   => $is_done
    ]);
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
        .mamboleo-terminal { background: #1e1e1e; color: #d4d4d4; font-family: 'Consolas', 'Monaco', monospace; padding: 15px; border-radius: 4px; height: 450px; overflow-y: auto; margin-top: 20px; line-height: 1.5; font-size: 13px; border: 1px solid #333; }
        .mamboleo-terminal-line { margin-bottom: 2px; border-left: 2px solid transparent; padding-left: 8px; }
        .mamboleo-status-bar { display: flex; align-items: center; margin-top: 15px; }
        .mamboleo-loader { border: 3px solid #f3f3f3; border-top: 3px solid #2271b1; border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; display: none; margin-right: 10px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .mamboleo-btn-group { display: flex; gap: 10px; margin-top: 10px; }
        .terminal-info { color: #569cd6; border-left-color: #569cd6; }
        .terminal-warning { color: #ce9178; border-left-color: #ce9178; }
        .terminal-error { color: #f44747; border-left-color: #f44747; background: rgba(244, 71, 71, 0.1); }
        .terminal-success { color: #6a9955; border-left-color: #6a9955; }
    </style>

    <div class="wrap">
        <h1>Mamboleo Scraper Control</h1>
        <div class="mamboleo-admin-card">
            <p><strong>Note:</strong> The scraper now runs in a detached background process to prevent Cloudflare timeouts (Error 524).</p>
            
            <div class="mamboleo-btn-group">
                <button id="run-scraper-btn" class="button button-primary">Start Scraper</button>
                <button id="install-deps-btn" class="button button-secondary">Install Dependencies</button>
            </div>

            <div class="mamboleo-status-bar">
                <div id="mamboleo-loader" class="mamboleo-loader"></div>
                <span id="mamboleo-status-text">System Ready.</span>
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
        let pollInterval = null;
        let lastFullText = "";

        function updateTerminal(fullText) {
            if (fullText === lastFullText) return;
            lastFullText = fullText;

            $terminal.empty();
            const lines = fullText.split('\n');
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

        function startPolling() {
            if (pollInterval) clearInterval(pollInterval);
            pollInterval = setInterval(function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mamboleo_poll_scraper',
                        security: '<?php echo wp_create_nonce("mamboleo_scraper_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            updateTerminal(response.data.output);
                            if (response.data.done) {
                                stopPolling('Completed.');
                            }
                        }
                    }
                });
            }, 2000);
        }

        function stopPolling(statusText) {
            clearInterval(pollInterval);
            pollInterval = null;
            $loader.hide();
            $('#run-scraper-btn').prop('disabled', false);
            $status.text(statusText);
        }

        $('#run-scraper-btn').on('click', function() {
            const $btn = $(this);
            $btn.prop('disabled', true);
            $loader.show();
            $status.text('Scraper starting in background...');
            $terminal.html('<div class="mamboleo-terminal-line terminal-info">Initializing background process...</div>');
            lastFullText = "";

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mamboleo_run_scraper',
                    security: '<?php echo wp_create_nonce("mamboleo_scraper_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $status.text('Scraper running... (Cloudflare safe)');
                        startPolling();
                    } else {
                        stopPolling('Failed to start.');
                    }
                },
                error: function() {
                    stopPolling('Initial request failed.');
                }
            });
        });

        $('#install-deps-btn').on('click', function() {
            const $btn = $(this);
            $btn.prop('disabled', true);
            $loader.show();
            $status.text('Installing dependencies (blocking)...');
            $terminal.html('<div class="mamboleo-terminal-line">--- Installing Dependencies ---</div>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mamboleo_install_deps',
                    security: '<?php echo wp_create_nonce("mamboleo_scraper_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        updateTerminal(response.data.output);
                        $status.text('Dependencies updated.');
                    } else {
                        $status.text('Installation failed.');
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $loader.hide();
                }
            });
        });
    });
    </script>
    <?php
}
