<?php
/**
 * CIG Importer — imports JSON export from old plugin into new custom tables.
 *
 * Steps:
 *   1. Users
 *   2. Customers
 *   3. Deposits
 *   4. Invoices (batched 100 at a time)
 */
class CIG_Importer {

    /** @var array [old_wp_user_id => new_cig_user_id] */
    private $user_map = [];

    /** @var array [old_customer_post_id => new_cig_customer_id] */
    private $customer_map = [];

    /** @var array Import options */
    private $options = [];

    /** @var array Accumulated results */
    private $results = [
        'users'     => [ 'inserted' => 0, 'skipped' => 0, 'errors' => [] ],
        'customers' => [ 'inserted' => 0, 'skipped' => 0, 'errors' => [] ],
        'deposits'  => [ 'inserted' => 0, 'skipped' => 0, 'errors' => [] ],
        'invoices'  => [ 'inserted' => 0, 'skipped' => 0, 'errors' => [] ],
    ];

    // Allowed payment methods in new system
    private const ALLOWED_METHODS = [
        'company_transfer', 'cash', 'consignment', 'credit', 'other', 'refund',
    ];

    // Accounting status → 4 boolean flags
    private const ACC_MAP = [
        'rs'        => [ 'is_rs_uploaded' => 1, 'is_credit_checked' => 0, 'is_receipt_checked' => 0, 'is_corrected' => 0 ],
        'corrected' => [ 'is_rs_uploaded' => 1, 'is_credit_checked' => 0, 'is_receipt_checked' => 0, 'is_corrected' => 1 ],
        'credit'    => [ 'is_rs_uploaded' => 1, 'is_credit_checked' => 1, 'is_receipt_checked' => 0, 'is_corrected' => 0 ],
        'receipt'   => [ 'is_rs_uploaded' => 1, 'is_credit_checked' => 0, 'is_receipt_checked' => 1, 'is_corrected' => 0 ],
    ];

    /**
     * Run the full import.
     *
     * @param array $data    Parsed JSON export (users, customers, deposits, invoices).
     * @param array $options { skip_duplicates, import_users, import_customers, import_deposits }
     * @return array Import results per entity.
     */
    public function run( array $data, array $options = [] ) {
        $this->options = wp_parse_args( $options, [
            'skip_duplicates'  => true,
            'import_users'     => true,
            'import_customers' => true,
            'import_deposits'  => true,
        ] );

        $start = microtime( true );

        if ( $this->options['import_users'] ) {
            $this->import_users( $data['users'] ?? [] );
        }
        if ( $this->options['import_customers'] ) {
            $this->import_customers( $data['customers'] ?? [] );
        }
        if ( $this->options['import_deposits'] ) {
            $this->import_deposits( $data['deposits'] ?? [] );
        }

        $this->import_invoices( $data['invoices'] ?? [] );

        // Always re-link customers and products after import
        $relink = self::relink();
        $this->results['relink'] = $relink;

        $duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        // Persist last import log
        $log = [
            'results'     => $this->results,
            'duration_ms' => $duration_ms,
            'imported_at' => date( 'c' ),
        ];
        update_option( 'cig_last_import_log', $log, false );

        return $log;
    }

    // ── Step 1: Users ─────────────────────────────────────────────────────────

    private function import_users( array $users ) {
        foreach ( $users as $u ) {
            try {
                $old_wp_id = (int) ( $u['wp_user_id'] ?? 0 );

                // Try to find WP user on new site
                $wp_user = get_user_by( 'login', $u['login'] ?? '' );
                if ( ! $wp_user && ! empty( $u['email'] ) ) {
                    $wp_user = get_user_by( 'email', $u['email'] );
                }

                if ( $wp_user ) {
                    // Find or create CIG user linked to this WP user
                    $cig_user = CIG_User::find_by_wp_user( $wp_user->ID );
                    if ( ! $cig_user ) {
                        $cig_user = $this->create_cig_user_from_wp( $wp_user, $u );
                    }
                } else {
                    // WP user not found — create CIG user with wp_user_id = NULL
                    $cig_user = $this->find_cig_user_by_display( $u );
                    if ( ! $cig_user ) {
                        $cig_user = $this->create_cig_user_orphan( $u );
                    }
                }

                if ( $cig_user && $old_wp_id ) {
                    $this->user_map[ $old_wp_id ] = (int) $cig_user['id'];
                    $this->results['users']['inserted']++;
                }
            } catch ( Exception $e ) {
                $this->results['users']['errors'][] = 'User ' . ( $u['login'] ?? '?' ) . ': ' . $e->getMessage();
            }
        }
    }

