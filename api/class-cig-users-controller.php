<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Users REST controller — team directory CRUD.
 */
class CIG_Users_Controller extends CIG_REST_Controller {

    public function register_routes() {
        register_rest_route( $this->namespace, '/users', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_items' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $this->namespace, '/users', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/users/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $this->namespace, '/users/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/users/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );
    }

    public function list_items( $request ) {
        $pagination = $this->get_pagination_params( $request );
        $sorting    = $this->get_sort_params( $request );

        $args = array_merge( $pagination, $sorting, [
            'search' => sanitize_text_field( $request->get_param( 'search' ) ?: '' ),
            'role'   => sanitize_text_field( $request->get_param( 'role' ) ?: '' ),
        ] );

        if ( ! empty( $args['sort'] ) ) {
            $args['sort'] = $this->camel_to_snake( $args['sort'] );
        }

        $result = CIG_User::list( $args );

        // Optionally attach per-user invoice stats in a single batch query
        if ( filter_var( $request->get_param( 'include_stats' ), FILTER_VALIDATE_BOOLEAN ) ) {
            $user_ids  = array_column( $result['data'], 'id' );
            $stats_map = CIG_User::batch_invoice_stats( $user_ids );
            $result['data'] = array_map( function( $user ) use ( $stats_map ) {
                $s = $stats_map[ $user['id'] ] ?? [ 'revenue' => 0, 'invoiceCount' => 0, 'outstanding' => 0 ];
                $user['stats'] = $s;
                return $user;
            }, $result['data'] );
        }

        return $this->paginated_response( $result );
    }

    public function get_item( $request ) {
        $id = (int) $request->get_param( 'id' );
        $user = CIG_User::find( $id );
        if ( ! $user ) {
            return new WP_Error( 'cig_not_found', 'User not found.', [ 'status' => 404 ] );
        }
        return rest_ensure_response( $user );
    }

    public function create_item( $request ) {
        $data = $request->get_json_params();
        $user = CIG_User::create( $data );
        if ( is_wp_error( $user ) ) return $user;
        return new WP_REST_Response( $user, 201 );
    }

    public function update_item( $request ) {
        $id   = (int) $request->get_param( 'id' );
        $data = $request->get_json_params();
        $user = CIG_User::update( $id, $data );
        if ( is_wp_error( $user ) ) return $user;
        return rest_ensure_response( $user );
    }

    public function delete_item( $request ) {
        $id = (int) $request->get_param( 'id' );
        $current_user = $this->get_user( $request );

        // Prevent self-delete
        if ( $id === $current_user['id'] ) {
            return new WP_Error( 'cig_forbidden', 'Cannot delete your own account.', [ 'status' => 403 ] );
        }

        $existing = CIG_User::find( $id );
        if ( ! $existing ) {
            return new WP_Error( 'cig_not_found', 'User not found.', [ 'status' => 404 ] );
        }

        CIG_User::delete( $id );
        return new WP_REST_Response( null, 204 );
    }
}
