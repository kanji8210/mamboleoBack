<?php
/**
 * Mamboleo Admin — Updates Moderation + Expiring Soon screens.
 *
 * Two related screens combined in one file:
 *   1) "Updates"        — moderate community-submitted incident updates
 *   2) "Expiring Soon"  — incidents within 24h of auto-trash; admin can
 *                          extend lifetime by posting an update.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    if ( ! isset( $GLOBALS['admin_page_hooks']['mamboleo-main'] ) ) return;

    // Pending update count → badge in menu label.
    $pending = (int) ( new WP_Query( [
        'post_type' => MAMBOLEO_UPDATE_CPT, 'post_status' => 'pending',
        'posts_per_page' => 1, 'fields' => 'ids',
    ] ) )->found_posts;
    $label = $pending > 0
        ? sprintf( 'Updates <span class="awaiting-mod count-%d"><span class="pending-count">%d</span></span>', $pending, $pending )
        : 'Updates';

    add_submenu_page( 'mamboleo-main', 'Incident Updates', $label, 'manage_options', 'mamboleo-updates', 'mamboleo_updates_admin_page', 3 );
    add_submenu_page( 'mamboleo-main', 'Expiring Soon',     'Expiring Soon',     'manage_options', 'mamboleo-expiring', 'mamboleo_expiring_admin_page', 5 );
}, 26 );

/* ─────────────────────────  Updates moderation  ─────────────────────── */

add_action( 'admin_post_mamboleo_update_action', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    check_admin_referer( 'mamboleo_update_action' );
    $action = sanitize_key( $_POST['mb_action'] ?? '' );
    $id     = absint( $_POST['update_id'] ?? 0 );
    if ( $id && get_post_type( $id ) === MAMBOLEO_UPDATE_CPT ) {
        if ( $action === 'approve' ) {
            wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
        } elseif ( $action === 'reject' ) {
            wp_trash_post( $id );
        }
    }
    wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=mamboleo-updates' ) );
    exit;
} );

function mamboleo_updates_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $tab = sanitize_key( $_GET['status'] ?? 'pending' );
    $statuses = [ 'pending' => 'Pending', 'publish' => 'Approved', 'trash' => 'Rejected' ];
    $q = new WP_Query( [
        'post_type'      => MAMBOLEO_UPDATE_CPT,
        'post_status'    => $tab,
        'posts_per_page' => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Incident Updates', 'mamboleo' ); ?></h1>
        <p class="description">
            <?php esc_html_e( 'Follow-ups submitted by the public, witnesses, scrapers and admins. Approving an update extends the parent incident\'s 7-day lifetime.', 'mamboleo' ); ?>
        </p>

        <ul class="subsubsub">
            <?php $i = 0; foreach ( $statuses as $k => $label ) :
                $count = (int) ( new WP_Query( [
                    'post_type' => MAMBOLEO_UPDATE_CPT, 'post_status' => $k,
                    'posts_per_page' => 1, 'fields' => 'ids',
                ] ) )->found_posts;
                $url = add_query_arg( [ 'page' => 'mamboleo-updates', 'status' => $k ], admin_url( 'admin.php' ) );
            ?>
                <li><a href="<?php echo esc_url( $url ); ?>" class="<?php echo $tab === $k ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?> <span class="count">(<?php echo $count; ?>)</span></a><?php echo ++$i < count( $statuses ) ? ' |' : ''; ?></li>
            <?php endforeach; ?>
        </ul>

        <table class="widefat striped" style="margin-top:14px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Update', 'mamboleo' ); ?></th>
                    <th><?php esc_html_e( 'Incident', 'mamboleo' ); ?></th>
                    <th><?php esc_html_e( 'Source', 'mamboleo' ); ?></th>
                    <th><?php esc_html_e( 'Submitted', 'mamboleo' ); ?></th>
                    <?php if ( $tab === 'pending' ) : ?>
                        <th><?php esc_html_e( 'Action', 'mamboleo' ); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $q->posts as $u ) :
                    $incident = get_post( $u->post_parent );
                    $source   = get_post_meta( $u->ID, 'source', true ) ?: '—';
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( wp_trim_words( $u->post_title, 12 ) ); ?></strong>
                            <p style="margin:4px 0 0;color:#3c434a;"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $u->post_content ), 40 ) ); ?></p>
                        </td>
                        <td>
                            <?php if ( $incident ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $incident->ID ) ); ?>"><?php echo esc_html( wp_trim_words( $incident->post_title, 8 ) ); ?></a>
                            <?php else : ?>
                                <em>—</em>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html( $source ); ?></code></td>
                        <td><?php echo esc_html( human_time_diff( strtotime( $u->post_date_gmt ), time() ) . ' ago' ); ?></td>
                        <?php if ( $tab === 'pending' ) : ?>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'mamboleo_update_action' ); ?>
                                    <input type="hidden" name="action"    value="mamboleo_update_action" />
                                    <input type="hidden" name="update_id" value="<?php echo (int) $u->ID; ?>" />
                                    <button name="mb_action" value="approve" class="button button-primary button-small"><?php esc_html_e( 'Approve', 'mamboleo' ); ?></button>
                                    <button name="mb_action" value="reject"  class="button button-small" style="margin-left:4px;"><?php esc_html_e( 'Reject',  'mamboleo' ); ?></button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ( ! $q->posts ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'Nothing here.', 'mamboleo' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ─────────────────────────  Expiring Soon  ──────────────────────────── */

add_action( 'admin_post_mamboleo_extend_incident', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    check_admin_referer( 'mamboleo_extend' );
    $id   = absint( $_POST['incident_id'] ?? 0 );
    $body = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
    if ( $id && get_post_type( $id ) === 'incident' ) {
        if ( $body !== '' ) {
            mamboleo_create_update( $id, [
                'body'         => $body,
                'source'       => 'admin',
                'auto_approve' => true,
                'author_id'    => get_current_user_id(),
            ] );
        } else {
            // No new content — just bump the clock.
            mamboleo_apply_update_to_incident( 0, $id );
        }
    }
    wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=mamboleo-expiring' ) );
    exit;
} );

