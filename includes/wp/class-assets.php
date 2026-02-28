<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Assets {

    public static function init(): void {
        add_action( 'wp_enqueue_scripts',    array( __CLASS__, 'frontend' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin' ) );
    }

    public static function frontend(): void {
        // Only load on pages that use our shortcodes.
        global $post;
        if ( ! $post || (
            ! has_shortcode( $post->post_content, 'cardano_auction' ) &&
            ! has_shortcode( $post->post_content, 'cardano_auction_list' )
        ) ) {
            return;
        }

        wp_enqueue_style(
            'ca-auction',
            CA_PLUGIN_URL . 'assets/css/cardano-auction.css',
            array(),
            CA_VERSION
        );

        wp_enqueue_script(
            'ca-auction',
            CA_PLUGIN_URL . 'assets/js/cardano-auction.js',
            array(),
            CA_VERSION,
            true
        );

        wp_localize_script( 'ca-auction', 'CA_Config', array(
            'restUrl'        => esc_url_raw( rest_url( 'cah/v1' ) ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'network'        => CA_Settings::get_network(),
            'paymentTimeout' => CA_Settings::get_payment_timeout(),
            'pollInterval'   => 5000,
        ) );
    }

    public static function admin( string $hook ): void {
        // Only load on our admin pages.
        if ( strpos( $hook, 'cardano-auctions' ) === false && strpos( $hook, 'ca-' ) === false ) {
            return;
        }

        // Enqueue WP media library on auction edit page.
        if ( strpos( $hook, 'ca-auction-edit' ) !== false ) {
            wp_enqueue_media();
        }

        wp_enqueue_style(
            'ca-admin',
            CA_PLUGIN_URL . 'assets/css/cardano-auction-admin.css',
            array(),
            CA_VERSION
        );

        wp_enqueue_script(
            'ca-admin',
            CA_PLUGIN_URL . 'assets/js/cardano-auction-admin.js',
            array( 'jquery' ),
            CA_VERSION,
            true
        );

        wp_localize_script( 'ca-admin', 'CA_Admin', array(
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'ca_admin_nonce' ),
            'pinataEnabled'  => CA_Settings::is_pinata_enabled(),
            'restUrl'        => esc_url_raw( rest_url( 'cah/v1' ) ),
            'widgetUrl'      => CA_PLUGIN_URL . 'assets/js/ca-widget.min.js',
        ) );
    }
}
