<?php
/**
 * Invoice model — CRUD + business logic including getInvoiceLifecycle() port.
 */
class CIG_Invoice {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cig_invoices';
    }

    private static function items_table() {
        global $wpdb;
        return $wpdb->prefix . 'cig_invoice_items';
    }

    private static function payments_table() {
        global $wpdb;
        return $wpdb->prefix . 'cig_payments';
    }

    /**
     * Find a single invoice by ID, with embedded items and payments.
     */
    public static function find( $id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $row ) return null;
        return self::hydrate( $row );
    }

    /**
     * List invoices with filtering, sorting, pagination.
     */
    public static function list( $args = [] ) {
        global $wpdb;

        $defaults = [
            'search'         => '',
            'status'         => '',
            'lifecycle'      => '',
            'outstanding'    => false,
            'payment_method' => '',
            'date_from'      => '',
            'date_to'        => '',
            'completion'     => '',
            'author_id'      => null,
            'customer_id'    => null,
            'lean'           => false,
            'sort'           => 'created_at',
            'order'          => 'DESC',
            'page'           => 1,
            'per_page'       => 25,
        ];
        $args = wp_parse_args( $args, $defaults );
        $lean = ! empty( $args['lean'] );

        $table = self::table();
        $items_table = self::items_table();
        $payments_table = self::payments_table();
        $where = [ '1=1' ];
        $params = [];

        // Status filter (standard/fictive)
        if ( ! empty( $args['status'] ) ) {
            $where[] = 'i.status = %s';
            $params[] = $args['status'];
        }

        // Lifecycle filter — replicates getInvoiceLifecycle() logic
        if ( ! empty( $args['lifecycle'] ) ) {
            switch ( $args['lifecycle'] ) {
                case 'draft':
                    $where[] = "(i.status = 'fictive' OR i.lifecycle_status = 'draft')";
                    break;
                case 'sold':
                    $where[] = "(i.lifecycle_status IN ('sold','completed') OR (
                        i.lifecycle_status = 'active' AND NOT EXISTS (
                            SELECT 1 FROM {$items_table} it
                            WHERE it.invoice_id = i.id AND it.item_status != 'sold'
                        ) AND EXISTS (
                            SELECT 1 FROM {$items_table} it2 WHERE it2.invoice_id = i.id
                        )
                    ))";
                    break;
                case 'reserved':
                    $where[] = "i.lifecycle_status = 'active' AND EXISTS (
                        SELECT 1 FROM {$items_table} it
                        WHERE it.invoice_id = i.id AND it.item_status = 'reserved'
                    )";
                    break;
                case 'canceled':
                    $where[] = "i.lifecycle_status = 'active' AND EXISTS (
                        SELECT 1 FROM {$items_table} it
                        WHERE it.invoice_id = i.id AND it.item_status = 'canceled'
                    )";
                    break;
            }
        }

        // Outstanding filter: standard, not sold, remaining > 0
        if ( $args['outstanding'] ) {
            $where[] = "i.status = 'standard'";
            $where[] = "i.lifecycle_status NOT IN ('sold','completed')";
            $where[] = '(i.total_amount - i.paid_amount) > 0';
        }

        // Payment method filter
        if ( ! empty( $args['payment_method'] ) ) {
            $where[] = "EXISTS (
                SELECT 1 FROM {$payments_table} p
                WHERE p.invoice_id = i.id AND p.method = %s
            )";
            $params[] = $args['payment_method'];
        }

        // Date range
        if ( ! empty( $args['date_from'] ) ) {
            $where[] = 'i.created_at >= %s';
            $params[] = $args['date_from'];
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[] = 'i.created_at <= %s';
            $params[] = $args['date_to'];
        }

        // Author filter (sales role sees own invoices only)
        if ( $args['author_id'] ) {
            $where[] = 'i.author_id = %d';
            $params[] = $args['author_id'];
        }

        // Customer filter
        if ( $args['customer_id'] ) {
            $where[] = 'i.customer_id = %d';
            $params[] = $args['customer_id'];
        }

        // Completion filter (accountant flags)
        if ( $args['completion'] === 'completed' ) {
            $where[] = '(i.is_rs_uploaded = 1 AND i.is_credit_checked = 1 AND i.is_receipt_checked = 1 AND i.is_corrected = 1)';
        } elseif ( $args['completion'] === 'incomplete' ) {
            $where[] = '(i.is_rs_uploaded = 0 OR i.is_credit_checked = 0 OR i.is_receipt_checked = 0 OR i.is_corrected = 0)';
        }

        // Search (invoice number, buyer name, buyer tax ID)
        if ( ! empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(i.invoice_number LIKE %s OR i.buyer_name LIKE %s OR i.buyer_tax_id LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $where_sql = implode( ' AND ', $where );

        // Validate sort column
        $allowed_sorts = [
            'created_at', 'invoice_number', 'total_amount', 'paid_amount',
            'buyer_name', 'sold_date', 'updated_datetime', 'id',
        ];
        $sort = in_array( $args['sort'], $allowed_sorts, true ) ? $args['sort'] : 'created_at';
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        // Count
        $count_sql = "SELECT COUNT(*) FROM {$table} i WHERE {$where_sql}";
        if ( ! empty( $params ) ) {
            $count_sql = $wpdb->prepare( $count_sql, ...$params );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Fetch
        $offset = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
        $limit  = (int) $args['per_page'];

        $query = "SELECT i.* FROM {$table} i WHERE {$where_sql} ORDER BY i.{$sort} {$order} LIMIT %d OFFSET %d";
        $query_params = array_merge( $params, [ $limit, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $query, ...$query_params ), ARRAY_A );

        // Batch-fetch items and payments for all invoices in 2 queries (fixes N+1)
        // Skipped in lean mode — items and payments returned as empty arrays.
        $items_by_id    = [];
        $payments_by_id = [];

        if ( ! $lean && ! empty( $rows ) ) {
            $invoice_ids    = array_column( $rows, 'id' );
            $ids_sql        = implode( ',', array_map( 'intval', $invoice_ids ) );

            $all_items = $wpdb->get_results(
                "SELECT * FROM {$items_table} WHERE invoice_id IN ({$ids_sql}) ORDER BY invoice_id, sort_order",
                ARRAY_A
            );
            foreach ( $all_items as $item ) {
                $items_by_id[ (int) $item['invoice_id'] ][] = $item;
            }

            $all_payments = $wpdb->get_results(
                "SELECT * FROM {$payments_table} WHERE invoice_id IN ({$ids_sql}) ORDER BY invoice_id, payment_date",
                ARRAY_A
            );
            foreach ( $all_payments as $payment ) {
                $payments_by_id[ (int) $payment['invoice_id'] ][] = $payment;
            }
        }

        // Hydrate all (uses pre-fetched data — 0 additional queries)
        $invoices = [];
        foreach ( $rows as $row ) {
            $invoices[] = self::hydrate(
                $row,
                $lean ? [] : ( $items_by_id[ (int) $row['id'] ] ?? [] ),
                $lean ? [] : ( $payments_by_id[ (int) $row['id'] ] ?? [] )
            );
        }

        return [
            'data'     => $invoices,
            'total'    => $total,
            'page'     => (int) $args['page'],
            'per_page' => $limit,
            'pages'    => $limit > 0 ? ceil( $total / $limit ) : 1,
        ];
    }

    /**
     * Create a new invoice.
     */
    public static function create( $data ) {
        global $wpdb;

        // Auto-generate invoice number if not provided
        if ( empty( $data['invoice_number'] ) ) {
            $data['invoice_number'] = self::generate_number();
        }

        $invoice_data = self::extract_invoice_fields( $data );
        $invoice_data['created_datetime'] = current_time( 'mysql' );

        $wpdb->insert( self::table(), $invoice_data );
        $invoice_id = $wpdb->insert_id;

        if ( ! $invoice_id ) {
            return new WP_Error( 'cig_create_failed', 'Failed to create invoice.', [ 'status' => 500 ] );
        }

        // Insert items
        if ( ! empty( $data['items'] ) ) {
            self::save_items( $invoice_id, $data['items'] );
        }

        // Insert payments
        if ( ! empty( $data['payments'] ) ) {
            self::save_payments( $invoice_id, $data['payments'] );
        }

        // Recalculate totals
        self::recalculate_totals( $invoice_id );

        self::clear_kpi_cache();
        return self::find( $invoice_id );
    }

    /**
     * Update an existing invoice.
     */
    public static function update( $id, $data ) {
        global $wpdb;

        $existing = self::find( $id );
        if ( ! $existing ) {
            return new WP_Error( 'cig_not_found', 'Invoice not found.', [ 'status' => 404 ] );
        }

        $invoice_data = self::extract_invoice_fields( $data );
        $wpdb->update( self::table(), $invoice_data, [ 'id' => $id ] );

        // Replace items if provided
        if ( isset( $data['items'] ) ) {
            $wpdb->delete( self::items_table(), [ 'invoice_id' => $id ] );
            self::save_items( $id, $data['items'] );
        }

        // Replace payments if provided
        if ( isset( $data['payments'] ) ) {
            $wpdb->delete( self::payments_table(), [ 'invoice_id' => $id ] );
            self::save_payments( $id, $data['payments'] );
        }

        // Recalculate totals
        self::recalculate_totals( $id );

        self::clear_kpi_cache();
        return self::find( $id );
    }

    /**
     * Delete an invoice (cascades to items + payments via FK).
     */
    public static function delete( $id ) {
        global $wpdb;
        $result = $wpdb->delete( self::table(), [ 'id' => $id ] );
        self::clear_kpi_cache();
        return $result;
    }

    /**
     * Generate next invoice number.
     */
    public static function generate_number() {
        global $wpdb;
        $company  = CIG_Company::get();
        $prefix   = ( $company && ! empty( $company['invoicePrefix'] ) ) ? $company['invoicePrefix'] : 'N';
        $starting = ( $company && ! empty( $company['startingInvoiceNumber'] ) ) ? (int) $company['startingInvoiceNumber'] : 1001;

        $last_raw = $wpdb->get_var(
            "SELECT MAX(CAST(REPLACE(invoice_number, '{$prefix}', '') AS UNSIGNED))
             FROM " . self::table() . "
             WHERE invoice_number LIKE '{$prefix}%'"
        );

        // Guard against NULL, scientific notation strings, or overflowed values
        // ctype_digit rejects 'E+18', negatives, decimals — only pure digit strings pass
        if ( $last_raw !== null && ctype_digit( (string) $last_raw ) && strlen( $last_raw ) <= 12 ) {
            $last_int = (int) $last_raw;
        } else {
            $last_int = 0;
        }

        $next = max( $starting, $last_int + 1 );
        return $prefix . $next;
    }

    /**
     * PHP port of getInvoiceLifecycle() from src/data/index.js.
     */
    public static function get_lifecycle( $invoice ) {
        $lifecycle_labels = [
            'draft'    => [ 'key' => 'draft',    'label' => 'Draft',    'color' => 'neutral' ],
            'sold'     => [ 'key' => 'sold',     'label' => 'Sold',     'color' => 'success' ],
            'reserved' => [ 'key' => 'reserved', 'label' => 'Reserved', 'color' => 'warning' ],
            'canceled' => [ 'key' => 'canceled', 'label' => 'Canceled', 'color' => 'danger' ],
        ];

        if ( empty( $invoice ) ) return $lifecycle_labels['draft'];

        // Fictive invoices always show Draft
        if ( ( $invoice['status'] ?? '' ) === 'fictive' ) return $lifecycle_labels['draft'];

        $ls = $invoice['lifecycleStatus'] ?? $invoice['lifecycle_status'] ?? 'draft';

        if ( $ls === 'draft' ) return $lifecycle_labels['draft'];
        if ( $ls === 'completed' || $ls === 'sold' ) return $lifecycle_labels['sold'];

        // For 'active' or any other: derive from items
        $items = $invoice['items'] ?? [];
        if ( count( $items ) > 0 ) {
            $all_sold = true;
            $has_reserved = false;
            $has_canceled = false;
            foreach ( $items as $item ) {
                $s = $item['itemStatus'] ?? $item['item_status'] ?? 'none';
                if ( $s !== 'sold' ) $all_sold = false;
                if ( $s === 'reserved' ) $has_reserved = true;
                if ( $s === 'canceled' ) $has_canceled = true;
            }
            if ( $all_sold ) return $lifecycle_labels['sold'];
            if ( $has_reserved ) return $lifecycle_labels['reserved'];
            if ( $has_canceled ) return $lifecycle_labels['canceled'];
        }

        return $lifecycle_labels['reserved']; // Default fallback for active
    }

    /**
     * Aggregate KPI data for the dashboard.
     * Returns totals, monthly trend, expiring reservations, and top products.
     */
    public static function get_dashboard_kpi( $args = [] ) {
        $cache_key = 'cig_kpi_dash_' . md5( wp_json_encode( $args ) );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        global $wpdb;

        $table    = self::table();
        $items_t  = self::items_table();
        $pays_t   = self::payments_table();
        $cust_t   = $wpdb->prefix . 'cig_customers';

        $from = sanitize_text_field( $args['date_from'] ?? '' );
        $to   = sanitize_text_field( $args['date_to'] ?? '' );

        // Date conditions on invoice.created_at
        $inv_date = '';
        if ( $from ) $inv_date .= $wpdb->prepare( ' AND i.created_at >= %s', $from );
        if ( $to )   $inv_date .= $wpdb->prepare( ' AND i.created_at <= %s', $to );

        // Date conditions on payment.payment_date
        $pay_date = '';
        if ( $from ) $pay_date .= $wpdb->prepare( ' AND p.payment_date >= %s', $from );
        if ( $to )   $pay_date .= $wpdb->prepare( ' AND p.payment_date <= %s', $to );

        // Optional author_id filter (for consultant dashboard)
        $author_id        = isset( $args['author_id'] ) ? (int) $args['author_id'] : null;
        $author_cond      = $author_id ? $wpdb->prepare( ' AND i.author_id = %d', $author_id ) : '';
        $author_cond_bare = $author_id ? $wpdb->prepare( ' AND author_id = %d', $author_id ) : '';

        // ── 1. Core invoice KPIs (standard invoices, date-filtered) ──────────
        $kpi_row = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_invoices,
                COALESCE(SUM(i.total_amount), 0) as gross_revenue,
                COALESCE(SUM(
                    CASE WHEN i.lifecycle_status NOT IN ('sold','completed')
                    THEN GREATEST(0, i.total_amount - i.paid_amount) ELSE 0 END
                ), 0) as outstanding_balance
             FROM {$table} i
             WHERE i.status = 'standard' {$inv_date}{$author_cond}",
            ARRAY_A
        );

        // ── 2. Pending reservations ──────────────────────────────────────────
        $pending_sql = "SELECT COUNT(DISTINCT i.id) FROM {$table} i
             WHERE i.status = 'standard' AND i.lifecycle_status = 'active'
             AND EXISTS (SELECT 1 FROM {$items_t} it WHERE it.invoice_id = i.id AND it.item_status = 'reserved')
             {$inv_date}{$author_cond}";
        $pending = (int) $wpdb->get_var( $pending_sql );

        // ── 3. Payment method totals (filtered by payment_date) ──────────────
        $method_rows = $wpdb->get_results(
            "SELECT p.method, SUM(p.amount) as total
             FROM {$pays_t} p
             JOIN {$table} i ON i.id = p.invoice_id
             WHERE i.status = 'standard' {$pay_date}{$author_cond}
             GROUP BY p.method",
            ARRAY_A
        );
        $method_totals = [ 'cash' => 0, 'company_transfer' => 0, 'credit' => 0, 'consignment' => 0, 'other' => 0 ];
        $total_paid = 0;
        foreach ( $method_rows as $row ) {
            $key = $row['method'];
            if ( array_key_exists( $key, $method_totals ) ) {
                $method_totals[ $key ] = (float) $row['total'];
            }
            if ( $key !== 'consignment' ) {
                $total_paid += (float) $row['total'];
            }
        }

        // ── 4. Monthly trend (last 6 months) — 2 queries replacing 12 ──────────
        $six_month_start = gmdate( 'Y-m-01', strtotime( '-5 months' ) );
        $today           = gmdate( 'Y-m-t' );

        // Invoice metrics by month
        $inv_by_month_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                DATE_FORMAT(created_at, '%%Y-%%m') AS ym,
                COUNT(*) AS count,
                COALESCE(SUM(total_amount), 0) AS revenue,
                COALESCE(SUM(CASE WHEN lifecycle_status IN ('sold','completed') THEN 1 ELSE 0 END), 0) AS completed,
                COALESCE(SUM(GREATEST(0, total_amount - paid_amount)), 0) AS outstanding
             FROM {$table}
             WHERE status = 'standard' AND created_at >= %s AND created_at <= %s{$author_cond_bare}
             GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')",
            $six_month_start, $today
        ), ARRAY_A );
        $inv_map = [];
        foreach ( $inv_by_month_rows as $r ) {
            $inv_map[ $r['ym'] ] = $r;
        }

        // Payment totals by month
        $pay_by_month_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                DATE_FORMAT(p.payment_date, '%%Y-%%m') AS ym,
                COALESCE(SUM(p.amount), 0) AS paid
             FROM {$pays_t} p
             JOIN {$table} i ON i.id = p.invoice_id
             WHERE i.status = 'standard' AND p.method != 'consignment'
               AND p.payment_date >= %s AND p.payment_date <= %s{$author_cond}
             GROUP BY DATE_FORMAT(p.payment_date, '%%Y-%%m')",
            $six_month_start, $today
        ), ARRAY_A );
        $pay_map = [];
        foreach ( $pay_by_month_rows as $r ) {
            $pay_map[ $r['ym'] ] = (float) $r['paid'];
        }

        // Build 6-month ordered trend array
        $monthly_trend = [];
        for ( $i = 5; $i >= 0; $i-- ) {
            $month_start = gmdate( 'Y-m-01', strtotime( "-{$i} months" ) );
            $ym          = gmdate( 'Y-m', strtotime( $month_start ) );
            $inv_row     = $inv_map[ $ym ] ?? null;
            $count       = $inv_row ? (int) $inv_row['count'] : 0;
            $revenue     = $inv_row ? (float) $inv_row['revenue'] : 0.0;

            $monthly_trend[] = [
                'month'       => gmdate( 'M Y', strtotime( $month_start ) ),
                'revenue'     => $revenue,
                'paid'        => $pay_map[ $ym ] ?? 0.0,
                'count'       => $count,
                'outstanding' => $inv_row ? (float) $inv_row['outstanding'] : 0.0,
                'completed'   => $inv_row ? (int) $inv_row['completed'] : 0,
                'avgOrder'    => $count > 0 ? round( $revenue / $count, 2 ) : 0,
            ];
        }

        // ── 5. Expiring reservations ─────────────────────────────────────────
        // product_id in invoice_items is a WooCommerce post ID when WC is active,
        // so we join BOTH wp_posts (WC name) + wp_postmeta (WC SKU) AND wp_cig_products
        // as a fallback, then COALESCE across all sources.
        $prod_t = $wpdb->prefix . 'cig_products';
        $posts  = $wpdb->posts;
        $pmeta  = $wpdb->postmeta;
        $res_rows = $wpdb->get_results(
            "SELECT
                i.id as invoice_id, i.invoice_number, i.created_at,
                it.product_id, it.reservation_days,
                COALESCE(
                    NULLIF(it.name,         ''),
                    NULLIF(wc.post_title,   ''),
                    NULLIF(cp.name,         ''),
                    NULLIF(cp.name_ka,      ''),
                    ''
                ) as product_name,
                COALESCE(
                    NULLIF(it.sku,          ''),
                    NULLIF(pm_sku.meta_value,''),
                    NULLIF(cp.sku,          ''),
                    ''
                ) as sku,
                c.name as customer_name
             FROM {$table} i
             JOIN {$items_t} it ON it.invoice_id = i.id AND it.item_status = 'reserved' AND it.reservation_days > 0
             LEFT JOIN {$cust_t}  c      ON c.id        = i.customer_id
             LEFT JOIN {$posts}   wc     ON wc.ID       = it.product_id
                                        AND wc.post_type IN ('product','product_variation')
             LEFT JOIN {$pmeta}   pm_sku ON pm_sku.post_id   = it.product_id
                                        AND pm_sku.meta_key   = '_sku'
             LEFT JOIN {$prod_t}  cp     ON cp.id        = it.product_id
             WHERE i.lifecycle_status = 'active'{$author_cond}
             ORDER BY i.created_at ASC
             LIMIT 50",
            ARRAY_A
        );
        $expiring = [];
        foreach ( $res_rows as $r ) {
            $days_elapsed   = (int) floor( ( time() - strtotime( $r['created_at'] ) ) / DAY_IN_SECONDS );
            $days_remaining = max( 0, (int) $r['reservation_days'] - $days_elapsed );
            $expiring[] = [
                'invoiceId'     => (int) $r['invoice_id'],
                'invoiceNumber' => $r['invoice_number'],
                'productName'   => $r['product_name'],
                'sku'           => $r['sku'],
                'customerName'  => $r['customer_name'] ?? '',
                'daysRemaining' => $days_remaining,
                'totalDays'     => (int) $r['reservation_days'],
            ];
        }
        usort( $expiring, fn( $a, $b ) => $a['daysRemaining'] - $b['daysRemaining'] );
        $expiring = array_slice( $expiring, 0, 20 );

        // ── 6. Top products (last 6 months) ──────────────────────────────────
        $six_ago = gmdate( 'Y-m-01', strtotime( '-5 months' ) );
        $top_products = $wpdb->get_results( $wpdb->prepare(
            "SELECT it.product_id, it.name, SUM(it.qty * it.price) as revenue
             FROM {$items_t} it
             JOIN {$table} i ON i.id = it.invoice_id
             WHERE i.status = 'standard' AND i.created_at >= %s AND it.item_status != 'canceled'{$author_cond}
             GROUP BY it.product_id, it.name
             ORDER BY revenue DESC
             LIMIT 5",
            $six_ago
        ), ARRAY_A );

        $data = [
            'totalInvoices'        => (int) $kpi_row['total_invoices'],
            'grossRevenue'         => (float) $kpi_row['gross_revenue'],
            'totalPaid'            => $total_paid,
            'outstandingBalance'   => (float) $kpi_row['outstanding_balance'],
            'pendingReservations'  => $pending,
            'methodTotals'         => $method_totals,
            'monthlyTrend'         => $monthly_trend,
            'expiringReservations' => $expiring,
            'topProducts'          => array_map( fn( $p ) => [
                'productId' => $p['product_id'] ? (int) $p['product_id'] : null,
                'name'      => $p['name'],
                'revenue'   => (float) $p['revenue'],
            ], $top_products ),
        ];
        set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
        return $data;
    }

    /**
     * Aggregate KPI data for StatisticsPage.
     * Replaces client-side per_page=9999 aggregations with SQL GROUP BY queries.
     *
     * @param array $args Optional date_from / date_to.
     * @return array overview, products, customers
     */
    public static function get_statistics_kpi( $args = [] ) {
        $cache_key = 'cig_kpi_stat_' . md5( wp_json_encode( $args ) );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        global $wpdb;

        $table   = self::table();
        $items_t = self::items_table();
        $pays_t  = self::payments_table();

        $from = sanitize_text_field( $args['date_from'] ?? '' );
        $to   = sanitize_text_field( $args['date_to'] ?? '' );

        // Date conditions on invoice.created_at
        $inv_date = '';
        if ( $from ) $inv_date .= $wpdb->prepare( ' AND i.created_at >= %s', $from );
        if ( $to )   $inv_date .= $wpdb->prepare( ' AND i.created_at <= %s', $to );

        // Date conditions on payment.payment_date
        $pay_date = '';
        if ( $from ) $pay_date .= $wpdb->prepare( ' AND p.payment_date >= %s', $from );
        if ( $to )   $pay_date .= $wpdb->prepare( ' AND p.payment_date <= %s', $to );

        // ── 1. Overview: standard invoices, date-filtered ─────────────────────
        $ov_row = $wpdb->get_row(
            "SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(paid_amount), 0) AS total_revenue,
                COALESCE(SUM(GREATEST(0, total_amount - paid_amount)), 0) AS outstanding_balance
             FROM {$table} i
             WHERE i.status = 'standard' {$inv_date}",
            ARRAY_A
        );

        // ── 2. Pending reservations ───────────────────────────────────────────
        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT i.id) FROM {$table} i
             WHERE i.status = 'standard' AND i.lifecycle_status = 'active'
               AND EXISTS (SELECT 1 FROM {$items_t} it WHERE it.invoice_id = i.id AND it.item_status = 'reserved')
               {$inv_date}"
        );

        // ── 3. Payment method totals (filtered by payment_date) ───────────────
        $method_rows = $wpdb->get_results(
            "SELECT p.method, SUM(p.amount) AS total
             FROM {$pays_t} p
             JOIN {$table} i ON i.id = p.invoice_id
             WHERE i.status = 'standard' {$pay_date}
             GROUP BY p.method",
            ARRAY_A
        );
        $method_totals = [ 'cash' => 0, 'company_transfer' => 0, 'credit' => 0, 'consignment' => 0, 'other' => 0 ];
        foreach ( $method_rows as $r ) {
            if ( array_key_exists( $r['method'], $method_totals ) ) {
                $method_totals[ $r['method'] ] = (float) $r['total'];
            }
        }

        // ── 4. Top users (standard, date-filtered) ────────────────────────────
        $user_rows = $wpdb->get_results(
            "SELECT i.author_id, COUNT(*) AS invoice_count, COALESCE(SUM(i.paid_amount), 0) AS revenue
             FROM {$table} i
             WHERE i.status = 'standard' AND i.author_id IS NOT NULL {$inv_date}
             GROUP BY i.author_id
             ORDER BY revenue DESC",
            ARRAY_A
        );
        $top_users = array_map( fn( $r ) => [
            'authorId'     => (int) $r['author_id'],
            'invoiceCount' => (int) $r['invoice_count'],
            'revenue'      => (float) $r['revenue'],
        ], $user_rows );

        // ── 5. Lifecycle distribution (date-filtered) ─────────────────────────
        $lifecycle_rows = $wpdb->get_results(
            "SELECT
                SUM(CASE WHEN lifecycle_status IN ('sold','completed') THEN 1 ELSE 0 END) AS sold,
                SUM(CASE WHEN status = 'fictive' OR lifecycle_status = 'draft' THEN 1 ELSE 0 END) AS draft,
                SUM(CASE WHEN status = 'standard' AND lifecycle_status = 'active' AND
                    EXISTS (SELECT 1 FROM {$items_t} it WHERE it.invoice_id = i.id AND it.item_status = 'reserved')
                    THEN 1 ELSE 0 END) AS reserved
             FROM {$table} i
             WHERE 1=1 {$inv_date}",
            ARRAY_A
        );
        $lifecycle_dist = [
            'sold'     => (int) ( $lifecycle_rows[0]['sold'] ?? 0 ),
            'draft'    => (int) ( $lifecycle_rows[0]['draft'] ?? 0 ),
            'reserved' => (int) ( $lifecycle_rows[0]['reserved'] ?? 0 ),
        ];

        // ── 6. Fictive stats (date-filtered) ─────────────────────────────────
        $fictive_row = $wpdb->get_row(
            "SELECT COUNT(*) AS fcount, COALESCE(SUM(total_amount), 0) AS ftotal
             FROM {$table} i
             WHERE i.status = 'fictive' {$inv_date}",
            ARRAY_A
        );
        $all_count_row = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} i WHERE 1=1 {$inv_date}"
        );
        $fictive = [
            'count'         => (int)   ( $fictive_row['fcount'] ?? 0 ),
            'totalAmount'   => (float) ( $fictive_row['ftotal'] ?? 0 ),
            'totalAllCount' => (int) $all_count_row,
        ];

        // ── 7. "Other" accumulated payments (date-filtered by payment_date) ───
        $other_accumulated = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(p.amount), 0)
             FROM {$pays_t} p
             JOIN {$table} i ON i.id = p.invoice_id
             WHERE i.status = 'standard' AND p.method = 'other' {$pay_date}"
        );

        // ── 8. Monthly trend for revenue chart (last 6 months, unfiltered) ───
        $six_ago  = gmdate( 'Y-m-01', strtotime( '-5 months' ) );
        $today    = gmdate( 'Y-m-t' );

        $trend_inv = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS ym,
                COALESCE(SUM(total_amount), 0) AS revenue
             FROM {$table}
             WHERE status = 'standard' AND created_at >= %s AND created_at <= %s
             GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')",
            $six_ago, $today
        ), ARRAY_A );
        $trend_inv_map = array_column( $trend_inv, null, 'ym' );

        $trend_pay = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(p.payment_date, '%%Y-%%m') AS ym,
                COALESCE(SUM(p.amount), 0) AS cash_in
             FROM {$pays_t} p
             JOIN {$table} i ON i.id = p.invoice_id
             WHERE i.status = 'standard' AND p.method != 'consignment'
               AND p.payment_date >= %s AND p.payment_date <= %s
             GROUP BY DATE_FORMAT(p.payment_date, '%%Y-%%m')",
            $six_ago, $today
        ), ARRAY_A );
        $trend_pay_map = array_column( $trend_pay, null, 'ym' );

        $monthly_trend = [];
        for ( $m = 5; $m >= 0; $m-- ) {
            $ts     = strtotime( "-{$m} months" );
            $ym     = gmdate( 'Y-m', $ts );
            $label  = gmdate( 'M Y', $ts );
            $monthly_trend[] = [
                'month'  => $label,
                'revenue' => (float) ( $trend_inv_map[ $ym ]['revenue'] ?? 0 ),
                'cashIn'  => (float) ( $trend_pay_map[ $ym ]['cash_in'] ?? 0 ),
            ];
        }

        // ── 9. Product performance (standard, date-filtered items) ────────────
        $prod_rows = $wpdb->get_results(
            "SELECT
                it.product_id,
                COALESCE(MAX(NULLIF(it.name,  '')), '') AS snap_name,
                COALESCE(MAX(NULLIF(it.sku,   '')), '') AS snap_sku,
                COALESCE(MAX(NULLIF(it.brand, '')), '') AS snap_brand,
                SUM(it.qty) AS units_sold,
                SUM(it.qty * it.price) AS revenue,
                AVG(it.price) AS avg_price,
                SUM(CASE WHEN it.item_status = 'reserved' THEN it.qty ELSE 0 END) AS reserved
             FROM {$items_t} it
             JOIN {$table} i ON i.id = it.invoice_id
             WHERE i.status = 'standard' AND it.item_status != 'canceled' {$inv_date}
             GROUP BY it.product_id
             ORDER BY revenue DESC",
            ARRAY_A
        );
        $products = array_map( fn( $r ) => [
            'productId'   => $r['product_id'] ? (int) $r['product_id'] : null,
            'snapshotName'  => $r['snap_name'],
            'snapshotSku'   => $r['snap_sku'],
            'snapshotBrand' => $r['snap_brand'],
            'unitsSold'   => (float) $r['units_sold'],
            'revenue'     => (float) $r['revenue'],
            'avgPrice'    => (float) $r['avg_price'],
            'reserved'    => (float) $r['reserved'],
        ], $prod_rows );

        // ── 10. Customer insights (standard, date-filtered) ───────────────────
        $cust_rows = $wpdb->get_results(
            "SELECT
                i.customer_id,
                COALESCE(SUM(i.paid_amount), 0) AS total_spent,
                COUNT(*) AS invoice_count,
                COALESCE(SUM(GREATEST(0, i.total_amount - i.paid_amount)), 0) AS outstanding
             FROM {$table} i
             WHERE i.status = 'standard' AND i.customer_id IS NOT NULL {$inv_date}
             GROUP BY i.customer_id",
            ARRAY_A
        );
        $customers = array_map( fn( $r ) => [
            'customerId'   => (int)   $r['customer_id'],
            'totalSpent'   => (float) $r['total_spent'],
            'invoiceCount' => (int)   $r['invoice_count'],
            'outstanding'  => (float) $r['outstanding'],
        ], $cust_rows );

        $data = [
            'overview' => [
                'invoiceCount'          => (int)   ( $ov_row['invoice_count'] ?? 0 ),
                'totalRevenue'          => (float) ( $ov_row['total_revenue'] ?? 0 ),
                'outstandingBalance'    => (float) ( $ov_row['outstanding_balance'] ?? 0 ),
                'pendingReservations'   => $pending,
                'methodTotals'          => $method_totals,
                'topUsers'              => $top_users,
                'lifecycleDistribution' => $lifecycle_dist,
                'fictive'               => $fictive,
                'otherAccumulated'      => $other_accumulated,
                'monthlyTrend'          => $monthly_trend,
            ],
            'products'  => $products,
            'customers' => $customers,
        ];
        set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
        return $data;
    }

    /**
     * Delete all KPI transients so the next request re-runs the queries.
     */
    private static function clear_kpi_cache() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_cig_kpi_%'
                OR option_name LIKE '_transient_timeout_cig_kpi_%'"
        );
    }

    // ── Private helpers ──

    /**
     * Hydrate a DB row into the camelCase shape the Vue frontend expects.
     *
     * @param array      $row       Raw DB row from wp_cig_invoices.
     * @param array|null $pre_items Pre-fetched item rows (batch mode). Null = query individually.
     * @param array|null $pre_pays  Pre-fetched payment rows (batch mode). Null = query individually.
     */
    private static function hydrate( $row, $pre_items = null, $pre_pays = null ) {
        global $wpdb;

        // Fetch items (individual query only when not batch-loaded)
        if ( $pre_items !== null ) {
            $items_rows = $pre_items;
        } else {
            $items_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM " . self::items_table() . " WHERE invoice_id = %d ORDER BY sort_order",
                    $row['id']
                ),
                ARRAY_A
            );
        }

        $items = array_map( function( $item ) {
            return [
                'id'             => (int) $item['id'],
                'productId'      => $item['product_id'] ? (int) $item['product_id'] : null,
                'name'           => $item['name'],
                'brand'          => $item['brand'],
                'sku'            => $item['sku'],
                'description'    => $item['description'] ?? '',
                'imageUrl'       => $item['image_url'],
                'qty'            => (float) $item['qty'],
                'price'          => (float) $item['price'],
                'total'          => (float) $item['total'],
                'itemStatus'     => $item['item_status'],
                'reservationDays' => (int) $item['reservation_days'],
                'warranty'       => $item['warranty'],
            ];
        }, $items_rows );

        // Fetch payments (individual query only when not batch-loaded)
        if ( $pre_pays !== null ) {
            $payment_rows = $pre_pays;
        } else {
            $payment_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM " . self::payments_table() . " WHERE invoice_id = %d ORDER BY payment_date",
                    $row['id']
                ),
                ARRAY_A
            );
        }

        $payments = array_map( function( $p ) {
            return [
                'id'      => (int) $p['id'],
                'date'    => $p['payment_date'],
                'amount'  => (float) $p['amount'],
                'method'  => $p['method'],
                'comment' => $p['comment'] ?? '',
                'userId'  => $p['user_id'] ? (int) $p['user_id'] : null,
            ];
        }, $payment_rows );

        return [
            'id'                => (int) $row['id'],
            'number'            => $row['invoice_number'],
            'customerId'        => $row['customer_id'] ? (int) $row['customer_id'] : null,
            'status'            => $row['status'],
            'lifecycleStatus'   => $row['lifecycle_status'],
            'items'             => $items,
            'payments'          => $payments,
            'totalAmount'       => (float) $row['total_amount'],
            'paidAmount'        => (float) $row['paid_amount'],
            'createdAt'         => $row['created_at'],
            'soldDate'          => $row['sold_date'],
            'saleDate'          => $row['sale_date'],
            'authorId'          => $row['author_id'] ? (int) $row['author_id'] : null,
            'buyerName'         => $row['buyer_name'],
            'buyerTaxId'        => $row['buyer_tax_id'],
            'buyerPhone'        => $row['buyer_phone'],
            'buyerAddress'      => $row['buyer_address'],
            'buyerEmail'        => $row['buyer_email'],
            'generalNote'       => $row['general_note'] ?? '',
            'consultantNote'    => $row['consultant_note'] ?? '',
            'accountantNote'    => $row['accountant_note'] ?? '',
            'isRsUploaded'      => (bool) $row['is_rs_uploaded'],
            'isCreditChecked'   => (bool) $row['is_credit_checked'],
            'isReceiptChecked'  => (bool) $row['is_receipt_checked'],
            'isCorrected'       => (bool) $row['is_corrected'],
        ];
    }

    /**
     * Extract invoice-level fields from input data (accepts both camelCase and snake_case).
     */
    private static function extract_invoice_fields( $data ) {
        $fields = [];
        $map = [
            'invoice_number'   => [ 'invoiceNumber', 'invoice_number', 'number' ],
            'customer_id'      => [ 'customerId', 'customer_id' ],
            'status'           => [ 'status' ],
            'lifecycle_status' => [ 'lifecycleStatus', 'lifecycle_status' ],
            'total_amount'     => [ 'totalAmount', 'total_amount' ],
            'paid_amount'      => [ 'paidAmount', 'paid_amount' ],
            'created_at'       => [ 'createdAt', 'created_at' ],
            'sold_date'        => [ 'soldDate', 'sold_date' ],
            'sale_date'        => [ 'saleDate', 'sale_date' ],
            'author_id'        => [ 'authorId', 'author_id' ],
            'buyer_name'       => [ 'buyerName', 'buyer_name' ],
            'buyer_tax_id'     => [ 'buyerTaxId', 'buyer_tax_id' ],
            'buyer_phone'      => [ 'buyerPhone', 'buyer_phone' ],
            'buyer_address'    => [ 'buyerAddress', 'buyer_address' ],
            'buyer_email'      => [ 'buyerEmail', 'buyer_email' ],
            'general_note'     => [ 'generalNote', 'general_note' ],
            'consultant_note'  => [ 'consultantNote', 'consultant_note' ],
            'accountant_note'  => [ 'accountantNote', 'accountant_note' ],
            'is_rs_uploaded'   => [ 'isRsUploaded', 'is_rs_uploaded' ],
            'is_credit_checked'  => [ 'isCreditChecked', 'is_credit_checked' ],
            'is_receipt_checked' => [ 'isReceiptChecked', 'is_receipt_checked' ],
            'is_corrected'     => [ 'isCorrected', 'is_corrected' ],
        ];

        foreach ( $map as $db_col => $keys ) {
            foreach ( $keys as $key ) {
                if ( array_key_exists( $key, $data ) ) {
                    $fields[ $db_col ] = $data[ $key ];
                    break;
                }
            }
        }

        // Cast booleans to int for DB
        foreach ( [ 'is_rs_uploaded', 'is_credit_checked', 'is_receipt_checked', 'is_corrected' ] as $bool_col ) {
            if ( isset( $fields[ $bool_col ] ) ) {
                $fields[ $bool_col ] = $fields[ $bool_col ] ? 1 : 0;
            }
        }

        return $fields;
    }

    /**
     * Save items for an invoice.
     */
    private static function save_items( $invoice_id, $items ) {
        global $wpdb;
        $table = self::items_table();

        foreach ( $items as $i => $item ) {
            $wpdb->insert( $table, [
                'invoice_id'       => $invoice_id,
                'sort_order'       => $i,
                'product_id'       => $item['productId'] ?? $item['product_id'] ?? null,
                'legacy_product_id' => $item['legacyProductId'] ?? $item['legacy_product_id'] ?? null,
                'name'             => $item['name'] ?? '',
                'brand'            => $item['brand'] ?? '',
                'sku'              => $item['sku'] ?? '',
                'description'      => $item['description'] ?? '',
                'image_url'        => $item['imageUrl'] ?? $item['image_url'] ?? '',
                'qty'              => $item['qty'] ?? 1,
                'price'            => $item['price'] ?? 0,
                'total'            => ( $item['qty'] ?? 1 ) * ( $item['price'] ?? 0 ),
                'item_status'      => $item['itemStatus'] ?? $item['item_status'] ?? 'none',
                'reservation_days' => $item['reservationDays'] ?? $item['reservation_days'] ?? 0,
                'warranty'         => $item['warranty'] ?? '',
            ] );
        }
    }

    /**
     * Save payments for an invoice.
     */
    private static function save_payments( $invoice_id, $payments ) {
        global $wpdb;
        $table = self::payments_table();

        foreach ( $payments as $payment ) {
            $wpdb->insert( $table, [
                'invoice_id'   => $invoice_id,
                'payment_date' => $payment['date'] ?? $payment['payment_date'] ?? current_time( 'Y-m-d' ),
                'amount'       => $payment['amount'] ?? 0,
                'method'       => $payment['method'] ?? 'cash',
                'comment'      => $payment['comment'] ?? '',
                'user_id'      => $payment['userId'] ?? $payment['user_id'] ?? null,
            ] );
        }
    }

    /**
     * Recalculate total_amount and paid_amount from items/payments.
     */
    private static function recalculate_totals( $invoice_id ) {
        global $wpdb;

        // total_amount = sum of non-canceled items
        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(qty * price), 0) FROM " . self::items_table() . "
             WHERE invoice_id = %d AND item_status != 'canceled'",
            $invoice_id
        ) );

        // paid_amount = sum of non-consignment payments (includes negative refunds)
        $paid = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM " . self::payments_table() . "
             WHERE invoice_id = %d AND method != 'consignment'",
            $invoice_id
        ) );

        $wpdb->update( self::table(), [
            'total_amount' => (float) $total,
            'paid_amount'  => (float) $paid,
        ], [ 'id' => $invoice_id ] );
    }
}
