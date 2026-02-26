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
}
