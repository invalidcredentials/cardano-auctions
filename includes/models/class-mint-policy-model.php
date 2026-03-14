<?php
/**
 * CA_Mint_Policy_Model — DB model for minting policies (collections).
 * One policy = one native script = one thematic collection of NFTs.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Mint_Policy_Model {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ca_mint_policies';
    }

    public static function create_table(): void {
        global $wpdb;
        $t = self::table();
        $c = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$t} (
            id                  bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name                varchar(255) NOT NULL,
            policy_id           varchar(56)  NOT NULL,
            policy_schema       longtext     NOT NULL,
            policy_script_cbor  text         NOT NULL,
            skey_encrypted      text         DEFAULT NULL,
            key_type            varchar(20)  NOT NULL DEFAULT 'master',
            source              varchar(20)  NOT NULL DEFAULT 'generated',
            master_wallet_id    bigint(20) UNSIGNED DEFAULT NULL,
            expiration_date     datetime     DEFAULT NULL,
            expiration_slot     bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            items_minted        int UNSIGNED NOT NULL DEFAULT 0,
            status              varchar(20)  NOT NULL DEFAULT 'active',
            network             varchar(20)  NOT NULL,
            created_at          datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_policy_id (policy_id),
            KEY idx_network_status (network, status)
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

    public static function get_by_policy_id( string $policy_id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE policy_id = %s ORDER BY id DESC LIMIT 1",
            $policy_id
        ), ARRAY_A ) ?: null;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( self::table(), $data, array( 'id' => $id ) );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), array( 'id' => $id ) );
    }

    /**
     * Get all active policies for a network.
     */
    public static function get_active( string $network ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE network = %s AND status = 'active' ORDER BY created_at DESC",
            $network
        ), ARRAY_A ) ?: array();
    }

    public static function increment_items_minted( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET items_minted = items_minted + 1 WHERE id = %d",
            $id
        ) );
    }

    public static function count( array $args = array() ): int {
        global $wpdb;
        $t = self::table();
        $where = '1=1';
        $params = array();
        if ( ! empty( $args['network'] ) ) { $where .= ' AND network = %s'; $params[] = $args['network']; }
        if ( ! empty( $args['status'] ) )  { $where .= ' AND status = %s';  $params[] = $args['status']; }
        $sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
        if ( $params ) $sql = $wpdb->prepare( $sql, ...$params );
        return (int) $wpdb->get_var( $sql );
    }

    public static function drop_table(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table() );
    }
}
