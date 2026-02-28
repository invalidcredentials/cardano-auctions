<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap ca-admin">
    <h1>
        Cardano Auctions
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ca-auction-edit' ) ); ?>" class="page-title-action">Create Auction</a>
        <span class="ca-network-badge"><?php echo esc_html( strtoupper( CA_Settings::get_network() ) ); ?></span>
    </h1>

    <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Action completed.</p></div>
    <?php endif; ?>

    <ul class="subsubsub">
        <li><a href="?page=cardano-auctions" <?php echo empty( $status ) ? 'class="current"' : ''; ?>>All <span class="count">(<?php echo (int) $counts['all']; ?>)</span></a> |</li>
        <li><a href="?page=cardano-auctions&status=draft" <?php echo $status === 'draft' ? 'class="current"' : ''; ?>>Draft <span class="count">(<?php echo (int) $counts['draft']; ?>)</span></a> |</li>
        <li><a href="?page=cardano-auctions&status=active" <?php echo $status === 'active' ? 'class="current"' : ''; ?>>Active <span class="count">(<?php echo (int) $counts['active']; ?>)</span></a> |</li>
        <li><a href="?page=cardano-auctions&status=settled" <?php echo $status === 'settled' ? 'class="current"' : ''; ?>>Settled <span class="count">(<?php echo (int) $counts['settled']; ?>)</span></a></li>
    </ul>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:40px">ID</th>
                <th>Title</th>
                <th>NFT</th>
                <th>Current Bid</th>
                <th>Bids</th>
                <th>Ends</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $auctions ) ) : ?>
            <tr><td colspan="8">No auctions found.</td></tr>
        <?php else : foreach ( $auctions as $a ) :
            $aid = (int) $a['id'];
            $edit_url = admin_url( 'admin.php?page=ca-auction-edit&id=' . $aid );
        ?>
            <tr>
                <td><?php echo $aid; ?></td>
                <td><a href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( $a['title'] ?: '(untitled)' ); ?></strong></a></td>
                <td>
                    <?php if ( $a['nft_image_url'] ) : ?>
                        <img src="<?php echo esc_url( $a['nft_image_url'] ); ?>" alt="" style="width:32px;height:32px;border-radius:4px;vertical-align:middle">
                    <?php endif; ?>
                    <?php echo esc_html( $a['nft_display_name'] ); ?>
                </td>
                <td><?php echo $a['current_bid_lovelace'] > 0 ? esc_html( number_format( $a['current_bid_lovelace'] / 1000000, 2 ) ) . ' ADA' : '&mdash;'; ?></td>
                <td><?php echo (int) $a['total_bids']; ?></td>
                <td><?php echo $a['end_time'] !== '0000-00-00 00:00:00' ? esc_html( $a['end_time'] ) : '&mdash;'; ?></td>
                <td><span class="ca-status ca-status--<?php echo esc_attr( $a['status'] ); ?>"><?php echo esc_html( ucfirst( $a['status'] ) ); ?></span></td>
                <td>
                    <a href="<?php echo esc_url( $edit_url ); ?>">Edit</a>
                    <?php if ( $a['status'] === 'draft' ) : ?>
                        | <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=cardano-auctions&ca_action=activate&id=' . $aid ), 'ca_action_activate_' . $aid, 'ca_nonce' ) ); ?>">Activate</a>
                        | <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=cardano-auctions&ca_action=force_activate&id=' . $aid ), 'ca_action_force_activate_' . $aid, 'ca_nonce' ) ); ?>" onclick="return confirm('Force activate without NFT check?')">Force Activate</a>
                        | <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=cardano-auctions&ca_action=delete&id=' . $aid ), 'ca_action_delete_' . $aid, 'ca_nonce' ) ); ?>" onclick="return confirm('Delete this auction?')">Delete</a>
                    <?php endif; ?>
                    <?php if ( $a['status'] === 'active' ) : ?>
                        | <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=cardano-auctions&ca_action=cancel&id=' . $aid ), 'ca_action_cancel_' . $aid, 'ca_nonce' ) ); ?>" onclick="return confirm('Cancel this auction? All bids will be refunded.')">Cancel</a>
                    <?php endif; ?>
                    <?php if ( $a['status'] === 'failed' ) : ?>
                        | <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=cardano-auctions&ca_action=settle&id=' . $aid ), 'ca_action_settle_' . $aid, 'ca_nonce' ) ); ?>">Retry Settlement</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
