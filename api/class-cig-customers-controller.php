<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Customers REST controller — CRUD with computed financial stats.
 */
class CIG_Customers_Controller extends CIG_REST_Controller {

    public function register_routes() {
        register_rest_route( $this->namespace, '/customers', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_items' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $this->namespace, '/customers', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_write' ],
        ] );

        register_rest_route( $this->namespace, '/customers/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $this->namespace, '/customers/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_write' ],
        ] );

        register_rest_route( $this->namespace, '/customers/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );
    }

    public function list_items( $request ) {
        $pagination = $this->get_pagination_params( $request );
        $sorting = $this->get_sort_params( $request );

        $args = array_merge( $pagination, $sorting, [
            'search' => sanitize_text_field( $request->get_param( 'search' ) ?: '' ),
        ] );

        if ( ! empty( $args['sort'] ) ) {
            $args['sort'] = $this->camel_to_snake( $args['sort'] );
        }

        $result = CIG_Customer::list( $args );
        return $this->paginated_response( $result );
    }

    public function get_item( $request ) {
        $id = (int) $request->get_param( 'id' );
        $customer = CIG_Customer::find( $id );
        if ( ! $customer ) {
            return new WP_Error( 'cig_not_found', 'Customer not found.', [ 'status' => 404 ] );
        }
        return rest_ensure_response( $customer );
    }

    public function create_item( $request ) {
        $data = $request->get_json_params();
        $customer = CIG_Customer::create( $data );
        if ( is_wp_error( $customer ) ) return $customer;
        return new WP_REST_Response( $customer, 201 );
    }

    public function update_item( $request ) {
        $id   = (int) $request->get_param( 'id' );
        $data = $request->get_json_params();
        $customer = CIG_Customer::update( $id, $data );
        if ( is_wp_error( $customer ) ) return $customer;
        return rest_ensure_response( $customer );
    }

    public function delete_item( $request ) {
        $id = (int) $request->get_param( 'id' );
        $existing = CIG_Customer::find( $id );
        if ( ! $existing ) {
            return new WP_Error( 'cig_not_found', 'Customer not found.', [ 'status' => 404 ] );
        }
        CIG_Customer::delete( $id );
        return new WP_REST_Response( null, 204 );
    }
}
