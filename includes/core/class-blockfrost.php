<?php
/**
 * CA_Blockfrost — Blockfrost API client with UTxO + asset queries.
 * Self-contained; based on WeldPress_Blockfrost_Client.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Blockfrost {

    /**
     * Get address balance (ADA + native assets).
     *
     * @return array|WP_Error { lovelace, ada, assets[] }
     */
    public static function get_address_balance( string $address ) {
        $result = self::request( '/addresses/' . $address );
        if ( is_wp_error( $result ) ) {
            if ( $result->get_error_data() && isset( $result->get_error_data()['status'] ) && 404 === $result->get_error_data()['status'] ) {
                return array( 'lovelace' => 0, 'ada' => '0.000000', 'assets' => array() );
            }
            return $result;
        }
        $lovelace = 0; $assets = array();
        if ( isset( $result['amount'] ) && is_array( $result['amount'] ) ) {
            foreach ( $result['amount'] as $item ) {
                if ( 'lovelace' === $item['unit'] ) {
                    $lovelace = intval( $item['quantity'] );
                } else {
                    $assets[] = array( 'unit' => $item['unit'], 'quantity' => intval( $item['quantity'] ) );
                }
            }
        }
        return array( 'lovelace' => $lovelace, 'ada' => number_format( $lovelace / 1000000, 6, '.', '' ), 'assets' => $assets );
    }

    /**
     * Get asset info (CIP-25 metadata).
     *
     * @return array|WP_Error { unit, policy_id, asset_name, asset_name_hex, fingerprint, quantity, image, metadata }
     */
    public static function get_asset_info( string $unit ) {
        $result = self::request( '/assets/' . $unit );
        if ( is_wp_error( $result ) ) return $result;

        $policy_id = $result['policy_id'] ?? '';
        $asset_hex = $result['asset_name'] ?? '';
        $asset_name = self::hex_to_ascii( $asset_hex );
        $metadata = array(); $image = '';
        if ( ! empty( $result['onchain_metadata'] ) ) {
            $meta = $result['onchain_metadata'];
            $image = self::resolve_image( $meta['image'] ?? '' );
            $metadata = self::flatten_metadata( $meta );
        }
        return array(
            'unit'           => $unit,
            'policy_id'      => $policy_id,
            'asset_name'     => $asset_name,
            'asset_name_hex' => $asset_hex,
            'fingerprint'    => $result['fingerprint'] ?? '',
            'quantity'       => intval( $result['quantity'] ?? 0 ),
            'image'          => $image,
            'metadata'       => $metadata,
        );
    }

    /**
     * Get UTxOs for an address.
     *
     * @return array|WP_Error Array of UTxO objects.
     */
    public static function get_address_utxos( string $address ) {
        $result = self::request( '/addresses/' . $address . '/utxos' );
        if ( is_wp_error( $result ) ) {
            if ( $result->get_error_data() && isset( $result->get_error_data()['status'] ) && 404 === $result->get_error_data()['status'] ) {
                return array();
            }
            return $result;
        }
        return is_array( $result ) ? $result : array();
    }

    /**
     * Check if an address holds a specific native asset.
     *
     * @param string $address Bech32 address.
     * @param string $unit    Policy ID + hex asset name.
     * @return bool|WP_Error
     */
    public static function address_has_asset( string $address, string $unit ) {
        $balance = self::get_address_balance( $address );
        if ( is_wp_error( $balance ) ) return $balance;
        foreach ( $balance['assets'] as $asset ) {
            if ( $asset['unit'] === $unit ) return true;
        }
        return false;
    }

    /**
     * Get transaction info.
     *
     * @return array|WP_Error
     */
    public static function get_transaction( string $tx_hash ) {
        return self::request( '/txs/' . $tx_hash );
    }

    /* ── Static helpers ───────────────────────────────────────────── */

    public static function hex_to_ascii( string $hex ): string {
        if ( empty( $hex ) ) return '';
        $str = '';
        for ( $i = 0; $i < strlen( $hex ) - 1; $i += 2 ) {
            $char = chr( hexdec( substr( $hex, $i, 2 ) ) );
            $str .= ( ord( $char ) >= 32 && ord( $char ) <= 126 ) ? $char : '?';
        }
        return $str;
    }

    public static function resolve_image( $image ): string {
        if ( empty( $image ) ) return '';
        if ( is_array( $image ) ) $image = implode( '', $image );
        return self::ipfs_to_http( $image );
    }

    public static function ipfs_to_http( string $uri ): string {
        if ( empty( $uri ) ) return '';
        if ( 0 === strpos( $uri, 'ipfs://' ) ) {
            $cid = substr( $uri, 7 );
            if ( 0 === strpos( $cid, 'ipfs/' ) ) $cid = substr( $cid, 5 );
            return 'https://ipfs.io/ipfs/' . $cid;
        }
        if ( preg_match( '/^(Qm[a-zA-Z0-9]{44}|bafy[a-zA-Z0-9]+)/', $uri ) ) return 'https://ipfs.io/ipfs/' . $uri;
        return $uri;
    }

    public static function flatten_metadata( $data, string $prefix = '' ): array {
        $result = array();
        if ( ! is_array( $data ) ) return $result;
        foreach ( $data as $key => $value ) {
            if ( '' === $prefix && 'image' === $key ) continue;
            $full_key = $prefix ? $prefix . '.' . $key : $key;
            if ( is_array( $value ) && ! isset( $value[0] ) ) {
                $result = array_merge( $result, self::flatten_metadata( $value, $full_key ) );
            } elseif ( is_array( $value ) ) {
                $result[ $full_key ] = implode( '', $value );
            } else {
                $result[ $full_key ] = (string) $value;
            }
        }
        return $result;
    }

    /* ── HTTP request ─────────────────────────────────────────────── */

    private static function request( string $endpoint ) {
        $api_key = CA_Settings::get_blockfrost_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'ca_no_blockfrost_key', 'Blockfrost API key is not configured.' );
        }
        $response = wp_remote_get(
            CA_Settings::get_blockfrost_base_url() . $endpoint,
            array(
                'timeout' => 15,
                'headers' => array( 'project_id' => $api_key ),
            )
        );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code >= 400 ) {
            return new WP_Error( 'blockfrost_error', isset( $body['message'] ) ? $body['message'] : "Blockfrost HTTP {$code}", array( 'status' => $code ) );
        }
        return $body;
    }
}
