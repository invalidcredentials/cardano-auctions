<?php
/**
 * CA_Auction_Engine — bid validation, state machine, anti-snipe logic.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Auction_Engine {

    /**
     * Validate and place a bid. Creates escrow wallet and bid record.
     *
     * @param int    $auction_id      Auction ID.
     * @param string $bidder_address  Bech32 address of bidder.
     * @param int    $bid_lovelace    Bid amount in lovelace.
     * @return array|WP_Error { bid_id, escrow_address, payment_deadline, amount_lovelace }
     */
    public static function place_bid( int $auction_id, string $bidder_address, int $bid_lovelace ) {
        global $wpdb;

        // Validate address format.
        if ( ! self::validate_address( $bidder_address ) ) {
            return new WP_Error( 'ca_invalid_address', 'Invalid Cardano address format.' );
        }

        // Rate limit: 5 bids per minute per IP.
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        $rate_key = 'ca_bid_rate_' . md5( $ip );
        $count = (int) get_transient( $rate_key );
        if ( $count >= 5 ) {
            return new WP_Error( 'ca_rate_limited', 'Too many bids. Please wait a moment.' );
        }

        // Begin transaction for row-level locking.
        $wpdb->query( 'START TRANSACTION' );

        $auction = CA_Auction_Model::get_for_update( $auction_id );
        if ( ! $auction ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'ca_auction_not_found', 'Auction not found.' );
        }

        // Status check.
        if ( $auction['status'] !== 'active' ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'ca_auction_not_active', 'This auction is not currently active.' );
        }

        // Time check.
        $now = current_time( 'mysql', true );
        if ( $now < $auction['start_time'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'ca_auction_not_started', 'This auction has not started yet.' );
        }
        if ( $now > $auction['end_time'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'ca_auction_ended', 'This auction has ended.' );
        }

        // Bid amount validation.
        $min_bid = (int) $auction['current_bid_lovelace'] > 0
            ? (int) $auction['current_bid_lovelace'] + (int) $auction['min_increment_lovelace']
            : (int) $auction['starting_bid_lovelace'];

        if ( $bid_lovelace < $min_bid ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'ca_bid_too_low', sprintf(
                'Minimum bid is %s ADA.',
                number_format( $min_bid / 1000000, 2 )
            ) );
        }

        // Prevent self-outbidding.
        if ( $auction['current_bidder_address'] === $bidder_address ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'ca_self_outbid', 'You are already the highest bidder.' );
        }

        // Prevent duplicate bids while payment is still pending.
        $pending = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . CA_Bid_Model::table() . " WHERE auction_id = %d AND bidder_address = %s AND payment_status IN ('awaiting','detected')",
            $auction_id, $bidder_address
        ) );
        if ( (int) $pending > 0 ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'ca_bid_pending', 'You already have a pending bid awaiting payment. Please wait for it to confirm or expire.' );
        }

        // Generate escrow wallet.
        $escrow = CA_Escrow::generate( $auction_id, 0, 'escrow' );
        if ( is_wp_error( $escrow ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $escrow;
        }

        // Payment deadline.
        $timeout  = CA_Settings::get_payment_timeout();
        $deadline = gmdate( 'Y-m-d H:i:s', time() + $timeout );

        // Create bid record.
        $bid_id = CA_Bid_Model::insert( array(
            'auction_id'       => $auction_id,
            'bidder_address'   => $bidder_address,
            'bid_lovelace'     => $bid_lovelace,
            'escrow_wallet_id' => $escrow['id'],
            'escrow_address'   => $escrow['payment_address'],
            'payment_status'   => 'awaiting',
            'payment_deadline' => $deadline,
        ) );

        // Link bid to escrow wallet.
        CA_Escrow_Model::update( $escrow['id'], array( 'bid_id' => $bid_id ) );

        $wpdb->query( 'COMMIT' );

        // Update rate limit.
        set_transient( $rate_key, $count + 1, 60 );

        CA_Event_Log_Model::log( $auction_id, $bid_id, 'bid_placed', sprintf(
            'Bid of %s ADA placed by %s...%s. Escrow: %s',
            number_format( $bid_lovelace / 1000000, 2 ),
            substr( $bidder_address, 0, 12 ),
            substr( $bidder_address, -6 ),
            $escrow['payment_address']
        ) );

        return array(
            'bid_id'           => $bid_id,
            'escrow_address'   => $escrow['payment_address'],
            'payment_deadline' => $deadline,
            'amount_lovelace'  => $bid_lovelace,
        );
    }

    /**
     * Confirm a bid payment and update auction state.
     */
    public static function confirm_bid( int $bid_id, string $tx_hash, int $amount_lovelace ): bool {
        global $wpdb;

        $bid = CA_Bid_Model::get( $bid_id );
        if ( ! $bid ) return false;

        // Must meet or exceed bid amount.
        if ( $amount_lovelace < (int) $bid['bid_lovelace'] ) {
            CA_Bid_Model::update( $bid_id, array(
                'payment_status'          => 'detected',
                'payment_tx_hash'         => $tx_hash,
                'payment_amount_lovelace' => $amount_lovelace,
            ) );
            CA_Event_Log_Model::log( (int) $bid['auction_id'], $bid_id, 'payment_insufficient',
                "Payment detected but insufficient: {$amount_lovelace} < {$bid['bid_lovelace']}" );
            return false;
        }

        $wpdb->query( 'START TRANSACTION' );

        $auction = CA_Auction_Model::get_for_update( (int) $bid['auction_id'] );
        if ( ! $auction || $auction['status'] !== 'active' ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        $old_bid_id = (int) $auction['current_bid_id'];

        // Update bid record.
        CA_Bid_Model::update( $bid_id, array(
            'payment_status'          => 'confirmed',
            'payment_tx_hash'         => $tx_hash,
            'payment_amount_lovelace' => $amount_lovelace,
        ) );

        // Update auction state.
        CA_Auction_Model::update( (int) $auction['id'], array(
            'current_bid_lovelace'   => (int) $bid['bid_lovelace'],
            'current_bidder_address' => $bid['bidder_address'],
            'current_bid_id'         => $bid_id,
            'total_bids'             => (int) $auction['total_bids'] + 1,
        ) );

        // Anti-snipe: extend if bid confirmed near end.
        self::apply_anti_snipe( (int) $auction['id'], $auction );

        $wpdb->query( 'COMMIT' );

        // Refund the previous highest bidder immediately.
        if ( $old_bid_id && $old_bid_id !== $bid_id ) {
            $old_bid = CA_Bid_Model::get( $old_bid_id );
            if ( $old_bid && in_array( $old_bid['payment_status'], array( 'confirmed', 'won' ), true ) ) {
                CA_Bid_Model::update( $old_bid_id, array(
                    'payment_status' => 'refunding',
                    'refund_status'  => 'pending',
                ) );
                CA_Event_Log_Model::log( (int) $bid['auction_id'], $old_bid_id, 'refund_queued',
                    'Previous highest bidder queued for refund.' );

                // Process the refund now (don't wait for cron).
                $old_bid['payment_status'] = 'refunding';
                $old_bid['refund_status']  = 'pending';
                CA_Refund_Processor::refund_bid( $old_bid );
            }
        }

        CA_Event_Log_Model::log( (int) $bid['auction_id'], $bid_id, 'bid_confirmed', sprintf(
            'Bid of %s ADA confirmed. TX: %s',
            number_format( (int) $bid['bid_lovelace'] / 1000000, 2 ),
            $tx_hash
        ) );

        return true;
    }

    /**
     * Apply anti-snipe extension if bid is near end time.
     */
    private static function apply_anti_snipe( int $auction_id, array $auction ): void {
        $threshold = (int) $auction['anti_snipe_threshold'];
        $extension = (int) $auction['anti_snipe_seconds'];
        if ( $threshold <= 0 || $extension <= 0 ) return;

        $now     = time();
        $end     = strtotime( $auction['end_time'] );
        $remaining = $end - $now;

        if ( $remaining > 0 && $remaining <= $threshold ) {
            $new_end = gmdate( 'Y-m-d H:i:s', $end + $extension );
            CA_Auction_Model::update( $auction_id, array( 'end_time' => $new_end ) );
            CA_Event_Log_Model::log( $auction_id, 0, 'anti_snipe', sprintf(
                'Auction extended by %d seconds. New end: %s', $extension, $new_end
            ) );
        }
    }

    /**
     * Activate a draft/scheduled auction.
     */
    public static function activate_auction( int $auction_id ): bool {
        $auction = CA_Auction_Model::get( $auction_id );
        if ( ! $auction ) return false;
        if ( ! in_array( $auction['status'], array( 'draft', 'scheduled' ), true ) ) return false;

        // Verify NFT custody wallet has the NFT.
        $custody = CA_Escrow_Model::get_custody_wallet( $auction_id );
        if ( ! $custody ) return false;

        $unit = $auction['nft_policy_id'] . $auction['nft_asset_name_hex'];
        $has_nft = CA_Blockfrost::address_has_asset( $custody['payment_address'], $unit );
        if ( is_wp_error( $has_nft ) || ! $has_nft ) return false;

        $now = current_time( 'mysql', true );
        CA_Auction_Model::update( $auction_id, array(
            'status'     => 'active',
            'start_time' => $now,
        ) );

        CA_Event_Log_Model::log( $auction_id, 0, 'auction_activated', 'Auction activated. NFT confirmed in custody.' );
        return true;
    }

    /**
     * Force-activate a draft auction, skipping the NFT custody check.
     * Useful for testing on preprod or when NFT custody is not required.
     */
    public static function force_activate( int $auction_id ): bool {
        $auction = CA_Auction_Model::get( $auction_id );
        if ( ! $auction ) return false;
        if ( ! in_array( $auction['status'], array( 'draft', 'scheduled' ), true ) ) return false;

        $now = current_time( 'mysql', true );
        CA_Auction_Model::update( $auction_id, array(
            'status'     => 'active',
            'start_time' => $now,
        ) );

        CA_Event_Log_Model::log( $auction_id, 0, 'auction_force_activated', 'Auction force-activated (NFT custody check skipped).' );
        return true;
    }

    /**
     * Cancel an auction and return NFT to seller.
     */
    public static function cancel_auction( int $auction_id ): bool {
        $auction = CA_Auction_Model::get( $auction_id );
        if ( ! $auction ) return false;
        if ( in_array( $auction['status'], array( 'settled', 'settling' ), true ) ) return false;

        CA_Auction_Model::update( $auction_id, array( 'status' => 'cancelled' ) );

        // Refund all confirmed bids.
        $bids = CA_Bid_Model::get_by_auction( $auction_id, 'confirmed' );
        foreach ( $bids as $bid ) {
            CA_Bid_Model::update( (int) $bid['id'], array(
                'payment_status' => 'refunding',
                'refund_status'  => 'pending',
            ) );
        }

        CA_Event_Log_Model::log( $auction_id, 0, 'auction_cancelled', 'Auction cancelled. Refunds queued.' );
        return true;
    }

    /**
     * Validate a Cardano bech32 address.
     */
    public static function validate_address( string $address ): bool {
        return (bool) preg_match( '/^addr(_test)?1[a-z0-9]{50,120}$/', $address );
    }
}
