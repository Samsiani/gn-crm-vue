<?php
/**
 * Notifications REST controller.
 *
 * GET    /notifications            List (most recent 50)
 * GET    /notifications?after_id=N Only newer than N (polling)
 * POST   /notifications/read-all   Mark all read
 * PUT    /notifications/:id/read   Mark one read
 * DELETE /notifications/:id        Delete one
 * DELETE /notifications            Clear all
 */
class CIG_Notifications_Controller extends CIG_REST_Controller {

    public function register_routes() {
        $ns = $this->namespace;

        register_rest_route( $ns, '/notifications', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'list_items' ],
                'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'clear_all' ],
                'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
            ],
        ] );

        register_rest_route( $ns, '/notifications/read-all', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'mark_all_read' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $ns, '/notifications/(?P<id>\d+)/read', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'mark_read' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
            'args'                => [ 'id' => [ 'validate_callback' => 'is_numeric' ] ],
        ] );

        register_rest_route( $ns, '/notifications/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
            'args'                => [ 'id' => [ 'validate_callback' => 'is_numeric' ] ],
        ] );
    }

    public function list_items( \WP_REST_Request $request ) {
        // 3am auto-clear (cheap option-based check, runs at most once per day)
        CIG_Notification::maybe_clear_old();

        $after_id = (int) $request->get_param( 'after_id' );
        $items    = CIG_Notification::list( [ 'after_id' => $after_id ] );
        return rest_ensure_response( $items );
    }

    public function mark_all_read( \WP_REST_Request $request ) {
        CIG_Notification::mark_all_read();
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function mark_read( \WP_REST_Request $request ) {
        CIG_Notification::mark_read( (int) $request->get_param( 'id' ) );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function delete_item( \WP_REST_Request $request ) {
        CIG_Notification::delete( (int) $request->get_param( 'id' ) );
        return new WP_REST_Response( null, 204 );
    }

    public function clear_all( \WP_REST_Request $request ) {
        CIG_Notification::clear_all();
        return rest_ensure_response( [ 'success' => true ] );
    }
}
