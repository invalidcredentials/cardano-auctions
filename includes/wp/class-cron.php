<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Cron {

    public static function init(): void {
        add_filter( 'cron_schedules', array( __CLASS__, 'add_intervals' ) );
        add_action( 'ca_poll_payments',   array( 'CA_Payment_Monitor', 'poll' ) );
        add_action( 'ca_check_ended',     array( __CLASS__, 'check_ended_auctions' ) );
        add_action( 'ca_process_refunds', array( 'CA_Refund_Processor', 'process_pending' ) );
        add_action( 'ca_cleanup',         array( __CLASS__, 'cleanup' ) );
    }

    public static function add_intervals( array $schedules ): array {
        $schedules['ca_30s'] = array( 'interval' => 30,  'display' => 'Every 30 seconds' );
        $schedules['ca_60s'] = array( 'interval' => 60,  'display' => 'Every 60 seconds' );
        return $schedules;
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( 'ca_poll_payments' ) )   wp_schedule_event( time(), 'ca_30s', 'ca_poll_payments' );
        if ( ! wp_next_scheduled( 'ca_check_ended' ) )     wp_schedule_event( time(), 'ca_60s', 'ca_check_ended' );
        if ( ! wp_next_scheduled( 'ca_process_refunds' ) ) wp_schedule_event( time(), 'ca_60s', 'ca_process_refunds' );
        if ( ! wp_next_scheduled( 'ca_cleanup' ) )         wp_schedule_event( time(), 'hourly', 'ca_cleanup' );
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook( 'ca_poll_payments' );
        wp_clear_scheduled_hook( 'ca_check_ended' );
        wp_clear_scheduled_hook( 'ca_process_refunds' );
        wp_clear_scheduled_hook( 'ca_cleanup' );
    }

    /**
     * Check for ended auctions and trigger auto-settlement.
     */
    public static function check_ended_auctions(): void {
        $auctions = CA_Auction_Model::get_ended_active();
        foreach ( $auctions as $auction ) {
            CA_Settlement::settle( (int) $auction['id'] );
        }
    }

    /**
     * Hourly cleanup: expire stale bids, archive drained wallets.
     */
    public static function cleanup(): void {
        // Expire overdue awaiting bids.
        $expired = CA_Bid_Model::get_expired();
        foreach ( $expired as $bid ) {
            CA_Bid_Model::update( (int) $bid['id'], array( 'payment_status' => 'expired' ) );
            if ( ! empty( $bid['escrow_wallet_id'] ) ) {
                CA_Escrow_Model::archive( (int) $bid['escrow_wallet_id'] );
            }
        }
    }

    /**
     * Get cron status for admin display.
     */
    public static function get_status(): array {
        return array(
            'ca_poll_payments'   => wp_next_scheduled( 'ca_poll_payments' ),
            'ca_check_ended'     => wp_next_scheduled( 'ca_check_ended' ),
            'ca_process_refunds' => wp_next_scheduled( 'ca_process_refunds' ),
            'ca_cleanup'         => wp_next_scheduled( 'ca_cleanup' ),
        );
    }
}
