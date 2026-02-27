<?php
/**
 * Migration REST API controller.
 * All endpoints require admin/manager role.
 */
class CIG_Migration_Controller extends CIG_REST_Controller {

    public function register_routes() {
        register_rest_route( $this->namespace, '/migration/analyze', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'analyze' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/migration/run', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'run_migration' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/migration/fix-columns', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'fix_columns' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/migration/fix-reserved', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'fix_reserved' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/migration/relink-customers', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'relink_customers' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/migration/verify', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'verify' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );

        register_rest_route( $this->namespace, '/migration/rollback', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'rollback' ],
            'permission_callback' => [ 'CIG_RBAC', 'is_admin' ],
        ] );
    }

    // ── POST /migration/analyze ──────────────────────────────────────────────

    public function analyze( WP_REST_Request $request ) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        // Legacy CPT counts
        $legacy_invoices  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'cig_invoice' AND post_status = 'publish'" );
        $legacy_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'cig_customer' AND post_status = 'publish'" );
        $legacy_deposits  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'cig_deposit' AND post_status = 'publish'" );

        // Check whether postmeta items exist
        $has_postmeta_items = (bool) $wpdb->get_var(
            "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_cig_items' LIMIT 1"
        );

        // Current table counts
        $cur_invoices  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}invoices" );
        $cur_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}customers" );
        $cur_items     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}invoice_items" );
        $cur_payments  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}payments" );
        $cur_deposits  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}deposits" );

        // Detect issues
        $issues = [];

        $reserved_stuck = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}invoices WHERE lifecycle_status = 'active'"
        );
        if ( $reserved_stuck > 0 ) {
            $issues[] = [
                'key'    => 'reserved_as_active',
                'count'  => $reserved_stuck,
                'label'  => 'Reserved invoices with wrong lifecycle (active instead of reserved)',
                'action' => 'fix-reserved',
            ];
        }

        $null_customers = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}invoices WHERE customer_id IS NULL"
        );
        if ( $null_customers > 0 ) {
            $issues[] = [
                'key'    => 'null_customer_id',
                'count'  => $null_customers,
                'label'  => 'Invoices with no customer linked',
                'action' => 'relink-customers',
            ];
        }

        // Empty item names — only if old column exists
        $has_product_name_col = (bool) $wpdb->get_var(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$prefix}invoice_items'
             AND COLUMN_NAME = 'product_name'"
        );
        if ( $has_product_name_col ) {
            $empty_names = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}invoice_items
                 WHERE (name = '' OR name IS NULL) AND product_name IS NOT NULL AND product_name != ''"
            );
            if ( $empty_names > 0 ) {
                $issues[] = [
                    'key'    => 'empty_item_names',
                    'count'  => $empty_names,
                    'label'  => 'Invoice items with empty name (column rename needed)',
                    'action' => 'fix-columns',
                ];
            }
        }

        // Payments with missing date — only if old date column exists
        $has_date_col = (bool) $wpdb->get_var(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$prefix}payments'
             AND COLUMN_NAME = 'date'"
        );
        if ( $has_date_col ) {
            $null_dates = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}payments
                 WHERE (payment_date IS NULL OR payment_date = '0000-00-00') AND `date` IS NOT NULL"
            );
            if ( $null_dates > 0 ) {
                $issues[] = [
                    'key'    => 'null_payment_dates',
                    'count'  => $null_dates,
                    'label'  => 'Payments with missing date (column rename needed)',
                    'action' => 'fix-columns',
                ];
            }
        }

        $empty_buyer_names = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}invoices WHERE buyer_name = '' OR buyer_name IS NULL"
        );
        if ( $empty_buyer_names > 0 ) {
            $issues[] = [
                'key'    => 'empty_buyer_names',
                'count'  => $empty_buyer_names,
                'label'  => 'Invoices with empty buyer name',
                'action' => 'fix-columns',
            ];
        }

        return new WP_REST_Response( [
            'legacy'  => [
                'invoices_cpt'       => $legacy_invoices,
                'customers_cpt'      => $legacy_customers,
                'deposits_cpt'       => $legacy_deposits,
                'has_postmeta_items' => $has_postmeta_items,
            ],
            'current' => [
                'invoices'      => $cur_invoices,
                'customers'     => $cur_customers,
                'invoice_items' => $cur_items,
                'payments'      => $cur_payments,
                'deposits'      => $cur_deposits,
            ],
            'issues'  => $issues,
        ], 200 );
    }

    // ── POST /migration/run ──────────────────────────────────────────────────

    public function run_migration( WP_REST_Request $request ) {
        $start    = microtime( true );
        $steps    = $request->get_param( 'steps' )   ?: [];
        $dry_run  = (bool) $request->get_param( 'dry_run' );

        $all_steps = [
            'M1_users'          => 'migrate_users',
            'M2_products'       => 'migrate_products',
            'M3_customers'      => 'migrate_customers',
            'M4_invoices'       => 'migrate_invoices',
            'M5_invoice_items'  => 'migrate_invoice_items',
            'M6_payments'       => 'migrate_payments',
            'M7_deposits'       => 'migrate_deposits',
            'M8_stock_requests' => 'migrate_stock_requests',
        ];

        // Filter to requested steps; if none specified, run all
        if ( ! empty( $steps ) ) {
            $all_steps = array_intersect_key( $all_steps, array_flip( $steps ) );
        }

        $results  = [];
        $logs     = [];
        $log_fn   = function( $msg ) use ( &$logs ) { $logs[] = $msg; };

        $migrator = new CIG_Migrator( 50, $dry_run, $log_fn );

        foreach ( $all_steps as $step_key => $method ) {
            $step_start = microtime( true );
            $errors     = [];

            try {
                // Use reflection to call private method
                $ref = new ReflectionMethod( $migrator, $method );
                $ref->setAccessible( true );
                $count = $ref->invoke( $migrator );

                $results[] = [
                    'step'     => $step_key,
                    'status'   => 'done',
                    'inserted' => (int) $count,
                    'errors'   => [],
                    'duration' => round( ( microtime( true ) - $step_start ) * 1000 ),
                ];
            } catch ( \Exception $e ) {
                $results[] = [
                    'step'     => $step_key,
                    'status'   => 'error',
                    'inserted' => 0,
                    'errors'   => [ $e->getMessage() ],
                    'duration' => round( ( microtime( true ) - $step_start ) * 1000 ),
                ];
            }
        }

        // Recalculate totals after full run (not dry run)
        if ( ! $dry_run && ! empty( $results ) ) {
            $ref = new ReflectionMethod( $migrator, 'recalculate_all_totals' );
            $ref->setAccessible( true );
            $ref->invoke( $migrator );
        }

        return new WP_REST_Response( [
            'steps'       => $results,
            'dry_run'     => $dry_run,
            'duration_ms' => round( ( microtime( true ) - $start ) * 1000 ),
            'logs'        => $logs,
        ], 200 );
    }

    // ── POST /migration/fix-columns ──────────────────────────────────────────

    public function fix_columns( WP_REST_Request $request ) {
        $migrator = new CIG_Migrator();
        $migrator->fix_column_renames();

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    // ── POST /migration/fix-reserved ────────────────────────────────────────

    public function fix_reserved( WP_REST_Request $request ) {
        $migrator = new CIG_Migrator();
        $updated  = $migrator->fix_reserved_lifecycle();

        return new WP_REST_Response( [ 'success' => true, 'updated' => $updated ], 200 );
    }

    // ── POST /migration/relink-customers ────────────────────────────────────

    public function relink_customers( WP_REST_Request $request ) {
        $migrator = new CIG_Migrator();
        $linked   = $migrator->relink_customers();

        return new WP_REST_Response( [ 'success' => true, 'linked' => $linked ], 200 );
    }

    // ── POST /migration/verify ───────────────────────────────────────────────

    public function verify( WP_REST_Request $request ) {
        $validator = new CIG_Data_Validator();
        $results   = $validator->validate();

        $passed = count( array_filter( $results, fn( $r ) => $r['pass'] ) );

        return new WP_REST_Response( [
            'results' => $results,
            'passed'  => $passed,
            'total'   => count( $results ),
        ], 200 );
    }

    // ── DELETE /migration/rollback ───────────────────────────────────────────

    public function rollback( WP_REST_Request $request ) {
        CIG_Migrator::rollback();

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }
}
