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
    if (isset($_POST['mamboleo_scrape_trigger'])) {
        // Use the plugin root constant to find the scraper directory
        $scraper_path = realpath(MAMBOLEO_PLUGIN_DIR . 'scraper/run_all_scrapers.py');
        
        if ($scraper_path && file_exists($scraper_path)) {
            // Get the directory of the scraper to run python from there (to handle relative imports/.env)
            $scraper_dir = dirname($scraper_path);
            $command = 'cd ' . escapeshellarg($scraper_dir) . ' && python run_all_scrapers.py 2>&1';
            $output = shell_exec($command);
        } else {
            $output = 'Scraper script not found at: ' . (MAMBOLEO_PLUGIN_DIR . 'scraper/run_all_scrapers.py');
        }
        echo '<div class="notice notice-success"><pre>' . esc_html($output) . '</pre></div>';
    }
    echo '<h2>Manual Scraper Trigger</h2>';
    echo '<form method="post"><button class="button button-primary" name="mamboleo_scrape_trigger">Run Scraper Now</button></form>';
}
