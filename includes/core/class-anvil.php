<?php
/**
 * CA_Anvil — Anvil API client with native asset output support.
 * Self-contained; based on WeldPress_Anvil_Client.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Anvil {

    /**
     * Build an unsigned transaction (ADA only).
     *
     * @param string $change_address Bech32 change address.
     * @param array  $outputs        [ { address, lovelace } ].
     * @return array|WP_Error
     */
    public static function build( string $change_address, array $outputs ) {
        return self::post( '/v2/services/transactions/build', array(
            'changeAddress' => $change_address,
            'outputs'       => $outputs,
        ) );
    }

    /**
     * Build a transaction with native asset outputs (for NFT transfer).
     *
     * @param string $change_address  Bech32 change address.
     * @param array  $outputs         [ { address, lovelace, assets? } ].
     * @return array|WP_Error
     */
    public static function build_with_assets( string $change_address, array $outputs ) {
        return self::post( '/v2/services/transactions/build', array(
            'changeAddress' => $change_address,
            'outputs'       => $outputs,
        ) );
    }

    /**
     * Submit a signed transaction.
     *
     * @param string $transaction  Unsigned tx CBOR hex (Anvil format).
     * @param array  $signatures   Witness set hex strings.
     * @return array|WP_Error
     */
    public static function submit( string $transaction, array $signatures = array() ) {
        $payload = array( 'transaction' => $transaction );
        if ( ! empty( $signatures ) ) {
            $payload['signatures'] = $signatures;
        }
        return self::post( '/v2/services/transactions/submit', $payload );
    }

    /**
     * Check Anvil API health.
     *
     * @return array|WP_Error
     */
    public static function health() {
        $api_key = CA_Settings::get_anvil_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'ca_no_anvil_key', 'Anvil API key is not configured.' );
        }
        $response = wp_remote_get(
            CA_Settings::get_anvil_base_url() . '/v2/services/health',
            array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Api-Key'    => $api_key,
                ),
            )
        );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code >= 400 ) {
            return new WP_Error( 'anvil_error', isset( $body['message'] ) ? $body['message'] : "Anvil HTTP {$code}" );
        }
        return $body;
    }

    /**
     * Build a minting transaction with CIP-25 metadata.
     *
     * @param string $change_address   Bech32 change address (also funds the TX).
     * @param array  $outputs          [ { address, lovelace, assets? } ].
     * @param array  $mint             Mint object with assets and metadata.
     * @param array  $preloaded_scripts Preloaded native scripts for the mint.
     * @return array|WP_Error
     */
    public static function build_mint( string $change_address, array $outputs, array $mint, array $preloaded_scripts ) {
        return self::post( '/v2/services/transactions/build', array(
            'changeAddress'    => $change_address,
            'outputs'          => $outputs,
            'mint'             => $mint,
            'preloadedScripts' => $preloaded_scripts,
        ) );
    }

    /* ── HTTP POST ────────────────────────────────────────────────── */

    public static function post( string $endpoint, array $payload ) {
        $api_key = CA_Settings::get_anvil_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'ca_no_anvil_key', 'Anvil API key is not configured.' );
        }
        $response = wp_remote_post(
            CA_Settings::get_anvil_base_url() . $endpoint,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Api-Key'    => $api_key,
                ),
                'body' => wp_json_encode( $payload ),
            )
        );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code >= 400 ) {
            return new WP_Error( 'anvil_error', isset( $body['message'] ) ? $body['message'] : "Anvil HTTP {$code}", array( 'status' => $code ) );
        }
        return $body;
    }
}