    private function create_cig_user_from_wp( $wp_user, array $u ) {
        global $wpdb;
        $role = $this->map_wp_role( $u['wp_role'] ?? 'subscriber' );
        $name_en = $u['display_name'] ?? $wp_user->display_name;
        $avatar  = $this->initials( $name_en );
        $wpdb->insert( $wpdb->prefix . 'cig_users', [
            'wp_user_id' => $wp_user->ID,
            'name'       => $name_en,
            'name_en'    => $name_en,
            'avatar'     => $avatar,
            'role'       => $role,
            'is_active'  => 1,
        ] );
        $id = $wpdb->insert_id;
        return $id ? CIG_User::find( $id ) : null;
    }

    private function create_cig_user_orphan( array $u ) {
        global $wpdb;
        $role    = $this->map_wp_role( $u['wp_role'] ?? 'subscriber' );
        $name_en = $u['display_name'] ?? $u['login'] ?? 'Unknown';
        $avatar  = $this->initials( $name_en );
        $wpdb->insert( $wpdb->prefix . 'cig_users', [
            'wp_user_id' => null,
            'name'       => $name_en,
            'name_en'    => $name_en,
            'avatar'     => $avatar,
            'role'       => $role,
            'is_active'  => 1,
        ] );
        $id = $wpdb->insert_id;
        return $id ? CIG_User::find( $id ) : null;
    }

