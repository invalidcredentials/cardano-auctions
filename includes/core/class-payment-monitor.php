<?php
/**
 * CA_Payment_Monitor — WP-Cron Blockfrost polling for escrow payments.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Payment_Monitor {

    /**
     * Poll all awaiting/detected bids for payment in their escrow wallets.
     * Called by WP-Cron every 30 seconds.
     */
    public static function poll(): void {
        $bids = CA_Bid_Model::get_awaiting_payment();

        foreach ( $bids as $bid ) {
            self::check_bid_payment( $bid );
        }

        // Also expire overdue bids.
        self::expire_overdue();
    }

    /**
     * Check a single bid's escrow wallet for payment.
     */
    private static function check_bid_payment( array $bid ): void {
        if ( empty( $bid['escrow_wallet_id'] ) ) return;

        $wallet = CA_Escrow_Model::get( (int) $bid['escrow_wallet_id'] );
        if ( ! $wallet ) return;

        $balance = CA_Blockfrost::get_address_balance( $wallet['payment_address'] );
        if ( is_wp_error( $balance ) ) return;

        $lovelace = (int) $balance['lovelace'];
        CA_Escrow_Model::update( (int) $wallet['id'], array( 'balance_lovelace' => $lovelace ) );

        if ( $lovelace <= 0 ) return;

        // Find the tx hash from UTxOs.
        $tx_hash = '';
        $utxos = CA_Blockfrost::get_address_utxos( $wallet['payment_address'] );
        if ( ! is_wp_error( $utxos ) && ! empty( $utxos ) ) {
            $tx_hash = $utxos[0]['tx_hash'] ?? '';
        }

        // Payment detected — try to confirm.
        if ( $lovelace >= (int) $bid['bid_lovelace'] ) {
            CA_Auction_Engine::confirm_bid( (int) $bid['id'], $tx_hash, $lovelace );
        } else {
            // Partial payment detected.
            if ( $bid['payment_status'] === 'awaiting' ) {
                CA_Bid_Model::update( (int) $bid['id'], array(
                    'payment_status'          => 'detected',
                    'payment_tx_hash'         => $tx_hash,
                    'payment_amount_lovelace' => $lovelace,
                ) );
                CA_Event_Log_Model::log(
                    (int) $bid['auction_id'],
                    (int) $bid['id'],
                    'payment_partial',
                    sprintf( 'Partial payment: %s of %s lovelace.', $lovelace, $bid['bid_lovelace'] )
                );
            }
        }
    }

    /**
     * Expire bids past their payment deadline.
     */
    private static function expire_overdue(): void {
        $expired = CA_Bid_Model::get_expired();

        foreach ( $expired as $bid ) {
            CA_Bid_Model::update( (int) $bid['id'], array( 'payment_status' => 'expired' ) );

            // Archive the escrow wallet.
            if ( ! empty( $bid['escrow_wallet_id'] ) ) {
                CA_Escrow_Model::archive( (int) $bid['escrow_wallet_id'] );
            }

            CA_Event_Log_Model::log(
                (int) $bid['auction_id'],
                (int) $bid['id'],
                'bid_expired',
                'Payment deadline passed. Bid expired.'
            );
        }
    }
}
