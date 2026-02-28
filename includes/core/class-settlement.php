<?php
/**
 * CA_Settlement — NFT transfer to winner + ADA transfer to seller.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Settlement {

    /**
     * Settle an ended auction: transfer NFT to winner + ADA to seller.
     *
     * @param int $auction_id Auction ID.
     * @return bool True on success.
     */
    public static function settle( int $auction_id ): bool {
        $auction = CA_Auction_Model::get( $auction_id );
        if ( ! $auction || $auction['status'] !== 'active' ) return false;

        CA_Auction_Model::update( $auction_id, array( 'status' => 'settling' ) );

        $winning_bid = CA_Bid_Model::get_highest_confirmed( $auction_id );

        // No winning bid or reserve not met.
        if ( ! $winning_bid || (int) $winning_bid['bid_lovelace'] < (int) $auction['reserve_price_lovelace'] ) {
            return self::handle_no_winner( $auction_id, $auction );
        }

        // Mark winning bid.
        CA_Bid_Model::update( (int) $winning_bid['id'], array( 'payment_status' => 'won' ) );

        // Step 1: Transfer NFT from custody wallet → winner.
        $nft_result = self::transfer_nft( $auction, $winning_bid );
        if ( is_wp_error( $nft_result ) ) {
            CA_Auction_Model::update( $auction_id, array( 'status' => 'failed' ) );
            CA_Event_Log_Model::log( $auction_id, (int) $winning_bid['id'], 'settlement_failed',
                'NFT transfer failed: ' . $nft_result->get_error_message() );
            return false;
        }

        CA_Auction_Model::update( $auction_id, array( 'settlement_tx_hash' => $nft_result['txHash'] ) );

        // Step 2: Transfer ADA from winning escrow → seller.
        $ada_result = self::transfer_ada( $auction, $winning_bid );
        if ( is_wp_error( $ada_result ) ) {
            // NFT already sent; mark partial settlement and log error.
            CA_Auction_Model::update( $auction_id, array( 'status' => 'failed' ) );
            CA_Event_Log_Model::log( $auction_id, (int) $winning_bid['id'], 'ada_settlement_failed',
                'ADA transfer failed: ' . $ada_result->get_error_message() );
            return false;
        }

        CA_Auction_Model::update( $auction_id, array(
            'ada_settlement_tx_hash' => $ada_result['txHash'],
            'status'                 => 'settled',
            'settled_at'             => current_time( 'mysql', true ),
        ) );

        CA_Bid_Model::update( (int) $winning_bid['id'], array( 'payment_status' => 'settled' ) );

        // Drain escrow wallets.
        if ( ! empty( $winning_bid['escrow_wallet_id'] ) ) {
            CA_Escrow_Model::drain( (int) $winning_bid['escrow_wallet_id'] );
        }
        $custody = CA_Escrow_Model::get_custody_wallet( $auction_id );
        if ( $custody ) CA_Escrow_Model::drain( (int) $custody['id'] );

        CA_Event_Log_Model::log( $auction_id, (int) $winning_bid['id'], 'auction_settled', sprintf(
            'Auction settled! NFT TX: %s, ADA TX: %s',
            $nft_result['txHash'],
            $ada_result['txHash']
        ) );

        return true;
    }

    /**
     * Transfer NFT from custody wallet to winner.
     */
    private static function transfer_nft( array $auction, array $bid ) {
        // Try master wallet first (new model — NFT held in master wallet).
        $master = CA_Master_Wallet::get_active();
        if ( $master ) {
            return CA_Master_Wallet::send_nft(
                (int) $master['id'],
                $bid['bidder_address'],
                $auction['nft_policy_id'],
                $auction['nft_asset_name_hex']
            );
        }

        // Fallback: legacy custody wallet model.
        $custody = CA_Escrow_Model::get_custody_wallet( (int) $auction['id'] );
        if ( ! $custody ) {
            return new WP_Error( 'ca_no_custody', 'No custody wallet found.' );
        }

        $policy_id      = $auction['nft_policy_id'];
        $asset_name_hex = $auction['nft_asset_name_hex'];
        $asset_name     = CA_Blockfrost::hex_to_ascii( $asset_name_hex );
        $winner_address = $bid['bidder_address'];

        // Build NFT transfer output: min UTxO (2 ADA) + the NFT.
        $outputs = array(
            array(
                'address'  => $winner_address,
                'lovelace' => 2000000, // 2 ADA min UTxO for token output
                'assets'   => array(
                    array(
                        'policyId'  => $policy_id,
                        'assetName' => array( 'name' => $asset_name, 'format' => 'utf8' ),
                        'quantity'  => 1,
                    ),
                ),
            ),
        );

        return CA_Escrow::send_from( (int) $custody['id'], $outputs );
    }

    /**
     * Transfer ADA from winning escrow to seller.
     */
    private static function transfer_ada( array $auction, array $bid ) {
        $wallet_id      = (int) $bid['escrow_wallet_id'];
        $seller_address = $auction['seller_address'];

        if ( empty( $seller_address ) ) {
            return new WP_Error( 'ca_no_seller', 'No seller address configured.' );
        }

        // Check balance and send minus fee reserve.
        $balance = CA_Escrow::check_balance( $wallet_id );
        if ( is_wp_error( $balance ) ) return $balance;

        $fee_reserve   = 200000; // 0.2 ADA
        $send_amount   = $balance['lovelace'] - $fee_reserve;

        // Apply platform fee if configured.
        $fee_bps = CA_Settings::get_platform_fee_bps();
        if ( $fee_bps > 0 ) {
            // Platform fee is deducted; for now just logged. Full implementation would send to platform address.
            $platform_fee = (int) ( $send_amount * $fee_bps / 10000 );
            $send_amount -= $platform_fee;
            CA_Event_Log_Model::log( (int) $auction['id'], (int) $bid['id'], 'platform_fee',
                sprintf( 'Platform fee: %d lovelace (%d bps)', $platform_fee, $fee_bps ) );
        }

        if ( $send_amount < 1000000 ) {
            return new WP_Error( 'ca_insufficient_escrow', 'Escrow balance too low for settlement.' );
        }

        return CA_Escrow::send_from( $wallet_id, array(
            array( 'address' => $seller_address, 'lovelace' => $send_amount ),
        ) );
    }

    /**
     * Handle auctions with no winner: return NFT to seller, cancel auction.
     */
    private static function handle_no_winner( int $auction_id, array $auction ): bool {
        $nft_returned = false;

        // Try master wallet first — return NFT to seller from master wallet.
        $master = CA_Master_Wallet::get_active();
        if ( $master && ! empty( $auction['seller_address'] ) ) {
            $result = CA_Master_Wallet::send_nft(
                (int) $master['id'],
                $auction['seller_address'],
                $auction['nft_policy_id'],
                $auction['nft_asset_name_hex']
            );
            $nft_returned = ! is_wp_error( $result );
        }

        // Fallback: legacy custody wallet model.
        if ( ! $nft_returned ) {
            $custody = CA_Escrow_Model::get_custody_wallet( $auction_id );
            if ( $custody && ! empty( $auction['seller_address'] ) ) {
                $policy_id      = $auction['nft_policy_id'];
                $asset_name_hex = $auction['nft_asset_name_hex'];
                $asset_name     = CA_Blockfrost::hex_to_ascii( $asset_name_hex );

                $result = CA_Escrow::send_from( (int) $custody['id'], array(
                    array(
                        'address'  => $auction['seller_address'],
                        'lovelace' => 2000000,
                        'assets'   => array(
                            array(
                                'policyId'  => $policy_id,
                                'assetName' => array( 'name' => $asset_name, 'format' => 'utf8' ),
                                'quantity'  => 1,
                            ),
                        ),
                    ),
                ) );

                if ( ! is_wp_error( $result ) ) {
                    CA_Escrow_Model::drain( (int) $custody['id'] );
                }
            }
        }

        CA_Auction_Model::update( $auction_id, array( 'status' => 'cancelled' ) );

        // Refund any confirmed bids.
        $bids = CA_Bid_Model::get_by_auction( $auction_id, 'confirmed' );
        foreach ( $bids as $bid ) {
            CA_Bid_Model::update( (int) $bid['id'], array(
                'payment_status' => 'refunding',
                'refund_status'  => 'pending',
            ) );
        }

        CA_Event_Log_Model::log( $auction_id, 0, 'no_winner', 'Auction ended with no qualifying winner. NFT returned to seller.' );
        return true;
    }
}
