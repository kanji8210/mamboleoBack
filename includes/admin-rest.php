<?php
/**
 * Frontend admin REST endpoints.
 *
 * Uses normal WordPress auth cookies plus a REST nonce so the React frontend
 * can manage incidents without leaving the public app shell.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function mamboleo_admin_rest_allowed_types(): array {
    return [ 'fire', 'accident', 'police', 'weather', 'protest', 'flood', 'medical', 'military', 'info', 'health', 'environmental', 'homicide', 'femicide' ];
}

function mamboleo_admin_rest_allowed_severities(): array {
    return [ 'low', 'medium', 'high' ];
}

function mamboleo_admin_rest_allowed_statuses(): array {
    return [ 'unsafe', 'all_clear', 'police_operating', 'police_aggressive', 'unknown' ];
}

function mamboleo_admin_rest_allowed_lifecycles(): array {
    return [ 'active', 'developing', 'resolved', 'archived' ];
}

function mamboleo_admin_rest_allowed_location_precisions(): array {
    return [ 'exact', 'subcounty', 'county', 'country' ];
}

function mamboleo_admin_rest_user_payload( WP_User $user ): array {
    return [
        'id'       => (int) $user->ID,
        'username' => (string) $user->user_login,
        'name'     => (string) $user->display_name,
        'email'    => (string) $user->user_email,
    ];
}

function mamboleo_admin_rest_token_key( string $token ): string {
    return 'mamboleo_admin_token_' . md5( $token );
}

function mamboleo_admin_rest_token_from_request( WP_REST_Request $request ): string {
    $token = trim( (string) $request->get_header( 'X-Mamboleo-Admin-Token' ) );
    if ( $token !== '' ) {
        return $token;
    }

    $auth = trim( (string) $request->get_header( 'Authorization' ) );
    if ( preg_match( '/Bearer\s+(.+)/i', $auth, $matches ) ) {
        return trim( (string) $matches[1] );
    }

    return '';
}

function mamboleo_admin_rest_store_token( int $user_id, bool $remember ): string {
    $token = wp_generate_password( 64, false, false );
    $ttl   = $remember ? ( 30 * DAY_IN_SECONDS ) : DAY_IN_SECONDS;
    set_transient( mamboleo_admin_rest_token_key( $token ), $user_id, $ttl );
    return $token;
}

function mamboleo_admin_rest_clear_token( string $token ): void {
    if ( $token === '' ) {
        return;
    }
    delete_transient( mamboleo_admin_rest_token_key( $token ) );
}

function mamboleo_admin_rest_token_user( WP_REST_Request $request ): ?WP_User {
    $token = mamboleo_admin_rest_token_from_request( $request );
    if ( $token === '' ) {
        return null;
    }

    $user_id = (int) get_transient( mamboleo_admin_rest_token_key( $token ) );
    if ( $user_id <= 0 ) {
        return null;
    }

    $user = get_user_by( 'id', $user_id );
    if ( ! $user instanceof WP_User || ! $user->exists() || ! user_can( $user, 'manage_options' ) ) {
        return null;
    }

    return $user;
}

function mamboleo_admin_rest_session_payload( ?WP_User $user = null, string $token = '' ): array {
    $user = $user ?: wp_get_current_user();
    $authenticated = $user instanceof WP_User && $user->exists();
    $authorized    = $authenticated && user_can( $user, 'manage_options' );

    return [
        'authenticated' => $authenticated,
        'authorized'    => $authorized,
        'nonce'         => $authorized ? wp_create_nonce( 'wp_rest' ) : '',
        'token'         => $authorized ? $token : '',
        'authMode'      => $authorized ? ( $token !== '' ? 'token' : 'cookie' ) : 'none',
        'user'          => $authorized ? mamboleo_admin_rest_user_payload( $user ) : null,
    ];
}

function mamboleo_admin_rest_require_manager( WP_REST_Request $request ): bool {
    if ( current_user_can( 'manage_options' ) ) {
        return true;
    }

    $token_user = mamboleo_admin_rest_token_user( $request );
    if ( $token_user ) {
        wp_set_current_user( (int) $token_user->ID );
        return true;
    }

    return false;
}

function mamboleo_admin_rest_request_params( WP_REST_Request $request ): array {
    $json = $request->get_json_params();
    if ( is_array( $json ) && $json ) {
        return $json;
    }

    $params = $request->get_params();
    return is_array( $params ) ? $params : [];
}

function mamboleo_admin_rest_read_log_tail( string $path, int $bytes = 12000 ): array {
    if ( ! file_exists( $path ) ) {
        return [
            'exists' => false,
            'tail'   => '',
            'done'   => true,
        ];
    }

    $size   = (int) filesize( $path );
    $offset = max( 0, $size - $bytes );
    $fh     = fopen( $path, 'rb' );
    if ( ! $fh ) {
        return [
            'exists' => true,
            'tail'   => '',
            'done'   => true,
        ];
    }

    if ( $offset > 0 ) {
        fseek( $fh, $offset );
    }

    $tail = stream_get_contents( $fh ) ?: '';
    fclose( $fh );

    return [
        'exists' => true,
        'tail'   => $tail,
        'done'   => strpos( $tail, '--- Done' ) !== false || strpos( $tail, 'Traceback' ) !== false,
    ];
}

function mamboleo_admin_rest_update_payload( WP_Post $post ): array {
    return [
        'id'           => (int) $post->ID,
        'incidentId'   => (int) $post->post_parent,
        'title'        => (string) $post->post_title,
        'body'         => wp_strip_all_tags( (string) $post->post_content ),
        'status'       => (string) $post->post_status,
        'createdAt'    => mysql2date( 'c', $post->post_date_gmt, false ),
        'source'       => (string) get_post_meta( $post->ID, 'source', true ),
        'reporter'     => (string) get_post_meta( $post->ID, 'reporter', true ),
        'incidentTitle'=> (string) get_the_title( (int) $post->post_parent ),
    ];
}

function mamboleo_admin_rest_incident_payload( WP_Post $post, bool $with_updates = false ): array {
    $id = (int) $post->ID;

    $payload = [
        'id'                 => $id,
        'title'              => (string) $post->post_title,
        'content'            => (string) $post->post_content,
        'excerpt'            => (string) $post->post_excerpt,
        'status'             => (string) $post->post_status,
        'date'               => mysql2date( 'c', $post->post_date_gmt, false ),
        'modified'           => mysql2date( 'c', $post->post_modified_gmt, false ),
        'type'               => (string) get_post_meta( $id, 'type', true ),
        'severity'           => (string) get_post_meta( $id, 'severity', true ),
        'incidentStatus'     => (string) get_post_meta( $id, 'status', true ),
        'incidentTime'       => (string) get_post_meta( $id, 'incident_time', true ),
        'videoUrl'           => (string) get_post_meta( $id, 'video_url', true ),
        'latitude'           => (float) get_post_meta( $id, 'latitude', true ),
        'longitude'          => (float) get_post_meta( $id, 'longitude', true ),
        'locationName'       => (string) get_post_meta( $id, 'location_name', true ),
        'locationCountry'    => (string) get_post_meta( $id, 'location_country', true ),
        'locationCounty'     => (string) get_post_meta( $id, 'location_county', true ),
        'locationSubcounty'  => (string) get_post_meta( $id, 'location_subcounty', true ),
        'locationPrecision'  => (string) get_post_meta( $id, 'location_precision', true ),
        'lifecycle'          => (string) get_post_meta( $id, 'lifecycle', true ),
        'needsReview'        => (bool) get_post_meta( $id, 'needs_review', true ),
        'isVerified'         => (bool) get_post_meta( $id, 'is_verified', true ),
        'corroborationCount' => (int) get_post_meta( $id, 'corroboration_count', true ),
        'updateCount'        => (int) get_post_meta( $id, 'update_count', true ),
        'lastUpdateAt'       => (string) get_post_meta( $id, 'last_update_at', true ),
        'expiresAt'          => (string) get_post_meta( $id, 'expires_at', true ),
        'reporterName'       => (string) get_post_meta( $id, 'reporter_name', true ),
        'articleUrl'         => (string) get_post_meta( $id, 'article_url', true ),
        'reviewReason'       => (string) get_post_meta( $id, 'review_reason', true ),
        'classificationConfidence' => (float) get_post_meta( $id, 'classification_confidence', true ),
        'aiModel'            => (string) get_post_meta( $id, 'ai_model', true ),
        'aiSummary'          => (string) get_post_meta( $id, 'ai_summary', true ),
        'aiFlags'            => (string) get_post_meta( $id, 'ai_flags', true ),
        'needsReanalysis'    => (bool) get_post_meta( $id, 'needs_reanalysis', true ),
    ];

    if ( ! $with_updates || ! defined( 'MAMBOLEO_UPDATE_CPT' ) ) {
        return $payload;
    }

    $updates = new WP_Query( [
        'post_type'      => MAMBOLEO_UPDATE_CPT,
        'post_parent'    => $id,
        'post_status'    => [ 'publish', 'pending' ],
        'posts_per_page' => 10,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ] );

    $payload['updates'] = array_map( 'mamboleo_admin_rest_update_payload', $updates->posts );

    return $payload;
}

function mamboleo_admin_rest_get_incident( int $id ): WP_Post|WP_Error {
    $post = get_post( $id );
    if ( ! $post || $post->post_type !== 'incident' ) {
        return new WP_Error( 'not_found', 'Incident not found.', [ 'status' => 404 ] );
    }

    return $post;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'mamboleo/v1', '/admin/session', [
        [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function ( WP_REST_Request $request ) {
                $token_user = mamboleo_admin_rest_token_user( $request );
                if ( $token_user ) {
                    wp_set_current_user( (int) $token_user->ID );
                    return mamboleo_admin_rest_session_payload( $token_user, mamboleo_admin_rest_token_from_request( $request ) );
                }

                return mamboleo_admin_rest_session_payload();
            },
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => function ( WP_REST_Request $request ) {
                $params = mamboleo_admin_rest_request_params( $request );
                $login  = sanitize_text_field( (string) ( $params['username'] ?? '' ) );
                $pass   = (string) ( $params['password'] ?? '' );

                if ( $login === '' || $pass === '' ) {
                    return new WP_Error( 'missing_credentials', 'Username and password are required.', [ 'status' => 400 ] );
                }

                $user = wp_signon( [
                    'user_login'    => $login,
                    'user_password' => $pass,
                    'remember'      => ! empty( $params['remember'] ),
                ], is_ssl() );

                if ( is_wp_error( $user ) ) {
                    return new WP_Error( 'invalid_login', 'Invalid admin credentials.', [ 'status' => 403 ] );
                }

                wp_set_current_user( $user->ID );
                wp_set_auth_cookie( $user->ID, ! empty( $params['remember'] ), is_ssl() );

                if ( ! user_can( $user, 'manage_options' ) ) {
                    wp_logout();
                    return new WP_Error( 'forbidden', 'This account does not have admin access.', [ 'status' => 403 ] );
                }

                $token = mamboleo_admin_rest_store_token( (int) $user->ID, ! empty( $params['remember'] ) );
                return mamboleo_admin_rest_session_payload( $user, $token );
            },
        ],
        [
            'methods'             => 'DELETE',
            'permission_callback' => '__return_true',
            'callback'            => function ( WP_REST_Request $request ) {
                mamboleo_admin_rest_clear_token( mamboleo_admin_rest_token_from_request( $request ) );
                if ( is_user_logged_in() ) {
                    wp_logout();
                }
                return [ 'ok' => true ];
            },
        ],
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/dashboard', [
        'methods'             => 'GET',
        'permission_callback' => 'mamboleo_admin_rest_require_manager',
        'callback'            => function () {
            $incident_counts = wp_count_posts( 'incident' );
            $update_counts   = defined( 'MAMBOLEO_UPDATE_CPT' ) ? wp_count_posts( MAMBOLEO_UPDATE_CPT ) : null;
            $log             = function_exists( 'mamboleo_get_log_path' )
                ? mamboleo_admin_rest_read_log_tail( mamboleo_get_log_path() )
                : [ 'exists' => false, 'tail' => '', 'done' => true ];

            return [
                'counts' => [
                    'publishedIncidents' => (int) ( $incident_counts->publish ?? 0 ),
                    'pendingIncidents'   => (int) ( $incident_counts->pending ?? 0 ),
                    'draftIncidents'     => (int) ( $incident_counts->draft ?? 0 ),
                    'reviewQueue'        => function_exists( 'mamboleo_pending_review_count' ) ? mamboleo_pending_review_count() : 0,
                    'pendingUpdates'     => (int) ( $update_counts->pending ?? 0 ),
                    'publishedUpdates'   => (int) ( $update_counts->publish ?? 0 ),
                    'expiringSoon'       => function_exists( 'mamboleo_get_expiring_incidents' ) ? count( mamboleo_get_expiring_incidents( 24, 100 ) ) : 0,
                ],
                'scraper' => $log,
                'session' => mamboleo_admin_rest_session_payload(),
            ];
        },
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/incidents', [
        'methods'             => 'GET',
        'permission_callback' => 'mamboleo_admin_rest_require_manager',
        'callback'            => function ( WP_REST_Request $request ) {
            $scope        = sanitize_key( (string) ( $request->get_param( 'scope' ) ?: 'active' ) );
            $status       = sanitize_key( (string) $request->get_param( 'status' ) );
            $search       = sanitize_text_field( (string) $request->get_param( 'search' ) );
            $needs_review = $request->get_param( 'needsReview' );
            $page         = max( 1, (int) $request->get_param( 'page' ) );
            $per_page     = min( 50, max( 1, (int) ( $request->get_param( 'perPage' ) ?: 20 ) ) );

            $post_status = [ 'publish', 'pending', 'draft' ];
            $meta_query   = [];

            if ( $scope === 'pending' ) {
                $post_status = 'pending';
            } elseif ( $scope === 'archived' ) {
                $meta_query[] = [
                    'key'     => 'lifecycle',
                    'value'   => 'archived',
                    'compare' => '=',
                ];
            } else {
                $post_status = 'publish';
                $meta_query[] = [
                    'relation' => 'OR',
                    [
                        'key'     => 'lifecycle',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => 'lifecycle',
                        'value'   => 'archived',
                        'compare' => '!=',
                    ],
                ];
            }

            if ( in_array( $status, [ 'publish', 'pending', 'draft' ], true ) ) {
                $post_status = $status;
            }

            $args = [
                'post_type'      => 'incident',
                'post_status'    => $post_status,
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ];

            if ( $search !== '' ) {
                $args['s'] = $search;
            }

            if ( $needs_review !== null && $needs_review !== '' ) {
                $meta_query[] = [
                    [
                        'key'     => 'needs_review',
                        'value'   => rest_sanitize_boolean( $needs_review ) ? '1' : '0',
                        'compare' => '=',
                    ],
                ];
            }

            if ( ! empty( $meta_query ) ) {
                $args['meta_query'] = array_merge( [ 'relation' => 'AND' ], $meta_query );
            }

            $query = new WP_Query( $args );

            return [
                'items' => array_map( static function ( $post ) {
                    return mamboleo_admin_rest_incident_payload( $post, false );
                }, $query->posts ),
                'pagination' => [
                    'page'       => $page,
                    'perPage'    => $per_page,
                    'total'      => (int) $query->found_posts,
                    'totalPages' => (int) $query->max_num_pages,
                ],
            ];
        },
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/incidents/(?P<id>\d+)', [
        [
            'methods'             => 'GET',
            'permission_callback' => 'mamboleo_admin_rest_require_manager',
            'callback'            => function ( WP_REST_Request $request ) {
                $post = mamboleo_admin_rest_get_incident( (int) $request['id'] );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }

                return mamboleo_admin_rest_incident_payload( $post, true );
            },
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => 'mamboleo_admin_rest_require_manager',
            'callback'            => function ( WP_REST_Request $request ) {
                $post = mamboleo_admin_rest_get_incident( (int) $request['id'] );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }

                $params      = mamboleo_admin_rest_request_params( $request );
                $post_update = [ 'ID' => $post->ID ];

                if ( array_key_exists( 'title', $params ) ) {
                    $post_update['post_title'] = sanitize_text_field( (string) $params['title'] );
                }
                if ( array_key_exists( 'content', $params ) ) {
                    $post_update['post_content'] = wp_kses_post( (string) $params['content'] );
                }
                if ( array_key_exists( 'excerpt', $params ) ) {
                    $post_update['post_excerpt'] = sanitize_textarea_field( (string) $params['excerpt'] );
                }
                if ( array_key_exists( 'status', $params ) ) {
                    $status = sanitize_key( (string) $params['status'] );
                    if ( ! in_array( $status, [ 'draft', 'pending', 'publish' ], true ) ) {
                        return new WP_Error( 'invalid_status', 'Invalid post status.', [ 'status' => 400 ] );
                    }
                    $post_update['post_status'] = $status;
                }

                if ( count( $post_update ) > 1 ) {
                    $result = wp_update_post( $post_update, true );
                    if ( is_wp_error( $result ) ) {
                        return $result;
                    }
                }

                $string_meta = [
                    'type'              => [ 'key' => 'type', 'allowed' => mamboleo_admin_rest_allowed_types() ],
                    'severity'          => [ 'key' => 'severity', 'allowed' => mamboleo_admin_rest_allowed_severities() ],
                    'incidentStatus'    => [ 'key' => 'status', 'allowed' => mamboleo_admin_rest_allowed_statuses() ],
                    'incidentTime'      => [ 'key' => 'incident_time' ],
                    'videoUrl'          => [ 'key' => 'video_url' ],
                    'locationName'      => [ 'key' => 'location_name' ],
                    'locationCountry'   => [ 'key' => 'location_country' ],
                    'locationCounty'    => [ 'key' => 'location_county' ],
                    'locationSubcounty' => [ 'key' => 'location_subcounty' ],
                    'locationPrecision' => [ 'key' => 'location_precision', 'allowed' => mamboleo_admin_rest_allowed_location_precisions() ],
                    'lifecycle'         => [ 'key' => 'lifecycle', 'allowed' => mamboleo_admin_rest_allowed_lifecycles() ],
                    'reporterName'      => [ 'key' => 'reporter_name' ],
                    'reviewReason'      => [ 'key' => 'review_reason' ],
                ];

                foreach ( $string_meta as $field => $config ) {
                    if ( ! array_key_exists( $field, $params ) ) {
                        continue;
                    }

                    $value = (string) $params[ $field ];
                    if ( $config['key'] === 'video_url' ) {
                        $value = esc_url_raw( $value );
                    } elseif ( str_starts_with( $config['key'], 'location_' ) ) {
                        $value = sanitize_title( $value );
                    } else {
                        $value = sanitize_text_field( $value );
                    }

                    if ( ! empty( $config['allowed'] ) && ! in_array( $value, $config['allowed'], true ) ) {
                        return new WP_Error( 'invalid_field', 'Invalid value for ' . $field . '.', [ 'status' => 400 ] );
                    }

                    update_post_meta( $post->ID, $config['key'], $value );
                }

                if ( array_key_exists( 'latitude', $params ) ) {
                    $lat = filter_var( $params['latitude'], FILTER_VALIDATE_FLOAT );
                    if ( $lat === false || $lat < -90 || $lat > 90 ) {
                        return new WP_Error( 'invalid_latitude', 'Latitude must be between -90 and 90.', [ 'status' => 400 ] );
                    }
                    update_post_meta( $post->ID, 'latitude', (float) $lat );
                }

                if ( array_key_exists( 'longitude', $params ) ) {
                    $lng = filter_var( $params['longitude'], FILTER_VALIDATE_FLOAT );
                    if ( $lng === false || $lng < -180 || $lng > 180 ) {
                        return new WP_Error( 'invalid_longitude', 'Longitude must be between -180 and 180.', [ 'status' => 400 ] );
                    }
                    update_post_meta( $post->ID, 'longitude', (float) $lng );
                }

                if ( array_key_exists( 'needsReview', $params ) ) {
                    update_post_meta( $post->ID, 'needs_review', rest_sanitize_boolean( $params['needsReview'] ) ? 1 : 0 );
                }
                if ( array_key_exists( 'isVerified', $params ) ) {
                    update_post_meta( $post->ID, 'is_verified', rest_sanitize_boolean( $params['isVerified'] ) ? 1 : 0 );
                }

                clean_post_cache( $post->ID );
                $fresh = get_post( $post->ID );
                return $fresh ? mamboleo_admin_rest_incident_payload( $fresh, true ) : new WP_Error( 'not_found', 'Incident not found after save.', [ 'status' => 404 ] );
            },
        ],
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/incidents/(?P<id>\d+)/review', [
        'methods'             => 'POST',
        'permission_callback' => 'mamboleo_admin_rest_require_manager',
        'callback'            => function ( WP_REST_Request $request ) {
            $post = mamboleo_admin_rest_get_incident( (int) $request['id'] );
            if ( is_wp_error( $post ) ) {
                return $post;
            }

            $params = mamboleo_admin_rest_request_params( $request );
            $action = sanitize_key( (string) ( $params['action'] ?? '' ) );

            if ( $action === 'approve' ) {
                wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'publish' ] );
                update_post_meta( $post->ID, 'is_verified', 1 );
                update_post_meta( $post->ID, 'needs_review', 0 );
                update_post_meta( $post->ID, 'reviewed_by', get_current_user_id() );
                update_post_meta( $post->ID, 'reviewed_at', current_time( 'mysql' ) );
                return [ 'ok' => true, 'status' => 'publish' ];
            }

            if ( $action === 'reject' ) {
                wp_trash_post( $post->ID );
                return [ 'ok' => true, 'status' => 'trash' ];
            }

            return new WP_Error( 'invalid_action', 'Review action must be approve or reject.', [ 'status' => 400 ] );
        },
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/incidents/(?P<id>\d+)/updates', [
        'methods'             => 'POST',
        'permission_callback' => 'mamboleo_admin_rest_require_manager',
        'callback'            => function ( WP_REST_Request $request ) {
            $post = mamboleo_admin_rest_get_incident( (int) $request['id'] );
            if ( is_wp_error( $post ) ) {
                return $post;
            }

            $params    = mamboleo_admin_rest_request_params( $request );
            $update_id = mamboleo_create_update( $post->ID, [
                'body'         => (string) ( $params['body'] ?? '' ),
                'source'       => sanitize_text_field( (string) ( $params['source'] ?? 'admin' ) ),
                'reporter'     => sanitize_text_field( (string) ( $params['reporter'] ?? '' ) ),
                'auto_approve' => true,
                'author_id'    => get_current_user_id(),
            ] );

            if ( is_wp_error( $update_id ) ) {
                return $update_id;
            }

            $created = get_post( $update_id );
            return [
                'ok'     => true,
                'update' => $created ? mamboleo_admin_rest_update_payload( $created ) : null,
            ];
        },
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/incidents/(?P<id>\d+)/extend', [
        'methods'             => 'POST',
        'permission_callback' => 'mamboleo_admin_rest_require_manager',
        'callback'            => function ( WP_REST_Request $request ) {
            $post = mamboleo_admin_rest_get_incident( (int) $request['id'] );
            if ( is_wp_error( $post ) ) {
                return $post;
            }

            $params = mamboleo_admin_rest_request_params( $request );
            $body   = sanitize_textarea_field( (string) ( $params['body'] ?? '' ) );

            if ( $body !== '' ) {
                $update_id = mamboleo_create_update( $post->ID, [
                    'body'         => $body,
                    'source'       => 'admin',
                    'auto_approve' => true,
                    'author_id'    => get_current_user_id(),
                ] );
                if ( is_wp_error( $update_id ) ) {
                    return $update_id;
                }
            } else {
                mamboleo_apply_update_to_incident( 0, $post->ID );
            }

            return [ 'ok' => true ];
        },
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/incidents/(?P<id>\d+)/reanalyse', [
        'methods'             => 'POST',
        'permission_callback' => 'mamboleo_admin_rest_require_manager',
        'callback'            => function ( WP_REST_Request $request ) {
            $post = mamboleo_admin_rest_get_incident( (int) $request['id'] );
            if ( is_wp_error( $post ) ) {
                return $post;
            }

            delete_post_meta( $post->ID, 'ai_model' );
            delete_post_meta( $post->ID, 'ai_processed_at' );
            update_post_meta( $post->ID, 'needs_reanalysis', 1 );

            return [ 'ok' => true ];
        },
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/updates', [
        'methods'             => 'GET',
        'permission_callback' => 'mamboleo_admin_rest_require_manager',
        'callback'            => function ( WP_REST_Request $request ) {
            if ( ! defined( 'MAMBOLEO_UPDATE_CPT' ) ) {
                return [ 'items' => [] ];
            }

            $status = sanitize_key( (string) ( $request->get_param( 'status' ) ?: 'pending' ) );
            if ( ! in_array( $status, [ 'pending', 'publish', 'trash' ], true ) ) {
                $status = 'pending';
            }

            $query = new WP_Query( [
                'post_type'      => MAMBOLEO_UPDATE_CPT,
                'post_status'    => $status,
                'posts_per_page' => 50,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ] );

            return [
                'items' => array_map( 'mamboleo_admin_rest_update_payload', $query->posts ),
            ];
        },
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/updates/(?P<id>\d+)/moderate', [
        'methods'             => 'POST',
        'permission_callback' => 'mamboleo_admin_rest_require_manager',
        'callback'            => function ( WP_REST_Request $request ) {
            $id   = (int) $request['id'];
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== MAMBOLEO_UPDATE_CPT ) {
                return new WP_Error( 'not_found', 'Incident update not found.', [ 'status' => 404 ] );
            }

            $params = mamboleo_admin_rest_request_params( $request );
            $action = sanitize_key( (string) ( $params['action'] ?? '' ) );
            if ( $action === 'approve' ) {
                wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
                return [ 'ok' => true, 'status' => 'publish' ];
            }
            if ( $action === 'reject' ) {
                wp_trash_post( $id );
                return [ 'ok' => true, 'status' => 'trash' ];
            }

            return new WP_Error( 'invalid_action', 'Update action must be approve or reject.', [ 'status' => 400 ] );
        },
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/expiring', [
        'methods'             => 'GET',
        'permission_callback' => 'mamboleo_admin_rest_require_manager',
        'callback'            => function () {
            if ( ! function_exists( 'mamboleo_get_expiring_incidents' ) ) {
                return [ 'items' => [] ];
            }

            $items = mamboleo_get_expiring_incidents( 24, 100 );
            return [
                'items' => array_map( static function ( $post ) {
                    $payload = mamboleo_admin_rest_incident_payload( $post, false );
                    $payload['expiresAt'] = (string) get_post_meta( $post->ID, 'expires_at', true );
                    return $payload;
                }, $items ),
            ];
        },
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/scraper/run', [
        'methods'             => 'POST',
        'permission_callback' => 'mamboleo_admin_rest_require_manager',
        'callback'            => function () {
            if ( ! function_exists( 'mamboleo_get_log_path' ) || ! function_exists( 'mamboleo_spawn_background' ) || ! function_exists( 'mamboleo_python_cmd' ) ) {
                return new WP_Error( 'unavailable', 'Scraper controls are not available.', [ 'status' => 500 ] );
            }

            $scraper_dir = MAMBOLEO_PLUGIN_DIR . 'scraper';
            $log_file    = mamboleo_get_log_path();
            $python      = mamboleo_python_cmd();

            file_put_contents( $log_file, "--- Initialization ---\n[" . gmdate( 'H:i:s' ) . "] Starting scraper\n" );

            if ( PHP_OS_FAMILY === 'Windows' ) {
                $cmd = sprintf( '%s -u run_all_scrapers.py & echo --- Done (exit=%%errorlevel%%) ---', $python );
            } else {
                $cmd = sprintf( '%s -u run_all_scrapers.py; echo "--- Done (exit=$?) ---"', $python );
            }

            mamboleo_spawn_background( $scraper_dir, $cmd, $log_file );

            return [
                'ok'      => true,
                'message' => 'Scraper started.',
                'scraper' => mamboleo_admin_rest_read_log_tail( $log_file ),
            ];
        },
    ] );

    register_rest_route( 'mamboleo/v1', '/admin/scraper/log', [
        'methods'             => 'GET',
        'permission_callback' => 'mamboleo_admin_rest_require_manager',
        'callback'            => function () {
            if ( ! function_exists( 'mamboleo_get_log_path' ) ) {
                return [ 'exists' => false, 'tail' => '', 'done' => true ];
            }

            return mamboleo_admin_rest_read_log_tail( mamboleo_get_log_path() );
        },
    ] );
} );
