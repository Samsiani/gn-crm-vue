<?php
/**
 * Main migration orchestrator — reads legacy wp_postmeta data and populates custom tables.
 *
 * Migration order (dependencies matter):
 * M1: Users → M2: Products → M3: Customers → M4: Invoices → M5: Invoice Items →
 * M6: Payments → M7: Deposits → M8: Stock Requests
 */
class CIG_Migrator {

    private $batch_size;
    private $dry_run;
    private $log_callback;
    private $errors = [];
    private $stats = [];

    public function __construct( $batch_size = 50, $dry_run = false, $log_callback = null ) {
        $this->batch_size   = $batch_size;
        $this->dry_run      = $dry_run;
        $this->log_callback = $log_callback ?: function( $msg ) { error_log( $msg ); };
    }

    public function run() {
        $this->log( '=== CIG Migration Started ===' );
        $this->log( 'Batch size: ' . $this->batch_size );
        $this->log( 'Dry run: ' . ( $this->dry_run ? 'YES' : 'NO' ) );
        $this->log( '' );

        $steps = [
            [ 'M1: Users',          'migrate_users' ],
            [ 'M2: Products',       'migrate_products' ],
            [ 'M3: Customers',      'migrate_customers' ],
            [ 'M4: Invoices',       'migrate_invoices' ],
            [ 'M5: Invoice Items',  'migrate_invoice_items' ],
            [ 'M6: Payments',       'migrate_payments' ],
            [ 'M7: Deposits',       'migrate_deposits' ],
            [ 'M8: Stock Requests', 'migrate_stock_requests' ],
        ];

        foreach ( $steps as [ $label, $method ] ) {
            $this->log( "--- {$label} ---" );
            $start = microtime( true );

            try {
                $count = $this->$method();
                $elapsed = round( microtime( true ) - $start, 2 );
                $this->stats[ $label ] = $count;
                $this->log( "{$label}: {$count} records migrated ({$elapsed}s)" );
            } catch ( \Exception $e ) {
                $this->errors[] = "{$label}: " . $e->getMessage();
                $this->log( "ERROR in {$label}: " . $e->getMessage() );
            }

            $this->log( '' );
        }

        // Recalculate all invoice totals
        if ( ! $this->dry_run ) {
            $this->recalculate_all_totals();
        }

        $this->log( '=== Migration Complete ===' );
        $this->log( 'Errors: ' . count( $this->errors ) );

        return [
            'stats'  => $this->stats,
            'errors' => $this->errors,
        ];
    }

    // ── M1: Users ──

    private function migrate_users() {
        global $wpdb;

        // Known WP user IDs from the legacy system
        $wp_user_ids = [ 1, 2, 16, 21, 22, 23, 24, 26 ];

        $count = 0;
        foreach ( $wp_user_ids as $wp_id ) {
            $wp_user = get_user_by( 'ID', $wp_id );
            if ( ! $wp_user ) {
                $this->log( "  Warning: WP user {$wp_id} not found, skipping." );
                continue;
            }

            // Determine CIG role from WP role
            $role = $this->map_wp_role( $wp_user );

            // Extract name parts
            $display_name = $wp_user->display_name;
            $first = $wp_user->first_name ?: $display_name;
            $last  = $wp_user->last_name ?: '';
            $name_en = trim( "{$first} {$last}" );

            // Generate avatar from initials
            $avatar = '';
            $parts = explode( ' ', $name_en );
            if ( count( $parts ) >= 2 ) {
                $avatar = strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[1], 0, 1 ) );
            } else {
                $avatar = strtoupper( mb_substr( $name_en, 0, 2 ) );
            }

            if ( $this->dry_run ) {
                $this->log( "  [DRY] Would create user: {$name_en} ({$role}) from WP#{$wp_id}" );
                $count++;
                continue;
            }

            $table = $wpdb->prefix . 'cig_users';
            $wpdb->insert( $table, [
                'wp_user_id' => $wp_id,
                'name'       => $display_name,
                'name_en'    => $name_en,
                'avatar'     => $avatar,
                'role'       => $role,
                'is_active'  => 1,
            ] );

