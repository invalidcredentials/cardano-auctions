<?php
/**
 * CA_Settings — centralised settings with own option keys.
 * Self-contained; no external plugin dependencies.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Settings {

    /* ── Option key constants ─────────────────────────────────────── */
    const OPT_NETWORK             = 'ca_network';
    const OPT_BLOCKFROST_KEY      = 'ca_blockfrost_key';
    const OPT_ANVIL_KEY           = 'ca_anvil_key';
    const OPT_ANTI_SNIPE_SECONDS  = 'ca_anti_snipe_seconds';
    const OPT_ANTI_SNIPE_THRESHOLD= 'ca_anti_snipe_threshold';
    const OPT_PAYMENT_TIMEOUT     = 'ca_payment_timeout';
    const OPT_DEFAULT_SELLER      = 'ca_default_seller_address';
    const OPT_PLATFORM_FEE_BPS   = 'ca_platform_fee_bps';
    const OPT_MIN_INCREMENT       = 'ca_default_min_increment';
    const OPT_PINATA_JWT          = 'ca_pinata_jwt';
    const OPT_PINATA_ENABLED      = 'ca_pinata_enabled';

    /* ── Blockchain settings ──────────────────────────────────────── */

    public static function get_network(): string {
        $net = get_option( self::OPT_NETWORK, 'preprod' );
        return in_array( $net, array( 'preprod', 'mainnet' ), true ) ? $net : 'preprod';
    }

    public static function get_blockfrost_key(): string {
        $raw = get_option( self::OPT_BLOCKFROST_KEY . '_' . self::get_network(), '' );
        return self::maybe_decrypt( $raw );
    }

    public static function get_blockfrost_base_url(): string {
        $net = self::get_network();
        return "https://cardano-{$net}.blockfrost.io/api/v0";
    }

    public static function get_anvil_key(): string {
        $raw = get_option( self::OPT_ANVIL_KEY . '_' . self::get_network(), '' );
        return self::maybe_decrypt( $raw );
    }

    public static function get_anvil_base_url(): string {
        $net = self::get_network();
        return "https://{$net}.api.ada-anvil.app";
    }

    /* ── Auction-specific ─────────────────────────────────────────── */

    public static function get_anti_snipe_seconds(): int {
        return (int) get_option( self::OPT_ANTI_SNIPE_SECONDS, 300 );
    }

    public static function get_anti_snipe_threshold(): int {
        return (int) get_option( self::OPT_ANTI_SNIPE_THRESHOLD, 300 );
    }

    public static function get_payment_timeout(): int {
        return (int) get_option( self::OPT_PAYMENT_TIMEOUT, 900 );
    }

    public static function get_default_seller_address(): string {
        $explicit = get_option( self::OPT_DEFAULT_SELLER, '' );
        if ( ! empty( $explicit ) ) {
            return $explicit;
        }

        // Fall back to the active master wallet address.
        $master = CA_Master_Wallet_Model::get_active( self::get_network() );
        return $master ? $master['payment_address'] : '';
    }

    public static function get_platform_fee_bps(): int {
        return (int) get_option( self::OPT_PLATFORM_FEE_BPS, 0 );
    }

    public static function get_default_min_increment(): int {
        return (int) get_option( self::OPT_MIN_INCREMENT, 1000000 );
    }

    /* ── IPFS / Pinata ───────────────────────────────────────────── */

    public static function get_pinata_jwt(): string {
        $raw = get_option( self::OPT_PINATA_JWT, '' );
        return self::maybe_decrypt( $raw );
    }

    public static function is_pinata_enabled(): bool {
        return (bool) get_option( self::OPT_PINATA_ENABLED, false );
    }

    /* ── Persist ──────────────────────────────────────────────────── */

    public static function save( array $data ): void {
        $allowed = array(
            self::OPT_NETWORK,
            self::OPT_ANTI_SNIPE_SECONDS,
            self::OPT_ANTI_SNIPE_THRESHOLD,
            self::OPT_PAYMENT_TIMEOUT,
            self::OPT_DEFAULT_SELLER,
            self::OPT_PLATFORM_FEE_BPS,
            self::OPT_MIN_INCREMENT,
            self::OPT_PINATA_ENABLED,
        );

        foreach ( $data as $key => $value ) {
            if ( in_array( $key, $allowed, true ) ) {
                update_option( $key, sanitize_text_field( $value ) );
            }
        }

        // API keys get encrypted.
        if ( isset( $data['ca_blockfrost_key'] ) ) {
            $net = isset( $data[ self::OPT_NETWORK ] ) ? sanitize_text_field( $data[ self::OPT_NETWORK ] ) : self::get_network();
            $encrypted = CA_Encryption::encrypt( sanitize_text_field( $data['ca_blockfrost_key'] ) );
            update_option( self::OPT_BLOCKFROST_KEY . '_' . $net, $encrypted );
        }
        if ( isset( $data['ca_anvil_key'] ) ) {
            $net = isset( $data[ self::OPT_NETWORK ] ) ? sanitize_text_field( $data[ self::OPT_NETWORK ] ) : self::get_network();
            $encrypted = CA_Encryption::encrypt( sanitize_text_field( $data['ca_anvil_key'] ) );
            update_option( self::OPT_ANVIL_KEY . '_' . $net, $encrypted );
        }

        // Pinata JWT gets encrypted.
        if ( isset( $data['ca_pinata_jwt'] ) && '' !== $data['ca_pinata_jwt'] ) {
            $encrypted = CA_Encryption::encrypt( sanitize_text_field( $data['ca_pinata_jwt'] ) );
            update_option( self::OPT_PINATA_JWT, $encrypted );
        }
    }

    /* ── Helpers ──────────────────────────────────────────────────── */

    private static function maybe_decrypt( string $value ): string {
        if ( empty( $value ) ) return '';
        $decrypted = CA_Encryption::decrypt( $value );
        return $decrypted !== '' ? $decrypted : $value;
    }
}
