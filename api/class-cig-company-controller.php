<?php
/**
 * Company REST controller — single-row config read/update.
 */
class CIG_Company_Controller extends CIG_REST_Controller {

    public function register_routes() {
        register_rest_route( $this->namespace, '/company', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $this->namespace, '/company', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/company/upload', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'upload_file' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );
    }

    public function get_item( $request ) {
        $company = CIG_Company::get();
        if ( ! $company ) {
            return new WP_Error( 'cig_not_found', 'Company config not found.', [ 'status' => 404 ] );
        }
        return rest_ensure_response( $company );
    }

    public function update_item( $request ) {
        $data = $request->get_json_params();
        $company = CIG_Company::update( $data );
        if ( is_wp_error( $company ) ) return $company;
        return rest_ensure_response( $company );
    }

    public function upload_file( $request ) {
        $type = sanitize_key( $request->get_param( 'type' ) );
        if ( ! in_array( $type, [ 'logo', 'signature' ], true ) ) {
            return new WP_Error( 'cig_invalid_type', 'Invalid upload type. Use "logo" or "signature".', [ 'status' => 400 ] );
        }

        if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['size'] ) ) {
            return new WP_Error( 'cig_no_file', 'No file provided.', [ 'status' => 400 ] );
        }

        // Enforce a 2 MB upload limit
        if ( $_FILES['file']['size'] > 2 * MB_IN_BYTES ) {
            return new WP_Error( 'cig_file_too_large', 'File exceeds the 2 MB size limit.', [ 'status' => 400 ] );
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $overrides = [
            'test_form' => false,
            'mimes'     => [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'gif'          => 'image/gif',
                'png'          => 'image/png',
                'webp'         => 'image/webp',
            ],
        ];

        $upload = wp_handle_upload( $_FILES['file'], $overrides );

        if ( isset( $upload['error'] ) ) {
            return new WP_Error( 'cig_upload_failed', $upload['error'], [ 'status' => 500 ] );
        }

        $field   = $type === 'logo' ? 'logoUrl' : 'signatureUrl';
        $company = CIG_Company::update( [ $field => $upload['url'] ] );

        return rest_ensure_response( [ 'url' => $upload['url'], 'company' => $company ] );
    }
}