            $new_id = $wpdb->insert_id;
            CIG_ID_Mapper::set( 'user', $wp_id, $new_id );
            $count++;
        }

        return $count;
    }

    private function map_wp_role( $wp_user ) {
        $roles = $wp_user->roles;
        if ( in_array( 'administrator', $roles, true ) ) return 'admin';
        if ( in_array( 'editor', $roles, true ) )        return 'manager';
        if ( in_array( 'shop_manager', $roles, true ) )  return 'manager';
        if ( in_array( 'author', $roles, true ) )        return 'sales';
        if ( in_array( 'contributor', $roles, true ) )   return 'accountant';
        return 'sales'; // Default
    }

    // ── M2: Products ──

    private function migrate_products() {
        global $wpdb;

        // Get all unique product IDs referenced in invoice items
        $product_ids = $wpdb->get_col(
            "SELECT DISTINCT pm_items.meta_value
             FROM {$wpdb->postmeta} pm_items
             WHERE pm_items.meta_key = '_cig_items'
             AND pm_items.meta_value != ''"
        );

        // Collect all referenced product post IDs by deserializing items
        $all_product_post_ids = [];
        foreach ( $product_ids as $serialized ) {
            $items = maybe_unserialize( $serialized );
            if ( ! is_array( $items ) ) continue;
            foreach ( $items as $item ) {
                if ( ! empty( $item['product_id'] ) ) {
                    $all_product_post_ids[ (int) $item['product_id'] ] = true;
                }
            }
        }

        // Also get WooCommerce products
        $woo_products = $wpdb->get_results(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'",
            ARRAY_A
        );
        foreach ( $woo_products as $p ) {
            $all_product_post_ids[ (int) $p['ID'] ] = true;
        }

        $count = 0;
        foreach ( array_keys( $all_product_post_ids ) as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                $this->log( "  Warning: Product post {$post_id} not found, creating placeholder." );
            }

            $sku   = get_post_meta( $post_id, '_sku', true ) ?: 'LEGACY-' . $post_id;
            $price = (float) get_post_meta( $post_id, '_price', true );
            $stock = (int) get_post_meta( $post_id, '_stock', true );
            $name  = $post ? $post->post_title : 'Unknown Product #' . $post_id;

            if ( $this->dry_run ) {
                $this->log( "  [DRY] Would create product: {$name} (SKU: {$sku})" );
                $count++;
                continue;
            }

            $table = $wpdb->prefix . 'cig_products';
            $wpdb->insert( $table, [
                'legacy_post_id' => $post_id,
                'sku'            => $sku,
                'name'           => $name,
                'name_ka'        => '', // Will need manual entry
                'brand'          => '',
                'description'    => $post ? $post->post_content : '',
                'image_url'      => get_the_post_thumbnail_url( $post_id, 'full' ) ?: '',
                'price'          => $price,
                'stock'          => $stock,
                'reserved'       => 0,
                'category'       => '',
                'is_active'      => 1,
            ] );

            $new_id = $wpdb->insert_id;
            CIG_ID_Mapper::set( 'product', $post_id, $new_id );
            $count++;
        }

        return $count;
    }

    // ── M3: Customers ──

    private function migrate_customers() {
        global $wpdb;

        // Step 1: Investigate what _cig_customer_id references
        // Check if they're taxonomy terms
        $sample_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_cig_customer_id' AND meta_value != ''
             LIMIT 20"
        );

        $term_matches = 0;
        if ( ! empty( $sample_ids ) ) {
            $ids_str = implode( ',', array_map( 'intval', $sample_ids ) );
            $term_matches = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id IN ({$ids_str})"
            );
        }

        $this->log( "  Customer ID investigation: {$term_matches}/" . count( $sample_ids ) . " match wp_terms" );

        // Step 2: Import customer CPT records (post_type = 'cig_customer')
        $customer_posts = $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type = 'cig_customer' AND post_status = 'publish'
             ORDER BY ID",
            ARRAY_A
        );

        $count = 0;

        foreach ( $customer_posts as $cp ) {
            $post_id = (int) $cp['ID'];
            $name    = $cp['post_title'];
            $tax_id  = get_post_meta( $post_id, '_cig_customer_tax_id', true ) ?: '';
            $phone   = get_post_meta( $post_id, '_cig_customer_phone', true ) ?: '';
            $email   = get_post_meta( $post_id, '_cig_customer_email', true ) ?: '';
            $address = get_post_meta( $post_id, '_cig_customer_address', true ) ?: '';

            // Check if there's a term/legacy ID association
            $legacy_term_id = get_post_meta( $post_id, '_cig_customer_legacy_id', true );

            if ( $this->dry_run ) {
                $this->log( "  [DRY] Would create customer: {$name} (Tax: {$tax_id})" );
                $count++;
                continue;
            }

            $table = $wpdb->prefix . 'cig_customers';
            $wpdb->insert( $table, [
                'legacy_post_id' => $post_id,
                'legacy_term_id' => $legacy_term_id ?: null,
                'name'           => $name,
                'name_en'        => '', // Will need manual entry
                'tax_id'         => $tax_id,
                'address'        => $address,
                'phone'          => $phone,
                'email'          => $email,
            ] );

            $new_id = $wpdb->insert_id;
            CIG_ID_Mapper::set( 'customer_post', $post_id, $new_id );

            if ( $legacy_term_id ) {
                CIG_ID_Mapper::set( 'customer', (int) $legacy_term_id, $new_id );
            }

            $count++;
        }

        // Step 3: If term matches, build mapping from term IDs
        if ( $term_matches > 0 ) {
            $this->build_customer_term_mapping();
        }

        // Step 4: Create customers from invoice buyer fields for any unmapped customer_ids
        $this->create_customers_from_invoices( $count );

        return $count;
    }

    private function build_customer_term_mapping() {
        global $wpdb;

        // Get all unique _cig_customer_id values from invoices
        $customer_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_cig_customer_id' AND meta_value != ''"
        );

        foreach ( $customer_ids as $legacy_id ) {
            $legacy_id = (int) $legacy_id;

            // Skip if already mapped
            if ( CIG_ID_Mapper::get_new_id( 'customer', $legacy_id ) ) continue;

            // Try matching by term name → customer name
            $term_name = $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM {$wpdb->terms} WHERE term_id = %d",
                $legacy_id
            ) );

            if ( $term_name ) {
                // Find customer by name
                $table = $wpdb->prefix . 'cig_customers';
                $new_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE name = %s LIMIT 1",
                    $term_name
                ) );

                if ( $new_id ) {
                    CIG_ID_Mapper::set( 'customer', $legacy_id, (int) $new_id );
                    $wpdb->update( $table, [ 'legacy_term_id' => $legacy_id ], [ 'id' => $new_id ] );
                }
            }
        }
    }

    private function create_customers_from_invoices( &$count ) {
        global $wpdb;

        // Find invoices with unmapped customer_ids
        $invoices = $wpdb->get_results(
            "SELECT p.ID,
                    MAX(CASE WHEN pm.meta_key = '_cig_customer_id' THEN pm.meta_value END) AS customer_id,
                    MAX(CASE WHEN pm.meta_key = '_cig_buyer_name' THEN pm.meta_value END) AS buyer_name,
                    MAX(CASE WHEN pm.meta_key = '_cig_buyer_tax_id' THEN pm.meta_value END) AS buyer_tax_id,
                    MAX(CASE WHEN pm.meta_key = '_cig_buyer_phone' THEN pm.meta_value END) AS buyer_phone,
                    MAX(CASE WHEN pm.meta_key = '_cig_buyer_email' THEN pm.meta_value END) AS buyer_email,
                    MAX(CASE WHEN pm.meta_key = '_cig_buyer_address' THEN pm.meta_value END) AS buyer_address
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'cig_invoice' AND p.post_status = 'publish'
             GROUP BY p.ID",
            ARRAY_A
        );

        foreach ( $invoices as $inv ) {
            $legacy_cid = (int) ( $inv['customer_id'] ?? 0 );
            if ( ! $legacy_cid ) continue;

            // Already mapped?
            if ( CIG_ID_Mapper::get_new_id( 'customer', $legacy_cid ) ) continue;

            $buyer = [
                'name'    => trim( $inv['buyer_name']    ?? '' ),
                'tax_id'  => trim( $inv['buyer_tax_id']  ?? '' ),
                'phone'   => trim( $inv['buyer_phone']   ?? '' ),
                'email'   => trim( $inv['buyer_email']   ?? '' ),
                'address' => trim( $inv['buyer_address'] ?? '' ),
            ];

            if ( ! $buyer['name'] && ! $buyer['tax_id'] ) continue;

            if ( $this->dry_run ) {
                $this->log( "  [DRY] Would find/create customer from invoice: {$buyer['name']} (Tax: {$buyer['tax_id']})" );
                continue;
            }

            // Waterfall match: tax_id → email → phone → name → create
            $new_id = $this->find_or_create_customer_waterfall( $buyer, $legacy_cid );
            if ( $new_id ) {
                CIG_ID_Mapper::set( 'customer', $legacy_cid, $new_id );
                $count++;
            }
        }
    }

    // ── M4: Invoices ──

    private function migrate_invoices() {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'cig_invoice' AND post_status = 'publish'"
        );

        $this->log( "  Total legacy invoices: {$total}" );
        $count = 0;

        for ( $offset = 0; $offset < $total; $offset += $this->batch_size ) {
            $posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'cig_invoice' AND post_status = 'publish'
                 ORDER BY ID
                 LIMIT %d OFFSET %d",
                $this->batch_size, $offset
            ), ARRAY_A );

            if ( ! $this->dry_run ) {
                $wpdb->query( 'START TRANSACTION' );
            }

            try {
                foreach ( $posts as $post ) {
                    $post_id = (int) $post['ID'];
                    $this->migrate_single_invoice( $post_id );
                    $count++;
                }

                if ( ! $this->dry_run ) {
                    $wpdb->query( 'COMMIT' );
                }
            } catch ( \Exception $e ) {
                if ( ! $this->dry_run ) {
                    $wpdb->query( 'ROLLBACK' );
                }
                throw $e;
            }

            $this->log( "  Batch: {$count}/{$total}" );
        }

        return $count;
    }

    private function migrate_single_invoice( $post_id ) {
        global $wpdb;

        $meta = $this->get_all_meta( $post_id, '_cig_' );

        $invoice_number = $meta['invoice_number'] ?? '';
        $status         = $meta['invoice_status'] ?? 'standard';
        $lifecycle      = $meta['lifecycle_status'] ?? 'reserved';

        // Map lifecycle status
        $new_lifecycle = $this->map_lifecycle( $lifecycle, $status );

        // Map customer_id
        $legacy_customer_id = (int) ( $meta['customer_id'] ?? 0 );
        $new_customer_id = CIG_ID_Mapper::get_new_id( 'customer', $legacy_customer_id );

        // Map author_id
        $legacy_author_id = (int) ( $meta['author_id'] ?? 0 );
        $new_author_id = CIG_ID_Mapper::get_new_id( 'user', $legacy_author_id );

        // Map accounting status → 4 booleans
        $acc = $this->map_accounting_status( $meta['acc_status'] ?? '' );

        // Handle sold_date
        $sold_date  = $meta['sold_date'] ?? null;
        $created_at = $meta['created_date'] ?? get_post_field( 'post_date', $post_id );
        $created_at = $created_at ? substr( $created_at, 0, 10 ) : date( 'Y-m-d' );

        // Backfill: completed without sold_date → use created_at
        if ( $new_lifecycle === 'sold' && empty( $sold_date ) ) {
            $sold_date = $created_at;
        }

        // Clean: unfinished with sold_date → clear it
        if ( $new_lifecycle === 'draft' && ! empty( $sold_date ) ) {
            $sold_date = null;
        }

        if ( $this->dry_run ) {
            $this->log( "  [DRY] Invoice {$invoice_number}: {$status}/{$lifecycle} → {$status}/{$new_lifecycle}" );
            return;
        }

        $table = $wpdb->prefix . 'cig_invoices';
        $wpdb->insert( $table, [
            'legacy_post_id'    => $post_id,
            'invoice_number'    => $invoice_number,
            'customer_id'       => $new_customer_id ?: null,
            'status'            => $status === 'fictive' ? 'fictive' : 'standard',
            'lifecycle_status'  => $new_lifecycle,
            'total_amount'      => (float) ( $meta['total_amount'] ?? 0 ),
            'paid_amount'       => 0, // Recalculated after payments
            'created_at'        => $created_at,
            'sold_date'         => $sold_date ?: null,
            'sale_date'         => null,
            'author_id'         => $new_author_id ?: null,
            'buyer_name'        => $meta['buyer_name'] ?? '',
            'buyer_tax_id'      => $meta['buyer_tax_id'] ?? '',
            'buyer_phone'       => $meta['buyer_phone'] ?? '',
            'buyer_address'     => $meta['buyer_address'] ?? '',
            'buyer_email'       => $meta['buyer_email'] ?? '',
            'general_note'      => $meta['general_note'] ?? '',
            'consultant_note'   => $meta['consultant_note'] ?? '',
            'accountant_note'   => $meta['accountant_note'] ?? '',
            'is_rs_uploaded'    => $acc['is_rs_uploaded'],
            'is_credit_checked' => $acc['is_credit_checked'],
            'is_receipt_checked' => $acc['is_receipt_checked'],
            'is_corrected'      => $acc['is_corrected'],
        ] );

        $new_id = $wpdb->insert_id;
        CIG_ID_Mapper::set( 'invoice', $post_id, $new_id );
    }

    private function map_lifecycle( $legacy, $status ) {
        if ( $status === 'fictive' ) return 'draft';

        switch ( $legacy ) {
            case 'unfinished': return 'draft';
            case 'completed':  return 'sold';
            case 'reserved':   return 'reserved';
            default:           return 'reserved';
        }
    }

    private function map_accounting_status( $acc_status ) {
        $result = [
            'is_rs_uploaded'    => 0,
            'is_credit_checked' => 0,
            'is_receipt_checked' => 0,
            'is_corrected'      => 0,
        ];

        switch ( $acc_status ) {
            case 'rs':
                $result['is_rs_uploaded'] = 1;
                break;
            case 'corrected':
                $result['is_rs_uploaded'] = 1;
                $result['is_corrected']   = 1;
                break;
            case 'credit':
                $result['is_rs_uploaded']    = 1;
                $result['is_credit_checked'] = 1;
                break;
            case 'receipt':
                $result['is_rs_uploaded']     = 1;
                $result['is_receipt_checked'] = 1;
                break;
        }

        return $result;
    }

    // ── M5: Invoice Items ──

    private function migrate_invoice_items() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_cig_items'",
            ARRAY_A
        );

        $count = 0;

        foreach ( $rows as $row ) {
            $post_id = (int) $row['post_id'];
            $new_invoice_id = CIG_ID_Mapper::get_new_id( 'invoice', $post_id );

            if ( ! $new_invoice_id ) {
                $this->log( "  Warning: No invoice mapping for post {$post_id}, skipping items." );
                continue;
            }

            $items = maybe_unserialize( $row['meta_value'] );
            if ( ! is_array( $items ) || empty( $items ) ) {
                $this->log( "  Warning: Invoice post {$post_id} has no items or failed to unserialize." );
                continue;
            }

            foreach ( $items as $i => $item ) {
                $legacy_product_id = (int) ( $item['product_id'] ?? 0 );
                $new_product_id = $legacy_product_id
                    ? CIG_ID_Mapper::get_new_id( 'product', $legacy_product_id )
                    : null;

                // Map item status
                $item_status = $item['status'] ?? 'none';
                if ( ! in_array( $item_status, [ 'none', 'reserved', 'sold', 'canceled' ], true ) ) {
                    $item_status = 'reserved';
                }

                if ( $this->dry_run ) continue;

                $table = $wpdb->prefix . 'cig_invoice_items';
                $wpdb->insert( $table, [
                    'invoice_id'       => $new_invoice_id,
                    'sort_order'       => $i,
                    'product_id'       => $new_product_id ?: null,
                    'legacy_product_id' => $legacy_product_id ?: null,
                    'name'             => $item['name'] ?? '',
                    'brand'            => $item['brand'] ?? '',
                    'sku'              => $item['sku'] ?? '',
                    'description'      => $item['desc'] ?? $item['description'] ?? '',
                    'image_url'        => $item['image'] ?? $item['image_url'] ?? '',
                    'qty'              => (float) ( $item['qty'] ?? 1 ),
                    'price'            => (float) ( $item['price'] ?? 0 ),
                    'total'            => (float) ( $item['total'] ?? ( ( $item['qty'] ?? 1 ) * ( $item['price'] ?? 0 ) ) ),
                    'item_status'      => $item_status,
                    'reservation_days' => (int) ( $item['reservation_days'] ?? 0 ),
                    'warranty'         => $item['warranty'] ?? '',
                ] );

                $count++;
            }
        }

        return $count;
    }

    // ── M6: Payments ──

    private function migrate_payments() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_cig_payment_history'",
            ARRAY_A
        );

        $count = 0;

        foreach ( $rows as $row ) {
            $post_id = (int) $row['post_id'];
            $new_invoice_id = CIG_ID_Mapper::get_new_id( 'invoice', $post_id );

            if ( ! $new_invoice_id ) continue;

            $payments = maybe_unserialize( $row['meta_value'] );
            if ( ! is_array( $payments ) || empty( $payments ) ) continue;

            foreach ( $payments as $payment ) {
                $method = $payment['method'] ?? 'cash';

                // Skip 'mixed' — it's a derived type
                if ( $method === 'mixed' ) continue;

                // Map valid methods
                if ( ! in_array( $method, [ 'company_transfer', 'cash', 'other', 'credit', 'consignment', 'refund' ], true ) ) {
                    $method = 'other';
                }

                // Map user_id
                $legacy_user_id = (int) ( $payment['user_id'] ?? 0 );
                $new_user_id = $legacy_user_id
                    ? CIG_ID_Mapper::get_new_id( 'user', $legacy_user_id )
                    : null;

                if ( $this->dry_run ) {
                    $count++;
                    continue;
                }

                $table = $wpdb->prefix . 'cig_payments';
                $wpdb->insert( $table, [
                    'invoice_id'   => $new_invoice_id,
                    'payment_date' => $payment['date'] ?? date( 'Y-m-d' ),
                    'amount'       => (float) ( $payment['amount'] ?? 0 ),
                    'method'       => $method,
                    'comment'      => $payment['comment'] ?? '',
                    'user_id'      => $new_user_id ?: null,
                ] );

                $count++;
            }
        }

        return $count;
    }

    // ── M7: Deposits ──

    private function migrate_deposits() {
        global $wpdb;

        $posts = $wpdb->get_results(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'cig_deposit' AND post_status = 'publish'
             ORDER BY ID",
            ARRAY_A
        );

        $count = 0;

        foreach ( $posts as $post ) {
            $post_id = (int) $post['ID'];
            $meta = $this->get_all_meta( $post_id, '_cig_' );

            $amount = (float) ( $meta['amount'] ?? 0 );
            $type = $amount >= 0 ? 'credit' : 'debit';

            if ( $this->dry_run ) {
                $this->log( "  [DRY] Deposit: {$amount} ({$type})" );
                $count++;
                continue;
            }

            $table = $wpdb->prefix . 'cig_deposits';
            $wpdb->insert( $table, [
                'legacy_post_id' => $post_id,
                'deposit_date'   => $meta['deposit_date'] ?? get_post_field( 'post_date', $post_id ),
                'amount'         => $amount,
                'type'           => $type,
                'note'           => $meta['note'] ?? get_post_field( 'post_title', $post_id ),
            ] );

            $new_id = $wpdb->insert_id;
            CIG_ID_Mapper::set( 'deposit', $post_id, $new_id );
            $count++;
        }

        return $count;
    }

    // ── M8: Stock Requests ──

    private function migrate_stock_requests() {
        global $wpdb;

        $posts = $wpdb->get_results(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'cig_stock_request' AND post_status = 'publish'
             ORDER BY ID",
            ARRAY_A
        );

        if ( empty( $posts ) ) {
            $this->log( '  No stock request posts found.' );
            return 0;
        }

        $count = 0;

        foreach ( $posts as $post ) {
            $post_id = (int) $post['ID'];
            $meta = $this->get_all_meta( $post_id, '_cig_' );

            $legacy_product_id = (int) ( $meta['product_id'] ?? 0 );
            $new_product_id = CIG_ID_Mapper::get_new_id( 'product', $legacy_product_id );

            $changes = maybe_unserialize( $meta['req_changes'] ?? '' );

            if ( $this->dry_run ) {
                $count++;
                continue;
            }

            $table = $wpdb->prefix . 'cig_stock_requests';
            $wpdb->insert( $table, [
                'legacy_post_id' => $post_id,
                'product_id'     => $new_product_id ?: 0,
                'status'         => $meta['req_status'] ?? 'pending',
                'request_date'   => get_post_field( 'post_date', $post_id ),
                'changes'        => wp_json_encode( $changes ?: [] ),
                'approver_id'    => null,
                'processed_date' => null,
            ] );

            $count++;
        }

        return $count;
    }

    // ── Post-Migration: Recalculate Totals ──

    private function recalculate_all_totals() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        $this->log( '  Recalculating all invoice totals...' );

        // Recalculate total_amount from items (excluding canceled)
        $wpdb->query(
            "UPDATE {$prefix}invoices i SET total_amount = (
                SELECT COALESCE(SUM(ii.qty * ii.price), 0)
                FROM {$prefix}invoice_items ii
                WHERE ii.invoice_id = i.id AND ii.item_status != 'canceled'
            )"
        );

        // Recalculate paid_amount from payments (excluding consignment)
        $wpdb->query(
            "UPDATE {$prefix}invoices i SET paid_amount = (
                SELECT COALESCE(SUM(p.amount), 0)
                FROM {$prefix}payments p
                WHERE p.invoice_id = i.id AND p.method != 'consignment'
            )"
        );

        $this->log( '  Totals recalculated.' );
    }

    // ── Helpers ──

    /**
     * Get all postmeta for a post with a given prefix, stripping the prefix from keys.
     */
    private function get_all_meta( $post_id, $prefix ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key LIKE %s",
            $post_id, $prefix . '%'
        ), ARRAY_A );

        $meta = [];
        foreach ( $rows as $row ) {
            $key = substr( $row['meta_key'], strlen( $prefix ) );
            $meta[ $key ] = $row['meta_value'];
        }

        return $meta;
    }

    private function log( $message ) {
        call_user_func( $this->log_callback, $message );
    }

    /**
     * Waterfall customer lookup: tax_id → email → phone → name → create new.
     * Used by create_customers_from_invoices() and relink_customers().
     */
    private function find_or_create_customer_waterfall( $buyer, $legacy_term_id = null ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'cig_customers';
        $name   = trim( $buyer['name']   ?? '' );
        $tax_id = trim( $buyer['tax_id'] ?? '' );
        $email  = trim( $buyer['email']  ?? '' );
        $phone  = trim( $buyer['phone']  ?? '' );

        // 1. by tax_id
        if ( $tax_id ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE tax_id = %s LIMIT 1", $tax_id
            ) );
            if ( $id ) return $id;
        }

        // 2. by email
        if ( $email ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE email = %s LIMIT 1", $email
            ) );
            if ( $id ) return $id;
        }

        // 3. by phone
        if ( $phone ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE phone = %s LIMIT 1", $phone
            ) );
            if ( $id ) return $id;
        }

        // 4. by name (case-insensitive)
        if ( $name ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE LOWER(name) = LOWER(%s) LIMIT 1", $name
            ) );
            if ( $id ) return $id;
        }

        // 5. create new
        if ( ! $name && ! $tax_id ) return null;

        $wpdb->insert( $table, [
            'legacy_term_id' => $legacy_term_id ?: null,
            'name'           => $name,
            'name_en'        => '',
            'tax_id'         => $tax_id,
            'address'        => trim( $buyer['address'] ?? '' ),
            'phone'          => $phone,
            'email'          => $email,
        ] );

        return $wpdb->insert_id ?: null;
    }

    /**
     * Re-link invoices that have customer_id = NULL by running waterfall match
     * against buyer_name / buyer_tax_id / buyer_phone / buyer_email.
     * Returns number of invoices re-linked.
     */
    public function relink_customers() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';
        $linked = 0;

        $invoices = $wpdb->get_results(
            "SELECT id, buyer_name, buyer_tax_id, buyer_phone, buyer_email, buyer_address
             FROM {$prefix}invoices
             WHERE customer_id IS NULL",
            ARRAY_A
        );

        foreach ( $invoices as $inv ) {
            if ( $this->dry_run ) {
                $linked++;
                continue;
            }

            $buyer = [
                'name'    => $inv['buyer_name'],
                'tax_id'  => $inv['buyer_tax_id'],
                'phone'   => $inv['buyer_phone'],
                'email'   => $inv['buyer_email'],
                'address' => $inv['buyer_address'],
            ];

            $customer_id = $this->find_or_create_customer_waterfall( $buyer );
            if ( $customer_id ) {
                $wpdb->update(
                    $prefix . 'invoices',
                    [ 'customer_id' => $customer_id ],
                    [ 'id' => (int) $inv['id'] ]
                );
                $linked++;
            }
        }

        return $linked;
    }

    /**
     * Fix column name mismatches for hybrid installs (old plugin activated over new tables).
     * Safe to run multiple times (idempotent WHERE conditions).
     */
    public function fix_column_renames() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        // wp_cig_invoice_items: product_name → name
        $wpdb->query(
            "UPDATE {$prefix}invoice_items
             SET name = product_name
             WHERE (name = '' OR name IS NULL) AND product_name IS NOT NULL AND product_name != ''"
        );

        // wp_cig_invoice_items: quantity → qty
        $wpdb->query(
            "UPDATE {$prefix}invoice_items
             SET qty = quantity
             WHERE qty IN (0, 1) AND quantity > 1"
        );

        // wp_cig_invoice_items: warranty_duration → warranty
        $wpdb->query(
            "UPDATE {$prefix}invoice_items
             SET warranty = warranty_duration
             WHERE (warranty = '' OR warranty IS NULL) AND warranty_duration IS NOT NULL AND warranty_duration != ''"
        );

        // wp_cig_invoice_items: image → image_url
        $wpdb->query(
            "UPDATE {$prefix}invoice_items
             SET image_url = image
             WHERE (image_url = '' OR image_url IS NULL) AND image IS NOT NULL AND image != ''"
        );

        // wp_cig_payments: date → payment_date
        $wpdb->query(
            "UPDATE {$prefix}payments
             SET payment_date = DATE(`date`)
             WHERE (payment_date IS NULL OR payment_date = '0000-00-00') AND `date` IS NOT NULL"
        );

        // Buyer fields from wp_postmeta (via legacy_post_id bridge)
        $buyer_fields = [
            'buyer_name'    => '_cig_buyer_name',
            'buyer_tax_id'  => '_cig_buyer_tax_id',
            'buyer_phone'   => '_cig_buyer_phone',
            'buyer_address' => '_cig_buyer_address',
            'buyer_email'   => '_cig_buyer_email',
        ];
        foreach ( $buyer_fields as $col => $meta_key ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$prefix}invoices i
                 INNER JOIN {$wpdb->postmeta} pm
                   ON pm.post_id = i.legacy_post_id AND pm.meta_key = %s
                 SET i.{$col} = pm.meta_value
                 WHERE (i.{$col} = '' OR i.{$col} IS NULL) AND i.legacy_post_id IS NOT NULL",
                $meta_key
            ) );
        }

        // Lifecycle value cleanup
        $wpdb->query( "UPDATE {$prefix}invoices SET lifecycle_status = 'draft'    WHERE lifecycle_status = 'unfinished'" );
        $wpdb->query( "UPDATE {$prefix}invoices SET lifecycle_status = 'sold'     WHERE lifecycle_status = 'completed'" );
        $wpdb->query( "UPDATE {$prefix}invoices SET lifecycle_status = 'reserved' WHERE lifecycle_status = 'active'" );
    }

    /**
     * Fix already-migrated reserved invoices that were incorrectly stored as 'active'.
     * Returns number of rows updated.
     */
    public function fix_reserved_lifecycle() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        if ( $this->dry_run ) {
            return (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}invoices WHERE lifecycle_status = 'active'"
            );
        }

        return (int) $wpdb->query(
            "UPDATE {$prefix}invoices SET lifecycle_status = 'reserved' WHERE lifecycle_status = 'active'"
        );
    }

    /**
     * Rollback — drop all custom tables and clear mappings.
     */
    public static function rollback() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        $tables = [
            'id_map', 'stock_requests', 'payments', 'invoice_items',
            'invoices', 'other_deliveries', 'deposits', 'customers',
            'products', 'users', 'company',
        ];

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
        }

        delete_option( 'cig_db_version' );
    }
}
