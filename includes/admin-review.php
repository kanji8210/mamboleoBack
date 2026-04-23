<?php
/**
 * Mamboleo Admin — Review Queue
 *
 * Surfaces scraped incidents the classifier flagged as uncertain
 * (low confidence or imprecise location). Admins can approve, edit,
 * or reject each entry before it appears on the public map.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Menu registration ─────────────────────────────────────────────────────────
add_action( 'admin_menu', function () {
    // Parent menu is registered in admin-scraper.php; guard in case order changes.
    $parent = isset( $GLOBALS['admin_page_hooks']['mamboleo-main'] ) ? 'mamboleo-main' : null;
    if ( ! $parent ) {
        add_menu_page( 'Mamboleo', 'Mamboleo', 'manage_options', 'mamboleo-main', '__return_null', 'dashicons-shield-alt', 80 );
        $parent = 'mamboleo-main';
    }

    $count = mamboleo_pending_review_count();
    $label = $count > 0
        ? sprintf( 'Review Queue <span class="awaiting-mod count-%d"><span class="pending-count">%d</span></span>', $count, $count )
        : 'Review Queue';

    add_submenu_page(
        $parent,
        'Review Queue',
        $label,
        'manage_options',
        'mamboleo-review-queue',
        'mamboleo_review_queue_page',
        2
    );
}, 20 );

// ── Pending count helper ──────────────────────────────────────────────────────
function mamboleo_pending_review_count(): int {
    $q = new WP_Query( [
        'post_type'      => 'incident',
        'post_status'    => 'pending',
        'meta_key'       => 'needs_review',
        'meta_value'     => 1,
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'no_found_rows'  => false,
    ] );
    return (int) $q->found_posts;
}

// ── Action handlers (approve / reject / bulk) ─────────────────────────────────
add_action( 'admin_init', 'mamboleo_handle_review_actions' );
function mamboleo_handle_review_actions(): void {
    if ( empty( $_REQUEST['mamboleo_review_action'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    check_admin_referer( 'mamboleo_review' );

    $action = sanitize_key( $_REQUEST['mamboleo_review_action'] );
    $ids    = array_map( 'absint', (array) ( $_REQUEST['incident_ids'] ?? [] ) );
    $ids    = array_filter( $ids );

    // Single-action convenience: ?incident_id=123
    if ( ! $ids && ! empty( $_REQUEST['incident_id'] ) ) {
        $ids = [ absint( $_REQUEST['incident_id'] ) ];
    }

    $processed = 0;
    foreach ( $ids as $id ) {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'incident' ) continue;

        if ( $action === 'approve' ) {
            wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
            update_post_meta( $id, 'is_verified',  true );
            update_post_meta( $id, 'needs_review', 0 );
            update_post_meta( $id, 'reviewed_by', get_current_user_id() );
            update_post_meta( $id, 'reviewed_at', current_time( 'mysql' ) );
            $processed++;
        } elseif ( $action === 'reject' ) {
            wp_trash_post( $id );
            $processed++;
        }
    }

    $redirect = add_query_arg(
        [ 'page' => 'mamboleo-review-queue', 'processed' => $processed, 'action_done' => $action ],
        admin_url( 'admin.php' )
    );
    wp_safe_redirect( $redirect );
    exit;
}

// ── Render the page ───────────────────────────────────────────────────────────
function mamboleo_review_queue_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

    $q = new WP_Query( [
        'post_type'      => 'incident',
        'post_status'    => 'pending',
        'meta_key'       => 'needs_review',
        'meta_value'     => 1,
        'posts_per_page' => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    $nonce = wp_create_nonce( 'mamboleo_review' );
    ?>
    <div class="wrap">
        <h1>Mamboleo Review Queue</h1>

        <?php if ( isset( $_GET['processed'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    printf(
                        '%d incident(s) %s.',
                        (int) $_GET['processed'],
                        $_GET['action_done'] === 'approve' ? 'approved and published' : 'rejected'
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <p class="description">
            These incidents were scraped but flagged as uncertain (low classification
            confidence or imprecise location). Approve to publish on the public map,
            edit to correct details, or reject to discard.
        </p>

        <?php if ( ! $q->have_posts() ) : ?>
            <div class="notice notice-info"><p><strong>Queue is empty.</strong> No incidents waiting for review.</p></div>
            <?php return; ?>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=mamboleo-review-queue' ) ); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">

            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <select name="mamboleo_review_action">
                        <option value="">Bulk actions</option>
                        <option value="approve">Approve</option>
                        <option value="reject">Reject</option>
                    </select>
                    <button type="submit" class="button action">Apply</button>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <input type="checkbox" onclick="jQuery('input[name=\'incident_ids[]\']').prop('checked', this.checked)">
                        </td>
                        <th style="width:28%">Title</th>
                        <th style="width:10%">Type</th>
                        <th style="width:8%">Confidence</th>
                        <th style="width:16%">Location</th>
                        <th style="width:18%">Reason</th>
                        <th style="width:10%">Source</th>
                        <th style="width:10%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ( $q->have_posts() ) : $q->the_post(); $id = get_the_ID(); ?>
                    <?php
                    $type       = get_post_meta( $id, 'type', true ) ?: '—';
                    $conf       = (float) get_post_meta( $id, 'classification_confidence', true );
                    $loc_name   = get_post_meta( $id, 'location_name', true ) ?: '—';
                    $lat        = get_post_meta( $id, 'latitude', true );
                    $lng        = get_post_meta( $id, 'longitude', true );
                    $reason     = get_post_meta( $id, 'review_reason', true ) ?: '—';
                    $source     = get_post_meta( $id, 'reporter_name', true ) ?: '—';
                    $article    = get_post_meta( $id, 'article_url', true );
                    $conf_pct   = (int) round( $conf * 100 );
                    $conf_color = $conf >= 0.4 ? '#46b450' : ( $conf >= 0.3 ? '#ffb900' : '#dc3232' );
                    ?>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox" name="incident_ids[]" value="<?php echo esc_attr( $id ); ?>">
                        </th>
                        <td>
                            <strong><?php echo esc_html( get_the_title() ); ?></strong><br>
                            <?php if ( $article ) : ?>
                                <a href="<?php echo esc_url( $article ); ?>" target="_blank" rel="noopener">View original ↗</a>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html( $type ); ?></code></td>
                        <td>
                            <span style="display:inline-block;min-width:40px;padding:2px 6px;background:<?php echo esc_attr( $conf_color ); ?>;color:#fff;border-radius:3px;font-size:11px;text-align:center">
                                <?php echo esc_html( $conf_pct ); ?>%
                            </span>
                        </td>
                        <td>
                            <?php echo esc_html( $loc_name ); ?>
                            <?php if ( $lat && $lng ) : ?>
                                <br>
                                <a href="https://www.google.com/maps/search/?api=1&query=<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>" target="_blank" rel="noopener" style="font-size:11px">
                                    <?php echo esc_html( number_format( (float) $lat, 4 ) ); ?>, <?php echo esc_html( number_format( (float) $lng, 4 ) ); ?> ↗
                                </a>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:#666"><?php echo esc_html( $reason ); ?></td>
                        <td><?php echo esc_html( $source ); ?></td>
                        <td>
                            <a class="button button-primary button-small"
                               href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => 'mamboleo-review-queue', 'mamboleo_review_action' => 'approve', 'incident_id' => $id ], admin_url( 'admin.php' ) ), 'mamboleo_review' ) ); ?>">
                                Approve
                            </a>
                            <a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">Edit</a>
                            <a class="button button-small button-link-delete"
                               onclick="return confirm('Reject and trash this incident?')"
                               href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => 'mamboleo-review-queue', 'mamboleo_review_action' => 'reject', 'incident_id' => $id ], admin_url( 'admin.php' ) ), 'mamboleo_review' ) ); ?>">
                                Reject
                            </a>
                        </td>
                    </tr>
                <?php endwhile; wp_reset_postdata(); ?>
                </tbody>
            </table>
        </form>
    </div>
    <?php
}

// ── Dashboard "at a glance" nudge ─────────────────────────────────────────────
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $screen = get_current_screen();
    // Only show on the main dashboard, not on every admin page.
    if ( ! $screen || $screen->id !== 'dashboard' ) return;

    $count = mamboleo_pending_review_count();
    if ( $count < 1 ) return;

    $url = admin_url( 'admin.php?page=mamboleo-review-queue' );
    printf(
        '<div class="notice notice-warning"><p><strong>Mamboleo:</strong> %d scraped incident%s waiting for review. <a href="%s">Open Review Queue →</a></p></div>',
        (int) $count,
        $count === 1 ? '' : 's',
        esc_url( $url )
    );
} );
