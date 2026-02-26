<?php
/**
 * KPI controller — aggregated metrics endpoints for Dashboard and Statistics.
 */
class CIG_KPI_Controller extends CIG_REST_Controller {

    public function register_routes() {
        register_rest_route( $this->namespace, '/kpi/dashboard', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'dashboard' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $this->namespace, '/kpi/other-accumulated', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'other_accumulated' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );

        register_rest_route( $this->namespace, '/kpi/statistics', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'statistics' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );
    }

    /**
     * GET /kpi/dashboard
     * Returns aggregated KPI data for the dashboard page.
     * Supports optional date_from / date_to query params.
     */
    public function dashboard( $request ) {
        $args = [
            'date_from' => sanitize_text_field( $request->get_param( 'date_from' ) ?? '' ),
            'date_to'   => sanitize_text_field( $request->get_param( 'date_to' ) ?? '' ),
            'author_id' => $request->get_param( 'author_id' ) ? (int) $request->get_param( 'author_id' ) : null,
        ];

        $data = CIG_Invoice::get_dashboard_kpi( $args );

        return rest_ensure_response( $data );
    }

    /**
     * GET /kpi/statistics
     * Returns all server-computed aggregations needed by StatisticsPage.
     * Supports optional date_from / date_to query params.
     */
    public function statistics( $request ) {
        $args = [
            'date_from' => sanitize_text_field( $request->get_param( 'date_from' ) ?? '' ),
            'date_to'   => sanitize_text_field( $request->get_param( 'date_to' ) ?? '' ),
        ];

        $data = CIG_Invoice::get_statistics_kpi( $args );

        return rest_ensure_response( $data );
    }

    /**
     * GET /kpi/other-accumulated
     * Returns all-time sum of "other" payment method amounts for standard invoices.
     * Used by StatisticsPage "Other Balance" tab for the all-time balance calculation.
     */
    public function other_accumulated( $request ) {
        global $wpdb;

        $invoices_t = $wpdb->prefix . 'cig_invoices';
        $payments_t = $wpdb->prefix . 'cig_payments';

        $total = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(p.amount), 0)
             FROM {$payments_t} p
             JOIN {$invoices_t} i ON i.id = p.invoice_id
             WHERE i.status = 'standard' AND p.method = 'other'"
        );

        return rest_ensure_response( [ 'total' => $total ] );
    }
}
