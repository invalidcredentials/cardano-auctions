<?php
/**
 * Cardano Auctions — Clean uninstall.
 *
 * Removes all plugin data: database tables, options, cron events.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom tables.
$tables = array(
    $wpdb->prefix . 'ca_auctions',
    $wpdb->prefix . 'ca_bids',
    $wpdb->prefix . 'ca_escrow_wallets',
    $wpdb->prefix . 'ca_master_wallets',
    $wpdb->prefix . 'ca_mint_policies',
    $wpdb->prefix . 'ca_event_log',
);

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Remove plugin options.
$options = array(
    'ca_db_version',
    'ca_network',
    'ca_blockfrost_key_preprod',
    'ca_blockfrost_key_mainnet',
    'ca_anvil_key_preprod',
    'ca_anvil_key_mainnet',
    'ca_anti_snipe_seconds',
    'ca_anti_snipe_threshold',
    'ca_payment_timeout',
    'ca_default_seller_address',
    'ca_platform_fee_bps',
    'ca_default_min_increment',
    'ca_widget_api_keys',
    'ca_widget_config',
    'ca_pinata_jwt',
    'ca_pinata_enabled',
);

foreach ( $options as $opt ) {
    delete_option( $opt );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'ca_poll_payments' );
wp_clear_scheduled_hook( 'ca_check_ended' );
wp_clear_scheduled_hook( 'ca_process_refunds' );
wp_clear_scheduled_hook( 'ca_cleanup' );

// Clean up any transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ca_%' OR option_name LIKE '_transient_timeout_ca_%'" );
