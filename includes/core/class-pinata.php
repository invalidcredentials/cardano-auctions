<?php
/**
 * CA_Pinata — Pinata IPFS upload client.
 * Pins files to IPFS via the Pinata API with CIDv0 (Qm...) format.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Pinata {

    private static $api_url = 'https://api.pinata.cloud/pinning/pinFileToIPFS';

    /**
     * Upload a file to Pinata and return its IPFS CID.
     *
     * @param string $file_path Absolute path to the file.
     * @param string $name      Display name for the pin.
     * @return array|WP_Error   { cid: 'Qm...', mediaType: 'image/png' }
     */
    public static function upload( string $file_path, string $name = '' ) {
        $jwt = CA_Settings::get_pinata_jwt();
        if ( empty( $jwt ) ) {
            return new WP_Error( 'ca_pinata_no_jwt', 'Pinata JWT is not configured.' );
        }

        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'ca_pinata_no_file', 'File not found: ' . $file_path );
        }

        $mime_type = wp_check_filetype( basename( $file_path ) )['type'] ?: 'application/octet-stream';

        // Build metadata for the pin.
        $metadata = wp_json_encode( array(
            'name'    => $name ?: basename( $file_path ),
            'keyvalues' => array(
                'source' => 'cardano-auctions',
            ),
        ) );

        $options = wp_json_encode( array(
            'cidVersion' => 0,
        ) );

        // Use cURL for multipart upload (wp_remote_post doesn't handle CURLFile).
        $ch = curl_init();
        curl_setopt_array( $ch, array(
            CURLOPT_URL            => self::$api_url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $jwt,
            ),
            CURLOPT_POSTFIELDS     => array(
                'file'              => new CURLFile( $file_path, $mime_type, basename( $file_path ) ),
                'pinataMetadata'    => $metadata,
                'pinataOptions'     => $options,
            ),
        ) );

        $response = curl_exec( $ch );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error = curl_error( $ch );
        curl_close( $ch );

        if ( $error ) {
            return new WP_Error( 'ca_pinata_curl_error', 'Pinata upload failed: ' . $error );
        }

        $body = json_decode( $response, true );

        if ( $http_code >= 400 || empty( $body['IpfsHash'] ) ) {
            $msg = isset( $body['error'] ) ? $body['error'] : ( isset( $body['message'] ) ? $body['message'] : "HTTP {$http_code}" );
            return new WP_Error( 'ca_pinata_error', 'Pinata error: ' . $msg );
        }

        return array(
            'cid'       => $body['IpfsHash'],
            'mediaType' => $mime_type,
        );
    }

    /**
     * Test the Pinata connection.
     *
     * @return array|WP_Error
     */
    public static function test_connection() {
        $jwt = CA_Settings::get_pinata_jwt();
        if ( empty( $jwt ) ) {
            return new WP_Error( 'ca_pinata_no_jwt', 'Pinata JWT is not configured.' );
        }

        $response = wp_remote_get( 'https://api.pinata.cloud/data/testAuthentication', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt,
            ),
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $msg = isset( $body['error'] ) ? $body['error'] : "HTTP {$code}";
            return new WP_Error( 'ca_pinata_auth_failed', 'Pinata auth failed: ' . $msg );
        }

        return $body;
    }
}
