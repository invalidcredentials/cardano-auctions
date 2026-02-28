<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Escrow_Model {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ca_escrow_wallets';
    }

    public static function create_table(): void {
        global $wpdb;
        $t = self::table();
        $c = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$t} (
            id                  bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            mnemonic_encrypted  text           NOT NULL,
            skey_encrypted      text           NOT NULL,
            payment_address     varchar(128)   NOT NULL DEFAULT '',
            payment_keyhash     varchar(64)    NOT NULL DEFAULT '',
            purpose             varchar(20)    NOT NULL DEFAULT 'escrow',
            auction_id          bigint(20) UNSIGNED DEFAULT NULL,
            bid_id              bigint(20) UNSIGNED DEFAULT NULL,
            status              varchar(20)    NOT NULL DEFAULT 'active',
            balance_lovelace    bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            network             varchar(20)    NOT NULL DEFAULT 'preprod',
            master_wallet_id    bigint(20) UNSIGNED DEFAULT NULL,
            derivation_index    int UNSIGNED   NOT NULL DEFAULT 0,
            created_at          datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_auction (auction_id),
            KEY idx_bid     (bid_id),
            KEY idx_purpose (purpose),
            KEY idx_status  (status),
            KEY idx_address (payment_address)
        ) {$c};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function insert( array $data ): int {
        global $wpdb;
        $data['created_at'] = current_time( 'mysql', true );
        $wpdb->insert( self::table(), $data );
        return (int) $wpdb->insert_id;
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", $id
        ), ARRAY_A ) ?: null;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( self::table(), $data, array( 'id' => $id ) );
    }

    public static function get_by_address( string $address ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE payment_address = %s LIMIT 1", $address
        ), ARRAY_A ) ?: null;
    }

    public static function get_custody_wallet( int $auction_id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE auction_id = %d AND purpose = 'nft_custody' AND status = 'active' LIMIT 1",
            $auction_id
        ), ARRAY_A ) ?: null;
    }

    public static function get_active_escrows(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::table() . " WHERE status = 'active' AND purpose = 'escrow'", ARRAY_A
        ) ?: array();
    }

    public static function get_all( array $args = array() ): array {
        global $wpdb;
        $t = self::table();
        $where = '1=1';
        $params = array();

        if ( ! empty( $args['purpose'] ) )    { $where .= ' AND purpose = %s';    $params[] = $args['purpose']; }
        if ( ! empty( $args['status'] ) )     { $where .= ' AND status = %s';     $params[] = $args['status']; }
        if ( ! empty( $args['network'] ) )    { $where .= ' AND network = %s';    $params[] = $args['network']; }
        if ( ! empty( $args['auction_id'] ) ) { $where .= ' AND auction_id = %d'; $params[] = (int) $args['auction_id']; }

        $sql = "SELECT * FROM {$t} WHERE {$where} ORDER BY created_at DESC";
        if ( ! empty( $args['limit'] ) )  { $sql .= ' LIMIT %d';  $params[] = (int) $args['limit']; }
        if ( ! empty( $args['offset'] ) ) { $sql .= ' OFFSET %d'; $params[] = (int) $args['offset']; }
        if ( $params ) $sql = $wpdb->prepare( $sql, ...$params );
        return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
    }

    public static function count( array $args = array() ): int {
        global $wpdb;
        $t = self::table();
        $where = '1=1';
        $params = array();

        if ( ! empty( $args['purpose'] ) )    { $where .= ' AND purpose = %s';    $params[] = $args['purpose']; }
        if ( ! empty( $args['status'] ) )     { $where .= ' AND status = %s';     $params[] = $args['status']; }
        if ( ! empty( $args['network'] ) )    { $where .= ' AND network = %s';    $params[] = $args['network']; }
        if ( ! empty( $args['auction_id'] ) ) { $where .= ' AND auction_id = %d'; $params[] = (int) $args['auction_id']; }

        $sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
        if ( $params ) $sql = $wpdb->prepare( $sql, ...$params );
        return (int) $wpdb->get_var( $sql );
    }

    public static function archive( int $id ): bool { return self::update( $id, array( 'status' => 'archived' ) ); }
    public static function drain( int $id ): bool   { return self::update( $id, array( 'status' => 'drained', 'balance_lovelace' => 0 ) ); }

    public static function drop_table(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table() );
    }
}
