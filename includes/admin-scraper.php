<?php
/**
 * Mamboleo Admin: Manual Scraper Trigger
 * Adds a button to the WP admin to trigger backend scraping.
 */

// Scraper submenu — parent menu is registered by admin-dashboard.php.
add_action('admin_menu', function() {
    if (!isset($GLOBALS['admin_page_hooks']['mamboleo-main'])) return;
    add_submenu_page(
        'mamboleo-main',
        'Scraper Trigger',
        'Scraper Trigger',
        'manage_options',
        'mamboleo-scraper',
        'mamboleo_scraper_admin_page',
        6
    );
}, 30);

// AJAX Handlers
add_action('wp_ajax_mamboleo_run_scraper', 'mamboleo_run_scraper_ajax');
add_action('wp_ajax_mamboleo_poll_scraper', 'mamboleo_poll_scraper_ajax');
add_action('wp_ajax_mamboleo_install_deps', 'mamboleo_install_deps_ajax');
add_action('wp_ajax_mamboleo_poll_install', 'mamboleo_poll_install_ajax');

function mamboleo_get_log_path() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['basedir'] . '/mamboleo_scraper.log';
}

function mamboleo_get_install_log_path() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['basedir'] . '/mamboleo_install.log';
}

/**
 * Pick the python executable. Prefer 'python' on Windows, 'python3' elsewhere.
 * Returns the resolved command string.
 */
function mamboleo_python_cmd() {
    if (defined('MAMBOLEO_PYTHON_CMD') && MAMBOLEO_PYTHON_CMD) {
        return MAMBOLEO_PYTHON_CMD;
    }
    return (PHP_OS_FAMILY === 'Windows') ? 'python' : 'python3';
}

/**
 * Spawn a detached background process and stream its output to $log_file.
 * Cross-platform (Windows + *nix). Returns immediately.
 *
 * The command is wrapped in a subshell group so any `&` (Windows) or `;`
 * (sh) chaining inside $cmd_line stays grouped under a single redirection.
 */
function mamboleo_spawn_background($work_dir, $cmd_line, $log_file) {
    if (PHP_OS_FAMILY === 'Windows') {
        // (cmd) > log 2>&1   groups the whole chain so sentinel echos also log.
        $full = sprintf(
            'start /B "" cmd /C "cd /D %s && (%s) > %s 2>&1"',
            escapeshellarg($work_dir),
            $cmd_line,
            escapeshellarg($log_file)
        );
        pclose(popen($full, 'r'));
    } else {
        $inner = sprintf(
            'cd %s && { %s; } > %s 2>&1',
            escapeshellarg($work_dir),
            $cmd_line,
            escapeshellarg($log_file)
        );
        exec(sprintf('nohup sh -c %s > /dev/null 2>&1 &', escapeshellarg($inner)));
    }
}

function mamboleo_run_scraper_ajax() {
    check_ajax_referer('mamboleo_scraper_nonce', 'security');
    if (!current_user_can('manage_options')) wp_die('Forbidden');

    $scraper_dir = MAMBOLEO_PLUGIN_DIR . 'scraper';
    $log_file = mamboleo_get_log_path();
    $python = mamboleo_python_cmd();

    // Reset log so polling sees this run's output, not the previous one.
    file_put_contents($log_file, "--- Initialization ---\n[" . date('H:i:s') . "] Starting scraper\n");

    // -u → unbuffered, so the log updates line-by-line. Trailing sentinel
    // marks completion regardless of exit code so the JS poller can stop.
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = sprintf(
            '%s -u run_all_scrapers.py & echo --- Done (exit=%%errorlevel%%) ---',
            $python
        );
    } else {
        $cmd = sprintf(
            '%s -u run_all_scrapers.py; echo "--- Done (exit=$?) ---"',
            $python
        );
    }

    mamboleo_spawn_background($scraper_dir, $cmd, $log_file);

    // Tiny pause so the first log line is usually flushed before the poller fires.
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
    // Use the explicit sentinel as the only "done" signal so partial logs
    // containing the word "Done" mid-run don't end polling prematurely.
    $is_done = (strpos($output, '--- Done') !== false || strpos($output, 'Traceback') !== false);
    
    wp_send_json_success([
        'output' => $output,
        'done'   => $is_done
    ]);
}

function mamboleo_install_deps_ajax() {
    check_ajax_referer('mamboleo_scraper_nonce', 'security');
    if (!current_user_can('manage_options')) wp_die('Forbidden');

    $mode = isset($_POST['mode']) && $_POST['mode'] === 'optional' ? 'optional' : 'core';
    $req_file = $mode === 'optional' ? 'requirements-optional.txt' : 'requirements.txt';

    $scraper_dir = MAMBOLEO_PLUGIN_DIR . 'scraper';
    if (!file_exists($scraper_dir . DIRECTORY_SEPARATOR . $req_file)) {
        wp_send_json_error(['message' => "$req_file not found."]);
    }

    $log_file = mamboleo_get_install_log_path();
    $python = mamboleo_python_cmd();

    // Prime the log so the poller has something to read immediately.
    file_put_contents(
        $log_file,
        "--- Installing Dependencies ($req_file) ---\n[" . date('H:i:s') . "] Starting pip install\n"
    );

    // Workaround for shared hosting where /tmp is mounted noexec, which
    // breaks pip's compiled C extensions ("failed to map segment from
    // shared object"). We point TMPDIR at a writable+exec scratch dir
    // inside wp-content/uploads, which on most hosts is exec-allowed.
    $upload_dir = wp_upload_dir();
    $exec_tmp = $upload_dir['basedir'] . '/mamboleo_pip_tmp';
    if (!is_dir($exec_tmp)) @mkdir($exec_tmp, 0755, true);

    // -u  → unbuffered Python output (so the log updates per line, not at end)
    // pip --progress-bar off  → cleaner log, one line per package state change
    // pip -v                  → "Collecting / Downloading / Installing" lines per package
    // Trailing "echo --- Done ---" is the explicit completion sentinel the
    // poller watches for. `&` in cmd / `;` in sh ensure it runs even on pip failure.
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = sprintf(
            'set TMPDIR=%s && %s -u -m pip install --progress-bar off -v -r %s & echo --- Done (exit=%%errorlevel%%) ---',
            escapeshellarg($exec_tmp),
            $python,
            escapeshellarg($req_file)
        );
    } else {
        $cmd = sprintf(
            'TMPDIR=%s TMP=%s %s -u -m pip install --progress-bar off -v -r %s; echo "--- Done (exit=$?) ---"',
            escapeshellarg($exec_tmp),
            escapeshellarg($exec_tmp),
            $python,
            escapeshellarg($req_file)
        );
    }

    mamboleo_spawn_background($scraper_dir, $cmd, $log_file);

    wp_send_json_success(['message' => "Install started ($req_file)."]);
}

