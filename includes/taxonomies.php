<?php
/**
 * Register custom taxonomies and handle seeding data.
 */

function mamboleo_register_county_taxonomy() {
    register_taxonomy('county', ['incident', 'article', 'social_post'], [
        'labels' => [
            'name' => 'Counties',
            'singular_name' => 'County',
            'search_items' => 'Search Counties',
            'all_items' => 'All Counties',
            'parent_item' => 'Parent County',
            'parent_item_colon' => 'Parent County:',
            'edit_item' => 'Edit County',
            'update_item' => 'Update County',
            'add_new_item' => 'Add New County',
            'new_item_name' => 'New County Name',
            'menu_name' => 'Counties',
        ],
        'public' => true,
        'show_in_graphql' => true,
        'graphql_single_name' => 'county',
        'graphql_plural_name' => 'counties',
        'hierarchical' => true,
        'show_in_rest' => true,
    ]);
}
add_action('init', 'mamboleo_register_county_taxonomy');

/**
 * Seed the counties taxonomy with Kenyan counties on plugin activation.
 */
function mamboleo_seed_counties() {
    $counties = [
        'Nairobi', 'Mombasa', 'Kisumu', 'Nakuru', 'Kiambu', 'Machakos', 'Uasin Gishu',
        'Kakamega', 'Kilifi', 'Garissa', 'Kisii', 'Bungoma', 'Meru', 'Vihiga',
        'Busia', 'Siaya', 'Homa Bay', 'Migori', 'Trans Nzoia', 'Baringo', 'Laikipia',
        'Nyeri', 'Kirinyaga', 'Murang\'a', 'Nyandarua', 'Embu', 'Kitui', 'Makueni',
        'Kajiado', 'Kericho', 'Bomet', 'Narok', 'Taita Taveta', 'Lamu', 'Kwale',
        'Tana River', 'Isiolo', 'Marsabit', 'Mandera', 'Wajir', 'Samburu', 'Turkana',
        'West Pokot', 'Elgeyo Marakwet', 'Tharaka Nithi'
    ];
    foreach ($counties as $county) {
        if (!term_exists($county, 'county')) {
            wp_insert_term($county, 'county');
        }
    }
}
// Note: This is called via register_activation_hook in the main plugin file.
// Since the hook is registered there, we don't need to call it here unless we use a specific callback.
// But the script had: register_activation_hook(__FILE__, 'mamboleo_seed_counties');
// However, since this file is included, __FILE__ refers to this file, not the main plugin file.
// Activation hooks must be registered in the main plugin file or using the main plugin file path.
// I'll adjust the main plugin file to call this seeding function.
