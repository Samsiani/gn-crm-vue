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
     * GET /import/log
     */
    public function get_log( WP_REST_Request $request ) {
        $log = get_option( 'cig_last_import_log', null );
        if ( ! $log ) {
            return new WP_Error( 'cig_no_log', 'No import log found.', [ 'status' => 404 ] );
        }
        return rest_ensure_response( $log );
    }
}
