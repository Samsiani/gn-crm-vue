<?php
/**
 * Invoices REST controller — full CRUD with lifecycle filtering and role-aware access.
 */
class CIG_Invoices_Controller extends CIG_REST_Controller {

    public function register_routes() {
        // List invoices
        register_rest_route( $this->namespace, '/invoices', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_items' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        // Create invoice
        register_rest_route( $this->namespace, '/invoices', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_write' ],
        ] );

        // Get single invoice
        register_rest_route( $this->namespace, '/invoices/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        // Update invoice
        register_rest_route( $this->namespace, '/invoices/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_write' ],
        ] );

        // Delete invoice
        register_rest_route( $this->namespace, '/invoices/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_item' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        // Generate next invoice number
        register_rest_route( $this->namespace, '/invoices/next-number', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'next_number' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_write' ],
        ] );
    }

    public function list_items( $request ) {
        $user = $this->get_user( $request );
        $pagination = $this->get_pagination_params( $request );
        $sorting = $this->get_sort_params( $request );

        $args = array_merge( $pagination, $sorting, [
            'search'         => sanitize_text_field( $request->get_param( 'search' ) ?: '' ),
            'status'         => sanitize_text_field( $request->get_param( 'status' ) ?: '' ),
            'lifecycle'      => sanitize_text_field( $request->get_param( 'lifecycle' ) ?: '' ),
            'outstanding'    => (bool) $request->get_param( 'outstanding' ),
            'payment_method' => sanitize_text_field( $request->get_param( 'payment_method' ) ?: '' ),
            'date_from'      => sanitize_text_field( $request->get_param( 'date_from' ) ?: '' ),
            'date_to'        => sanitize_text_field( $request->get_param( 'date_to' ) ?: '' ),
            'customer_id'    => $request->get_param( 'customer_id' ) ? (int) $request->get_param( 'customer_id' ) : null,
            'completion'     => sanitize_text_field( $request->get_param( 'completion' ) ?: '' ),
            'flags'          => sanitize_text_field( $request->get_param( 'flags' ) ?: '' ),
            'lean'           => (bool) $request->get_param( 'lean' ),
        ] );

        // Sort field: convert camelCase from frontend
        if ( ! empty( $args['sort'] ) ) {
            $args['sort'] = $this->camel_to_snake( $args['sort'] );
        }

        // Sales role: auto-filter to own invoices only.
        // Accountant can see all invoices (their job is to review all).
        if ( $user['role'] === 'sales' ) {
            $args['author_id'] = $user['id'];
        } elseif ( $request->get_param( 'author_id' ) ) {
            $args['author_id'] = (int) $request->get_param( 'author_id' );
        }

        $result = CIG_Invoice::list( $args );
        return $this->paginated_response( $result );
    }

    public function get_item( $request ) {
        $id = (int) $request->get_param( 'id' );
        $invoice = CIG_Invoice::find( $id );

        if ( ! $invoice ) {
            return new WP_Error( 'cig_not_found', 'Invoice not found.', [ 'status' => 404 ] );
        }

        // Sales role: can only view own invoices
        $user = $this->get_user( $request );
        if ( CIG_RBAC::user_is_consultant( $user ) && $invoice['authorId'] !== $user['id'] ) {
            return new WP_Error( 'cig_forbidden', 'You can only view your own invoices.', [ 'status' => 403 ] );
        }

        return rest_ensure_response( $invoice );
    }

    public function create_item( $request ) {
        $data = $request->get_json_params();
        $user = $this->get_user( $request );

        // Set author to current user
        $data['authorId'] = $user['id'];

        // Standard invoices auto-activate on save
        if ( ( $data['status'] ?? 'standard' ) === 'standard' ) {
            $data['lifecycleStatus'] = $data['lifecycleStatus'] ?? 'active';
        }

        $invoice = CIG_Invoice::create( $data );
        if ( is_wp_error( $invoice ) ) {
            return $invoice;
        }

        return new WP_REST_Response( $invoice, 201 );
    }

    public function update_item( $request ) {
        $id   = (int) $request->get_param( 'id' );
        $data = $request->get_json_params();
        $user = $this->get_user( $request );

        $existing = CIG_Invoice::find( $id );
        if ( ! $existing ) {
            return new WP_Error( 'cig_not_found', 'Invoice not found.', [ 'status' => 404 ] );
        }

        // Sold invoices: admin-only edit
        $lifecycle = CIG_Invoice::get_lifecycle( $existing );
        if ( $lifecycle['label'] === 'Sold' && ! CIG_RBAC::user_is_admin( $user ) ) {
            return new WP_Error(
                'cig_forbidden',
                'Only admins can edit sold invoices.',
                [ 'status' => 403 ]
            );
        }

        // Sales role: can only edit own invoices
        if ( CIG_RBAC::user_is_consultant( $user ) && $existing['authorId'] !== $user['id'] ) {
            return new WP_Error( 'cig_forbidden', 'You can only edit your own invoices.', [ 'status' => 403 ] );
        }

        $invoice = CIG_Invoice::update( $id, $data );
        if ( is_wp_error( $invoice ) ) {
            return $invoice;
        }

        return rest_ensure_response( $invoice );
    }

    public function delete_item( $request ) {
        $id = (int) $request->get_param( 'id' );

        $existing = CIG_Invoice::find( $id );
        if ( ! $existing ) {
            return new WP_Error( 'cig_not_found', 'Invoice not found.', [ 'status' => 404 ] );
        }

        // Don't allow deleting sold invoices
        $lifecycle = CIG_Invoice::get_lifecycle( $existing );
        if ( $lifecycle['label'] === 'Sold' ) {
            return new WP_Error(
                'cig_forbidden',
                'Cannot delete sold invoices.',
                [ 'status' => 403 ]
            );
        }

        CIG_Invoice::delete( $id );
        return new WP_REST_Response( null, 204 );
    }

    public function next_number( $request ) {
        return rest_ensure_response( [
            'number' => CIG_Invoice::generate_number(),
        ] );
    }
}