    private function find_cig_user_by_display( array $u ) {
        global $wpdb;
        $name = $u['display_name'] ?? '';
        if ( empty( $name ) ) return null;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cig_users WHERE name_en = %s LIMIT 1",
                $name
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    private function map_wp_role( $wp_role ) {
        $map = [
            'administrator' => 'admin',
            'editor'        => 'manager',
            'shop_manager'  => 'manager',
            'author'        => 'sales',
            'contributor'   => 'accountant',
            'subscriber'    => 'accountant',
        ];
        return $map[ $wp_role ] ?? 'sales';
    }

    private function initials( $name ) {
        $parts = explode( ' ', trim( $name ) );
        $out   = '';
        foreach ( array_slice( $parts, 0, 2 ) as $p ) {
            if ( ! empty( $p ) ) $out .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
        }
        return $out ?: 'GN';
    }

    // ── Step 2: Customers ─────────────────────────────────────────────────────

    private function import_customers( array $customers ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cig_customers';

        foreach ( $customers as $c ) {
            try {
                $legacy_id = (int) ( $c['legacy_post_id'] ?? 0 );
                $new_id    = $this->find_or_create_customer( $c, $table );
                if ( $new_id ) {
                    $this->customer_map[ $legacy_id ] = $new_id;
                    $this->results['customers']['inserted']++;
                }
            } catch ( Exception $e ) {
                $this->results['customers']['errors'][] = 'Customer ' . ( $c['name'] ?? '?' ) . ': ' . $e->getMessage();
            }
        }
    }

    private function find_or_create_customer( array $c, $table ) {
        global $wpdb;

        // Waterfall dedup: tax_id → phone → name
        $existing_id = null;

        if ( ! empty( $c['tax_id'] ) ) {
            $existing_id = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE tax_id = %s LIMIT 1", $c['tax_id'] )
            );
        }
        if ( ! $existing_id && ! empty( $c['phone'] ) ) {
            $existing_id = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE phone = %s LIMIT 1", $c['phone'] )
            );
        }
        if ( ! $existing_id && ! empty( $c['name'] ) ) {
            $existing_id = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s LIMIT 1", $c['name'] )
            );
        }

        if ( $existing_id ) {
            return $existing_id;
        }

        // Insert new customer
        $wpdb->insert( $table, [
            'legacy_post_id' => (int) ( $c['legacy_post_id'] ?? 0 ) ?: null,
            'name'           => $c['name'] ?? '',
            'name_en'        => $c['name_en'] ?? '',
            'tax_id'         => $c['tax_id'] ?? '',
            'phone'          => $c['phone'] ?? '',
            'email'          => $c['email'] ?? '',
            'address'        => $c['address'] ?? '',
        ] );

        return $wpdb->insert_id ?: null;
    }

    // ── Step 3: Deposits ──────────────────────────────────────────────────────

    private function import_deposits( array $deposits ) {
        global $wpdb;
        $table     = $wpdb->prefix . 'cig_deposits';
        $id_table  = $wpdb->prefix . 'cig_id_map';

        foreach ( $deposits as $d ) {
            try {
                $legacy_id = (int) ( $d['legacy_post_id'] ?? 0 );

                // Check if already imported
                if ( $this->options['skip_duplicates'] && $legacy_id ) {
                    $exists = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT new_id FROM {$id_table} WHERE entity_type = 'deposit' AND legacy_id = %d LIMIT 1",
                            $legacy_id
                        )
                    );
                    if ( $exists ) {
                        $this->results['deposits']['skipped']++;
                        continue;
                    }
                }

                $deposit_date = $d['deposit_date'] ?? '';
                if ( empty( $deposit_date ) ) $deposit_date = date( 'Y-m-d' );

                $wpdb->insert( $table, [
                    'deposit_date' => $deposit_date,
                    'amount'       => (float) ( $d['amount'] ?? 0 ),
                    'type'         => 'credit',
                    'note'         => $d['note'] ?? '',
                ] );
                $new_id = $wpdb->insert_id;

                if ( $new_id && $legacy_id ) {
                    $wpdb->insert( $id_table, [
                        'entity_type' => 'deposit',
                        'legacy_id'   => $legacy_id,
                        'new_id'      => $new_id,
                    ] );
                }

                $this->results['deposits']['inserted']++;
            } catch ( Exception $e ) {
                $this->results['deposits']['errors'][] = 'Deposit #' . ( $d['legacy_post_id'] ?? '?' ) . ': ' . $e->getMessage();
            }
        }
    }

    // ── Step 4: Invoices ──────────────────────────────────────────────────────

    private function import_invoices( array $invoices ) {
        $batch_size = 100;
        $batches    = array_chunk( $invoices, $batch_size );

        foreach ( $batches as $batch ) {
            foreach ( $batch as $inv ) {
                $this->import_single_invoice( $inv );
            }
        }
    }

    private function import_single_invoice( array $inv ) {
        global $wpdb;
        $inv_table  = $wpdb->prefix . 'cig_invoices';
        $item_table = $wpdb->prefix . 'cig_invoice_items';
        $pay_table  = $wpdb->prefix . 'cig_payments';
        $id_table   = $wpdb->prefix . 'cig_id_map';

        $legacy_id     = (int) ( $inv['legacy_post_id'] ?? 0 );
        $inv_number    = trim( $inv['invoice_number'] ?? '' );

        // Duplicate check by invoice_number
        if ( ! empty( $inv_number ) && $this->options['skip_duplicates'] ) {
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$inv_table} WHERE invoice_number = %s LIMIT 1", $inv_number )
            );
            if ( $exists ) {
                $this->results['invoices']['skipped']++;
                return;
            }
        }

        // Author mapping
        $old_author_id = (int) ( $inv['author_wp_user_id'] ?? 0 );
        $author_id     = $old_author_id && isset( $this->user_map[ $old_author_id ] )
                         ? $this->user_map[ $old_author_id ]
                         : null;

        // Customer mapping
        $customer_post_id = (int) ( $inv['customer_post_id'] ?? 0 );
        $customer_id      = null;
        if ( $customer_post_id && isset( $this->customer_map[ $customer_post_id ] ) ) {
            $customer_id = $this->customer_map[ $customer_post_id ];
        }
        // Fallback waterfall by buyer info
        if ( ! $customer_id ) {
            $customer_id = $this->find_customer_by_buyer( $inv );
        }

        // Lifecycle mapping
        $raw_lifecycle = strtolower( trim( $inv['lifecycle_status'] ?? 'draft' ) );
        $lifecycle_map = [
            'unfinished' => 'draft',
            'draft'      => 'draft',
            'reserved'   => 'reserved',
            'completed'  => 'sold',
            'sold'       => 'sold',
            'canceled'   => 'canceled',
            'cancelled'  => 'canceled',
            'active'     => 'reserved',
        ];
        $lifecycle = $lifecycle_map[ $raw_lifecycle ] ?? 'draft';

        // Accounting flags
        $acc_status = strtolower( trim( $inv['acc_status'] ?? '' ) );
        $acc_flags  = self::ACC_MAP[ $acc_status ] ?? [
            'is_rs_uploaded'     => 0,
            'is_credit_checked'  => 0,
            'is_receipt_checked' => 0,
            'is_corrected'       => 0,
        ];

        // Invoice status
        $status = in_array( $inv['invoice_status'] ?? 'standard', [ 'standard', 'fictive' ], true )
                  ? $inv['invoice_status']
                  : 'standard';

        // Created date
        $post_date = $inv['post_date'] ?? '';
        $created_at = $post_date ? substr( $post_date, 0, 10 ) : date( 'Y-m-d' );

        // Sold date
        $sold_date = ! empty( $inv['sold_date'] ) ? $inv['sold_date'] : null;

        // Insert invoice
        $inserted = $wpdb->insert( $inv_table, [
            'legacy_post_id'     => $legacy_id ?: null,
            'invoice_number'     => $inv_number,
            'customer_id'        => $customer_id,
            'author_id'          => $author_id,
            'status'             => $status,
            'lifecycle_status'   => $lifecycle,
            'total_amount'       => (float) ( $inv['invoice_total'] ?? 0 ),
            'paid_amount'        => 0, // will recalculate after payments
            'created_at'         => $created_at,
            'sold_date'          => $sold_date,
            'buyer_name'         => $inv['buyer_name'] ?? '',
            'buyer_tax_id'       => $inv['buyer_tax_id'] ?? '',
            'buyer_phone'        => $inv['buyer_phone'] ?? '',
            'buyer_address'      => $inv['buyer_address'] ?? '',
            'buyer_email'        => $inv['buyer_email'] ?? '',
            'general_note'       => $inv['general_note'] ?? '',
            'consultant_note'    => $inv['consultant_note'] ?? '',
            'accountant_note'    => $inv['accountant_note'] ?? '',
            'is_rs_uploaded'     => $acc_flags['is_rs_uploaded'],
            'is_credit_checked'  => $acc_flags['is_credit_checked'],
            'is_receipt_checked' => $acc_flags['is_receipt_checked'],
            'is_corrected'       => $acc_flags['is_corrected'],
        ] );

        if ( ! $inserted ) {
            $this->results['invoices']['errors'][] = '#' . $inv_number . ': insert failed — ' . $wpdb->last_error;
            return;
        }

        $new_inv_id = $wpdb->insert_id;

        // Items
        $sort = 0;
        foreach ( $inv['items'] ?? [] as $item ) {
            $item_status = $item['item_status'] ?? ( $item['status'] ?? 'none' );
            $allowed_item_statuses = [ 'none', 'reserved', 'canceled', 'sold' ];
            if ( ! in_array( $item_status, $allowed_item_statuses, true ) ) {
                $item_status = 'none';
            }
            // Try to link product by SKU (same across sites)
            $item_sku   = trim( $item['sku'] ?? '' );
            $product_id = null;
            if ( $item_sku ) {
                $product_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}cig_products WHERE sku = %s LIMIT 1",
                        $item_sku
                    )
                ) ?: null;
            }

            $wpdb->insert( $item_table, [
                'invoice_id'       => $new_inv_id,
                'sort_order'       => $sort++,
                'product_id'       => $product_id,
                'legacy_product_id'=> isset( $item['product_id'] ) ? (int) $item['product_id'] : null,
                'name'             => $item['name'] ?? '',
                'brand'            => $item['brand'] ?? '',
                'sku'              => $item['sku'] ?? '',
                'description'      => $item['description'] ?? '',
                'image_url'        => $item['image_url'] ?? '',
                'qty'              => (float) ( $item['qty'] ?? 1 ),
                'price'            => (float) ( $item['price'] ?? 0 ),
                'total'            => (float) ( $item['total'] ?? 0 ),
                'item_status'      => $item_status,
                'reservation_days' => (int) ( $item['reservation_days'] ?? 0 ),
                'warranty'         => $item['warranty'] ?? '',
            ] );
        }

        // Payments
        foreach ( $inv['payments'] ?? [] as $pay ) {
            $method = $pay['method'] ?? 'cash';
            if ( ! in_array( $method, self::ALLOWED_METHODS, true ) ) {
                $method = 'other';
            }

            // Normalise date: handles Y-m-d, Y-m-d H:i:s, d/m/Y, Unix timestamp
            $pay_date_raw = $pay['date'] ?? ( $pay['payment_date'] ?? '' );
            $payment_date = date( 'Y-m-d' ); // fallback to today
            if ( ! empty( $pay_date_raw ) && $pay_date_raw !== '0000-00-00' ) {
                // Unix timestamp (integer or numeric string)
                if ( is_numeric( $pay_date_raw ) ) {
                    $payment_date = date( 'Y-m-d', (int) $pay_date_raw );
                } else {
                    $ts = strtotime( $pay_date_raw );
                    if ( ! $ts ) {
                        // Try d/m/Y format (Georgian / European)
                        if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})#', $pay_date_raw, $m ) ) {
                            $ts = mktime( 0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3] );
                        }
                    }
                    if ( $ts && $ts > 0 ) {
                        $payment_date = date( 'Y-m-d', $ts );
                    }
                }
            }

            // Map old user_id to new cig_user_id
            $old_pay_user  = (int) ( $pay['user_id'] ?? 0 );
            $pay_user_id   = $old_pay_user && isset( $this->user_map[ $old_pay_user ] )
                             ? $this->user_map[ $old_pay_user ]
                             : null;

            $wpdb->insert( $pay_table, [
                'invoice_id'   => $new_inv_id,
                'payment_date' => $payment_date,
                'amount'       => (float) ( $pay['amount'] ?? 0 ),
                'method'       => $method,
                'comment'      => $pay['comment'] ?? '',
                'user_id'      => $pay_user_id,
            ] );
        }

        // Recalculate paid_amount from actual payment rows
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$inv_table}
             SET paid_amount = (
                 SELECT COALESCE(SUM(amount), 0)
                 FROM {$pay_table}
                 WHERE invoice_id = %d
             )
             WHERE id = %d",
            $new_inv_id,
            $new_inv_id
        ) );

        // Record in id_map for idempotence
        if ( $legacy_id ) {
            $wpdb->insert( $id_table, [
                'entity_type' => 'invoice',
                'legacy_id'   => $legacy_id,
                'new_id'      => $new_inv_id,
            ] );
        }

        $this->results['invoices']['inserted']++;
    }

    /**
     * Waterfall customer lookup by buyer info on the invoice itself.
     */
    private function find_customer_by_buyer( array $inv ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cig_customers';

        if ( ! empty( $inv['buyer_tax_id'] ) ) {
            $id = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE tax_id = %s LIMIT 1", $inv['buyer_tax_id'] )
            );
            if ( $id ) return $id;
        }
        if ( ! empty( $inv['buyer_phone'] ) ) {
            $id = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE phone = %s LIMIT 1", $inv['buyer_phone'] )
            );
            if ( $id ) return $id;
        }
        if ( ! empty( $inv['buyer_name'] ) ) {
            $id = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s LIMIT 1", $inv['buyer_name'] )
            );
            if ( $id ) return $id;
        }
        return null;
    }

    // ── Re-link (fix customer_id + product_id on existing rows) ─────────────

    /**
     * Batch-update customer_id on invoices and product_id on items.
     * Safe to run multiple times (WHERE customer_id IS NULL / product_id IS NULL).
     *
     * @return array { invoices_by_tax_id, invoices_by_phone, invoices_by_name, items_by_sku }
     */
    public static function relink() {
        global $wpdb;

        $inv_table  = $wpdb->prefix . 'cig_invoices';
        $cust_table = $wpdb->prefix . 'cig_customers';
        $item_table = $wpdb->prefix . 'cig_invoice_items';
        $prod_table = $wpdb->prefix . 'cig_products';
        $pay_table  = $wpdb->prefix . 'cig_payments';

        // ── Customers: tax_id (most reliable) ──
        $by_tax = $wpdb->query(
            "UPDATE {$inv_table} i
             INNER JOIN {$cust_table} c ON c.tax_id = i.buyer_tax_id
                 AND i.buyer_tax_id != ''
             SET i.customer_id = c.id
             WHERE i.customer_id IS NULL"
        );

        // ── Customers: phone fallback ──
        $by_phone = $wpdb->query(
            "UPDATE {$inv_table} i
             INNER JOIN {$cust_table} c ON c.phone = i.buyer_phone
                 AND i.buyer_phone != ''
             SET i.customer_id = c.id
             WHERE i.customer_id IS NULL"
        );

        // ── Customers: name fallback ──
        $by_name = $wpdb->query(
            "UPDATE {$inv_table} i
             INNER JOIN {$cust_table} c ON c.name = i.buyer_name
                 AND i.buyer_name != ''
             SET i.customer_id = c.id
             WHERE i.customer_id IS NULL"
        );

        // ── Products: cig_products SKU match ──
        $by_sku = $wpdb->query(
            "UPDATE {$item_table} ii
             INNER JOIN {$prod_table} p ON p.sku = ii.sku
                 AND ii.sku != ''
             SET ii.product_id = p.id
             WHERE ii.product_id IS NULL"
        );

        // ── Products: WooCommerce SKU match (wp_postmeta._sku) ──
        $by_wc_sku = $wpdb->query(
            "UPDATE {$item_table} ii
             INNER JOIN {$wpdb->postmeta} pm ON pm.meta_value = ii.sku
                 AND pm.meta_key = '_sku' AND ii.sku != ''
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 AND p.post_type IN ('product','product_variation')
             SET ii.product_id = pm.post_id
             WHERE ii.product_id IS NULL"
        );

        // ── Backfill name/brand from WC products (wp_posts.post_title) ──
        $wpdb->query(
            "UPDATE {$item_table} ii
             INNER JOIN {$wpdb->posts} p ON p.ID = ii.product_id
                 AND p.post_type IN ('product','product_variation')
             SET ii.name = CASE WHEN (ii.name IS NULL OR ii.name = '') THEN p.post_title ELSE ii.name END
             WHERE ii.product_id IS NOT NULL AND (ii.name IS NULL OR ii.name = '')"
        );

        // ── Fix zero dates (0000-00-00) in payments — use invoice created_at ──
        $wpdb->query(
            "UPDATE {$pay_table} p
             INNER JOIN {$inv_table} i ON i.id = p.invoice_id
             SET p.payment_date = i.created_at
             WHERE p.payment_date = '0000-00-00' OR p.payment_date IS NULL"
        );

        // ── Backfill item name/brand/image_url from linked products ──
        $names_backfilled = $wpdb->query(
            "UPDATE {$item_table} ii
             INNER JOIN {$prod_table} p ON p.id = ii.product_id
             SET
                 ii.name      = CASE WHEN (ii.name      IS NULL OR ii.name      = '') THEN p.name      ELSE ii.name      END,
                 ii.brand     = CASE WHEN (ii.brand     IS NULL OR ii.brand     = '') THEN p.brand     ELSE ii.brand     END,
                 ii.image_url = CASE WHEN (ii.image_url IS NULL OR ii.image_url = '') THEN p.image_url ELSE ii.image_url END
             WHERE ii.product_id IS NOT NULL
               AND (ii.name IS NULL OR ii.name = '' OR ii.brand IS NULL OR ii.brand = '')"
        );

        return [
            'invoices_linked_by_tax_id'  => (int) $by_tax,
            'invoices_linked_by_phone'   => (int) $by_phone,
            'invoices_linked_by_name'    => (int) $by_name,
            'items_linked_by_sku'        => (int) $by_sku,
            'items_linked_by_wc_sku'     => (int) $by_wc_sku,
            'items_names_backfilled'     => (int) $names_backfilled,
        ];
    }

    // ── Preview (no DB writes) ────────────────────────────────────────────────

    /**
     * Analyse the export data and return counts + issues without writing to DB.
     *
     * @param array $data Parsed JSON export.
     * @return array { counts, issues }
     */
    public static function preview( array $data ) {
        global $wpdb;
        $inv_table = $wpdb->prefix . 'cig_invoices';

        $counts = [
            'invoices'  => count( $data['invoices'] ?? [] ),
            'customers' => count( $data['customers'] ?? [] ),
            'deposits'  => count( $data['deposits'] ?? [] ),
            'users'     => count( $data['users'] ?? [] ),
        ];

        $issues = [];

        // Duplicate invoice numbers
        $dup_numbers = [];
        $missing_buyer = 0;
        $unknown_lifecycle = 0;
        $already_imported  = 0;
        $known_lifecycles  = [ 'draft', 'reserved', 'completed', 'sold', 'canceled', 'cancelled', 'unfinished', 'active' ];

        foreach ( $data['invoices'] ?? [] as $inv ) {
            $num = trim( $inv['invoice_number'] ?? '' );
            if ( empty( trim( $inv['buyer_name'] ?? '' ) ) ) {
                $missing_buyer++;
            }
            $lc = strtolower( trim( $inv['lifecycle_status'] ?? '' ) );
            if ( ! empty( $lc ) && ! in_array( $lc, $known_lifecycles, true ) ) {
                $unknown_lifecycle++;
            }
            if ( ! empty( $num ) ) {
                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare( "SELECT id FROM {$inv_table} WHERE invoice_number = %s LIMIT 1", $num )
                );
                if ( $exists ) {
                    $already_imported++;
                    $dup_numbers[] = $num;
                }
            }
        }

        if ( ! empty( $dup_numbers ) ) {
            $issues[] = [
                'key'      => 'duplicate_invoice_numbers',
                'count'    => count( $dup_numbers ),
                'examples' => array_slice( $dup_numbers, 0, 5 ),
                'label'    => 'Invoice numbers already in DB (will skip)',
            ];
        }
        if ( $missing_buyer > 0 ) {
            $issues[] = [
                'key'   => 'missing_buyer_name',
                'count' => $missing_buyer,
                'label' => 'Invoices missing buyer name',
            ];
        }
        if ( $unknown_lifecycle > 0 ) {
            $issues[] = [
                'key'   => 'unknown_lifecycle',
                'count' => $unknown_lifecycle,
                'label' => 'Invoices with unknown lifecycle (will import as draft)',
            ];
        }
        if ( $already_imported > 0 ) {
            $issues[] = [
                'key'   => 'already_imported',
                'count' => $already_imported,
                'label' => 'Invoices already in DB (will skip if skip_duplicates enabled)',
            ];
        }

        return compact( 'counts', 'issues' );
    }
}
