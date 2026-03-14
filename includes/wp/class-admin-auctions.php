<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Admin_Auctions {

    public static function init(): void {
        if ( ! is_admin() ) return;
        add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
        add_action( 'wp_ajax_ca_lookup_nft',   array( __CLASS__, 'ajax_lookup_nft' ) );
        add_action( 'wp_ajax_ca_check_nft',    array( __CLASS__, 'ajax_check_nft' ) );
        add_action( 'wp_ajax_ca_auction_data',  array( __CLASS__, 'ajax_auction_data' ) );
        add_action( 'wp_ajax_ca_poll_payments',   array( __CLASS__, 'ajax_poll_payments' ) );
        add_action( 'wp_ajax_ca_process_refunds', array( __CLASS__, 'ajax_process_refunds' ) );
        add_action( 'wp_ajax_ca_wallet_balance',          array( __CLASS__, 'ajax_wallet_balance' ) );
        add_action( 'wp_ajax_ca_wallet_send',             array( __CLASS__, 'ajax_wallet_send' ) );
        add_action( 'wp_ajax_ca_refresh_balances',        array( __CLASS__, 'ajax_refresh_balances' ) );
        add_action( 'wp_ajax_ca_create_auction_from_nft', array( __CLASS__, 'ajax_create_auction_from_nft' ) );
        add_action( 'wp_ajax_ca_generate_master_wallet',  array( __CLASS__, 'ajax_generate_master_wallet' ) );
        add_action( 'wp_ajax_ca_archive_master_wallet',   array( __CLASS__, 'ajax_archive_master_wallet' ) );
        add_action( 'wp_ajax_ca_unarchive_master_wallet',  array( __CLASS__, 'ajax_unarchive_master_wallet' ) );
        add_action( 'wp_ajax_ca_master_wallet_assets',    array( __CLASS__, 'ajax_master_wallet_assets' ) );
        add_action( 'wp_ajax_ca_master_wallet_balance',   array( __CLASS__, 'ajax_master_wallet_balance' ) );
        add_action( 'wp_ajax_ca_dismiss_mnemonic',        array( __CLASS__, 'ajax_dismiss_mnemonic' ) );

        // Minting AJAX.
        add_action( 'wp_ajax_ca_create_policy',     array( __CLASS__, 'ajax_create_policy' ) );
        add_action( 'wp_ajax_ca_get_policies',      array( __CLASS__, 'ajax_get_policies' ) );

        // Policy import AJAX.
        add_action( 'wp_ajax_ca_import_policy_skey',     array( __CLASS__, 'ajax_import_policy_skey' ) );
        add_action( 'wp_ajax_ca_import_policy_mnemonic', array( __CLASS__, 'ajax_import_policy_mnemonic' ) );
        add_action( 'wp_ajax_ca_import_policy_manual',   array( __CLASS__, 'ajax_import_policy_manual' ) );
        add_action( 'wp_ajax_ca_pin_to_ipfs',       array( __CLASS__, 'ajax_pin_to_ipfs' ) );
        add_action( 'wp_ajax_ca_test_pinata',       array( __CLASS__, 'ajax_test_pinata' ) );
        add_action( 'wp_ajax_ca_mint_auction_nft',  array( __CLASS__, 'ajax_mint_auction_nft' ) );
    }

    /* ── Pages ────────────────────────────────────────────────────── */

    public static function page_list(): void {
        $status  = sanitize_text_field( $_GET['status'] ?? '' );
        $network = CA_Settings::get_network();
        $args    = array( 'network' => $network );
        if ( $status ) $args['status'] = $status;
        $auctions = CA_Auction_Model::get_all( $args );
        $counts   = array(
            'all'    => CA_Auction_Model::count( array( 'network' => $network ) ),
            'draft'  => CA_Auction_Model::count( array( 'network' => $network, 'status' => 'draft' ) ),
            'active' => CA_Auction_Model::count( array( 'network' => $network, 'status' => 'active' ) ),
            'settled'=> CA_Auction_Model::count( array( 'network' => $network, 'status' => 'settled' ) ),
        );
        include CA_PLUGIN_DIR . 'templates/admin-auction-list.php';
    }

    public static function page_edit(): void {
        $id             = (int) ( $_GET['id'] ?? 0 );
        $auction        = $id ? CA_Auction_Model::get( $id ) : null;
        $custody        = $id ? CA_Escrow_Model::get_custody_wallet( $id ) : null;
        $bids           = $id ? CA_Bid_Model::get_by_auction( $id ) : array();
        $events         = $id ? CA_Event_Log_Model::get_by_auction( $id ) : array();
        $master_wallet  = CA_Master_Wallet::get_active();
        $policies       = CA_Mint_Policy::get_all_active();
        include CA_PLUGIN_DIR . 'templates/admin-auction-edit.php';
    }

    public static function page_wallets(): void {
        $network = CA_Settings::get_network();
        $filter  = sanitize_text_field( $_GET['filter'] ?? '' );
        $status  = sanitize_text_field( $_GET['status'] ?? '' );

        $base_args = array( 'network' => $network );
        if ( $status ) $base_args['status'] = $status;

        // Build filter args for current tab.
        $args = $base_args;
        if ( 'custody' === $filter ) {
            $args['purpose'] = 'nft_custody';
        } elseif ( 'escrow' === $filter ) {
            $args['purpose'] = 'escrow';
        }

        $wallets = CA_Escrow_Model::get_all( $args );

        // Summary counts.
        $counts = array(
            'total'   => CA_Escrow_Model::count( array( 'network' => $network ) ),
            'custody' => CA_Escrow_Model::count( array( 'network' => $network, 'purpose' => 'nft_custody' ) ),
            'escrow'  => CA_Escrow_Model::count( array( 'network' => $network, 'purpose' => 'escrow' ) ),
            'active'  => CA_Escrow_Model::count( array( 'network' => $network, 'status'  => 'active' ) ),
        );

        // Master wallet data.
        $master_wallet       = CA_Master_Wallet_Model::get_active( $network );
        $master_mnemonic     = $master_wallet ? get_transient( 'ca_master_mnemonic_' . $master_wallet['id'] ) : false;
        $archived_count      = CA_Master_Wallet_Model::count_archived( $network );
        $archived_wallets    = $archived_count > 0 ? CA_Master_Wallet_Model::get_archived( $network ) : array();

        include CA_PLUGIN_DIR . 'templates/admin-wallet-dashboard.php';
    }

    /* ── Save / Actions ───────────────────────────────────────────── */

    public static function handle_actions(): void {
        // Save auction.
        if ( isset( $_POST['ca_auction_nonce'] ) && wp_verify_nonce( $_POST['ca_auction_nonce'], 'ca_save_auction' ) && current_user_can( 'manage_options' ) ) {
            self::save_auction();
            return;
        }

        // Quick actions (activate, cancel, settle).
        if ( isset( $_GET['ca_action'], $_GET['ca_nonce'] ) && current_user_can( 'manage_options' ) ) {
            $action = sanitize_text_field( $_GET['ca_action'] );
            $id     = (int) ( $_GET['id'] ?? 0 );

            if ( ! wp_verify_nonce( $_GET['ca_nonce'], 'ca_action_' . $action . '_' . $id ) ) return;

            switch ( $action ) {
                case 'activate':
                    CA_Auction_Engine::activate_auction( $id );
                    break;
                case 'force_activate':
                    CA_Auction_Engine::force_activate( $id );
                    break;
                case 'cancel':
                    CA_Auction_Engine::cancel_auction( $id );
                    break;
                case 'settle':
                    CA_Settlement::settle( $id );
                    break;
                case 'delete':
                    CA_Auction_Model::delete( $id );
                    break;
                case 'export_events':
                    self::export_events_csv( $id );
                    return; // exits via die()
            }

            wp_redirect( admin_url( 'admin.php?page=cardano-auctions&updated=1' ) );
            exit;
        }
    }

    private static function sanitize_gallery_images( string $json ): string {
        $urls = json_decode( wp_unslash( $json ), true );
        if ( ! is_array( $urls ) ) return '[]';
        $clean = array();
        foreach ( $urls as $url ) {
            $sanitized = esc_url_raw( $url );
            if ( $sanitized ) $clean[] = $sanitized;
        }
        return wp_json_encode( $clean );
    }

    private static function save_auction(): void {
        $id   = (int) ( $_POST['auction_id'] ?? 0 );
        $data = array(
            'title'                => sanitize_text_field( $_POST['title'] ?? '' ),
            'description'          => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'artist_name'          => sanitize_text_field( $_POST['artist_name'] ?? '' ),
            'listed_by'            => sanitize_text_field( $_POST['listed_by'] ?? '' ),
            'nft_policy_id'        => sanitize_text_field( $_POST['nft_policy_id'] ?? '' ),
            'nft_asset_name_hex'   => sanitize_text_field( $_POST['nft_asset_name_hex'] ?? '' ),
            'nft_display_name'     => sanitize_text_field( $_POST['nft_display_name'] ?? '' ),
            'nft_image_url'        => esc_url_raw( $_POST['nft_image_url'] ?? '' ),
            'nft_metadata'         => sanitize_textarea_field( $_POST['nft_metadata'] ?? '' ),
            'image_url'            => esc_url_raw( $_POST['image_url'] ?? '' ),
            'gallery_images'       => self::sanitize_gallery_images( $_POST['gallery_images'] ?? '[]' ),
            'starting_bid_lovelace'=> (int) ( ( (float) ( $_POST['starting_bid'] ?? 0 ) ) * 1000000 ),
            'reserve_price_lovelace'=> (int) ( ( (float) ( $_POST['reserve_price'] ?? 0 ) ) * 1000000 ),
            'min_increment_lovelace'=> (int) ( ( (float) ( $_POST['min_increment'] ?? 1 ) ) * 1000000 ),
            'end_time'             => sanitize_text_field( $_POST['end_time'] ?? '' ),
            'original_end_time'    => sanitize_text_field( $_POST['end_time'] ?? '' ),
            'anti_snipe_seconds'   => (int) ( $_POST['anti_snipe_seconds'] ?? 300 ),
            'anti_snipe_threshold' => (int) ( $_POST['anti_snipe_threshold'] ?? 300 ),
            'seller_address'       => sanitize_text_field( $_POST['seller_address'] ?? '' ),
            'mint_tx_hash'         => sanitize_text_field( $_POST['mint_tx_hash'] ?? '' ) ?: null,
            'mint_policy_table_id' => (int) ( $_POST['mint_policy_table_id'] ?? 0 ) ?: null,
            'network'              => CA_Settings::get_network(),
        );

        if ( $id ) {
            unset( $data['original_end_time'] );
            CA_Auction_Model::update( $id, $data );
        } else {
            $data['status'] = 'draft';
            $id = CA_Auction_Model::insert( $data );

            // Auto-generate custody wallet for new auctions (only when no master wallet).
            if ( $id && ! empty( $data['nft_policy_id'] ) && ! CA_Master_Wallet::get_active() ) {
                $custody = CA_Escrow::generate( $id, 0, 'nft_custody' );
                if ( ! is_wp_error( $custody ) ) {
                    CA_Auction_Model::update( $id, array( 'nft_wallet_id' => $custody['id'] ) );
                }
            }
        }

        wp_redirect( admin_url( 'admin.php?page=ca-auction-edit&id=' . $id . '&saved=1' ) );
        exit;
    }

    /* ── CSV Export ───────────────────────────────────────────────── */

    private static function export_events_csv( int $auction_id ): void {
        $args = array();
        if ( $auction_id ) {
            $args['auction_id'] = $auction_id;
        }
        $events = CA_Event_Log_Model::get_all( $args );

        $filename = $auction_id
            ? 'auction-' . $auction_id . '-events-' . gmdate( 'Y-m-d' ) . '.csv'
            : 'all-events-' . gmdate( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'ID', 'Auction ID', 'Bid ID', 'Event Type', 'Message', 'Meta', 'Created At' ) );

        foreach ( $events as $ev ) {
            fputcsv( $out, array(
                $ev['id'],
                $ev['auction_id'],
                $ev['bid_id'],
                $ev['event_type'],
                $ev['message'],
                $ev['meta'],
                $ev['created_at'],
            ) );
        }

        fclose( $out );
        die();
    }

    /* ── AJAX Handlers ────────────────────────────────────────────── */

    public static function ajax_lookup_nft(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $unit = sanitize_text_field( $_POST['unit'] ?? '' );
        if ( empty( $unit ) ) wp_send_json_error( 'No asset unit provided.' );

        $info = CA_Blockfrost::get_asset_info( $unit );
        if ( is_wp_error( $info ) ) wp_send_json_error( $info->get_error_message() );

        wp_send_json_success( $info );
    }

    public static function ajax_check_nft(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $auction_id = (int) ( $_POST['auction_id'] ?? 0 );
        $auction = CA_Auction_Model::get( $auction_id );
        if ( ! $auction ) wp_send_json_error( 'Auction not found.' );

        $custody = CA_Escrow_Model::get_custody_wallet( $auction_id );
        if ( ! $custody ) wp_send_json_error( 'No custody wallet.' );

        $unit = $auction['nft_policy_id'] . $auction['nft_asset_name_hex'];
        $has_nft = CA_Blockfrost::address_has_asset( $custody['payment_address'], $unit );

        if ( is_wp_error( $has_nft ) ) wp_send_json_error( $has_nft->get_error_message() );

        wp_send_json_success( array(
            'has_nft' => (bool) $has_nft,
            'address' => $custody['payment_address'],
        ) );
    }

    public static function ajax_auction_data(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $auction_id = (int) ( $_POST['auction_id'] ?? 0 );
        $auction = CA_Auction_Model::get( $auction_id );
        if ( ! $auction ) wp_send_json_error( 'Auction not found.' );

        $bids   = CA_Bid_Model::get_by_auction( $auction_id );
        $events = CA_Event_Log_Model::get_by_auction( $auction_id, 20 );

        wp_send_json_success( array( 'auction' => $auction, 'bids' => $bids, 'events' => $events ) );
    }

    public static function ajax_poll_payments(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $auction_id = (int) ( $_POST['auction_id'] ?? 0 );

        // Run the payment monitor for all awaiting bids on this auction.
        $bids = CA_Bid_Model::get_by_auction( $auction_id );
        $checked = 0;
        $confirmed = 0;

        foreach ( $bids as $bid ) {
            if ( ! in_array( $bid['payment_status'], array( 'awaiting', 'detected' ), true ) ) continue;
            if ( empty( $bid['escrow_wallet_id'] ) ) continue;

            $checked++;
            $wallet = CA_Escrow_Model::get( (int) $bid['escrow_wallet_id'] );
            if ( ! $wallet ) continue;

            $balance = CA_Blockfrost::get_address_balance( $wallet['payment_address'] );
            if ( is_wp_error( $balance ) ) continue;

            $lovelace = (int) $balance['lovelace'];
            CA_Escrow_Model::update( (int) $wallet['id'], array( 'balance_lovelace' => $lovelace ) );

            if ( $lovelace <= 0 ) continue;

            $tx_hash = '';
            $utxos = CA_Blockfrost::get_address_utxos( $wallet['payment_address'] );
            if ( ! is_wp_error( $utxos ) && ! empty( $utxos ) ) {
                $tx_hash = $utxos[0]['tx_hash'] ?? '';
            }

            if ( $lovelace >= (int) $bid['bid_lovelace'] ) {
                CA_Auction_Engine::confirm_bid( (int) $bid['id'], $tx_hash, $lovelace );
                $confirmed++;
            } elseif ( $bid['payment_status'] === 'awaiting' ) {
                CA_Bid_Model::update( (int) $bid['id'], array(
                    'payment_status'          => 'detected',
                    'payment_tx_hash'         => $tx_hash,
                    'payment_amount_lovelace' => $lovelace,
                ) );
            }
        }

        wp_send_json_success( array(
            'checked'   => $checked,
            'confirmed' => $confirmed,
            'message'   => $confirmed > 0
                ? "{$confirmed} payment(s) confirmed!"
                : ( $checked > 0 ? "Checked {$checked} bid(s), no new payments found." : 'No awaiting bids to check.' ),
        ) );
    }

    public static function ajax_process_refunds(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $auction_id = (int) ( $_POST['auction_id'] ?? 0 );
        $bids = CA_Bid_Model::get_by_auction( $auction_id );
        $processed = 0;
        $succeeded = 0;
        $errors = array();

        foreach ( $bids as $bid ) {
            if ( $bid['payment_status'] !== 'refunding' ) continue;
            if ( ! in_array( $bid['refund_status'], array( 'pending', '', 'failed' ), true ) ) continue;

            $processed++;

            // Reset failed status so we can retry.
            if ( $bid['refund_status'] === 'failed' ) {
                CA_Bid_Model::update( (int) $bid['id'], array( 'refund_status' => 'pending' ) );
                $bid['refund_status'] = 'pending';
            }

            $result = CA_Refund_Processor::refund_bid( $bid );
            if ( $result === true ) {
                $succeeded++;
            } else {
                $errors[] = sprintf( 'Bid #%d: %s', $bid['id'], is_string( $result ) ? $result : 'Unknown error' );
            }
        }

        $message = $succeeded > 0
            ? "{$succeeded} refund(s) processed successfully!"
            : ( $processed > 0
                ? "Processed {$processed} refund(s), none succeeded. " . implode( '; ', $errors )
                : 'No pending refunds found.' );

        wp_send_json_success( array(
            'processed' => $processed,
            'succeeded' => $succeeded,
            'errors'    => $errors,
            'message'   => $message,
        ) );
    }

    /* ── Wallet Dashboard AJAX ───────────────────────────────────── */

    public static function ajax_wallet_balance(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $wallet_id = (int) ( $_POST['wallet_id'] ?? 0 );
        $wallet = CA_Escrow_Model::get( $wallet_id );
        if ( ! $wallet ) wp_send_json_error( 'Wallet not found.' );

        $balance = CA_Blockfrost::get_address_balance( $wallet['payment_address'] );
        if ( is_wp_error( $balance ) ) wp_send_json_error( $balance->get_error_message() );

        // Update cached balance.
        CA_Escrow_Model::update( $wallet_id, array( 'balance_lovelace' => $balance['lovelace'] ) );

        // Enrich assets with metadata (cached in transients).
        $assets = array();
        foreach ( $balance['assets'] as $asset ) {
            $info = self::get_cached_asset_info( $asset['unit'] );
            $assets[] = array(
                'unit'  => $asset['unit'],
                'qty'   => $asset['quantity'],
                'name'  => $info ? ( $info['asset_name'] ?: $info['asset_name_hex'] ) : substr( $asset['unit'], 56 ),
                'image' => $info ? $info['image'] : '',
            );
        }

        wp_send_json_success( array(
            'lovelace' => $balance['lovelace'],
            'ada'      => $balance['ada'],
            'assets'   => $assets,
        ) );
    }

    public static function ajax_wallet_send(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $wallet_id = (int) ( $_POST['wallet_id'] ?? 0 );
        $recipient = sanitize_text_field( $_POST['recipient'] ?? '' );
        $amount    = (float) ( $_POST['amount'] ?? 0 );

        if ( empty( $recipient ) || $amount <= 0 ) {
            wp_send_json_error( 'Invalid recipient or amount.' );
        }

        // Validate Cardano address format.
        if ( strpos( $recipient, 'addr' ) !== 0 ) {
            wp_send_json_error( 'Invalid Cardano address.' );
        }

        $lovelace = (int) ( $amount * 1000000 );
        $result = CA_Escrow::send_from( $wallet_id, array(
            array( 'address' => $recipient, 'lovelace' => $lovelace ),
        ) );

        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        // Log the send event.
        $wallet = CA_Escrow_Model::get( $wallet_id );
        CA_Event_Log_Model::log(
            (int) ( $wallet['auction_id'] ?? 0 ),
            0,
            'wallet_send',
            sprintf( 'Sent %s ADA from wallet #%d to %s', number_format( $amount, 2 ), $wallet_id, substr( $recipient, 0, 20 ) . '...' ),
            array( 'txHash' => $result['txHash'], 'lovelace' => $lovelace, 'recipient' => $recipient )
        );

        wp_send_json_success( array( 'txHash' => $result['txHash'] ) );
    }

    public static function ajax_refresh_balances(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $network = CA_Settings::get_network();
        $wallets = CA_Escrow_Model::get_all( array( 'network' => $network, 'status' => 'active' ) );
        $updated = 0;
        $results = array();

        foreach ( $wallets as $w ) {
            $balance = CA_Blockfrost::get_address_balance( $w['payment_address'] );
            if ( is_wp_error( $balance ) ) continue;

            CA_Escrow_Model::update( (int) $w['id'], array( 'balance_lovelace' => $balance['lovelace'] ) );
            $updated++;
            $results[] = array(
                'id'  => (int) $w['id'],
                'ada' => $balance['ada'],
            );
        }

        wp_send_json_success( array( 'updated' => $updated, 'wallets' => $results ) );
    }

    public static function ajax_create_auction_from_nft(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $wallet_id = (int) ( $_POST['wallet_id'] ?? 0 );
        $unit      = sanitize_text_field( $_POST['unit'] ?? '' );

        $wallet = CA_Escrow_Model::get( $wallet_id );
        if ( ! $wallet ) wp_send_json_error( 'Wallet not found.' );
        if ( empty( $unit ) ) wp_send_json_error( 'No asset unit provided.' );

        // Lookup asset info from Blockfrost.
        $info = self::get_cached_asset_info( $unit );
        if ( ! $info ) wp_send_json_error( 'Could not look up asset info.' );

        $policy_id      = $info['policy_id'];
        $asset_name_hex = $info['asset_name_hex'];
        $display_name   = $info['asset_name'] ?: $info['asset_name_hex'];
        $image          = $info['image'] ?? '';
        $metadata_json  = ! empty( $info['metadata'] ) ? wp_json_encode( $info['metadata'] ) : '';

        // Create draft auction.
        $auction_id = CA_Auction_Model::insert( array(
            'title'               => $display_name,
            'description'         => '',
            'nft_policy_id'       => $policy_id,
            'nft_asset_name_hex'  => $asset_name_hex,
            'nft_display_name'    => $display_name,
            'nft_image_url'       => $image,
            'nft_metadata'        => $metadata_json,
            'image_url'           => '',
            'gallery_images'      => '[]',
            'nft_wallet_id'       => $wallet_id,
            'starting_bid_lovelace' => 5000000,
            'min_increment_lovelace'=> CA_Settings::get_default_min_increment(),
            'seller_address'      => CA_Settings::get_default_seller_address(),
            'status'              => 'draft',
            'network'             => CA_Settings::get_network(),
        ) );

        if ( ! $auction_id ) wp_send_json_error( 'Failed to create auction.' );

        // Link wallet to the new auction.
        CA_Escrow_Model::update( $wallet_id, array( 'auction_id' => $auction_id, 'purpose' => 'nft_custody' ) );

        CA_Event_Log_Model::log( $auction_id, 0, 'auction_created_from_nft', sprintf(
            'Draft auction created from NFT %s in wallet #%d', $display_name, $wallet_id
        ) );

        wp_send_json_success( array(
            'redirect_url' => admin_url( 'admin.php?page=ca-auction-edit&id=' . $auction_id ),
        ) );
    }

    /* ── Master Wallet AJAX ────────────────────────────────────────── */

    public static function ajax_generate_master_wallet(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $name = sanitize_text_field( $_POST['wallet_name'] ?? 'Master Wallet' );
        if ( empty( $name ) ) $name = 'Master Wallet';

        $result = CA_Master_Wallet::generate( $name );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        wp_send_json_success( array(
            'id'      => $result['id'],
            'address' => $result['payment_address'],
            'mnemonic'=> $result['mnemonic'],
        ) );
    }

    public static function ajax_archive_master_wallet(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $wallet_id = (int) ( $_POST['wallet_id'] ?? 0 );
        $wallet = CA_Master_Wallet_Model::get( $wallet_id );
        if ( ! $wallet ) wp_send_json_error( 'Wallet not found.' );

        CA_Master_Wallet_Model::archive( $wallet_id );

        CA_Event_Log_Model::log( 0, 0, 'master_wallet_archived', sprintf(
            'Master wallet #%d "%s" archived.',
            $wallet_id,
            $wallet['wallet_name']
        ) );

        wp_send_json_success( array( 'archived' => true ) );
    }

    public static function ajax_unarchive_master_wallet(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $wallet_id = (int) ( $_POST['wallet_id'] ?? 0 );
        $wallet = CA_Master_Wallet_Model::get( $wallet_id );
        if ( ! $wallet ) wp_send_json_error( 'Wallet not found.' );

        // Check no other active wallet on this network.
        $network = $wallet['network'];
        $existing = CA_Master_Wallet_Model::get_active( $network );
        if ( $existing ) {
            wp_send_json_error( 'Another active master wallet exists on ' . $network . '. Archive it first.' );
        }

        CA_Master_Wallet_Model::unarchive( $wallet_id );

        CA_Event_Log_Model::log( 0, 0, 'master_wallet_unarchived', sprintf(
            'Master wallet #%d "%s" unarchived.',
            $wallet_id,
            $wallet['wallet_name']
        ) );

        wp_send_json_success( array( 'unarchived' => true ) );
    }

    public static function ajax_master_wallet_assets(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $wallet_id = (int) ( $_POST['wallet_id'] ?? 0 );
        if ( ! $wallet_id ) {
            $master = CA_Master_Wallet::get_active();
            if ( ! $master ) wp_send_json_error( 'No active master wallet.' );
            $wallet_id = (int) $master['id'];
        }

        $assets = CA_Master_Wallet::get_assets( $wallet_id );
        if ( is_wp_error( $assets ) ) wp_send_json_error( $assets->get_error_message() );

        wp_send_json_success( array( 'assets' => $assets ) );
    }

    public static function ajax_master_wallet_balance(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $wallet_id = (int) ( $_POST['wallet_id'] ?? 0 );
        if ( ! $wallet_id ) {
            $master = CA_Master_Wallet::get_active();
            if ( ! $master ) wp_send_json_error( 'No active master wallet.' );
            $wallet_id = (int) $master['id'];
        }

        $wallet = CA_Master_Wallet_Model::get( $wallet_id );
        if ( ! $wallet ) wp_send_json_error( 'Wallet not found.' );

        $balance = CA_Blockfrost::get_address_balance( $wallet['payment_address'] );
        if ( is_wp_error( $balance ) ) wp_send_json_error( $balance->get_error_message() );

        wp_send_json_success( array(
            'lovelace' => $balance['lovelace'],
            'ada'      => $balance['ada'],
            'assets'   => $balance['assets'],
        ) );
    }

    public static function ajax_dismiss_mnemonic(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $wallet_id = (int) ( $_POST['wallet_id'] ?? 0 );
        if ( $wallet_id ) {
            delete_transient( 'ca_master_mnemonic_' . $wallet_id );
        }

        wp_send_json_success();
    }

    /* ── Minting AJAX ────────────────────────────────────────────── */

    public static function ajax_create_policy(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $name       = sanitize_text_field( $_POST['name'] ?? '' );
        $expiration = sanitize_text_field( $_POST['expiration_date'] ?? '' );

        if ( empty( $name ) ) wp_send_json_error( 'Collection name is required.' );

        $result = CA_Mint_Policy::generate( $name, $expiration );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        wp_send_json_success( $result );
    }

    public static function ajax_get_policies(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $policies = CA_Mint_Policy::get_all_active();
        wp_send_json_success( array( 'policies' => $policies ) );
    }

    /* ── Policy Import AJAX ──────────────────────────────────────── */

    public static function ajax_import_policy_skey(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $name   = sanitize_text_field( $_POST['name'] ?? '' );
        $skey   = wp_unslash( $_POST['skey'] ?? '' );
        $script = wp_unslash( $_POST['script'] ?? '' );

        if ( empty( $name ) )   wp_send_json_error( 'Collection name is required.' );
        if ( empty( $skey ) )   wp_send_json_error( 'Signing key is required.' );
        if ( empty( $script ) ) wp_send_json_error( 'Policy script is required.' );

        $result = CA_Policy_Import::import_skey_and_script( $name, $skey, $script );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        wp_send_json_success( $result );
    }

    public static function ajax_import_policy_mnemonic(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $name       = sanitize_text_field( $_POST['name'] ?? '' );
        $mnemonic   = sanitize_text_field( $_POST['mnemonic'] ?? '' );
        $expiration = sanitize_text_field( $_POST['expiration_date'] ?? '' );

        if ( empty( $name ) )     wp_send_json_error( 'Collection name is required.' );
        if ( empty( $mnemonic ) ) wp_send_json_error( 'Seed phrase is required.' );

        $result = CA_Policy_Import::import_mnemonic( $name, $mnemonic, $expiration );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        wp_send_json_success( $result );
    }

    public static function ajax_import_policy_manual(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $name      = sanitize_text_field( $_POST['name'] ?? '' );
        $policy_id = sanitize_text_field( $_POST['policy_id'] ?? '' );
        $skey      = wp_unslash( $_POST['skey'] ?? '' );
        $script    = wp_unslash( $_POST['script'] ?? '' );

        if ( empty( $name ) )      wp_send_json_error( 'Collection name is required.' );
        if ( empty( $policy_id ) ) wp_send_json_error( 'Policy ID is required.' );
        if ( empty( $skey ) )      wp_send_json_error( 'Signing key is required.' );
        if ( empty( $script ) )    wp_send_json_error( 'Policy script is required.' );

        $result = CA_Policy_Import::import_manual( $name, $policy_id, $script, $skey );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        wp_send_json_success( $result );
    }

    public static function ajax_pin_to_ipfs(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $attachment_id = (int) ( $_POST['attachment_id'] ?? 0 );
        if ( ! $attachment_id ) wp_send_json_error( 'No attachment ID provided.' );

        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            wp_send_json_error( 'Attachment file not found.' );
        }

        $name = get_the_title( $attachment_id ) ?: basename( $file_path );

        $result = CA_Pinata::upload( $file_path, $name );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        wp_send_json_success( $result );
    }

    public static function ajax_test_pinata(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $result = CA_Pinata::test_connection();
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        wp_send_json_success( $result );
    }

    public static function ajax_mint_auction_nft(): void {
        check_ajax_referer( 'ca_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        $policy_db_id = (int) ( $_POST['policy_db_id'] ?? 0 );
        $title        = sanitize_text_field( $_POST['title'] ?? '' );
        $image_ipfs   = sanitize_text_field( $_POST['image_ipfs'] ?? '' );
        $media_type   = sanitize_text_field( $_POST['media_type'] ?? 'image/png' );
        $description  = sanitize_textarea_field( $_POST['description'] ?? '' );
        $category     = sanitize_text_field( $_POST['category'] ?? '' );
        $condition    = sanitize_text_field( $_POST['condition'] ?? '' );
        $creator      = sanitize_text_field( $_POST['creator'] ?? '' );
        $starting_price = sanitize_text_field( $_POST['starting_price'] ?? '' );
        $reserve_price  = sanitize_text_field( $_POST['reserve_price'] ?? '' );

        // Custom attributes.
        $custom_raw = $_POST['custom'] ?? array();
        $custom = array();
        if ( is_array( $custom_raw ) ) {
            foreach ( $custom_raw as $attr ) {
                $k = sanitize_text_field( $attr['key'] ?? '' );
                $v = sanitize_text_field( $attr['value'] ?? '' );
                if ( $k && $v !== '' ) {
                    $custom[] = array( 'key' => $k, 'value' => $v );
                }
            }
        }

        if ( ! $policy_db_id ) wp_send_json_error( 'No collection selected.' );
        if ( empty( $title ) )      wp_send_json_error( 'NFT title is required.' );
        if ( empty( $image_ipfs ) ) wp_send_json_error( 'NFT image (IPFS CID or URL) is required.' );

        // Build IPFS URI if just a CID was passed.
        if ( strpos( $image_ipfs, 'ipfs://' ) !== 0 && strpos( $image_ipfs, 'http' ) !== 0 ) {
            $image_ipfs = 'ipfs://' . $image_ipfs;
        }

        // Build CIP-25 metadata.
        $cip25 = CA_Metadata_Builder::build( array(
            'name'           => $title,
            'image'          => $image_ipfs,
            'mediaType'      => $media_type,
            'description'    => $description,
            'starting_price' => $starting_price ? $starting_price . ' ADA' : '',
            'reserve_price'  => $reserve_price ? $reserve_price . ' ADA' : '',
            'category'       => $category,
            'condition'      => $condition,
            'creator'        => $creator,
            'custom'         => $custom,
        ) );

        // Generate asset name.
        $policy = CA_Mint_Policy_Model::get( $policy_db_id );
        $asset_name = CA_Minter::generate_asset_name( $title, $policy ? $policy['policy_id'] : '' );

        // Mint.
        $result = CA_Minter::mint_to_master( $policy_db_id, $asset_name, $cip25 );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        // Return data for the form auto-population.
        $ipfs_gateway_url = '';
        if ( strpos( $image_ipfs, 'ipfs://' ) === 0 ) {
            $cid = substr( $image_ipfs, 7 );
            $ipfs_gateway_url = 'https://ipfs.io/ipfs/' . $cid;
        }

        $result['nft_image_url']   = $ipfs_gateway_url ?: $image_ipfs;
        $result['nft_display_name'] = $title;
        $result['nft_metadata']    = wp_json_encode( $cip25 );
        $result['policy_db_id']    = $policy_db_id;

        wp_send_json_success( $result );
    }

    /* ── Helpers ──────────────────────────────────────────────────── */

    private static function get_cached_asset_info( string $unit ): ?array {
        $cache_key = 'ca_asset_' . md5( $unit );
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $info = CA_Blockfrost::get_asset_info( $unit );
        if ( is_wp_error( $info ) ) return null;

        set_transient( $cache_key, $info, HOUR_IN_SECONDS );
        return $info;
    }
}
