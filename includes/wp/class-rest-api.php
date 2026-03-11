<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_REST_API {

    const API_NAMESPACE = 'cah/v1';

    /**
     * Boot the REST API: register routes + CORS filters.
     */
    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
        add_filter( 'rest_pre_serve_request', array( __CLASS__, 'add_cors_headers' ), 10, 4 );
        add_action( 'rest_api_init', array( __CLASS__, 'handle_preflight' ), 5 );
    }

    /**
     * CORS: add headers when an API key is present and origin is allowed.
     */
    public static function add_cors_headers( $served, $result, $request, $server ) {
        $route = $request->get_route();
        if ( strpos( $route, '/' . self::API_NAMESPACE ) !== 0 ) return $served;

        $api_key_raw = $request->get_header( 'X-CA-Api-Key' );
        if ( empty( $api_key_raw ) ) return $served;

        $key_record = CA_Api_Keys::validate( $api_key_raw );
        if ( ! $key_record ) return $served;

        $origin = $request->get_header( 'Origin' );
        if ( empty( $origin ) ) return $served;

        if ( ! CA_Api_Keys::is_origin_allowed( $key_record, $origin ) ) return $served;

        header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
        header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Content-Type, X-CA-Api-Key' );
        header( 'Access-Control-Max-Age: 86400' );

        return $served;
    }

    /**
     * Handle OPTIONS preflight requests for cah/v1 routes.
     */
    public static function handle_preflight(): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'OPTIONS' ) return;

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos( $uri, 'cah/v1' ) === false ) return;

        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';
        $api_key = isset( $_SERVER['HTTP_X_CA_API_KEY'] ) ? $_SERVER['HTTP_X_CA_API_KEY'] : '';

        if ( ! empty( $api_key ) && ! empty( $origin ) ) {
            $key_record = CA_Api_Keys::validate( $api_key );
            if ( $key_record && CA_Api_Keys::is_origin_allowed( $key_record, $origin ) ) {
                header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
                header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
                header( 'Access-Control-Allow-Headers: Content-Type, X-CA-Api-Key' );
                header( 'Access-Control-Max-Age: 86400' );
                header( 'Content-Length: 0' );
                header( 'Content-Type: text/plain' );
                status_header( 200 );
                exit;
            }
        }
    }

    /**
     * Dual auth: WP nonce OR valid API key.
     * Used as permission_callback for authenticated REST endpoints.
     *
     * @return true|WP_Error
     */
    public static function verify_auth( WP_REST_Request $request ) {
        // Check WP nonce first (existing shortcode flow).
        if ( wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
            return true;
        }

        // Check API key (widget flow).
        $api_key_raw = $request->get_header( 'X-CA-Api-Key' );
        if ( ! empty( $api_key_raw ) ) {
            $key_record = CA_Api_Keys::validate( $api_key_raw );
            if ( $key_record ) {
                CA_Api_Keys::touch( $key_record['id'] );
                return true;
            }
        }

        return new WP_Error( 'rest_forbidden', 'Authentication required.', array( 'status' => 403 ) );
    }

    public static function register(): void {
        // Public endpoints.
        register_rest_route( self::API_NAMESPACE, '/auctions', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'list_auctions' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::API_NAMESPACE, '/auctions/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_auction' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::API_NAMESPACE, '/auctions/(?P<id>\d+)/bids', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_bids' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::API_NAMESPACE, '/bids/(?P<id>\d+)/status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'bid_status' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::API_NAMESPACE, '/auctions/(?P<id>\d+)/bidder-status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'bidder_status' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'address' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/config', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_config' ),
            'permission_callback' => '__return_true',
        ) );

        // Authenticated endpoints.
        register_rest_route( self::API_NAMESPACE, '/auctions/(?P<id>\d+)/bid', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'place_bid' ),
            'permission_callback' => array( __CLASS__, 'verify_auth' ),
            'args'                => array(
                'bidder_address' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'bid_ada'        => array( 'required' => true, 'type' => 'number' ),
            ),
        ) );

        // Transaction proxy endpoints (Anvil calls must go through server to avoid CORS + protect API key).
        register_rest_route( self::API_NAMESPACE, '/tx/build', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'tx_build' ),
            'permission_callback' => array( __CLASS__, 'verify_auth' ),
            'args'                => array(
                'change_address' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'outputs'        => array( 'required' => true ),
            ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/tx/submit', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'tx_submit' ),
            'permission_callback' => array( __CLASS__, 'verify_auth' ),
            'args'                => array(
                'transaction' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'witnesses'   => array( 'required' => true ),
            ),
        ) );
    }

    /* ── Handlers ─────────────────────────────────────────────────── */

    public static function list_auctions( WP_REST_Request $request ): WP_REST_Response {
        $status  = $request->get_param( 'status' ) ?: 'active';
        $limit   = (int) ( $request->get_param( 'limit' ) ?: 20 );
        $network = CA_Settings::get_network();

        $auctions = CA_Auction_Model::get_all( array(
            'status'  => $status,
            'network' => $network,
            'limit'   => min( $limit, 100 ),
        ) );

        return new WP_REST_Response( array(
            'auctions' => array_map( array( __CLASS__, 'format_auction' ), $auctions ),
        ) );
    }

    public static function get_auction( WP_REST_Request $request ): WP_REST_Response {
        $auction = CA_Auction_Model::get( (int) $request['id'] );
        if ( ! $auction ) return new WP_REST_Response( array( 'error' => 'Not found.' ), 404 );
        return new WP_REST_Response( self::format_auction( $auction ) );
    }

    public static function get_bids( WP_REST_Request $request ): WP_REST_Response {
        $bids = CA_Bid_Model::get_by_auction( (int) $request['id'] );
        return new WP_REST_Response( array(
            'bids' => array_map( function( $b ) {
                return array(
                    'id'             => (int) $b['id'],
                    'bid_ada'        => number_format( $b['bid_lovelace'] / 1000000, 2, '.', '' ),
                    'bid_lovelace'   => (int) $b['bid_lovelace'],
                    'bidder'         => substr( $b['bidder_address'], 0, 10 ) . '...' . substr( $b['bidder_address'], -6 ),
                    'payment_status' => $b['payment_status'],
                    'created_at'     => $b['created_at'],
                );
            }, $bids ),
        ) );
    }

    public static function bid_status( WP_REST_Request $request ): WP_REST_Response {
        $bid = CA_Bid_Model::get( (int) $request['id'] );
        if ( ! $bid ) return new WP_REST_Response( array( 'error' => 'Not found.' ), 404 );

        // If still awaiting/detected, do a live Blockfrost check right now.
        // This makes the frontend poll act as a real-time payment monitor
        // (WP-Cron alone is unreliable on local/low-traffic sites).
        if ( in_array( $bid['payment_status'], array( 'awaiting', 'detected' ), true ) && ! empty( $bid['escrow_wallet_id'] ) ) {
            $wallet = CA_Escrow_Model::get( (int) $bid['escrow_wallet_id'] );
            if ( $wallet ) {
                $balance = CA_Blockfrost::get_address_balance( $wallet['payment_address'] );
                if ( ! is_wp_error( $balance ) ) {
                    $lovelace = (int) $balance['lovelace'];
                    CA_Escrow_Model::update( (int) $wallet['id'], array( 'balance_lovelace' => $lovelace ) );

                    if ( $lovelace > 0 ) {
                        // Find tx hash from UTxOs.
                        $tx_hash = '';
                        $utxos = CA_Blockfrost::get_address_utxos( $wallet['payment_address'] );
                        if ( ! is_wp_error( $utxos ) && ! empty( $utxos ) ) {
                            $tx_hash = $utxos[0]['tx_hash'] ?? '';
                        }

                        if ( $lovelace >= (int) $bid['bid_lovelace'] ) {
                            CA_Auction_Engine::confirm_bid( (int) $bid['id'], $tx_hash, $lovelace );
                        } elseif ( $bid['payment_status'] === 'awaiting' ) {
                            CA_Bid_Model::update( (int) $bid['id'], array(
                                'payment_status'          => 'detected',
                                'payment_tx_hash'         => $tx_hash,
                                'payment_amount_lovelace' => $lovelace,
                            ) );
                        }

                        // Re-fetch the bid to get updated status.
                        $bid = CA_Bid_Model::get( (int) $request['id'] );
                    }
                }
            }
        }

        return new WP_REST_Response( array(
            'bid_id'         => (int) $bid['id'],
            'payment_status' => $bid['payment_status'],
            'payment_tx'     => $bid['payment_tx_hash'],
            'refund_status'  => $bid['refund_status'],
            'refund_tx'      => $bid['refund_tx_hash'],
        ) );
    }

    public static function bidder_status( WP_REST_Request $request ): WP_REST_Response {
        $auction_id = (int) $request['id'];
        $address    = $request->get_param( 'address' );

        $auction = CA_Auction_Model::get( $auction_id );
        if ( ! $auction ) return new WP_REST_Response( array( 'error' => 'Not found.' ), 404 );

        $is_highest = ( $auction['current_bidder_address'] === $address );

        // Check for pending bids from this address.
        global $wpdb;
        $pending_bid = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, bid_lovelace, payment_status, escrow_address, payment_deadline FROM " . CA_Bid_Model::table() .
            " WHERE auction_id = %d AND bidder_address = %s AND payment_status IN ('awaiting','detected') ORDER BY created_at DESC LIMIT 1",
            $auction_id, $address
        ), ARRAY_A );

        $result = array(
            'is_highest_bidder' => $is_highest,
            'highest_bid_ada'   => $is_highest ? number_format( $auction['current_bid_lovelace'] / 1000000, 2, '.', '' ) : null,
            'has_pending_bid'   => ! empty( $pending_bid ),
        );

        if ( $pending_bid ) {
            $result['pending_bid'] = array(
                'bid_id'           => (int) $pending_bid['id'],
                'bid_ada'          => number_format( $pending_bid['bid_lovelace'] / 1000000, 2, '.', '' ),
                'payment_status'   => $pending_bid['payment_status'],
                'escrow_address'   => $pending_bid['escrow_address'],
                'payment_deadline' => $pending_bid['payment_deadline'],
            );
        }

        return new WP_REST_Response( $result );
    }

    public static function get_config(): WP_REST_Response {
        return new WP_REST_Response( array(
            'network'         => CA_Settings::get_network(),
            'payment_timeout' => CA_Settings::get_payment_timeout(),
            'min_increment'   => CA_Settings::get_default_min_increment(),
            'anvil_base_url'  => CA_Settings::get_anvil_base_url(),
        ) );
    }

    public static function place_bid( WP_REST_Request $request ): WP_REST_Response {
        $auction_id     = (int) $request['id'];
        $bidder_address = $request->get_param( 'bidder_address' );
        $bid_ada        = (float) $request->get_param( 'bid_ada' );
        $bid_lovelace   = (int) round( $bid_ada * 1000000 );

        if ( $bid_lovelace < 1000000 ) {
            return new WP_REST_Response( array( 'error' => 'Minimum bid is 1 ADA.' ), 400 );
        }

        $result = CA_Auction_Engine::place_bid( $auction_id, $bidder_address, $bid_lovelace );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 400 );
        }

        return new WP_REST_Response( $result, 201 );
    }

    /* ── Transaction Proxy (Anvil) ─────────────────────────────────── */

    public static function tx_build( WP_REST_Request $request ): WP_REST_Response {
        $change_address = $request->get_param( 'change_address' );
        $outputs        = $request->get_param( 'outputs' );

        if ( ! is_array( $outputs ) || empty( $outputs ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid outputs.' ), 400 );
        }

        // Sanitise outputs — each must have address + lovelace.
        $clean_outputs = array();
        foreach ( $outputs as $out ) {
            if ( empty( $out['address'] ) || empty( $out['lovelace'] ) ) {
                return new WP_REST_Response( array( 'error' => 'Each output requires address and lovelace.' ), 400 );
            }
            $clean = array(
                'address'  => sanitize_text_field( $out['address'] ),
                'lovelace' => (int) $out['lovelace'],
            );
            // Support native asset outputs (for settlement NFT transfer).
            if ( ! empty( $out['assets'] ) && is_array( $out['assets'] ) ) {
                $clean['assets'] = $out['assets'];
            }
            $clean_outputs[] = $clean;
        }

        $result = CA_Anvil::build( $change_address, $clean_outputs );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 502 );
        }

        return new WP_REST_Response( $result );
    }

    public static function tx_submit( WP_REST_Request $request ): WP_REST_Response {
        $transaction = $request->get_param( 'transaction' );
        $witnesses   = $request->get_param( 'witnesses' );

        if ( ! is_array( $witnesses ) ) {
            $witnesses = array( $witnesses );
        }

        // Anvil expects "signatures" not "witnesses".
        $result = CA_Anvil::submit( $transaction, $witnesses );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 502 );
        }

        return new WP_REST_Response( $result );
    }

    /* ── Formatting ───────────────────────────────────────────────── */

    public static function format_auction( array $a ): array {
        $image = ! empty( $a['image_url'] ) ? $a['image_url'] : $a['nft_image_url'];

        // Build gallery array with backwards compat fallback.
        $gallery = array();
        if ( ! empty( $a['gallery_images'] ) ) {
            $gallery = json_decode( $a['gallery_images'], true );
            if ( ! is_array( $gallery ) ) $gallery = array();
        }
        if ( empty( $gallery ) && $image ) {
            $gallery = array( $image );
        }

        return array(
            'id'                => (int) $a['id'],
            'title'             => $a['title'],
            'description'       => $a['description'],
            'nft_display_name'  => $a['nft_display_name'],
            'nft_image_url'     => $a['nft_image_url'],
            'image_url'         => $image,
            'gallery_images'    => $gallery,
            'nft_policy_id'     => $a['nft_policy_id'],
            'starting_bid_ada'  => number_format( $a['starting_bid_lovelace'] / 1000000, 2, '.', '' ),
            'current_bid_ada'   => $a['current_bid_lovelace'] > 0 ? number_format( $a['current_bid_lovelace'] / 1000000, 2, '.', '' ) : null,
            'current_bid_lovelace' => (int) $a['current_bid_lovelace'],
            'min_increment_ada' => number_format( $a['min_increment_lovelace'] / 1000000, 2, '.', '' ),
            'current_bidder'         => $a['current_bidder_address'] ? substr( $a['current_bidder_address'], 0, 10 ) . '...' . substr( $a['current_bidder_address'], -6 ) : null,
            'current_bidder_address' => $a['current_bidder_address'] ?: null,
            'total_bids'        => (int) $a['total_bids'],
            'start_time'        => $a['start_time'],
            'end_time'          => $a['end_time'],
            'status'            => $a['status'],
            'network'           => $a['network'],
            'artist_name'       => $a['artist_name'] ?? '',
            'listed_by'         => $a['listed_by'] ?? '',
        );
    }
}
