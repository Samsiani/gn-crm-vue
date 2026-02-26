<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Other Deliveries REST controller — tracks delivery records for "Other" payment balance.
 */
class CIG_Deliveries_Controller extends CIG_REST_Controller {

    public function register_routes() {
        register_rest_route( $this->namespace, '/other-deliveries', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_items' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $this->namespace, '/other-deliveries', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/other-deliveries/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/other-deliveries/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );
    }

    public function list_items( $request ) {
        $pagination = $this->get_pagination_params( $request );

        $args = array_merge( $pagination, [
            'date_from' => sanitize_text_field( $request->get_param( 'date_from' ) ?: '' ),
            'date_to'   => sanitize_text_field( $request->get_param( 'date_to' ) ?: '' ),
        ] );

        $result = CIG_Delivery::list( $args );
        return $this->paginated_response( $result );
    }

    public function create_item( $request ) {
        $data = $request->get_json_params();
        $delivery = CIG_Delivery::create( $data );
        if ( is_wp_error( $delivery ) ) return $delivery;
        return new WP_REST_Response( $delivery, 201 );
    }

    public function update_item( $request ) {
        $id   = (int) $request->get_param( 'id' );
        $data = $request->get_json_params();
        $delivery = CIG_Delivery::update( $id, $data );
        if ( is_wp_error( $delivery ) ) return $delivery;
        return rest_ensure_response( $delivery );
    }

    public function delete_item( $request ) {
        $id = (int) $request->get_param( 'id' );
        $existing = CIG_Delivery::find( $id );
        if ( ! $existing ) {
            return new WP_Error( 'cig_not_found', 'Delivery record not found.', [ 'status' => 404 ] );
        }
        CIG_Delivery::delete( $id );
        return new WP_REST_Response( null, 204 );
    }
}
