<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Bid_Model {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ca_bids';
    }

    public static function create_table(): void {
        global $wpdb;
        $t = self::table();
        $c = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$t} (
            id                      bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            auction_id              bigint(20) UNSIGNED NOT NULL,
            bidder_address          varchar(128)   NOT NULL DEFAULT '',
            bid_lovelace            bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            escrow_wallet_id        bigint(20) UNSIGNED DEFAULT NULL,
            escrow_address          varchar(128)   NOT NULL DEFAULT '',
            payment_status          varchar(20)    NOT NULL DEFAULT 'awaiting',
            payment_tx_hash         varchar(64)    NOT NULL DEFAULT '',
            payment_amount_lovelace bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            payment_deadline        datetime       NOT NULL DEFAULT '0000-00-00 00:00:00',
            refund_tx_hash          varchar(64)    NOT NULL DEFAULT '',
            refund_status           varchar(20)    NOT NULL DEFAULT '',
            refund_amount_lovelace  bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at              datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_auction  (auction_id),
            KEY idx_payment  (payment_status),
            KEY idx_escrow   (escrow_wallet_id),
            KEY idx_deadline (payment_deadline)
        ) {$c};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function insert( array $data ): int {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
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
        $data['updated_at'] = current_time( 'mysql', true );
        return (bool) $wpdb->update( self::table(), $data, array( 'id' => $id ) );
    }

    public static function get_by_auction( int $auction_id, string $status = '' ): array {
        global $wpdb;
        $t = self::table();
        if ( $status ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$t} WHERE auction_id = %d AND payment_status = %s ORDER BY bid_lovelace DESC",
                $auction_id, $status
            ), ARRAY_A ) ?: array();
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE auction_id = %d ORDER BY bid_lovelace DESC", $auction_id
        ), ARRAY_A ) ?: array();
    }

    public static function get_highest_confirmed( int $auction_id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE auction_id = %d AND payment_status IN ('confirmed','won')
             ORDER BY bid_lovelace DESC LIMIT 1",
            $auction_id
        ), ARRAY_A ) ?: null;
    }

    public static function get_awaiting_payment(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::table() . " WHERE payment_status IN ('awaiting','detected') ORDER BY payment_deadline ASC",
            ARRAY_A
        ) ?: array();
    }

    public static function get_pending_refunds(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::table() . " WHERE payment_status = 'refunding' AND refund_status IN ('pending','') ORDER BY created_at ASC",
            ARRAY_A
        ) ?: array();
    }

    public static function get_expired(): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE payment_status = 'awaiting' AND payment_deadline < %s",
            current_time( 'mysql', true )
        ), ARRAY_A ) ?: array();
    }

    public static function count_confirmed( int $auction_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE auction_id = %d AND payment_status IN ('confirmed','won','settled')",
            $auction_id
        ) );
    }

    public static function drop_table(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table() );
    }
}
