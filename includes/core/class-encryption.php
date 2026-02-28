<?php
/**
 * CA_Encryption — AES-256-CBC encryption using WordPress salts.
 * Self-contained; no external plugin dependencies.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Encryption {

    public static function encrypt( string $plaintext ): string {
        if ( empty( $plaintext ) ) return '';
        $key = self::derive_key();
        $iv  = openssl_random_pseudo_bytes( 16 );
        $encrypted = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        if ( false === $encrypted ) return '';
        return base64_encode( $iv . $encrypted );
    }

    public static function decrypt( string $ciphertext ): string {
        if ( empty( $ciphertext ) ) return '';
        $key  = self::derive_key();
        $data = base64_decode( $ciphertext, true );
        if ( false === $data || strlen( $data ) < 17 ) return '';
        $iv        = substr( $data, 0, 16 );
        $encrypted = substr( $data, 16 );
        $plaintext = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        if ( false === $plaintext ) return '';
        return $plaintext;
    }

    private static function derive_key(): string {
        if ( defined( 'CA_MASTER_KEY' ) ) {
            return hash( 'sha256', CA_MASTER_KEY, true );
        }
        $salt  = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
        $salt .= defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '';
        $salt .= defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '';
        $salt .= defined( 'NONCE_KEY' ) ? NONCE_KEY : '';
        return hash( 'sha256', $salt, true );
    }

    public static function test(): bool {
        $data = 'ca_encryption_test_' . time();
        return self::decrypt( self::encrypt( $data ) ) === $data;
    }
}
