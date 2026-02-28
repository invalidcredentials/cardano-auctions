<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Plugin {

    private static $booted = false;

    public static function init(): void {
        if ( self::$booted ) return;
        self::$booted = true;

        self::maybe_upgrade();

        CA_Admin::init();
        CA_Admin_Auctions::init();
        CA_Admin_Widget::init();
        CA_Assets::init();
        CA_Shortcodes::init();
        CA_Cron::init();

        CA_REST_API::init();
    }

    public static function activate(): void {
        // Create all database tables.
        CA_Auction_Model::create_table();
        CA_Bid_Model::create_table();
        CA_Escrow_Model::create_table();
        CA_Master_Wallet_Model::create_table();
        CA_Mint_Policy_Model::create_table();
        CA_Event_Log_Model::create_table();

        // Store installed version.
        update_option( 'ca_db_version', CA_VERSION );

        // Schedule cron events.
        CA_Cron::schedule();

        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        CA_Cron::unschedule();
        flush_rewrite_rules();
    }

    /**
     * Run table upgrades when plugin version changes.
     */
    private static function maybe_upgrade(): void {
        $installed = get_option( 'ca_db_version', '0' );
        if ( version_compare( $installed, CA_VERSION, '>=' ) ) return;

        CA_Auction_Model::create_table();
        CA_Bid_Model::create_table();
        CA_Escrow_Model::create_table();
        CA_Master_Wallet_Model::create_table();
        CA_Mint_Policy_Model::create_table();
        CA_Event_Log_Model::create_table();

        update_option( 'ca_db_version', CA_VERSION );
    }

    /**
     * System requirements check (called from admin).
     */
    public static function check_requirements(): array {
        $errors = array();
        if ( ! extension_loaded( 'sodium' ) )  $errors[] = 'PHP ext/sodium is required.';
        if ( ! extension_loaded( 'openssl' ) ) $errors[] = 'PHP ext/openssl is required.';
        if ( ! extension_loaded( 'bcmath' ) )  $errors[] = 'PHP ext/bcmath is required.';
        if ( ! CA_Encryption::test() )         $errors[] = 'Encryption self-test failed. Check your WordPress salts.';
        return $errors;
    }
}