function mamboleo_poll_install_ajax() {
    check_ajax_referer('mamboleo_scraper_nonce', 'security');
    if (!current_user_can('manage_options')) wp_die('Forbidden');

    $log_file = mamboleo_get_install_log_path();
    if (!file_exists($log_file)) {
        wp_send_json_success(['output' => 'Waiting for install log...', 'done' => false]);
        return;
    }

    $output = file_get_contents($log_file);
    $is_done = (strpos($output, '--- Done') !== false);

    wp_send_json_success([
        'output' => $output,
        'done'   => $is_done,
    ]);
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
                <button id="install-deps-btn" class="button button-secondary" data-mode="core">Install Dependencies</button>
                <button id="install-optional-btn" class="button button-secondary" data-mode="optional" title="Optional spaCy NLP — may fail on shared hosting; safe to skip.">Install Optional NLP</button>
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
            if (window.scraperElapsed) { clearInterval(window.scraperElapsed); window.scraperElapsed = null; }
            $loader.hide();
            $('#run-scraper-btn').prop('disabled', false);
            $('#install-deps-btn').prop('disabled', false);
            $status.text(statusText);
        }

        $('#run-scraper-btn').on('click', function() {
            const $btn = $(this);
            $btn.prop('disabled', true);
            $('#install-deps-btn').prop('disabled', true);
            $loader.show();
            const startedAt = Date.now();
            $status.text('Scraper starting in background...');
            $terminal.html('<div class="mamboleo-terminal-line terminal-info">Initializing background process...</div>');
            lastFullText = "";

            // Live elapsed-time so the user sees activity even when the
            // scraper is mid-LLM-call and not flushing log lines.
            if (window.scraperElapsed) clearInterval(window.scraperElapsed);
            window.scraperElapsed = setInterval(function() {
                const secs = Math.round((Date.now() - startedAt) / 1000);
                const mins = Math.floor(secs / 60);
                $status.text('Scraper running… ' + (mins ? (mins + 'm ') : '') + (secs % 60) + 's');
            }, 1000);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mamboleo_run_scraper',
                    security: '<?php echo wp_create_nonce("mamboleo_scraper_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        startPolling();
                    } else {
                        clearInterval(window.scraperElapsed);
                        stopPolling('Failed to start.');
                    }
                },
                error: function() {
                    clearInterval(window.scraperElapsed);
                    stopPolling('Initial request failed.');
                }
            });
        });

        $('#install-deps-btn, #install-optional-btn').on('click', function() {
            const $btn = $(this);
            const mode = $btn.data('mode') || 'core';
            const label = mode === 'optional' ? 'optional NLP (spaCy)' : 'core dependencies';
            $btn.prop('disabled', true);
            $('#run-scraper-btn').prop('disabled', true);
            $('#install-deps-btn, #install-optional-btn').prop('disabled', true);
            $loader.show();
            const startedAt = Date.now();
            $status.text('Starting pip install...');
            $terminal.html('<div class="mamboleo-terminal-line terminal-info">--- Installing ' + label + ' ---</div>');
            lastFullText = "";

            // Live elapsed-time ticker so the user sees activity even if
            // pip is silent during slow downloads (e.g. spacy ~50MB wheel).
            let elapsedTimer = setInterval(function() {
                const secs = Math.round((Date.now() - startedAt) / 1000);
                $status.text('Installing ' + label + '… ' + secs + 's elapsed');
            }, 1000);

            function stopInstallPolling(statusText) {
                clearInterval(installPoll);
                clearInterval(elapsedTimer);
                installPoll = null;
                $loader.hide();
                $('#run-scraper-btn').prop('disabled', false);
                $('#install-deps-btn, #install-optional-btn').prop('disabled', false);
                $status.text(statusText);
            }

            // Kick off the background install
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mamboleo_install_deps',
                    mode: mode,
                    security: '<?php echo wp_create_nonce("mamboleo_scraper_nonce"); ?>'
                },
                error: function() {
                    stopInstallPolling('Failed to start install.');
                }
            });

            // Poll the install log every 1.5s
            var installPoll = setInterval(function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mamboleo_poll_install',
                        security: '<?php echo wp_create_nonce("mamboleo_scraper_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            updateTerminal(response.data.output);
                            if (response.data.done) {
                                const out = response.data.output;
                                const failed = out.indexOf('ERROR:') !== -1
                                            || out.indexOf('Traceback') !== -1
                                            || /exit=([1-9]\d*)/.test(out);
                                stopInstallPolling(failed ? 'Install finished with errors — see log.' : 'Dependencies installed.');
                            }
                        }
                    }
                });
            }, 1500);
        });
    });
    </script>
    <?php
}
