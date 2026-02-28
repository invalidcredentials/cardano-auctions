<?php
/**
 * CA_Minter — Mint NFTs to the master wallet via Anvil.
 * Single-signature minting: master wallet is both funder and policy authority.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Minter {

    /**
     * Mint an NFT to the master wallet.
     *
     * @param int    $policy_db_id     Mint policy DB row ID.
     * @param string $asset_name       ASCII asset name (will be hex-encoded).
     * @param array  $cip25_metadata   CIP-25 metadata from CA_Metadata_Builder::build().
     * @return array|WP_Error { txHash, policy_id, asset_name, asset_name_hex, unit }
     */
    public static function mint_to_master( int $policy_db_id, string $asset_name, array $cip25_metadata ) {
        // 1. Load and validate policy.
        $policy = CA_Mint_Policy_Model::get( $policy_db_id );
        if ( ! $policy ) {
            return new WP_Error( 'ca_policy_not_found', 'Minting policy not found.' );
        }

        if ( $policy['status'] !== 'active' ) {
            return new WP_Error( 'ca_policy_inactive', 'This minting policy is not active.' );
        }

        $exp_time = strtotime( $policy['expiration_date'] );
        if ( $exp_time && $exp_time < time() ) {
            CA_Mint_Policy_Model::update( $policy_db_id, array( 'status' => 'expired' ) );
            return new WP_Error( 'ca_policy_expired', 'This minting policy has expired.' );
        }

        // 2. Get master wallet + signing key.
        $master = CA_Master_Wallet_Model::get( (int) $policy['master_wallet_id'] );
        if ( ! $master ) {
            return new WP_Error( 'ca_wallet_not_found', 'Master wallet for this policy not found.' );
        }

        $skey = CA_Master_Wallet::get_signing_key( (int) $master['id'] );
        if ( is_wp_error( $skey ) ) return $skey;

        // 3. Verify signing key matches wallet.
        $verify = CA_Escrow::verify_key_matches( $skey, $master );
        if ( is_wp_error( $verify ) ) return $verify;

        $change_address = $master['payment_address'];
        $policy_id      = $policy['policy_id'];
        $asset_name_hex = bin2hex( $asset_name );

        // 4. Build Anvil mint transaction.
        //    Output: 2 ADA + the minted NFT → master wallet.
        $outputs = array(
            array(
                'address'  => $change_address,
                'lovelace' => 2000000,
                'assets'   => array(
                    array(
                        'policyId'  => $policy_id,
                        'assetName' => array( 'name' => $asset_name, 'format' => 'utf8' ),
                        'quantity'  => 1,
                    ),
                ),
            ),
        );

        //    Mint array with CIP-25 metadata.
        $mint = array(
            'assets' => array(
                array(
                    'version'  => 'cip25',
                    'policyId' => $policy_id,
                    'assets'   => array(
                        array(
                            'assetName' => array( 'name' => $asset_name, 'format' => 'utf8' ),
                            'quantity'  => 1,
                            'metadata'  => $cip25_metadata,
                        ),
                    ),
                ),
            ),
        );

        //    Preloaded scripts: the policy's native script.
        $policy_schema = json_decode( $policy['policy_schema'], true );
        $preloaded_scripts = array(
            array(
                'type'       => 'native',
                'script'     => $policy_schema,
                'scriptHash' => $policy_id,
            ),
        );

        $build_result = CA_Anvil::build_mint( $change_address, $outputs, $mint, $preloaded_scripts );
        if ( is_wp_error( $build_result ) ) {
            return new WP_Error( 'ca_mint_build_failed', 'Anvil mint build failed: ' . $build_result->get_error_message() );
        }

        $unsigned_tx = $build_result['complete'] ?? ( $build_result['stripped'] ?? '' );
        if ( empty( $unsigned_tx ) ) {
            return new WP_Error( 'ca_mint_build_failed', 'Anvil returned no CBOR for mint.' );
        }

        // 5. Sign with master wallet key.
        $sign_result = CardanoTransactionSignerPHP::signTransaction( $unsigned_tx, $skey );
        if ( empty( $sign_result['success'] ) ) {
            return new WP_Error( 'ca_mint_sign_failed', 'Signing failed: ' . ( $sign_result['error'] ?? 'unknown' ) );
        }

        if ( empty( $sign_result['witnessSetHex'] ) ) {
            return new WP_Error( 'ca_mint_sign_failed', 'Signing succeeded but no witnessSetHex returned.' );
        }

        // 6. Submit via Anvil.
        $submit_result = CA_Anvil::submit( $unsigned_tx, array( $sign_result['witnessSetHex'] ) );
        if ( is_wp_error( $submit_result ) ) {
            return new WP_Error( 'ca_mint_submit_failed', 'Anvil submit failed: ' . $submit_result->get_error_message() );
        }

        $tx_hash = $submit_result['txHash'] ?? ( $submit_result['hash'] ?? '' );
        if ( empty( $tx_hash ) ) {
            $tx_hash = 'submitted_' . time();
        }

        // 7. Increment items_minted on the policy.
        CA_Mint_Policy_Model::increment_items_minted( $policy_db_id );

        $unit = $policy_id . $asset_name_hex;

        // Log event.
        CA_Event_Log_Model::log( 0, 0, 'nft_minted', sprintf(
            'NFT "%s" minted under policy %s. TX: %s',
            $asset_name, substr( $policy_id, 0, 16 ) . '...', $tx_hash
        ), array(
            'txHash'         => $tx_hash,
            'policy_id'      => $policy_id,
            'asset_name'     => $asset_name,
            'asset_name_hex' => $asset_name_hex,
            'unit'           => $unit,
        ) );

        return array(
            'txHash'         => $tx_hash,
            'policy_id'      => $policy_id,
            'asset_name'     => $asset_name,
            'asset_name_hex' => $asset_name_hex,
            'unit'           => $unit,
        );
    }

    /**
     * Generate a URL-safe asset name from a title.
     * Format: Title_Slug_abc1234 (sanitized, max 32 chars).
     *
     * @param string $title     The NFT title.
     * @param string $policy_id Optional policy ID for uniqueness salt.
     * @return string
     */
    public static function generate_asset_name( string $title, string $policy_id = '' ): string {
        // Sanitize to alphanumeric + underscores.
        $slug = preg_replace( '/[^a-zA-Z0-9]/', '_', $title );
        $slug = preg_replace( '/_+/', '_', $slug );
        $slug = trim( $slug, '_' );

        // Generate a short hash for uniqueness.
        $hash_input = $title . $policy_id . microtime( true ) . wp_rand();
        $hash = substr( md5( $hash_input ), 0, 7 );

        // Max 32 chars total: slug + '_' + 7-char hash.
        $max_slug_len = 32 - 1 - 7; // 24
        if ( strlen( $slug ) > $max_slug_len ) {
            $slug = substr( $slug, 0, $max_slug_len );
            $slug = rtrim( $slug, '_' );
        }

        return $slug . '_' . $hash;
    }
}
