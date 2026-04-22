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

function mamboleo_scraper_admin_page() {
    $scraper_dir = MAMBOLEO_PLUGIN_DIR . 'scraper';
    
    // Increase execution time for long-running scraping tasks
    if (function_exists('set_time_limit')) {
        @set_time_limit(300); // 5 minutes
    }

    if (isset($_POST['mamboleo_install_deps'])) {
        $command = 'cd ' . escapeshellarg($scraper_dir) . ' && pip install -r requirements.txt 2>&1';
        $output = (string) shell_exec($command);
        echo '<div class="notice notice-info"><h3>Dependency Installation Output</h3><pre>' . esc_html($output ?: 'No output from pip.') . '</pre></div>';
    }

    if (isset($_POST['mamboleo_scrape_trigger'])) {
        $scraper_path = realpath($scraper_dir . '/run_all_scrapers.py');
        
        if ($scraper_path && file_exists($scraper_path)) {
            // Try python3 first, then python
            $python_cmd = 'python3';
            $check_py3 = (string) shell_exec('python3 --version 2>&1');
            if (strpos($check_py3, 'Python 3') === false) {
                $python_cmd = 'python';
            }

            $command = 'cd ' . escapeshellarg($scraper_dir) . ' && ' . $python_cmd . ' run_all_scrapers.py 2>&1';
            $output = (string) shell_exec($command);
            
            if (strpos($output, 'ModuleNotFoundError') !== false) {
                echo '<div class="notice notice-error"><h3>Missing Dependencies</h3><p>It looks like some Python packages are missing. Please click the "Install/Update Dependencies" button below.</p><pre>' . esc_html($output) . '</pre></div>';
            } elseif (empty($output)) {
                echo '<div class="notice notice-warning"><h3>No Output</h3><p>The scraper ran but returned no output. Check if shell_exec is enabled on your server.</p></div>';
            } else {
                echo '<div class="notice notice-success"><h3>Scraper Output</h3><pre>' . esc_html($output) . '</pre></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Scraper script not found at: ' . esc_html($scraper_dir . '/run_all_scrapers.py') . '</p></div>';
        }
    }

    echo '<h2>Manual Scraper Trigger</h2>';
    echo '<div class="card" style="max-width: 600px; padding: 20px;">';
    echo '<p>Trigger the backend Python scraper to fetch the latest security incidents and news articles.</p>';
    echo '<form method="post" style="display: inline-block; margin-right: 10px;">';
    echo '<button class="button button-primary" name="mamboleo_scrape_trigger">Run Scraper Now</button>';
    echo '</form>';
    
    echo '<form method="post" style="display: inline-block;">';
    echo '<button class="button button-secondary" name="mamboleo_install_deps">Install/Update Dependencies</button>';
    echo '</form>';
    echo '</div>';
}
