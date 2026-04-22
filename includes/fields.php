<?php
/**
 * Register post meta fields for all Mamboleo post types.
 * Uses register_post_meta() — no ACF dependency required.
 */

function mamboleo_register_meta(): void {

    // -- Shared defaults ---------------------------------------------------
    $shared = [
        'single'       => true,
        'show_in_rest' => true,
    ];

    // -- Incident meta -----------------------------------------------------
    $incident = array_merge( $shared, [ 'object_subtype' => 'incident' ] );

    register_post_meta( 'incident', 'type', array_merge( $incident, [
        'type'        => 'string',
        'description' => 'Incident type: fire | accident | police | weather',
        'default'     => 'fire',
    ] ) );
    register_post_meta( 'incident', 'latitude', array_merge( $incident, [
        'type'        => 'number',
        'description' => 'GPS latitude',
        'default'     => 0,
    ] ) );
    register_post_meta( 'incident', 'longitude', array_merge( $incident, [
        'type'        => 'number',
        'description' => 'GPS longitude',
        'default'     => 0,
    ] ) );
    register_post_meta( 'incident', 'severity', array_merge( $incident, [
        'type'        => 'string',
        'description' => 'Severity level: low | medium | high',
        'default'     => 'low',
    ] ) );
    register_post_meta( 'incident', 'status', array_merge( $incident, [
        'type'        => 'string',
        'description' => 'Situation status: unsafe | all_clear | police_operating | police_aggressive | unknown',
        'default'     => 'unsafe',
    ] ) );
    register_post_meta( 'incident', 'incident_time', array_merge( $incident, [
        'type'        => 'string',
        'description' => 'ISO 8601 date-time when the incident occurred',
        'default'     => '',
    ] ) );
    register_post_meta( 'incident', 'video_url', array_merge( $incident, [
        'type'        => 'string',
        'description' => 'Link to video evidence (Rumble, YouTube, etc.)',
        'default'     => '',
    ] ) );
    register_post_meta( 'incident', 'reporter_name', array_merge( $incident, [
        'type'        => 'string',
        'description' => 'Reporter display name (empty if anonymous)',
        'default'     => '',
    ] ) );
    register_post_meta( 'incident', 'is_anonymous', array_merge( $incident, [
        'type'        => 'boolean',
        'description' => 'Whether the report was submitted anonymously',
        'default'     => true,
    ] ) );
    register_post_meta( 'incident', 'is_verified', array_merge( $incident, [
        'type'        => 'boolean',
        'description' => 'Whether the incident has been verified by a moderator',
        'default'     => false,
    ] ) );
    register_post_meta( 'incident', 'corroboration_count', array_merge( $incident, [
        'type'        => 'integer',
        'description' => 'Number of independent corroborations',
        'default'     => 0,
    ] ) );
    register_post_meta( 'incident', 'location_name', array_merge( $incident, [
        'type'        => 'string',
        'description' => 'Human-readable location label (street, area)',
        'default'     => '',
    ] ) );

    // -- Article meta ------------------------------------------------------
    $article = array_merge( $shared, [ 'object_subtype' => 'article' ] );

    register_post_meta( 'article', 'source', array_merge( $article, [
        'type'        => 'string',
        'description' => 'Publication or platform source name',
        'default'     => '',
    ] ) );
    register_post_meta( 'article', 'article_url', array_merge( $article, [
        'type'        => 'string',
        'description' => 'Original article URL',
        'default'     => '',
    ] ) );
    register_post_meta( 'article', 'bias_score', array_merge( $article, [
        'type'        => 'integer',
        'description' => 'Bias score from -10 (left) to +10 (right)',
        'default'     => 0,
    ] ) );
    register_post_meta( 'article', 'sentiment', array_merge( $article, [
        'type'        => 'string',
        'description' => 'Sentiment: positive | neutral | negative',
        'default'     => 'neutral',
    ] ) );

    // -- Social Post meta --------------------------------------------------
    $social = array_merge( $shared, [ 'object_subtype' => 'social_post' ] );

    register_post_meta( 'social_post', 'platform', array_merge( $social, [
        'type'        => 'string',
        'description' => 'Platform: twitter | facebook | instagram | tiktok | telegram',
        'default'     => 'twitter',
    ] ) );
    register_post_meta( 'social_post', 'author_handle', array_merge( $social, [
        'type'        => 'string',
        'description' => 'Author handle or display name',
        'default'     => '',
    ] ) );
    register_post_meta( 'social_post', 'post_url', array_merge( $social, [
        'type'        => 'string',
        'description' => 'Direct URL to the social media post',
        'default'     => '',
    ] ) );
    register_post_meta( 'social_post', 'engagement', array_merge( $social, [
        'type'        => 'integer',
        'description' => 'Combined likes + shares count',
        'default'     => 0,
    ] ) );
}
add_action( 'init', 'mamboleo_register_meta' );

