<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Products REST controller — CRUD with stock management.
 */
class CIG_Products_Controller extends CIG_REST_Controller {

    public function register_routes() {
        register_rest_route( $this->namespace, '/products', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_items' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $this->namespace, '/products', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/products/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $this->namespace, '/products/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_write' ],
        ] );

        register_rest_route( $this->namespace, '/products/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );
    }

    public function list_items( $request ) {
        $pagination = $this->get_pagination_params( $request );
        $sorting = $this->get_sort_params( $request );

        $args = array_merge( $pagination, $sorting, [
            'search'   => sanitize_text_field( $request->get_param( 'search' ) ?: '' ),
            'category' => sanitize_text_field( $request->get_param( 'category' ) ?: '' ),
        ] );

        if ( ! empty( $args['sort'] ) ) {
            $args['sort'] = $this->camel_to_snake( $args['sort'] );
        }

        $result = CIG_Product::list( $args );
        return $this->paginated_response( $result );
    }

    public function get_item( $request ) {
        $id = (int) $request->get_param( 'id' );
        $product = CIG_Product::find( $id );
        if ( ! $product ) {
            return new WP_Error( 'cig_not_found', 'Product not found.', [ 'status' => 404 ] );
        }
        return rest_ensure_response( $product );
    }

    public function create_item( $request ) {
        $data = $request->get_json_params();
        $product = CIG_Product::create( $data );
        if ( is_wp_error( $product ) ) return $product;
        return new WP_REST_Response( $product, 201 );
    }

    public function update_item( $request ) {
        $id   = (int) $request->get_param( 'id' );
        $data = $request->get_json_params();
        $user = $this->get_user( $request );

        // Sales role: create stock request instead of direct update
        if ( $user['role'] === 'sales' ) {
            // For now, direct update is allowed; stock request feature can be added later
            // Only allow updating stock-related fields
        }

        $product = CIG_Product::update( $id, $data );
        if ( is_wp_error( $product ) ) return $product;
        return rest_ensure_response( $product );
    }

    public function delete_item( $request ) {
        $id = (int) $request->get_param( 'id' );
        $existing = CIG_Product::find( $id );
        if ( ! $existing ) {
            return new WP_Error( 'cig_not_found', 'Product not found.', [ 'status' => 404 ] );
        }
        CIG_Product::delete( $id );
        return new WP_REST_Response( null, 204 );
    }
}
