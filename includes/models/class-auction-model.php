<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Auction_Model {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ca_auctions';
    }

    public static function create_table(): void {
        global $wpdb;
        $t = self::table();
        $c = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$t} (
            id                      bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title                   varchar(255)   NOT NULL DEFAULT '',
            description             text           NOT NULL,
            nft_policy_id           varchar(56)    NOT NULL DEFAULT '',
            nft_asset_name_hex      varchar(64)    NOT NULL DEFAULT '',
            nft_display_name        varchar(255)   NOT NULL DEFAULT '',
            nft_image_url           varchar(512)   NOT NULL DEFAULT '',
            nft_metadata            longtext       NOT NULL,
            image_url               varchar(512)   NOT NULL DEFAULT '',
            gallery_images          text           NOT NULL,
            artist_name             varchar(255)   NOT NULL DEFAULT '',
            listed_by               varchar(255)   NOT NULL DEFAULT '',
            nft_wallet_id           bigint(20) UNSIGNED DEFAULT NULL,
            starting_bid_lovelace   bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            reserve_price_lovelace  bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            min_increment_lovelace  bigint(20) UNSIGNED NOT NULL DEFAULT 1000000,
            start_time              datetime       NOT NULL DEFAULT '0000-00-00 00:00:00',
            end_time                datetime       NOT NULL DEFAULT '0000-00-00 00:00:00',
            original_end_time       datetime       NOT NULL DEFAULT '0000-00-00 00:00:00',
            anti_snipe_seconds      int UNSIGNED   NOT NULL DEFAULT 300,
            anti_snipe_threshold    int UNSIGNED   NOT NULL DEFAULT 300,
            status                  varchar(20)    NOT NULL DEFAULT 'draft',
            current_bid_lovelace    bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            current_bidder_address  varchar(128)   NOT NULL DEFAULT '',
            current_bid_id          bigint(20) UNSIGNED DEFAULT NULL,
            total_bids              int UNSIGNED   NOT NULL DEFAULT 0,
            seller_address          varchar(128)   NOT NULL DEFAULT '',
            settlement_tx_hash      varchar(64)    NOT NULL DEFAULT '',
            ada_settlement_tx_hash  varchar(64)    NOT NULL DEFAULT '',
            mint_tx_hash            varchar(64)    DEFAULT NULL,
            mint_policy_table_id    bigint(20) UNSIGNED DEFAULT NULL,
            network                 varchar(20)    NOT NULL DEFAULT 'preprod',
            created_at              datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            settled_at              datetime       DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_status   (status),
            KEY idx_end_time (end_time),
            KEY idx_network  (network)
        ) {$c};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function insert( array $data ): int {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $data = array_merge( array( 'description' => '', 'nft_metadata' => '', 'gallery_images' => '[]' ), $data );
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $wpdb->insert( self::table(), $data );
        return (int) $wpdb->insert_id;
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ), ARRAY_A ) ?: null;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql', true );
        return (bool) $wpdb->update( self::table(), $data, array( 'id' => $id ) );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), array( 'id' => $id ) );
    }

    public static function get_for_update( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d FOR UPDATE", $id
        ), ARRAY_A ) ?: null;
    }

    public static function get_by_status( string $status, string $network = '' ): array {
        global $wpdb;
        $t = self::table();
        if ( $network ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$t} WHERE status = %s AND network = %s ORDER BY end_time ASC", $status, $network
            ), ARRAY_A ) ?: array();
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE status = %s ORDER BY end_time ASC", $status
        ), ARRAY_A ) ?: array();
    }

    public static function get_active( string $network = '' ): array {
        return self::get_by_status( 'active', $network );
    }

    public static function get_all( array $args = array() ): array {
        global $wpdb;
        $t = self::table();
        $where = '1=1'; $params = array();
        if ( ! empty( $args['status'] ) )  { $where .= ' AND status = %s';  $params[] = $args['status']; }
        if ( ! empty( $args['network'] ) ) { $where .= ' AND network = %s'; $params[] = $args['network']; }
        $sql = "SELECT * FROM {$t} WHERE {$where} ORDER BY created_at DESC";
        if ( ! empty( $args['limit'] ) )  { $sql .= ' LIMIT %d';  $params[] = (int) $args['limit']; }
        if ( ! empty( $args['offset'] ) ) { $sql .= ' OFFSET %d'; $params[] = (int) $args['offset']; }
        if ( $params ) $sql = $wpdb->prepare( $sql, ...$params );
        return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
    }

    public static function count( array $args = array() ): int {
        global $wpdb;
        $t = self::table(); $where = '1=1'; $params = array();
        if ( ! empty( $args['status'] ) )  { $where .= ' AND status = %s';  $params[] = $args['status']; }
        if ( ! empty( $args['network'] ) ) { $where .= ' AND network = %s'; $params[] = $args['network']; }
        $sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
        if ( $params ) $sql = $wpdb->prepare( $sql, ...$params );
        return (int) $wpdb->get_var( $sql );
    }

    public static function get_ended_active(): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE status = 'active' AND end_time <= %s",
            current_time( 'mysql', true )
        ), ARRAY_A ) ?: array();
    }

    public static function drop_table(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table() );
    }
}
