<?php
/**
 * Country dataset — Kenya + East African neighbours.
 *
 * Each entry: { name, slug, center:[lat,lng], bbox:[minLat,minLng,maxLat,maxLng] }.
 * `kenya` is the canonical local country and unlocks the county/subcounty
 * pickers; the other entries provide a coarse country-level fallback so
 * incidents mentioned across the border still get a defensible pin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'mamboleo_countries_data' ) ) {
    function mamboleo_countries_data(): array {
        static $cache = null;
        if ( $cache !== null ) return $cache;

        $cache = [
            [ 'name' => 'Kenya',        'slug' => 'kenya',        'center' => [  0.0236, 37.9062 ], 'bbox' => [ -4.72, 33.91,  5.02, 41.91 ] ],
            [ 'name' => 'Uganda',       'slug' => 'uganda',       'center' => [  1.3733, 32.2903 ], 'bbox' => [ -1.48, 29.57,  4.23, 35.04 ] ],
            [ 'name' => 'Tanzania',     'slug' => 'tanzania',     'center' => [ -6.3690, 34.8888 ], 'bbox' => [-11.75, 29.34, -0.99, 40.44 ] ],
            [ 'name' => 'Ethiopia',     'slug' => 'ethiopia',     'center' => [  9.1450, 40.4897 ], 'bbox' => [  3.40, 32.99, 14.89, 47.99 ] ],
            [ 'name' => 'Somalia',      'slug' => 'somalia',      'center' => [  5.1521, 46.1996 ], 'bbox' => [ -1.69, 40.99, 11.99, 51.42 ] ],
            [ 'name' => 'South Sudan',  'slug' => 'south-sudan',  'center' => [  6.8770, 31.3070 ], 'bbox' => [  3.49, 24.13, 12.24, 35.95 ] ],
            [ 'name' => 'Rwanda',       'slug' => 'rwanda',       'center' => [ -1.9403, 29.8739 ], 'bbox' => [ -2.84, 28.86, -1.05, 30.90 ] ],
            [ 'name' => 'Burundi',      'slug' => 'burundi',      'center' => [ -3.3731, 29.9189 ], 'bbox' => [ -4.47, 28.98, -2.30, 30.85 ] ],
            [ 'name' => 'DR Congo',     'slug' => 'dr-congo',     'center' => [ -4.0383, 21.7587 ], 'bbox' => [-13.46, 12.21,  5.39, 31.30 ] ],
        ];
        return $cache;
    }
}

if ( ! function_exists( 'mamboleo_country_by_slug' ) ) {
    function mamboleo_country_by_slug( string $slug ): ?array {
        foreach ( mamboleo_countries_data() as $c ) {
            if ( $c['slug'] === $slug ) return $c;
        }
        return null;
    }
}

if ( ! function_exists( 'mamboleo_point_in_country' ) ) {
    function mamboleo_point_in_country( float $lat, float $lng, array $country ): bool {
        [ $minLat, $minLng, $maxLat, $maxLng ] = $country['bbox'];
        return $lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng;
    }
}
