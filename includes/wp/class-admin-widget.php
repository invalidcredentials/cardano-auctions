<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Admin_Widget {

    const CONFIG_OPTION = 'ca_widget_config';

    public static function init(): void {
        if ( ! is_admin() ) return;
        add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
        add_action( 'wp_ajax_ca_generate_api_key', array( __CLASS__, 'ajax_generate_key' ) );
        add_action( 'wp_ajax_ca_revoke_api_key',   array( __CLASS__, 'ajax_revoke_key' ) );
    }

    public static function page_deployer(): void {
        $api_keys = CA_Api_Keys::get_all();
        $config   = get_option( self::CONFIG_OPTION, array() );
        $network  = CA_Settings::get_network();
        $auctions = CA_Auction_Model::get_all( array( 'network' => $network, 'limit' => 100 ) );
        $rest_url = esc_url_raw( rest_url( 'cah/v1' ) );

        $defaults = array(
            'theme'      => 'game-dark',
            'accent'     => '#00e5ff',
            'confirm'    => '#f0c040',
            'background' => '#0a0e1a',
            'text'       => '#ffffff',
        );
        $config = wp_parse_args( $config, $defaults );

        include CA_PLUGIN_DIR . 'templates/admin-widget-deployer.php';
    }

    public static function handle_save(): void {
        if ( ! isset( $_POST['ca_widget_config_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['ca_widget_config_nonce'], 'ca_save_widget_config' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $config = array(
            'theme'      => sanitize_text_field( $_POST['ca_widget_theme'] ?? 'game-dark' ),
            'accent'     => sanitize_hex_color( $_POST['ca_widget_accent'] ?? '#00e5ff' ),
            'confirm'    => sanitize_hex_color( $_POST['ca_widget_confirm'] ?? '#f0c040' ),
            'background' => sanitize_hex_color( $_POST['ca_widget_background'] ?? '#0a0e1a' ),
            'text'       => sanitize_hex_color( $_POST['ca_widget_text'] ?? '#ffffff' ),
        );

        update_option( self::CONFIG_OPTION, $config, false );
        add_settings_error( 'ca_settings', 'ca_widget_saved', 'Widget appearance saved.', 'success' );
    }

    public static function ajax_generate_key(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $label   = sanitize_text_field( $_POST['label'] ?? '' );
        $origins = array_filter( array_map( 'trim', explode( "\n", $_POST['allowed_origins'] ?? '' ) ) );
        $auction_ids = array();
        if ( ! empty( $_POST['auction_ids'] ) && is_array( $_POST['auction_ids'] ) ) {
            $auction_ids = array_map( 'intval', $_POST['auction_ids'] );
        }

        if ( empty( $label ) ) wp_send_json_error( 'Label is required.' );

        $result = CA_Api_Keys::generate( $label, $origins, $auction_ids );
        wp_send_json_success( $result );
    }

    public static function ajax_revoke_key(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $id = sanitize_text_field( $_POST['key_id'] ?? '' );
        if ( empty( $id ) ) wp_send_json_error( 'Key ID is required.' );

        $revoked = CA_Api_Keys::revoke( $id );
        if ( $revoked ) {
            wp_send_json_success( array( 'revoked' => true ) );
        } else {
            wp_send_json_error( 'Key not found.' );
        }
    }
}
