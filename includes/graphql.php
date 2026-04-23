<?php
/**
 * Register custom GraphQL types and fields for WPGraphQL.
 * All meta is read via get_post_meta() — no ACF dependency.
 */

add_action( 'graphql_register_types', 'mamboleo_register_graphql_types' );
function mamboleo_register_graphql_types(): void {

    // ── IncidentFields composite type ─────────────────────────────────────
    register_graphql_object_type( 'IncidentFields', [
        'description' => __( 'Incident metadata fields', 'mamboleo' ),
        'fields'      => [
            'type'               => [ 'type' => 'String',  'description' => 'Incident type: fire | accident | police | weather' ],
            'latitude'           => [ 'type' => 'Float',   'description' => 'GPS latitude' ],
            'longitude'          => [ 'type' => 'Float',   'description' => 'GPS longitude' ],
            'severity'           => [ 'type' => 'String',  'description' => 'Severity: low | medium | high' ],
            'status'             => [ 'type' => 'String',  'description' => 'Situation status' ],
            'incidentTime'       => [ 'type' => 'String',  'description' => 'ISO 8601 date-time the incident occurred' ],
            'videoUrl'           => [ 'type' => 'String',  'description' => 'Link to video evidence' ],
            'reporterName'       => [ 'type' => 'String',  'description' => 'Reporter display name' ],
            'isAnonymous'        => [ 'type' => 'Boolean', 'description' => 'Submitted anonymously?' ],
            'isVerified'         => [ 'type' => 'Boolean', 'description' => 'Verified by moderator?' ],
            'corroborationCount' => [ 'type' => 'Int',     'description' => 'Number of corroborations' ],
            'locationName'       => [ 'type' => 'String',  'description' => 'Human-readable location label' ],
        ],
    ] );

    register_graphql_field( 'Incident', 'incidentFields', [
        'type'        => 'IncidentFields',
        'description' => __( 'Core incident metadata', 'mamboleo' ),
        'resolve'     => function ( \WPGraphQL\Model\Post $post ) {
            $id          = $post->ID;
            $is_verified = get_post_meta( $id, 'is_verified', true );
            return [
                'type'               => get_post_meta( $id, 'type',               true ) ?: 'fire',
                'latitude'           => (float) ( get_post_meta( $id, 'latitude',  true ) ?: 0 ),
                'longitude'          => (float) ( get_post_meta( $id, 'longitude', true ) ?: 0 ),
                'severity'           => get_post_meta( $id, 'severity',           true ) ?: 'low',
                'status'             => get_post_meta( $id, 'status',             true ) ?: 'unsafe',
                'incidentTime'       => get_post_meta( $id, 'incident_time',      true ) ?: null,
                'videoUrl'           => get_post_meta( $id, 'video_url',          true ) ?: null,
                'reporterName'       => get_post_meta( $id, 'reporter_name',      true ) ?: null,
                'isAnonymous'        => (bool) get_post_meta( $id, 'is_anonymous', true ),
                // Admin-created posts (no meta set) default to verified = true
                'isVerified'         => $is_verified === '' ? true : (bool) $is_verified,
                'corroborationCount' => (int) ( get_post_meta( $id, 'corroboration_count', true ) ?: 0 ),
                'locationName'       => get_post_meta( $id, 'location_name',     true ) ?: null,
            ];
        },
    ] );

    // ── Article fields ────────────────────────────────────────────────────
    register_graphql_field( 'Article', 'source', [
        'type'    => 'String',
        'resolve' => fn( $p ) => get_post_meta( $p->ID, 'source', true ) ?: null,
    ] );
    register_graphql_field( 'Article', 'articleUrl', [
        'type'    => 'String',
        'resolve' => fn( $p ) => get_post_meta( $p->ID, 'article_url', true ) ?: null,
    ] );
    register_graphql_field( 'Article', 'biasScore', [
        'type'    => 'Int',
        'resolve' => fn( $p ) => (int) ( get_post_meta( $p->ID, 'bias_score', true ) ?: 0 ),
    ] );
    register_graphql_field( 'Article', 'sentiment', [
        'type'    => 'String',
        'resolve' => fn( $p ) => get_post_meta( $p->ID, 'sentiment', true ) ?: 'neutral',
    ] );
    register_graphql_field( 'Article', 'sentimentScore', [
        'type'    => 'Float',
        'resolve' => fn( $p ) => (float) ( get_post_meta( $p->ID, 'sentiment_score', true ) ?: 0 ),
    ] );
    register_graphql_field( 'Article', 'tier', [
        'type'    => 'Int',
        'resolve' => fn( $p ) => (int) ( get_post_meta( $p->ID, 'tier', true ) ?: 3 ),
    ] );
    register_graphql_field( 'Article', 'publishedAt', [
        'type'    => 'String',
        'resolve' => fn( $p ) => get_post_meta( $p->ID, 'published_at', true ) ?: null,
    ] );
    register_graphql_field( 'Article', 'topics', [
        'type'    => [ 'list_of' => 'String' ],
        'resolve' => function ( $p ) {
            $t = get_post_meta( $p->ID, 'topics', true );
            return is_array( $t ) ? array_values( $t ) : [];
        },
    ] );
    register_graphql_field( 'Article', 'keywords', [
        'type'    => [ 'list_of' => 'String' ],
        'resolve' => function ( $p ) {
            $k = get_post_meta( $p->ID, 'keywords', true );
            return is_array( $k ) ? array_values( $k ) : [];
        },
    ] );
    register_graphql_field( 'Article', 'entitiesJson', [
        'type'        => 'String',
        'description' => 'JSON-encoded { persons, orgs, places }',
        'resolve'     => fn( $p ) => get_post_meta( $p->ID, 'entities_json', true ) ?: '',
    ] );

    // ── Social Post fields ────────────────────────────────────────────────
    register_graphql_field( 'SocialPost', 'platform', [
        'type'    => 'String',
        'resolve' => fn( $p ) => get_post_meta( $p->ID, 'platform', true ) ?: null,
    ] );
    register_graphql_field( 'SocialPost', 'authorHandle', [
        'type'    => 'String',
        'resolve' => fn( $p ) => get_post_meta( $p->ID, 'author_handle', true ) ?: null,
    ] );
    register_graphql_field( 'SocialPost', 'postUrl', [
        'type'    => 'String',
        'resolve' => fn( $p ) => get_post_meta( $p->ID, 'post_url', true ) ?: null,
    ] );
    register_graphql_field( 'SocialPost', 'engagement', [
        'type'    => 'Int',
        'resolve' => fn( $p ) => (int) ( get_post_meta( $p->ID, 'engagement', true ) ?: 0 ),
    ] );
}

