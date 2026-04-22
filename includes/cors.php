<?php
/**
 * CORS headers for local Vite dev server — WPGraphQL and REST API.
 */

$mamboleo_allowed_origins = [
    'http://localhost:5173',
    'http://localhost:5174',
    'http://localhost:5175',
    'http://127.0.0.1:5173',
    'http://127.0.0.1:5174',
    // Production and Vercel frontend domains:
    'https://mamboleole.com',
    'https://mamboleole.vercel.app',
    // Add more deployed frontend URLs as needed
];

// ── WPGraphQL CORS ────────────────────────────────────────────────────────────
add_action( 'graphql_response_headers_to_send', 'mamboleo_graphql_cors' );
function mamboleo_graphql_cors( array $headers ): array {
    global $mamboleo_allowed_origins;
    $origin = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ?? '' ) );
    if ( in_array( $origin, $mamboleo_allowed_origins, true ) ) {
        $headers['Access-Control-Allow-Origin']  = $origin;
        $headers['Access-Control-Allow-Headers'] = 'Content-Type, Authorization';
        $headers['Access-Control-Allow-Methods'] = 'POST, GET, OPTIONS';
    }
    return $headers;
}

// ── REST API CORS ─────────────────────────────────────────────────────────────
add_filter( 'rest_pre_serve_request', 'mamboleo_rest_cors', 10, 4 );
function mamboleo_rest_cors( bool $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ): bool {
    global $mamboleo_allowed_origins;
    $origin = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ?? '' ) );
    if ( in_array( $origin, $mamboleo_allowed_origins, true ) ) {
        header( 'Access-Control-Allow-Origin: '  . $origin );
        header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
        header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE' );
    }
    return $served;
}
