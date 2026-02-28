<?php
/**
 * CA_Master_Wallet_Model — DB model for master HD wallets.
 * One active wallet per network. Derives child escrow addresses via CIP-1852.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Master_Wallet_Model {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ca_master_wallets';
    }

    public static function create_table(): void {
        global $wpdb;
        $t = self::table();
        $c = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$t} (
            id                    bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            wallet_name           varchar(100) NOT NULL DEFAULT 'Master Wallet',
            mnemonic_encrypted    text         NOT NULL,
            skey_encrypted        text         NOT NULL,
            payment_address       varchar(128) NOT NULL DEFAULT '',
            payment_keyhash       varchar(64)  NOT NULL DEFAULT '',
            stake_address         varchar(128) NOT NULL DEFAULT '',
            network               varchar(20)  NOT NULL DEFAULT 'preprod',
            next_derivation_index int UNSIGNED  NOT NULL DEFAULT 1,
            archived              tinyint(1)   NOT NULL DEFAULT 0,
            archived_at           datetime     DEFAULT NULL,
            created_at            datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_network  (network),
            KEY idx_archived (archived)
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

    /**
     * Get the active (non-archived) master wallet for a network.
     */
    public static function get_active( string $network ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE network = %s AND archived = 0 ORDER BY id DESC LIMIT 1",
            $network
        ), ARRAY_A ) ?: null;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( self::table(), $data, array( 'id' => $id ) );
    }

    public static function archive( int $id ): bool {
        return self::update( $id, array(
            'archived'    => 1,
            'archived_at' => current_time( 'mysql', true ),
        ) );
    }

    public static function unarchive( int $id ): bool {
        return self::update( $id, array(
            'archived'    => 0,
            'archived_at' => null,
        ) );
    }

    public static function increment_derivation_index( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET next_derivation_index = next_derivation_index + 1 WHERE id = %d",
            $id
        ) );
    }

    public static function count_archived( string $network ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE network = %s AND archived = 1",
            $network
        ) );
    }

    public static function get_archived( string $network ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE network = %s AND archived = 1 ORDER BY archived_at DESC",
            $network
        ), ARRAY_A ) ?: array();
    }

    public static function drop_table(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table() );
    }
}