function mamboleo_expiring_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $items = mamboleo_get_expiring_incidents( 24, 100 );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Expiring Soon', 'mamboleo' ); ?></h1>
        <p class="description">
            <?php printf(
                /* translators: %d days */
                esc_html__( 'Incidents auto-archive %d days after their last update. Add a quick note below to confirm relevance and reset the clock.', 'mamboleo' ),
                MAMBOLEO_EXPIRY_DAYS
            ); ?>
        </p>
        <?php if ( ! $items ) : ?>
            <div class="notice notice-success"><p><?php esc_html_e( 'Nothing expiring in the next 24 hours.', 'mamboleo' ); ?></p></div>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Incident', 'mamboleo' ); ?></th>
                    <th style="width:140px;"><?php esc_html_e( 'Expires', 'mamboleo' ); ?></th>
                    <th><?php esc_html_e( 'Quick update (extends 7d)', 'mamboleo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $p ) :
                    $exp_iso = get_post_meta( $p->ID, 'expires_at', true );
                    $exp_ts  = $exp_iso ? strtotime( $exp_iso ) : 0;
                ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( wp_trim_words( $p->post_title, 12 ) ); ?></a></strong>
                            <div style="font-size:11px;color:#646970;">
                                <?php echo esc_html( get_post_meta( $p->ID, 'location_name', true ) ?: '—' ); ?>
                            </div>
                        </td>
                        <td>
                            <?php if ( $exp_ts > time() ) : ?>
                                <span style="color:#dba617;font-weight:600;">in <?php echo esc_html( human_time_diff( time(), $exp_ts ) ); ?></span>
                            <?php else : ?>
                                <span style="color:#d63638;font-weight:600;"><?php esc_html_e( 'overdue', 'mamboleo' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;">
                                <?php wp_nonce_field( 'mamboleo_extend' ); ?>
                                <input type="hidden" name="action"      value="mamboleo_extend_incident" />
                                <input type="hidden" name="incident_id" value="<?php echo (int) $p->ID; ?>" />
                                <input type="text" name="body" placeholder="<?php esc_attr_e( 'Optional: short status note', 'mamboleo' ); ?>" style="flex:1;" />
                                <button class="button button-primary"><?php esc_html_e( 'Extend', 'mamboleo' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
