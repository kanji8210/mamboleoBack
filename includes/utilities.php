<?php
/**
 * Utility functions for Mamboleo Backend.
 */

/**
 * Geocode an address using Nominatim (OpenStreetMap).
 */
function mamboleo_geocode_address($address) {
    if (empty($address)) return null;

    $url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($address) . "&format=json&limit=1";
    
    // Identifiying the user agent as required by Nominatim's usage policy
    $args = [
        'user-agent' => 'Mamboleo-WordPress-Plugin/1.0.0 (https://mamboleo.ai)'
    ];

    $response = wp_remote_get($url, $args);
    
    if (is_wp_error($response)) return null;
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);
    
    if (!empty($data) && isset($data[0])) {
        return [
            'lat' => $data[0]->lat,
            'lon' => $data[0]->lon,
            'display_name' => $data[0]->display_name
        ];
    }
    
    return null;
}

/**
 * Reverse geocode coordinates to find the county using Nominatim.
 */
function mamboleo_get_county_from_coords($lat, $lon) {
    if (empty($lat) || empty($lon)) return null;

    $url = "https://nominatim.openstreetmap.org/reverse?lat={$lat}&lon={$lon}&format=json";
    
    $args = [
        'user-agent' => 'Mamboleo-WordPress-Plugin/1.0.0 (https://mamboleo.ai)'
    ];

    $response = wp_remote_get($url, $args);
    
    if (is_wp_error($response)) return null;
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);
    
    if (isset($data->address->county)) {
        return $data->address->county;
    }
    
    return null;
}
