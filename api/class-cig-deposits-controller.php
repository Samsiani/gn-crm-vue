<?php
/**
 * Deposits REST controller — external balance credit/debit tracking.
 */
class CIG_Deposits_Controller extends CIG_REST_Controller {

    public function register_routes() {
        register_rest_route( $this->namespace, '/deposits', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_items' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $this->namespace, '/deposits', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/deposits/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/deposits/balance', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_balance' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );
    }

    public function list_items( $request ) {
        $pagination = $this->get_pagination_params( $request );

        $args = array_merge( $pagination, [
            'date_from' => sanitize_text_field( $request->get_param( 'date_from' ) ?: '' ),
            'date_to'   => sanitize_text_field( $request->get_param( 'date_to' ) ?: '' ),
        ] );

        $result = CIG_Deposit::list( $args );
        return $this->paginated_response( $result );
    }

    public function create_item( $request ) {
        $data = $request->get_json_params();
        $deposit = CIG_Deposit::create( $data );
        if ( is_wp_error( $deposit ) ) return $deposit;
        return new WP_REST_Response( $deposit, 201 );
    }

    public function delete_item( $request ) {
        $id = (int) $request->get_param( 'id' );
        $existing = CIG_Deposit::find( $id );
        if ( ! $existing ) {
            return new WP_Error( 'cig_not_found', 'Deposit not found.', [ 'status' => 404 ] );
        }
        CIG_Deposit::delete( $id );
        return new WP_REST_Response( null, 204 );
    }

    public function get_balance( $request ) {
        return rest_ensure_response( [
            'balance' => CIG_Deposit::get_balance(),
        ] );
    }
}
