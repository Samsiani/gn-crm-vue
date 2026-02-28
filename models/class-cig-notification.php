<?php
/**
 * Notification model — persisted alerts for invoice events and stock changes.
 */
class CIG_Notification {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cig_notifications';
    }

    // ── Create ─────────────────────────────────────────────────────────────────

    /**
     * @param string $type    invoice | stock | system
     * @param string $title   Short heading (English)
     * @param string $message Detail line (English)
     * @param string $icon    Lucide icon name
     * @param string $link    Frontend route, e.g. /invoices/42
     */
    public static function create( string $type, string $title, string $message, string $icon = 'bell', string $link = '' ): void {
        global $wpdb;
        $wpdb->insert( self::table(), [
            'type'       => $type,
            'title'      => $title,
            'message'    => $message,
            'icon'       => $icon,
            'link'       => $link,
            'is_read'    => 0,
            'created_at' => current_time( 'mysql' ),
        ] );
    }

    // ── List ───────────────────────────────────────────────────────────────────

    /**
     * Return recent notifications.
     *
     * @param array $args {
     *   after_id int  Only return rows with id > after_id (polling).
     *   limit    int  Max rows (default 50).
     * }
     */
    public static function list( array $args = [] ): array {
        global $wpdb;
        $table    = self::table();
        $after_id = isset( $args['after_id'] ) ? (int) $args['after_id'] : 0;
        $limit    = isset( $args['limit'] )    ? (int) $args['limit']    : 50;

        if ( $after_id > 0 ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id > %d ORDER BY id DESC LIMIT %d",
                $after_id, $limit
            ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
                $limit
            ), ARRAY_A );
        }

        return array_map( [ __CLASS__, 'hydrate' ], $rows ?: [] );
    }

    // ── Read / Delete ──────────────────────────────────────────────────────────

    public static function mark_read( int $id ): void {
        global $wpdb;
        $wpdb->update( self::table(), [ 'is_read' => 1 ], [ 'id' => $id ] );
    }

    public static function mark_all_read(): void {
        global $wpdb;
        $wpdb->query( "UPDATE " . self::table() . " SET is_read = 1 WHERE is_read = 0" );
    }

    public static function delete( int $id ): void {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'id' => $id ] );
    }

    public static function clear_all(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM " . self::table() );
    }

    /**
     * Delete all notifications created before $before_datetime.
     * Called by the 3 am daily cleanup.
     */
    public static function clear_before( string $before_datetime ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE created_at < %s",
            $before_datetime
        ) );
    }

    // ── 3 am auto-clear ────────────────────────────────────────────────────────

    /**
     * If the local time is past 03:00 and we haven't cleared today yet,
     * delete all notifications older than today 03:00.
     * Called at the start of every GET /notifications request (cheap option check).
     */
    public static function maybe_clear_old(): void {
        $tz        = wp_timezone();
        $now       = new DateTime( 'now', $tz );
        $today_str = $now->format( 'Y-m-d' );

        // Read once, skip if already cleared today
        $last = get_option( 'cig_notif_last_cleared', '' );
        if ( $last === $today_str ) return;

        $today_3am = new DateTime( $today_str . ' 03:00:00', $tz );
        if ( $now < $today_3am ) return; // Not yet 3 am

        // Past 3 am — clear everything before today 03:00
        self::clear_before( $today_3am->format( 'Y-m-d H:i:s' ) );
        update_option( 'cig_notif_last_cleared', $today_str );
    }

    // ── Hydration ──────────────────────────────────────────────────────────────

    private static function hydrate( array $row ): array {
        return [
            'id'        => (int)  $row['id'],
            'type'      =>        $row['type'],
            'title'     =>        $row['title'],
            'message'   =>        $row['message'],
            'icon'      =>        $row['icon'],
            'link'      =>        $row['link'],
            'read'      => (bool) $row['is_read'],
            'time'      =>        $row['created_at'],
        ];
    }
}
