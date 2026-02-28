<?php
/**
 * CA_Escrow — wallet generation, encrypted key storage, signing key access, lifecycle.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Escrow {

    /**
     * Generate a new escrow wallet and store encrypted keys.
     *
     * @param int    $auction_id  Associated auction.
     * @param int    $bid_id      Associated bid (0 for custody wallets).
     * @param string $purpose     'escrow' or 'nft_custody'.
     * @return array { id, payment_address, payment_keyhash } or WP_Error.
     */
    public static function generate( int $auction_id, int $bid_id = 0, string $purpose = 'escrow' ) {
        // Try HD derivation from master wallet first.
        $master = CA_Master_Wallet::get_active();
        if ( $master ) {
            return CA_Master_Wallet::derive_child( $auction_id, $bid_id, $purpose );
        }

        // Fallback: generate independent wallet (legacy behavior for sites without master wallet).
        $network = CA_Settings::get_network();

        $wallet = CardanoWalletPHP::generateWallet( $network );
        if ( empty( $wallet['success'] ) ) {
            return new WP_Error( 'ca_wallet_gen_failed', 'Failed to generate escrow wallet.' );
        }

        $mnemonic_enc = CA_Encryption::encrypt( $wallet['mnemonic'] );
        $skey_enc     = CA_Encryption::encrypt( $wallet['payment_skey_extended'] );

        if ( empty( $mnemonic_enc ) || empty( $skey_enc ) ) {
            return new WP_Error( 'ca_encryption_failed', 'Failed to encrypt wallet keys.' );
        }

        $id = CA_Escrow_Model::insert( array(
            'mnemonic_encrypted' => $mnemonic_enc,
            'skey_encrypted'     => $skey_enc,
            'payment_address'    => $wallet['addresses']['payment_address'],
            'payment_keyhash'    => $wallet['payment_keyhash'],
            'purpose'            => $purpose,
            'auction_id'         => $auction_id,
            'bid_id'             => $bid_id ?: null,
            'status'             => 'active',
            'balance_lovelace'   => 0,
            'network'            => $network,
        ) );

        if ( ! $id ) {
            return new WP_Error( 'ca_escrow_insert_failed', 'Failed to store escrow wallet.' );
        }

        CA_Event_Log_Model::log( $auction_id, $bid_id, 'escrow_created', sprintf(
            'Escrow wallet created (%s): %s',
            $purpose,
            $wallet['addresses']['payment_address']
        ) );

        return array(
            'id'              => $id,
            'payment_address' => $wallet['addresses']['payment_address'],
            'payment_keyhash' => $wallet['payment_keyhash'],
        );
    }

    /**
     * Get decrypted signing key for an escrow wallet.
     *
     * @param int $wallet_id Escrow wallet ID.
     * @return string|WP_Error 128-char hex extended signing key.
     */
    public static function get_signing_key( int $wallet_id ) {
        $wallet = CA_Escrow_Model::get( $wallet_id );
        if ( ! $wallet ) {
            return new WP_Error( 'ca_wallet_not_found', 'Escrow wallet not found.' );
        }

        $skey = CA_Encryption::decrypt( $wallet['skey_encrypted'] );
        if ( empty( $skey ) ) {
            return new WP_Error( 'ca_decrypt_failed', 'Failed to decrypt signing key.' );
        }

        return $skey;
    }

    /**
     * Check balance of an escrow wallet via Blockfrost.
     *
     * @param int $wallet_id Escrow wallet ID.
     * @return array|WP_Error { lovelace, ada, assets[] }
     */
    public static function check_balance( int $wallet_id ) {
        $wallet = CA_Escrow_Model::get( $wallet_id );
        if ( ! $wallet ) {
            return new WP_Error( 'ca_wallet_not_found', 'Escrow wallet not found.' );
        }

        $balance = CA_Blockfrost::get_address_balance( $wallet['payment_address'] );
        if ( is_wp_error( $balance ) ) return $balance;

        // Update cached balance.
        CA_Escrow_Model::update( $wallet_id, array( 'balance_lovelace' => $balance['lovelace'] ) );

        return $balance;
    }

    /**
     * Build, sign, and submit a transaction FROM an escrow wallet.
     *
     * Anvil uses changeAddress to determine which UTxOs to use as inputs,
     * so we MUST always pass the escrow wallet's own address as changeAddress.
     *
     * @param int   $wallet_id  Source escrow wallet ID.
     * @param array $outputs    [ { address, lovelace, assets? } ].
     * @return array|WP_Error { txHash }
     */
    public static function send_from( int $wallet_id, array $outputs ) {
        $wallet = CA_Escrow_Model::get( $wallet_id );
        if ( ! $wallet ) {
            return new WP_Error( 'ca_wallet_not_found', 'Escrow wallet not found.' );
        }

        $skey = self::get_signing_key( $wallet_id );
        if ( is_wp_error( $skey ) ) return $skey;

        // Verify the signing key matches the stored address before trying to spend.
        $verify = self::verify_key_matches( $skey, $wallet );
        if ( is_wp_error( $verify ) ) return $verify;

        // Always use the escrow wallet's own address as changeAddress.
        // Anvil uses this to select input UTxOs from the blockchain.
        $change_address = $wallet['payment_address'];

        // Check if any output has assets (NFT transfer).
        $has_assets = false;
        foreach ( $outputs as $out ) {
            if ( ! empty( $out['assets'] ) ) { $has_assets = true; break; }
        }

        // Step 1: Build unsigned transaction via Anvil.
        $build_result = $has_assets
            ? CA_Anvil::build_with_assets( $change_address, $outputs )
            : CA_Anvil::build( $change_address, $outputs );

        if ( is_wp_error( $build_result ) ) {
            return new WP_Error( 'ca_build_failed', 'Anvil build failed: ' . $build_result->get_error_message() );
        }

        $unsigned_tx = $build_result['complete'] ?? ( $build_result['stripped'] ?? '' );
        if ( empty( $unsigned_tx ) ) {
            return new WP_Error( 'ca_build_failed', 'Anvil returned no CBOR. Response keys: ' . implode( ', ', array_keys( $build_result ) ) );
        }

        // Step 2: Sign with escrow wallet key.
        $sign_result = CardanoTransactionSignerPHP::signTransaction( $unsigned_tx, $skey );
        if ( empty( $sign_result['success'] ) ) {
            return new WP_Error( 'ca_sign_failed', 'Signing failed: ' . ( $sign_result['error'] ?? 'unknown' ) . ' (skey length: ' . strlen( $skey ) . ')' );
        }

        if ( empty( $sign_result['witnessSetHex'] ) ) {
            return new WP_Error( 'ca_sign_failed', 'Signing succeeded but no witnessSetHex returned. Keys: ' . implode( ', ', array_keys( $sign_result ) ) );
        }

        // Step 3: Submit via Anvil.
        $submit_result = CA_Anvil::submit( $unsigned_tx, array( $sign_result['witnessSetHex'] ) );
        if ( is_wp_error( $submit_result ) ) {
            return new WP_Error( 'ca_submit_failed', 'Anvil submit failed: ' . $submit_result->get_error_message() );
        }

        $tx_hash = $submit_result['txHash'] ?? ( $submit_result['hash'] ?? '' );
        if ( empty( $tx_hash ) ) {
            // Submission succeeded but no hash — check response for clues.
            $tx_hash = 'submitted_' . time();
        }

        return array( 'txHash' => $tx_hash );
    }

    /**
     * Verify that a signing key derives the same keyhash as the stored wallet.
     *
     * @param string $skey_hex   128-char hex extended signing key.
     * @param array  $wallet     Escrow wallet row from DB.
     * @return true|WP_Error
     */
    public static function verify_key_matches( string $skey_hex, array $wallet ) {
        $stored_keyhash = $wallet['payment_keyhash'] ?? '';

        // Derive public key from the signing key.
        if ( strlen( $skey_hex ) === 128 ) {
            $kL = hex2bin( substr( $skey_hex, 0, 64 ) );
            if ( $kL === false ) {
                return new WP_Error( 'ca_key_invalid', 'Cannot decode signing key hex.' );
            }
            $pub = Ed25519Compat::ge_scalarmult_base_noclamp( $kL );
        } elseif ( strlen( $skey_hex ) === 64 ) {
            $seed = hex2bin( $skey_hex );
            $kp   = sodium_crypto_sign_seed_keypair( $seed );
            $pub  = sodium_crypto_sign_publickey( $kp );
        } else {
            return new WP_Error( 'ca_key_invalid', sprintf( 'Signing key has unexpected length: %d chars (expected 64 or 128).', strlen( $skey_hex ) ) );
        }

        // Blake2b-224 of the public key = payment keyhash.
        $derived_keyhash = bin2hex( sodium_crypto_generichash( $pub, '', 28 ) );

        if ( $stored_keyhash && $derived_keyhash !== $stored_keyhash ) {
            return new WP_Error( 'ca_key_mismatch', sprintf(
                'Signing key mismatch! Stored keyhash: %s, Derived keyhash: %s, Skey length: %d, Address: %s',
                $stored_keyhash,
                $derived_keyhash,
                strlen( $skey_hex ),
                $wallet['payment_address']
            ) );
        }

        return true;
    }
}
