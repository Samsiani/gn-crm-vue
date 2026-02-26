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
            'sort'           => 'created_at',
            'order'          => 'DESC',
            'page'           => 1,
            'per_page'       => 25,
        ];
        $args = wp_parse_args( $args, $defaults );

        $table = self::table();
        $items_table = self::items_table();
        $payments_table = self::payments_table();
        $where = [ '1=1' ];
        $joins = [];
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

        // Hydrate all
        $invoices = [];
        foreach ( $rows as $row ) {
            $invoices[] = self::hydrate( $row );
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

        return self::find( $id );
    }

    /**
     * Delete an invoice (cascades to items + payments via FK).
     */
    public static function delete( $id ) {
        global $wpdb;
        return $wpdb->delete( self::table(), [ 'id' => $id ] );
    }

    /**
     * Generate next invoice number.
     */
    public static function generate_number() {
        global $wpdb;
        $company = CIG_Company::get();
        $prefix = $company ? $company['invoicePrefix'] : 'GN';
        $starting = $company ? (int) $company['startingInvoiceNumber'] : 1001;

        $last_number = $wpdb->get_var(
            "SELECT MAX(CAST(REPLACE(invoice_number, '{$prefix}-', '') AS UNSIGNED))
             FROM " . self::table() . "
             WHERE invoice_number LIKE '{$prefix}-%'"
        );

        $next = max( $starting, ( (int) $last_number ) + 1 );
        return $prefix . '-' . $next;
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
             WHERE i.status = 'standard' {$inv_date}",
            ARRAY_A
        );

        // ── 2. Pending reservations ──────────────────────────────────────────
        $pending_sql = "SELECT COUNT(DISTINCT i.id) FROM {$table} i
             WHERE i.status = 'standard' AND i.lifecycle_status = 'active'
             AND EXISTS (SELECT 1 FROM {$items_t} it WHERE it.invoice_id = i.id AND it.item_status = 'reserved')
             {$inv_date}";
        $pending = (int) $wpdb->get_var( $pending_sql );

        // ── 3. Payment method totals (filtered by payment_date) ──────────────
        $method_rows = $wpdb->get_results(
            "SELECT p.method, SUM(p.amount) as total
             FROM {$pays_t} p
             JOIN {$table} i ON i.id = p.invoice_id
             WHERE i.status = 'standard' {$pay_date}
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

        // ── 4. Monthly trend (last 6 months, always unfiltered by date params) ──
        $monthly_trend = [];
        for ( $i = 5; $i >= 0; $i-- ) {
            $month_start = gmdate( 'Y-m-01', strtotime( "-{$i} months" ) );
            $month_end   = gmdate( 'Y-m-t', strtotime( "-{$i} months" ) );

            $inv_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT
                    COUNT(*) as count,
                    COALESCE(SUM(total_amount), 0) as revenue,
                    COALESCE(SUM(CASE WHEN lifecycle_status IN ('sold','completed') THEN 1 ELSE 0 END), 0) as completed,
                    COALESCE(SUM(GREATEST(0, total_amount - paid_amount)), 0) as outstanding
                 FROM {$table}
                 WHERE status = 'standard' AND created_at >= %s AND created_at <= %s",
                $month_start, $month_end
            ), ARRAY_A );

            $paid_in_month = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(p.amount), 0)
                 FROM {$pays_t} p
                 JOIN {$table} i ON i.id = p.invoice_id
                 WHERE i.status = 'standard' AND p.method != 'consignment'
                   AND p.payment_date >= %s AND p.payment_date <= %s",
                $month_start, $month_end
            ) );

            $count   = (int) $inv_row['count'];
            $revenue = (float) $inv_row['revenue'];
            $monthly_trend[] = [
                'month'       => gmdate( 'M Y', strtotime( $month_start ) ),
                'revenue'     => $revenue,
                'paid'        => $paid_in_month,
                'count'       => $count,
                'outstanding' => (float) $inv_row['outstanding'],
                'completed'   => (int) $inv_row['completed'],
                'avgOrder'    => $count > 0 ? round( $revenue / $count, 2 ) : 0,
            ];
        }

        // ── 5. Expiring reservations ─────────────────────────────────────────
        $res_rows = $wpdb->get_results(
            "SELECT
                i.invoice_number, i.created_at,
                it.product_id, it.name as product_name, it.sku, it.reservation_days,
                c.name as customer_name
             FROM {$table} i
             JOIN {$items_t} it ON it.invoice_id = i.id AND it.item_status = 'reserved' AND it.reservation_days > 0
             LEFT JOIN {$cust_t} c ON c.id = i.customer_id
             WHERE i.lifecycle_status = 'active'
             ORDER BY i.created_at ASC
             LIMIT 50",
            ARRAY_A
        );
        $expiring = [];
        foreach ( $res_rows as $r ) {
            $days_elapsed   = (int) floor( ( time() - strtotime( $r['created_at'] ) ) / DAY_IN_SECONDS );
            $days_remaining = max( 0, (int) $r['reservation_days'] - $days_elapsed );
            $expiring[] = [
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
             WHERE i.status = 'standard' AND i.created_at >= %s AND it.item_status != 'canceled'
             GROUP BY it.product_id, it.name
             ORDER BY revenue DESC
             LIMIT 5",
            $six_ago
        ), ARRAY_A );

        return [
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
    }

    // ── Private helpers ──

    /**
     * Hydrate a DB row into the camelCase shape the Vue frontend expects.
     */
    private static function hydrate( $row ) {
        global $wpdb;

        // Fetch items
        $items_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::items_table() . " WHERE invoice_id = %d ORDER BY sort_order",
                $row['id']
            ),
            ARRAY_A
        );

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

        // Fetch payments
        $payment_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::payments_table() . " WHERE invoice_id = %d ORDER BY payment_date",
                $row['id']
            ),
            ARRAY_A
        );

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
