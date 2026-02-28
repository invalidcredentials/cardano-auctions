<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Event_Log_Model {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ca_event_log';
    }

    public static function create_table(): void {
        global $wpdb;
        $t = self::table();
        $c = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$t} (
            id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            auction_id  bigint(20) UNSIGNED DEFAULT NULL,
            bid_id      bigint(20) UNSIGNED DEFAULT NULL,
            event_type  varchar(50)    NOT NULL DEFAULT '',
            message     text           NOT NULL,
            meta        longtext       NOT NULL,
            created_at  datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_auction (auction_id),
            KEY idx_type    (event_type)
        ) {$c};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function log( int $auction_id, int $bid_id, string $event_type, string $message, array $meta = array() ): int {
        global $wpdb;
        $wpdb->insert( self::table(), array(
            'auction_id' => $auction_id ?: null,
            'bid_id'     => $bid_id ?: null,
            'event_type' => $event_type,
            'message'    => $message,
            'meta'       => ! empty( $meta ) ? wp_json_encode( $meta ) : '',
            'created_at' => current_time( 'mysql', true ),
        ) );
        return (int) $wpdb->insert_id;
    }

    public static function get_by_auction( int $auction_id, int $limit = 50 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE auction_id = %d ORDER BY created_at DESC LIMIT %d",
            $auction_id, $limit
        ), ARRAY_A ) ?: array();
    }

    public static function get_all( array $args = array() ): array {
        global $wpdb;
        $t = self::table();
        $where = '1=1';
        $params = array();

        if ( ! empty( $args['auction_id'] ) ) { $where .= ' AND auction_id = %d'; $params[] = (int) $args['auction_id']; }
        if ( ! empty( $args['event_type'] ) ) { $where .= ' AND event_type = %s'; $params[] = $args['event_type']; }

        $limit  = min( (int) ( $args['limit'] ?? 10000 ), 10000 );
        $offset = (int) ( $args['offset'] ?? 0 );

        $sql = "SELECT * FROM {$t} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;
        $sql = $wpdb->prepare( $sql, ...$params );
        return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
    }

    public static function get_event_types(): array {
        global $wpdb;
        return $wpdb->get_col( "SELECT DISTINCT event_type FROM " . self::table() . " ORDER BY event_type ASC" ) ?: array();
    }

    public static function get_recent( int $limit = 100 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " ORDER BY created_at DESC LIMIT %d", $limit
        ), ARRAY_A ) ?: array();
    }

    public static function drop_table(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table() );
    }
}
