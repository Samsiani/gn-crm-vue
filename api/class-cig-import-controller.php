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
     * Recalculates paid_amount on all invoices from their actual payment records.
     * Excludes consignment payments (consistent with financial model).
     * Safe to run multiple times.
     */
    public function repair_paid( WP_REST_Request $request ) {
        global $wpdb;
        $inv_table = $wpdb->prefix . 'cig_invoices';
        $pay_table = $wpdb->prefix . 'cig_payments';

        // Bulk recalculate paid_amount from payment records (excluding consignment)
        $rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inv_table}" );

        $wpdb->query(
            "UPDATE {$inv_table} SET paid_amount = (
                SELECT COALESCE(SUM(p.amount), 0)
                FROM {$pay_table} p
                WHERE p.invoice_id = {$inv_table}.id
                  AND p.method != 'consignment'
             )"
        );

        // Clear all KPI transient caches
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cig_kpi%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_cig_kpi%'" );

        return rest_ensure_response( [
            'fixed'   => $rows_before,
            'message' => 'paid_amount recalculated from payment records (consignment excluded)',
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
