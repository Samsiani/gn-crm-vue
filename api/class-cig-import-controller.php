<?php
/**
 * Import REST controller — JSON migration from old plugin.
 *
 * Endpoints (all require is_admin permission):
 *   POST /cig/v1/import/preview  — analyse JSON, no DB writes
 *   POST /cig/v1/import/run      — run the import
 *   GET  /cig/v1/import/log      — retrieve last import log
 */
class CIG_Import_Controller extends CIG_REST_Controller {

    public function register_routes() {
        register_rest_route( $this->namespace, '/import/preview', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'preview' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/import/run', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'run_import' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/import/log', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_log' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/import/relink', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'relink' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/import/sync/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'sync_status' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/import/sync/settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'sync_save_settings' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/import/sync/run', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'sync_run' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/import/repair-paid', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'repair_paid' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );
    }

    /**
     * POST /import/preview
     * Body: { data: { ...json... } }
     */
    public function preview( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        $data = $body['data'] ?? null;

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'cig_import_invalid', 'Invalid JSON data.', [ 'status' => 400 ] );
        }

        require_once CIG_PLUGIN_DIR . 'migration/class-cig-importer.php';

        $result = CIG_Importer::preview( $data );
        return rest_ensure_response( $result );
    }

    /**
     * POST /import/run
     * Body: { data: { ...json... }, options: { ... } }
     */
    public function run_import( WP_REST_Request $request ) {
        $body    = $request->get_json_params();
        $data    = $body['data'] ?? null;
        $options = $body['options'] ?? [];

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'cig_import_invalid', 'Invalid JSON data.', [ 'status' => 400 ] );
        }

        require_once CIG_PLUGIN_DIR . 'migration/class-cig-importer.php';

        $importer = new CIG_Importer();
        $result   = $importer->run( $data, $options );

        return rest_ensure_response( $result );
    }

    /**
     * POST /import/relink
     * Re-links customer_id on invoices and product_id on items.
     * Safe to run multiple times — only touches NULL fields.
     */
    public function relink( WP_REST_Request $request ) {
        require_once CIG_PLUGIN_DIR . 'migration/class-cig-importer.php';
        $result = CIG_Importer::relink();
        return rest_ensure_response( $result );
    }

    /**
     * GET /import/log
     */
    public function get_log( WP_REST_Request $request ) {
        $log = get_option( 'cig_last_import_log', null );
        if ( ! $log ) {
            return new WP_Error( 'cig_no_log', 'No import log found.', [ 'status' => 404 ] );
        }
        return rest_ensure_response( $log );
    }

    /**
     * POST /import/repair-paid
     * Fetches export JSON and sets paid_amount on each invoice from the authoritative
     * exported value (fixing incorrect recalculated values from payment history).
     */
    public function repair_paid( WP_REST_Request $request ) {
        $url = sanitize_url( get_option( 'cig_sync_url', '' ) );
        if ( empty( $url ) ) {
            return new WP_Error( 'cig_no_url', 'No sync URL configured. Set it in the Live Sync section first.', [ 'status' => 400 ] );
        }

        $response = wp_remote_get( $url, [ 'timeout' => 90, 'sslverify' => false ] );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'cig_fetch_failed', $response->get_error_message(), [ 'status' => 502 ] );
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'cig_fetch_failed', "Remote returned HTTP {$code}", [ 'status' => 502 ] );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['invoices'] ) ) {
            return new WP_Error( 'cig_invalid_json', 'Invalid JSON from remote URL.', [ 'status' => 400 ] );
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'cig_invoices';
        $fixed   = 0;
        $skipped = 0;

        foreach ( $data['invoices'] as $inv ) {
            $inv_number  = trim( $inv['invoice_number'] ?? '' );
            $paid_amount = (float) ( $inv['paid_amount'] ?? 0 );
            if ( empty( $inv_number ) ) continue;

            $updated = $wpdb->update(
                $table,
                [ 'paid_amount' => $paid_amount ],
                [ 'invoice_number' => $inv_number ]
            );
            if ( $updated ) $fixed++;
            else $skipped++;
        }

        // Clear KPI cache
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cig_kpi_%'" );

        return rest_ensure_response( [
            'fixed'   => $fixed,
            'skipped' => $skipped,
            'total'   => count( $data['invoices'] ),
        ] );
    }

    /**
     * GET /import/sync/status
     * Returns current sync settings + last log + next scheduled run.
     */
    public function sync_status( WP_REST_Request $request ) {
        require_once CIG_PLUGIN_DIR . 'migration/class-cig-sync.php';
        return rest_ensure_response( CIG_Sync::get_status() );
    }

    /**
     * POST /import/sync/settings
     * Body: { url: string, enabled: bool }
     */
    public function sync_save_settings( WP_REST_Request $request ) {
        $body    = $request->get_json_params();
        $url     = sanitize_url( $body['url'] ?? '' );
        $enabled = ! empty( $body['enabled'] );

        require_once CIG_PLUGIN_DIR . 'migration/class-cig-sync.php';
        CIG_Sync::save_settings( $url, $enabled );

        return rest_ensure_response( CIG_Sync::get_status() );
    }

    /**
     * POST /import/sync/run
     * Manually trigger a sync now. Body: { url?: string } — uses saved URL if omitted.
     */
    public function sync_run( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        $url  = sanitize_url( $body['url'] ?? '' );

        require_once CIG_PLUGIN_DIR . 'migration/class-cig-sync.php';

        if ( empty( $url ) ) {
            $url = get_option( CIG_Sync::OPTION_URL, '' );
        }
        if ( empty( $url ) ) {
            return new WP_Error( 'cig_sync_no_url', 'No sync URL configured.', [ 'status' => 400 ] );
        }

        $result = CIG_Sync::fetch_and_sync( $url );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }
}
