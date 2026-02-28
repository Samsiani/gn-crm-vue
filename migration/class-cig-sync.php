<?php
/**
 * CIG Sync — fetches JSON export from remote URL and syncs invoices.
 *
 * Registered as a WP Cron job every 10 minutes.
 * Can also be triggered manually via REST API.
 */
class CIG_Sync {

    const OPTION_URL     = 'cig_sync_url';
    const OPTION_ENABLED = 'cig_sync_enabled';
    const OPTION_LOG     = 'cig_last_sync_log';

    /**
     * Run a sync cycle: fetch JSON from saved URL, call importer, save log.
     * Called by WP Cron hook 'cig_sync_cron'.
     *
     * @return array|WP_Error Sync results or error.
     */
    public static function run() {
        $url     = get_option( self::OPTION_URL, '' );
        $enabled = (bool) get_option( self::OPTION_ENABLED, false );

        if ( ! $enabled || empty( $url ) ) {
            return new WP_Error( 'cig_sync_disabled', 'Live sync is disabled or URL not set.' );
        }

        return self::fetch_and_sync( $url );
    }

    /**
     * Fetch a URL and run the sync — used both by cron and manual trigger.
     *
     * @param string $url Remote export URL.
     * @return array|WP_Error
     */
    public static function fetch_and_sync( string $url, bool $force = false ) {
        $start = microtime( true );

        $response = wp_remote_get( $url, [
            'timeout'   => 90,
            'sslverify' => false,
        ] );

        if ( is_wp_error( $response ) ) {
            $log = self::make_log( 'error', $response->get_error_message(), [], $start );
            self::save_log( $log );
            return $log;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $log = self::make_log( 'error', "Remote server returned HTTP {$code}", [], $start );
            self::save_log( $log );
            return $log;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || empty( $data['invoices'] ) ) {
            $log = self::make_log( 'error', 'Invalid or empty JSON response from remote URL', [], $start );
            self::save_log( $log );
            return $log;
        }

        // Run the sync
        require_once CIG_PLUGIN_DIR . 'migration/class-cig-importer.php';
        $importer = new CIG_Importer();
        $results  = $importer->sync( $data, $force );

        $log = self::make_log( 'success', '', $results, $start );
        self::save_log( $log );
        return $log;
    }

    /**
     * Get sync status: last log + next scheduled run + settings.
     *
     * @return array
     */
    public static function get_status(): array {
        $log      = get_option( self::OPTION_LOG, null );
        $next_run = wp_next_scheduled( 'cig_sync_cron' );

        return [
            'enabled'  => (bool) get_option( self::OPTION_ENABLED, false ),
            'url'      => get_option( self::OPTION_URL, '' ),
            'last_log' => $log,
            'next_run' => $next_run ? date( 'c', $next_run ) : null,
        ];
    }

    /**
     * Save or update sync settings and reschedule cron.
     *
     * @param string $url     Remote export URL.
     * @param bool   $enabled Whether auto-sync is enabled.
     */
    public static function save_settings( string $url, bool $enabled ): void {
        update_option( self::OPTION_URL, sanitize_url( $url ), false );
        update_option( self::OPTION_ENABLED, $enabled ? '1' : '0', false );

        // Reschedule cron: clear and re-add if enabled
        $timestamp = wp_next_scheduled( 'cig_sync_cron' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'cig_sync_cron' );
        }

        if ( $enabled && ! empty( $url ) ) {
            wp_schedule_event( time(), 'cig_10min', 'cig_sync_cron' );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function make_log( string $status, string $error, array $results, float $start ): array {
        return [
            'status'      => $status,
            'error'       => $error,
            'results'     => $results,
            'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
            'synced_at'   => date( 'c' ),
        ];
    }

    private static function save_log( array $log ): void {
        update_option( self::OPTION_LOG, $log, false );
    }
}
