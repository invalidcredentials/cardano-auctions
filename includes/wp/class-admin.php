<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Admin {

    public static function init(): void {
        if ( ! is_admin() ) return;
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_settings_save' ) );
    }

    public static function menu(): void {
        add_menu_page(
            'Cardano Auctions',
            'Cardano Auctions',
            'manage_options',
            'cardano-auctions',
            array( 'CA_Admin_Auctions', 'page_list' ),
            'dashicons-gavel',
            30
        );

        add_submenu_page(
            'cardano-auctions',
            'All Auctions',
            'All Auctions',
            'manage_options',
            'cardano-auctions',
            array( 'CA_Admin_Auctions', 'page_list' )
        );

        add_submenu_page(
            'cardano-auctions',
            'Create Auction',
            'Create Auction',
            'manage_options',
            'ca-auction-edit',
            array( 'CA_Admin_Auctions', 'page_edit' )
        );

        add_submenu_page(
            'cardano-auctions',
            'Wallets',
            'Wallets',
            'manage_options',
            'ca-wallets',
            array( 'CA_Admin_Auctions', 'page_wallets' )
        );

        add_submenu_page(
            'cardano-auctions',
            'Widget Deployer',
            'Widget Deployer',
            'manage_options',
            'ca-widget-deployer',
            array( 'CA_Admin_Widget', 'page_deployer' )
        );

        add_submenu_page(
            'cardano-auctions',
            'Settings',
            'Settings',
            'manage_options',
            'ca-settings',
            array( __CLASS__, 'page_settings' )
        );
    }

    public static function page_settings(): void {
        $errors = CA_Plugin::check_requirements();
        $cron_status = CA_Cron::get_status();
        include CA_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    public static function handle_settings_save(): void {
        if ( ! isset( $_POST['ca_settings_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['ca_settings_nonce'], 'ca_save_settings' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        CA_Settings::save( $_POST );

        add_settings_error( 'ca_settings', 'ca_saved', 'Settings saved.', 'success' );
    }
}
