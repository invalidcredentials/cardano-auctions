<?php
/**
 * CA_Metadata_Builder — CIP-25 metadata construction for NFT minting.
 * Handles 64-character chunking for Cardano on-chain metadata limits.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Metadata_Builder {

    /**
     * Cardano on-chain metadata string byte limit.
     * The ledger CDDL allows max 64 bytes per metadata text string.
     */
    const CHUNK_BYTES = 64;

    /**
     * Build a CIP-25 metadata object from form data.
     *
     * @param array $data {
     *   name:           string  NFT name (required)
     *   image:          string  IPFS URI "ipfs://Qm..." or URL (required)
     *   mediaType:      string  MIME type e.g. "image/png"
     *   description:    string  NFT description
     *   starting_price: string  e.g. "50 ADA"
     *   reserve_price:  string  e.g. "100 ADA"
     *   category:       string
     *   condition:      string
     *   creator:        string
     *   custom:         array   [ { key: '...', value: '...' }, ... ]
     * }
     * @return array CIP-25 metadata structure.
     */
    public static function build( array $data ): array {
        $meta = array();

        // Required fields.
        $meta['name'] = self::chunk_string( $data['name'] ?? 'Untitled' );

        $image = $data['image'] ?? '';
        $meta['image'] = self::chunk_string( $image );

        // Media type.
        if ( ! empty( $data['mediaType'] ) ) {
            $meta['mediaType'] = $data['mediaType'];
        } else {
            $meta['mediaType'] = self::detect_media_type( $image );
        }

        // Description (often long, needs chunking).
        if ( ! empty( $data['description'] ) ) {
            $meta['description'] = self::chunk_string( $data['description'] );
        }

        // Files array (standard CIP-25 structure).
        if ( ! empty( $image ) ) {
            $file_entry = array(
                'name'      => self::chunk_string( $data['name'] ?? 'Untitled' ),
                'mediaType' => $meta['mediaType'],
                'src'       => self::chunk_string( $image ),
            );
            $meta['files'] = array( $file_entry );
        }

        // Auction metadata — all values chunked.
        $auction = array();
        if ( ! empty( $data['starting_price'] ) ) {
            $auction['starting_price'] = self::chunk_string( $data['starting_price'] );
        }
        if ( ! empty( $data['reserve_price'] ) ) {
            $auction['reserve_price'] = self::chunk_string( $data['reserve_price'] );
        }
        $auction['platform'] = self::chunk_string( 'cardanowordpressplugins.com' );
        $meta['auction'] = $auction;

        // Optional standard fields.
        if ( ! empty( $data['category'] ) ) {
            $meta['category'] = self::chunk_string( $data['category'] );
        }
        if ( ! empty( $data['condition'] ) ) {
            $meta['condition'] = self::chunk_string( $data['condition'] );
        }
        if ( ! empty( $data['creator'] ) ) {
            $meta['creator'] = self::chunk_string( $data['creator'] );
        }

        // Custom attributes.
        if ( ! empty( $data['custom'] ) && is_array( $data['custom'] ) ) {
            foreach ( $data['custom'] as $attr ) {
                $key   = sanitize_key( $attr['key'] ?? '' );
                $value = $attr['value'] ?? '';
                if ( $key && $value !== '' ) {
                    $meta[ $key ] = self::chunk_string( $value );
                }
            }
        }

        return $meta;
    }

    /**
     * Chunk a string into segments that each fit within 64 bytes.
     * UTF-8 safe — never splits in the middle of a multibyte character.
     * Returns the string as-is if it fits, or an array of chunks.
     *
     * @param string $value The value to chunk.
     * @return string|array
     */
    public static function chunk_string( string $value ) {
        if ( strlen( $value ) <= self::CHUNK_BYTES ) {
            return $value;
        }

        $chunks = array();
        $remaining = $value;

        while ( strlen( $remaining ) > 0 ) {
            if ( strlen( $remaining ) <= self::CHUNK_BYTES ) {
                $chunks[] = $remaining;
                break;
            }

            // mb_strcut cuts at byte boundaries without splitting multibyte chars.
            $chunk = mb_strcut( $remaining, 0, self::CHUNK_BYTES, 'UTF-8' );
            $chunks[] = $chunk;
            $remaining = substr( $remaining, strlen( $chunk ) );
        }

        return $chunks;
    }

    /**
     * Detect the media type from a file URL or IPFS URI.
     *
     * @param string $source URL or IPFS URI.
     * @return string MIME type.
     */
    public static function detect_media_type( string $source ): string {
        // Strip IPFS prefix and query strings.
        $path = preg_replace( '/\?.*$/', '', $source );
        $path = str_replace( 'ipfs://', '', $path );

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

        $map = array(
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            'mp4'  => 'video/mp4',
            'webm' => 'video/webm',
        );

        return $map[ $ext ] ?? 'image/png';
    }
}
