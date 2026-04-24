<?php
/**
 * Kenya counties dataset — PHP mirror of mamboleo/src/lib/counties.ts.
 *
 * Each county has:
 *   - name     : display name
 *   - slug     : URL-safe identifier (lowercase, hyphenated)
 *   - center   : [lat, lng] approximate geographic centre
 *   - bbox     : [minLat, minLng, maxLat, maxLng] rough bounding box (±~0.35°)
 *   - subs     : list of subcounty arrays { name, slug, center }
 *
 * bbox values are deliberately approximate. Use them for "probably inside
 * this county" checks; snap to the centroid when a point fails the check.
 * Drop a real GeoJSON into /data/kenya-counties.geojson later to upgrade
 * the check without changing callers.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'mamboleo_kenya_bbox' ) ) {
    /** Country-level bbox: [minLat, minLng, maxLat, maxLng]. */
    function mamboleo_kenya_bbox(): array {
        return [ -4.72, 33.91, 5.02, 41.91 ];
    }
}

if ( ! function_exists( 'mamboleo_counties_data' ) ) {
    function mamboleo_counties_data(): array {
        static $cache = null;
        if ( $cache !== null ) return $cache;

        // helper: build a ±0.35° bbox around the centroid
        $bb = function ( float $lat, float $lng, float $pad = 0.35 ): array {
            return [ $lat - $pad, $lng - $pad, $lat + $pad, $lng + $pad ];
        };
        $sub = function ( string $name, float $lat, float $lng ): array {
            return [
                'name'   => $name,
                'slug'   => sanitize_title( $name ),
                'center' => [ $lat, $lng ],
            ];
        };

        $cache = [
            // ── Coast ────────────────────────────────────────────────
            [ 'name' => 'Mombasa', 'center' => [ -4.0435, 39.6682 ], 'bbox' => $bb( -4.0435, 39.6682, 0.15 ), 'subs' => [
                $sub( 'Changamwe', -4.0256, 39.6206 ),
                $sub( 'Jomvu',     -4.0167, 39.6333 ),
                $sub( 'Kisauni',   -4.0177, 39.7137 ),
                $sub( 'Nyali',     -4.0300, 39.7000 ),
                $sub( 'Likoni',    -4.0951, 39.6667 ),
                $sub( 'Mvita',     -4.0629, 39.6667 ),
            ] ],
            [ 'name' => 'Kwale',        'center' => [ -4.1816, 39.4606 ], 'bbox' => $bb( -4.1816, 39.4606, 0.6 ),  'subs' => [] ],
            [ 'name' => 'Kilifi',       'center' => [ -3.5107, 39.9093 ], 'bbox' => $bb( -3.5107, 39.9093, 0.7 ),  'subs' => [] ],
            [ 'name' => 'Tana River',   'center' => [ -1.6518, 39.6518 ], 'bbox' => $bb( -1.6518, 39.6518, 1.4 ),  'subs' => [] ],
            [ 'name' => 'Lamu',         'center' => [ -2.2717, 40.9020 ], 'bbox' => $bb( -2.2717, 40.9020, 0.6 ),  'subs' => [] ],
            [ 'name' => 'Taita-Taveta', 'center' => [ -3.3961, 38.4850 ], 'bbox' => $bb( -3.3961, 38.4850, 0.9 ),  'subs' => [] ],

            // ── North-East ────────────────────────────────────────────
            [ 'name' => 'Garissa',  'center' => [ -0.4569, 39.6583 ], 'bbox' => $bb( -0.4569, 39.6583, 1.6 ), 'subs' => [] ],
            [ 'name' => 'Wajir',    'center' => [ 1.7471, 40.0629 ],  'bbox' => $bb( 1.7471, 40.0629, 1.6 ),  'subs' => [] ],
            [ 'name' => 'Mandera',  'center' => [ 3.9366, 41.8569 ],  'bbox' => $bb( 3.9366, 41.8569, 1.1 ),  'subs' => [] ],

            // ── Eastern ───────────────────────────────────────────────
            [ 'name' => 'Marsabit',      'center' => [ 2.3354, 37.9900 ],  'bbox' => $bb( 2.3354, 37.9900, 2.0 ),  'subs' => [] ],
            [ 'name' => 'Isiolo',        'center' => [ 0.3536, 37.5822 ],  'bbox' => $bb( 0.3536, 37.5822, 1.3 ),  'subs' => [] ],
            [ 'name' => 'Meru',          'center' => [ 0.0466, 37.6560 ],  'bbox' => $bb( 0.0466, 37.6560, 0.6 ),  'subs' => [] ],
            [ 'name' => 'Tharaka-Nithi', 'center' => [ -0.2964, 37.7260 ], 'bbox' => $bb( -0.2964, 37.7260, 0.5 ), 'subs' => [] ],
            [ 'name' => 'Embu',          'center' => [ -0.5384, 37.4571 ], 'bbox' => $bb( -0.5384, 37.4571, 0.5 ), 'subs' => [] ],
            [ 'name' => 'Kitui',         'center' => [ -1.3667, 38.0100 ], 'bbox' => $bb( -1.3667, 38.0100, 1.2 ), 'subs' => [] ],
            [ 'name' => 'Machakos',      'center' => [ -1.5177, 37.2634 ], 'bbox' => $bb( -1.5177, 37.2634, 0.7 ), 'subs' => [] ],
            [ 'name' => 'Makueni',       'center' => [ -1.8040, 37.6242 ], 'bbox' => $bb( -1.8040, 37.6242, 0.8 ), 'subs' => [] ],

            // ── Central ───────────────────────────────────────────────
            [ 'name' => 'Nyandarua',  'center' => [ -0.1800, 36.5200 ], 'bbox' => $bb( -0.1800, 36.5200, 0.5 ), 'subs' => [] ],
            [ 'name' => 'Nyeri',      'center' => [ -0.4201, 36.9476 ], 'bbox' => $bb( -0.4201, 36.9476, 0.5 ), 'subs' => [] ],
            [ 'name' => 'Kirinyaga',  'center' => [ -0.6590, 37.3827 ], 'bbox' => $bb( -0.6590, 37.3827, 0.4 ), 'subs' => [] ],
            [ 'name' => "Murang'a",   'center' => [ -0.7839, 37.0400 ], 'bbox' => $bb( -0.7839, 37.0400, 0.5 ), 'subs' => [] ],
            [ 'name' => 'Kiambu',     'center' => [ -1.1714, 36.8356 ], 'bbox' => $bb( -1.1714, 36.8356, 0.5 ), 'subs' => [] ],

            // ── Nairobi ──────────────────────────────────────────────
            [ 'name' => 'Nairobi', 'center' => [ -1.286389, 36.817223 ], 'bbox' => $bb( -1.286389, 36.817223, 0.2 ), 'subs' => [
                $sub( 'Westlands',        -1.2647, 36.8121 ),
                $sub( 'Dagoretti North',  -1.2833, 36.7667 ),
                $sub( 'Dagoretti South',  -1.3000, 36.7300 ),
                $sub( "Lang'ata",         -1.3621, 36.7672 ),
                $sub( 'Kibra',            -1.3127, 36.7924 ),
                $sub( 'Roysambu',         -1.2000, 36.9000 ),
                $sub( 'Kasarani',         -1.2333, 36.9000 ),
                $sub( 'Ruaraka',          -1.2500, 36.8700 ),
                $sub( 'Embakasi South',   -1.3342, 36.8947 ),
                $sub( 'Embakasi North',   -1.2500, 36.9000 ),
                $sub( 'Embakasi Central', -1.2833, 36.9000 ),
                $sub( 'Embakasi East',    -1.3167, 36.9000 ),
                $sub( 'Embakasi West',    -1.2833, 36.8500 ),
                $sub( 'Makadara',         -1.3000, 36.8667 ),
                $sub( 'Kamukunji',        -1.2833, 36.8667 ),
                $sub( 'Starehe',          -1.2833, 36.8333 ),
                $sub( 'Mathare',          -1.2667, 36.8667 ),
            ] ],

            // ── Rift Valley ───────────────────────────────────────────
            [ 'name' => 'Turkana',          'center' => [ 3.1167, 35.6000 ],  'bbox' => $bb( 3.1167, 35.6000, 2.2 ), 'subs' => [] ],
            [ 'name' => 'West Pokot',       'center' => [ 1.4024, 35.1119 ],  'bbox' => $bb( 1.4024, 35.1119, 0.8 ), 'subs' => [] ],
            [ 'name' => 'Samburu',          'center' => [ 1.2152, 36.9545 ],  'bbox' => $bb( 1.2152, 36.9545, 1.2 ), 'subs' => [] ],
            [ 'name' => 'Trans Nzoia',      'center' => [ 1.0226, 34.9906 ],  'bbox' => $bb( 1.0226, 34.9906, 0.4 ), 'subs' => [] ],
            [ 'name' => 'Uasin Gishu',      'center' => [ 0.5143, 35.2698 ],  'bbox' => $bb( 0.5143, 35.2698, 0.5 ), 'subs' => [] ],
            [ 'name' => 'Elgeyo-Marakwet',  'center' => [ 0.8542, 35.5330 ],  'bbox' => $bb( 0.8542, 35.5330, 0.6 ), 'subs' => [] ],
            [ 'name' => 'Nandi',            'center' => [ 0.1667, 35.1000 ],  'bbox' => $bb( 0.1667, 35.1000, 0.4 ), 'subs' => [] ],
            [ 'name' => 'Baringo',          'center' => [ 0.6554, 35.8800 ],  'bbox' => $bb( 0.6554, 35.8800, 1.0 ), 'subs' => [] ],
            [ 'name' => 'Laikipia',         'center' => [ 0.3606, 36.7820 ],  'bbox' => $bb( 0.3606, 36.7820, 0.9 ), 'subs' => [] ],
            [ 'name' => 'Nakuru',           'center' => [ -0.3031, 36.0800 ], 'bbox' => $bb( -0.3031, 36.0800, 0.8 ), 'subs' => [] ],
            [ 'name' => 'Narok',            'center' => [ -1.0781, 35.8711 ], 'bbox' => $bb( -1.0781, 35.8711, 1.3 ), 'subs' => [] ],
            [ 'name' => 'Kajiado',          'center' => [ -1.8527, 36.7765 ], 'bbox' => $bb( -1.8527, 36.7765, 1.3 ), 'subs' => [] ],
            [ 'name' => 'Kericho',          'center' => [ -0.3689, 35.2833 ], 'bbox' => $bb( -0.3689, 35.2833, 0.4 ), 'subs' => [] ],
            [ 'name' => 'Bomet',            'center' => [ -0.7816, 35.3418 ], 'bbox' => $bb( -0.7816, 35.3418, 0.4 ), 'subs' => [] ],

            // ── Western ───────────────────────────────────────────────
            [ 'name' => 'Kakamega', 'center' => [ 0.2827, 34.7519 ],  'bbox' => $bb( 0.2827, 34.7519, 0.4 ), 'subs' => [] ],
            [ 'name' => 'Vihiga',   'center' => [ 0.0765, 34.7225 ],  'bbox' => $bb( 0.0765, 34.7225, 0.2 ), 'subs' => [] ],
            [ 'name' => 'Bungoma',  'center' => [ 0.5695, 34.5584 ],  'bbox' => $bb( 0.5695, 34.5584, 0.4 ), 'subs' => [] ],
            [ 'name' => 'Busia',    'center' => [ 0.4608, 34.1115 ],  'bbox' => $bb( 0.4608, 34.1115, 0.3 ), 'subs' => [] ],

            // ── Nyanza ────────────────────────────────────────────────
            [ 'name' => 'Siaya',    'center' => [ 0.0611, 34.2881 ],  'bbox' => $bb( 0.0611, 34.2881, 0.4 ),  'subs' => [] ],
            [ 'name' => 'Kisumu',   'center' => [ -0.0917, 34.7680 ], 'bbox' => $bb( -0.0917, 34.7680, 0.4 ), 'subs' => [] ],
            [ 'name' => 'Homa Bay', 'center' => [ -0.5273, 34.4571 ], 'bbox' => $bb( -0.5273, 34.4571, 0.5 ), 'subs' => [] ],
            [ 'name' => 'Migori',   'center' => [ -1.0634, 34.4731 ], 'bbox' => $bb( -1.0634, 34.4731, 0.4 ), 'subs' => [] ],
            [ 'name' => 'Kisii',    'center' => [ -0.6817, 34.7720 ], 'bbox' => $bb( -0.6817, 34.7720, 0.3 ), 'subs' => [] ],
            [ 'name' => 'Nyamira',  'center' => [ -0.5633, 34.9358 ], 'bbox' => $bb( -0.5633, 34.9358, 0.3 ), 'subs' => [] ],
        ];

        foreach ( $cache as &$c ) {
            $c['slug'] = sanitize_title( $c['name'] );
        }
        unset( $c );

        return $cache;
    }
}

if ( ! function_exists( 'mamboleo_county_by_slug' ) ) {
    function mamboleo_county_by_slug( string $slug ): ?array {
        foreach ( mamboleo_counties_data() as $c ) {
            if ( $c['slug'] === $slug ) return $c;
        }
        return null;
    }
}

if ( ! function_exists( 'mamboleo_subcounty_by_slug' ) ) {
    function mamboleo_subcounty_by_slug( array $county, string $slug ): ?array {
        foreach ( $county['subs'] as $s ) {
            if ( $s['slug'] === $slug ) return $s;
        }
        return null;
    }
}
