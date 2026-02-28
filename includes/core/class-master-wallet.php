<?php
/**
 * CA_Master_Wallet — HD master wallet: generate, derive children, send NFTs, query assets.
 * Single seed → CIP-1852 HD derivation for all escrow/custody addresses.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Master_Wallet {

    /**
     * Generate a new master wallet for the current network.
     *
     * @param string $wallet_name Display name.
     * @return array|WP_Error { id, payment_address, mnemonic }
     */
    public static function generate( string $wallet_name = 'Master Wallet' ) {
        $network = CA_Settings::get_network();

        // Only one active master wallet per network.
        $existing = CA_Master_Wallet_Model::get_active( $network );
        if ( $existing ) {
            return new WP_Error( 'ca_master_exists', 'An active master wallet already exists on this network. Archive it first.' );
        }

        $wallet = CardanoWalletPHP::generateWallet( $network );
        if ( empty( $wallet['success'] ) ) {
            return new WP_Error( 'ca_wallet_gen_failed', 'Failed to generate master wallet.' );
        }

        $mnemonic_enc = CA_Encryption::encrypt( $wallet['mnemonic'] );
        $skey_enc     = CA_Encryption::encrypt( $wallet['payment_skey_extended'] );

        if ( empty( $mnemonic_enc ) || empty( $skey_enc ) ) {
            return new WP_Error( 'ca_encryption_failed', 'Failed to encrypt master wallet keys.' );
        }

        $id = CA_Master_Wallet_Model::insert( array(
            'wallet_name'         => sanitize_text_field( $wallet_name ),
            'mnemonic_encrypted'  => $mnemonic_enc,
            'skey_encrypted'      => $skey_enc,
            'payment_address'     => $wallet['addresses']['payment_address'],
            'payment_keyhash'     => $wallet['payment_keyhash'],
            'stake_address'       => $wallet['addresses']['stake_address'],
            'network'             => $network,
            'next_derivation_index' => 1,
        ) );

        if ( ! $id ) {
            return new WP_Error( 'ca_master_insert_failed', 'Failed to store master wallet.' );
        }

        // Store mnemonic in transient for one-time display (5-minute window).
        set_transient( 'ca_master_mnemonic_' . $id, $wallet['mnemonic'], 5 * MINUTE_IN_SECONDS );

        CA_Event_Log_Model::log( 0, 0, 'master_wallet_created', sprintf(
            'Master wallet "%s" created: %s',
            $wallet_name,
            $wallet['addresses']['payment_address']
        ) );

        return array(
            'id'              => $id,
            'payment_address' => $wallet['addresses']['payment_address'],
            'mnemonic'        => $wallet['mnemonic'],
        );
    }

    /**
     * Get the active master wallet for the current network.
     */
    public static function get_active(): ?array {
        return CA_Master_Wallet_Model::get_active( CA_Settings::get_network() );
    }

    /**
     * Get the decrypted signing key for a master wallet (index 0).
     *
     * @param int $wallet_id Master wallet ID.
     * @return string|WP_Error 128-char hex extended signing key.
     */
    public static function get_signing_key( int $wallet_id ) {
        $wallet = CA_Master_Wallet_Model::get( $wallet_id );
        if ( ! $wallet ) {
            return new WP_Error( 'ca_wallet_not_found', 'Master wallet not found.' );
        }

        $skey = CA_Encryption::decrypt( $wallet['skey_encrypted'] );
        if ( empty( $skey ) ) {
            return new WP_Error( 'ca_decrypt_failed', 'Failed to decrypt master signing key.' );
        }

        return $skey;
    }

    /**
     * Derive a child escrow wallet from the master wallet.
     *
     * @param int    $auction_id Auction association.
     * @param int    $bid_id     Bid association (0 for custody).
     * @param string $purpose    'escrow' or 'nft_custody'.
     * @return array|WP_Error { id, payment_address, payment_keyhash }
     */
    public static function derive_child( int $auction_id, int $bid_id = 0, string $purpose = 'escrow' ) {
        $network = CA_Settings::get_network();
        $master  = CA_Master_Wallet_Model::get_active( $network );

        if ( ! $master ) {
            return new WP_Error( 'ca_no_master', 'No active master wallet.' );
        }

        // Decrypt mnemonic to derive child.
        $mnemonic = CA_Encryption::decrypt( $master['mnemonic_encrypted'] );
        if ( empty( $mnemonic ) ) {
            return new WP_Error( 'ca_decrypt_failed', 'Failed to decrypt master mnemonic.' );
        }

        $index = (int) $master['next_derivation_index'];

        // Derive child at the next index.
        $child = CardanoWalletPHP::fromMnemonic( $mnemonic, '', $network, $index );
        if ( empty( $child['success'] ) ) {
            return new WP_Error( 'ca_derive_failed', 'Failed to derive child wallet at index ' . $index );
        }

        // Encrypt child signing key (no mnemonic stored for children).
        $skey_enc = CA_Encryption::encrypt( $child['payment_skey_extended'] );
        if ( empty( $skey_enc ) ) {
            return new WP_Error( 'ca_encryption_failed', 'Failed to encrypt child signing key.' );
        }

        // Insert as escrow wallet with master wallet linkage.
        $id = CA_Escrow_Model::insert( array(
            'mnemonic_encrypted' => '',
            'skey_encrypted'     => $skey_enc,
            'payment_address'    => $child['addresses']['payment_address'],
            'payment_keyhash'    => $child['payment_keyhash'],
            'purpose'            => $purpose,
            'auction_id'         => $auction_id,
            'bid_id'             => $bid_id ?: null,
            'status'             => 'active',
            'balance_lovelace'   => 0,
            'network'            => $network,
            'master_wallet_id'   => (int) $master['id'],
            'derivation_index'   => $index,
        ) );

        if ( ! $id ) {
            return new WP_Error( 'ca_escrow_insert_failed', 'Failed to store derived escrow wallet.' );
        }

        // Increment the derivation index on the master wallet.
        CA_Master_Wallet_Model::increment_derivation_index( (int) $master['id'] );

        CA_Event_Log_Model::log( $auction_id, $bid_id, 'escrow_created', sprintf(
            'HD-derived escrow wallet (%s, index %d): %s',
            $purpose,
            $index,
            $child['addresses']['payment_address']
        ) );

        return array(
            'id'              => $id,
            'payment_address' => $child['addresses']['payment_address'],
            'payment_keyhash' => $child['payment_keyhash'],
        );
    }

    /**
     * Send an NFT from the master wallet to a recipient.
     *
     * @param int    $wallet_id       Master wallet ID.
     * @param string $recipient       Bech32 recipient address.
     * @param string $policy_id       NFT policy ID.
     * @param string $asset_name_hex  Hex-encoded asset name.
     * @return array|WP_Error { txHash }
     */
    public static function send_nft( int $wallet_id, string $recipient, string $policy_id, string $asset_name_hex ) {
        $master = CA_Master_Wallet_Model::get( $wallet_id );
        if ( ! $master ) {
            return new WP_Error( 'ca_wallet_not_found', 'Master wallet not found.' );
        }

        $skey = self::get_signing_key( $wallet_id );
        if ( is_wp_error( $skey ) ) return $skey;

        // Verify signing key matches master wallet.
        $verify = CA_Escrow::verify_key_matches( $skey, $master );
        if ( is_wp_error( $verify ) ) return $verify;

        $asset_name = CA_Blockfrost::hex_to_ascii( $asset_name_hex );
        $change_address = $master['payment_address'];

        // Build NFT transfer output: 2 ADA min UTxO + the NFT.
        $outputs = array(
            array(
                'address'  => $recipient,
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

        // Build via Anvil.
        $build_result = CA_Anvil::build_with_assets( $change_address, $outputs );
        if ( is_wp_error( $build_result ) ) {
            return new WP_Error( 'ca_build_failed', 'Anvil build failed: ' . $build_result->get_error_message() );
        }

        $unsigned_tx = $build_result['complete'] ?? ( $build_result['stripped'] ?? '' );
        if ( empty( $unsigned_tx ) ) {
            return new WP_Error( 'ca_build_failed', 'Anvil returned no CBOR.' );
        }

        // Sign with master wallet key.
        $sign_result = CardanoTransactionSignerPHP::signTransaction( $unsigned_tx, $skey );
        if ( empty( $sign_result['success'] ) ) {
            return new WP_Error( 'ca_sign_failed', 'Signing failed: ' . ( $sign_result['error'] ?? 'unknown' ) );
        }

        if ( empty( $sign_result['witnessSetHex'] ) ) {
            return new WP_Error( 'ca_sign_failed', 'Signing succeeded but no witnessSetHex returned.' );
        }

        // Submit via Anvil.
        $submit_result = CA_Anvil::submit( $unsigned_tx, array( $sign_result['witnessSetHex'] ) );
        if ( is_wp_error( $submit_result ) ) {
            return new WP_Error( 'ca_submit_failed', 'Anvil submit failed: ' . $submit_result->get_error_message() );
        }

        $tx_hash = $submit_result['txHash'] ?? ( $submit_result['hash'] ?? '' );
        if ( empty( $tx_hash ) ) {
            $tx_hash = 'submitted_' . time();
        }

        return array( 'txHash' => $tx_hash );
    }

    /**
     * Get all native assets held by a master wallet.
     *
     * @param int $wallet_id Master wallet ID.
     * @return array|WP_Error [ { unit, quantity, name, image, policy_id, asset_name_hex, metadata } ]
     */
    public static function get_assets( int $wallet_id ) {
        $master = CA_Master_Wallet_Model::get( $wallet_id );
        if ( ! $master ) {
            return new WP_Error( 'ca_wallet_not_found', 'Master wallet not found.' );
        }

        $balance = CA_Blockfrost::get_address_balance( $master['payment_address'] );
        if ( is_wp_error( $balance ) ) return $balance;

        $assets = array();
        foreach ( $balance['assets'] as $asset ) {
            $cache_key = 'ca_asset_' . md5( $asset['unit'] );
            $info = get_transient( $cache_key );

            if ( false === $info ) {
                $info = CA_Blockfrost::get_asset_info( $asset['unit'] );
                if ( ! is_wp_error( $info ) ) {
                    set_transient( $cache_key, $info, HOUR_IN_SECONDS );
                } else {
                    $info = null;
                }
            }

            $assets[] = array(
                'unit'           => $asset['unit'],
                'quantity'       => $asset['quantity'],
                'name'           => $info ? ( $info['asset_name'] ?: $info['asset_name_hex'] ) : substr( $asset['unit'], 56 ),
                'image'          => $info ? ( $info['image'] ?? '' ) : '',
                'policy_id'      => $info ? $info['policy_id'] : substr( $asset['unit'], 0, 56 ),
                'asset_name_hex' => $info ? $info['asset_name_hex'] : substr( $asset['unit'], 56 ),
                'metadata'       => $info ? ( $info['metadata'] ?? array() ) : array(),
            );
        }

        return $assets;
    }
}
