<?php
/**
 * CA_Refund_Processor — build, sign, and submit refund transactions.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Refund_Processor {

    /**
     * Process all pending refunds.
     * Called by WP-Cron every 60 seconds.
     */
    public static function process_pending(): void {
        $bids = CA_Bid_Model::get_pending_refunds();

        foreach ( $bids as $bid ) {
            self::refund_bid( $bid );
        }
    }

    /**
     * Refund a single bid from its escrow wallet back to the bidder.
     *
     * @return true|string True on success, error message string on failure.
     */
    public static function refund_bid( array $bid ) {
        $bid_id     = (int) $bid['id'];
        $auction_id = (int) $bid['auction_id'];
        $wallet_id  = (int) $bid['escrow_wallet_id'];

        if ( ! $wallet_id || empty( $bid['bidder_address'] ) ) {
            CA_Bid_Model::update( $bid_id, array( 'refund_status' => 'failed' ) );
            $msg = 'Missing wallet ID or bidder address.';
            CA_Event_Log_Model::log( $auction_id, $bid_id, 'refund_failed', $msg );
            return $msg;
        }

        $wallet = CA_Escrow_Model::get( $wallet_id );
        if ( ! $wallet ) {
            CA_Bid_Model::update( $bid_id, array( 'refund_status' => 'failed' ) );
            $msg = "Escrow wallet #{$wallet_id} not found in database.";
            CA_Event_Log_Model::log( $auction_id, $bid_id, 'refund_failed', $msg );
            return $msg;
        }

        // Check escrow balance.
        $balance = CA_Escrow::check_balance( $wallet_id );
        if ( is_wp_error( $balance ) ) {
            CA_Bid_Model::update( $bid_id, array( 'refund_status' => 'failed' ) );
            $msg = 'Blockfrost balance check failed: ' . $balance->get_error_message();
            CA_Event_Log_Model::log( $auction_id, $bid_id, 'refund_failed', $msg );
            return $msg;
        }
        if ( $balance['lovelace'] <= 0 ) {
            CA_Bid_Model::update( $bid_id, array( 'refund_status' => 'failed' ) );
            $msg = sprintf( 'Escrow wallet %s has 0 lovelace balance.', $wallet['payment_address'] );
            CA_Event_Log_Model::log( $auction_id, $bid_id, 'refund_failed', $msg );
            return $msg;
        }

        // Calculate refund: send balance minus fee buffer.
        // Any small dust remaining gets absorbed into the fee by Anvil.
        $fee_estimate   = 350000;  // 0.35 ADA fee buffer
        $min_utxo       = 1000000; // 1 ADA minimum UTxO
        $refund_amount  = $balance['lovelace'] - $fee_estimate;

        if ( $refund_amount < $min_utxo ) {
            CA_Bid_Model::update( $bid_id, array( 'refund_status' => 'failed' ) );
            $msg = sprintf( 'Escrow balance too low to refund: %s lovelace (need >%s).', $balance['lovelace'], $min_utxo + $fee_estimate );
            CA_Event_Log_Model::log( $auction_id, $bid_id, 'refund_dust', $msg );
            return $msg;
        }

        CA_Event_Log_Model::log( $auction_id, $bid_id, 'refund_starting', sprintf(
            'Starting refund: %s lovelace from %s to %s',
            $refund_amount,
            $wallet['payment_address'],
            $bid['bidder_address']
        ) );

        // Mark as submitted to prevent double-processing.
        CA_Bid_Model::update( $bid_id, array( 'refund_status' => 'submitted' ) );

        // Build, sign, submit from escrow → bidder.
        // changeAddress = escrow (Anvil uses it to select input UTxOs).
        $result = CA_Escrow::send_from( $wallet_id, array(
            array(
                'address'  => $bid['bidder_address'],
                'lovelace' => $refund_amount,
            ),
        ) );

        if ( is_wp_error( $result ) ) {
            CA_Bid_Model::update( $bid_id, array( 'refund_status' => 'failed' ) );
            $msg = 'Refund tx failed: ' . $result->get_error_message();
            CA_Event_Log_Model::log( $auction_id, $bid_id, 'refund_failed', $msg );
            return $msg;
        }

        $tx_hash = $result['txHash'] ?? '';
        CA_Bid_Model::update( $bid_id, array(
            'payment_status'       => 'refunded',
            'refund_status'        => 'confirmed',
            'refund_tx_hash'       => $tx_hash,
            'refund_amount_lovelace' => $refund_amount,
        ) );

        CA_Escrow_Model::drain( $wallet_id );

        CA_Event_Log_Model::log( $auction_id, $bid_id, 'refund_confirmed', sprintf(
            'Refund of %s ADA sent. TX: %s',
            number_format( $refund_amount / 1000000, 6 ),
            $tx_hash
        ) );

        return true;
    }
}
