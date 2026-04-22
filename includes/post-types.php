<?php
/**
 * Register custom post types for Mamboleo Backend.
 */

function mamboleo_register_post_types(): void {

    // ── Incident ──────────────────────────────────────────────────────────
    register_post_type( 'incident', [
        'labels' => [
            'name'               => __( 'Incidents',         'mamboleo' ),
            'singular_name'      => __( 'Incident',          'mamboleo' ),
            'add_new'            => __( 'Add New',           'mamboleo' ),
            'add_new_item'       => __( 'Add New Incident',  'mamboleo' ),
            'edit_item'          => __( 'Edit Incident',     'mamboleo' ),
            'new_item'           => __( 'New Incident',      'mamboleo' ),
            'view_item'          => __( 'View Incident',     'mamboleo' ),
            'search_items'       => __( 'Search Incidents',  'mamboleo' ),
            'not_found'          => __( 'No incidents found.', 'mamboleo' ),
            'not_found_in_trash' => __( 'No incidents found in trash.', 'mamboleo' ),
        ],
        'public'              => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_rest'        => true,
        'show_in_graphql'     => true,
        'graphql_single_name' => 'incident',
        'graphql_plural_name' => 'incidents',
        'supports'            => [ 'title', 'excerpt', 'custom-fields', 'revisions', 'thumbnail' ],
        'has_archive'         => false,
        'rewrite'             => [ 'slug' => 'incidents' ],
        'menu_icon'           => 'dashicons-location-alt',
    ] );

    // ── Article ───────────────────────────────────────────────────────────
    register_post_type( 'article', [
        'labels' => [
            'name'          => __( 'Articles',        'mamboleo' ),
            'singular_name' => __( 'Article',         'mamboleo' ),
            'add_new_item'  => __( 'Add New Article', 'mamboleo' ),
            'edit_item'     => __( 'Edit Article',    'mamboleo' ),
            'search_items'  => __( 'Search Articles', 'mamboleo' ),
        ],
        'public'              => true,
        'show_in_rest'        => true,
        'show_in_graphql'     => true,
        'graphql_single_name' => 'article',
        'graphql_plural_name' => 'articles',
        'supports'            => [ 'title', 'editor', 'excerpt', 'custom-fields', 'thumbnail', 'revisions' ],
        'has_archive'         => true,
        'menu_icon'           => 'dashicons-media-text',
    ] );

    // ── Social Post ───────────────────────────────────────────────────────
    register_post_type( 'social_post', [
        'labels' => [
            'name'          => __( 'Social Posts',        'mamboleo' ),
            'singular_name' => __( 'Social Post',         'mamboleo' ),
            'add_new_item'  => __( 'Add New Social Post', 'mamboleo' ),
            'edit_item'     => __( 'Edit Social Post',    'mamboleo' ),
            'search_items'  => __( 'Search Social Posts', 'mamboleo' ),
        ],
        'public'              => true,
        'show_in_rest'        => true,
        'show_in_graphql'     => true,
        'graphql_single_name' => 'socialPost',
        'graphql_plural_name' => 'socialPosts',
        'supports'            => [ 'title', 'editor', 'custom-fields', 'revisions' ],
        'has_archive'         => true,
        'menu_icon'           => 'dashicons-twitter',
    ] );
}
add_action( 'init', 'mamboleo_register_post_types' );

