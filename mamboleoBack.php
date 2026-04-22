<?php
/**
 * Plugin Name: Mamboleo Backend
 * Description: Custom post types, GraphQL extensions, and APIs for security & media monitoring.
 * Version: 1.0.0
 * Author: Mamboleo
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MAMBOLEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MAMBOLEO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MAMBOLEO_API_KEY', 'mamboleo-dev-key-change-in-production');

// Include required files
require_once MAMBOLEO_PLUGIN_DIR . 'includes/post-types.php';
require_once MAMBOLEO_PLUGIN_DIR . 'includes/taxonomies.php';
require_once MAMBOLEO_PLUGIN_DIR . 'includes/fields.php';
require_once MAMBOLEO_PLUGIN_DIR . 'includes/graphql.php';
require_once MAMBOLEO_PLUGIN_DIR . 'includes/rest-api.php';
require_once MAMBOLEO_PLUGIN_DIR . 'includes/utilities.php';
require_once MAMBOLEO_PLUGIN_DIR . 'includes/cors.php';
require_once MAMBOLEO_PLUGIN_DIR . 'includes/admin.php';
require_once MAMBOLEO_PLUGIN_DIR . 'includes/admin-scraper.php';

// Activation/Deactivation hooks
function mamboleo_activate() {
    // Flush rewrite rules to ensure CPTs and taxonomies are recognized immediately
    if (function_exists('mamboleo_register_post_types')) {
        mamboleo_register_post_types();
    }
    if (function_exists('mamboleo_register_county_taxonomy')) {
        mamboleo_register_county_taxonomy();
    }
    if (function_exists('mamboleo_seed_counties')) {
        mamboleo_seed_counties();
    }
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'mamboleo_activate');
