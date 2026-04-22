<?php
/**
 * Mamboleo Admin: Manual Scraper Trigger
 * Adds a button to the WP admin to trigger backend scraping.
 */
add_action('admin_menu', function() {
    add_menu_page(
        'Mamboleo Scraper',
        'Scraper Trigger',
        'manage_options',
        'mamboleo-scraper',
        'mamboleo_scraper_admin_page',
        'dashicons-update',
        80
    );
});

function mamboleo_scraper_admin_page() {
    if (isset($_POST['mamboleo_scrape_trigger'])) {
        // Trigger the scraper (e.g., via shell_exec or REST call)
        $output = shell_exec('python ../scraper/run_all_scrapers.py 2>&1');
        echo '<div class="notice notice-success"><pre>' . esc_html($output) . '</pre></div>';
    }
    echo '<h2>Manual Scraper Trigger</h2>';
    echo '<form method="post"><button class="button button-primary" name="mamboleo_scrape_trigger">Run Scraper Now</button></form>';
}
