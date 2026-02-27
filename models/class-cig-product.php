<?php
/**
 * Product model — reads from WooCommerce when available, falls back to custom table.
 * Stores CIG-specific fields (reserved, nameKa) as WooCommerce product meta.
 */
class CIG_Product {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cig_products';
    }

    /**
     * Check if WooCommerce is active and has products.
     */
    private static function use_woocommerce() {
        return function_exists( 'wc_get_products' );
    }

    // ── Find ──────────────────────────────────────

    public static function find( $id ) {
        if ( self::use_woocommerce() ) {
            return self::find_wc( $id );
        }
        return self::find_table( $id );
    }

    private static function find_wc( $id ) {
        $wc = wc_get_product( $id );
        if ( ! $wc || ! $wc->exists() ) return null;
        $product = self::hydrate_wc( $wc );
        $counts  = self::batch_reserved_counts( [ $product['id'] ] );
        $product['reserved'] = $counts[ $product['id'] ] ?? 0;
        return $product;
    }

    private static function find_table( $id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $row ) return null;
        $product = self::hydrate( $row );
        $counts  = self::batch_reserved_counts( [ $product['id'] ] );
        $product['reserved'] = $counts[ $product['id'] ] ?? 0;
        return $product;
    }

    // ── List ──────────────────────────────────────

    public static function list( $args = [] ) {
        if ( self::use_woocommerce() ) {
            return self::list_wc( $args );
        }
        return self::list_table( $args );
    }

    private static function list_wc( $args = [] ) {
        $defaults = [
            'search'   => '',
            'category' => '',
            'sort'     => 'name',
            'order'    => 'ASC',
            'page'     => 1,
            'per_page' => 100,
        ];
        $args = wp_parse_args( $args, $defaults );

        // Map CIG sort fields to WooCommerce orderby
        $sort_map = [
            'name'  => 'title',
            'price' => 'price',
            'stock' => 'title', // WC doesn't support stock sort natively
            'sku'   => 'title', // WC doesn't support SKU sort natively
            'id'    => 'ID',
        ];
        $orderby = isset( $sort_map[ $args['sort'] ] ) ? $sort_map[ $args['sort'] ] : 'title';

        $wc_args = [
            'status'   => 'publish',
            'limit'    => (int) $args['per_page'],
            'page'     => (int) $args['page'],
            'orderby'  => $orderby,
            'order'    => strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC',
            'return'   => 'objects',
        ];

        if ( ! empty( $args['search'] ) ) {
            $wc_args['s'] = $args['search'];
        }

        if ( ! empty( $args['category'] ) ) {
            $wc_args['category'] = [ $args['category'] ];
        }

        $products = wc_get_products( $wc_args );

        // Batch-prime WordPress meta and term caches — converts 4N queries into 2 queries.
        // Each hydrate_wc() call hits the in-process cache instead of the DB.
        if ( ! empty( $products ) ) {
            $ids = array_map( fn( $p ) => $p->get_id(), $products );
            update_meta_cache( 'post', $ids );
            update_object_term_cache( $ids, 'product' );
        }

        // Get total count via SQL COUNT (avoids loading all IDs into PHP memory)
        $count_args            = $wc_args;
        $count_args['paginate'] = true;
        $count_args['limit']   = 1;
        $count_args['page']    = 1;
        unset( $count_args['return'] );
        $count_result = wc_get_products( $count_args );
        $total        = $count_result->total;

        $per_page = max( 1, (int) $args['per_page'] );

        $hydrated = array_map( [ __CLASS__, 'hydrate_wc' ], $products );

        // Overlay real reserved counts from invoice items (overrides stale meta).
        if ( ! empty( $hydrated ) ) {
            $ids    = array_column( $hydrated, 'id' );
            $counts = self::batch_reserved_counts( $ids );
            foreach ( $hydrated as &$p ) {
                $p['reserved'] = $counts[ $p['id'] ] ?? 0;
            }
            unset( $p );
        }

        return [
            'data'     => $hydrated,
            'total'    => $total,
            'page'     => (int) $args['page'],
            'per_page' => $per_page,
            'pages'    => $per_page > 0 ? ceil( $total / $per_page ) : 1,
        ];
    }

    private static function list_table( $args = [] ) {
        global $wpdb;

        $defaults = [
            'search'   => '',
            'category' => '',
            'sort'     => 'name',
            'order'    => 'ASC',
            'page'     => 1,
            'per_page' => 100,
        ];
        $args = wp_parse_args( $args, $defaults );

        $table = self::table();
        $where = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(name LIKE %s OR name_ka LIKE %s OR sku LIKE %s OR brand LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if ( ! empty( $args['category'] ) ) {
            $where[] = 'category = %s';
            $params[] = $args['category'];
        }

        $where_sql = implode( ' AND ', $where );

        $allowed_sorts = [ 'name', 'sku', 'price', 'stock', 'brand', 'category', 'id' ];
        $sort  = in_array( $args['sort'], $allowed_sorts, true ) ? $args['sort'] : 'name';
        $order = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        if ( ! empty( $params ) ) {
            $count_sql = $wpdb->prepare( $count_sql, ...$params );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        $offset = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
        $limit  = (int) $args['per_page'];

        $query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$sort} {$order} LIMIT %d OFFSET %d";
        $query_params = array_merge( $params, [ $limit, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $query, ...$query_params ), ARRAY_A );

        $products = array_map( [ __CLASS__, 'hydrate' ], $rows );

        // Overlay real reserved counts from invoice items (overrides stale column value).
        if ( ! empty( $products ) ) {
            $ids    = array_column( $products, 'id' );
            $counts = self::batch_reserved_counts( $ids );
            foreach ( $products as &$p ) {
                $p['reserved'] = $counts[ $p['id'] ] ?? 0;
            }
            unset( $p );
        }

        return [
            'data'     => $products,
            'total'    => $total,
            'page'     => (int) $args['page'],
            'per_page' => $limit,
            'pages'    => $limit > 0 ? ceil( $total / $limit ) : 1,
        ];
    }

    // ── Create / Update / Delete ──────────────────

    public static function create( $data ) {
        if ( self::use_woocommerce() ) {
            return self::create_wc( $data );
        }
        return self::create_table( $data );
    }

    public static function update( $id, $data ) {
        if ( self::use_woocommerce() ) {
            return self::update_wc( $id, $data );
        }
        return self::update_table( $id, $data );
    }

    public static function delete( $id ) {
        if ( self::use_woocommerce() ) {
            $wc = wc_get_product( $id );
            if ( $wc ) {
                $wc->delete( true );
                return true;
            }
            return false;
        }
        global $wpdb;
        return $wpdb->delete( self::table(), [ 'id' => $id ] );
    }

    // ── WooCommerce CRUD ──────────────────────────

    private static function create_wc( $data ) {
        $product = new WC_Product_Simple();
        self::apply_wc_fields( $product, $data );
        $id = $product->save();
        if ( ! $id ) {
            return new WP_Error( 'cig_create_failed', 'Failed to create product.', [ 'status' => 500 ] );
        }
        return self::find_wc( $id );
    }

    private static function update_wc( $id, $data ) {
        $product = wc_get_product( $id );
        if ( ! $product ) {
            return new WP_Error( 'cig_not_found', 'Product not found.', [ 'status' => 404 ] );
        }
        self::apply_wc_fields( $product, $data );
        $product->save();
        return self::find_wc( $id );
    }

    private static function apply_wc_fields( $product, $data ) {
        if ( isset( $data['name'] ) )        $product->set_name( $data['name'] );
        if ( isset( $data['sku'] ) )         $product->set_sku( $data['sku'] );
        if ( isset( $data['price'] ) )       { $product->set_regular_price( $data['price'] ); $product->set_price( $data['price'] ); }
        if ( isset( $data['description'] ) ) $product->set_short_description( $data['description'] );
        if ( isset( $data['stock'] ) )       { $product->set_manage_stock( true ); $product->set_stock_quantity( (int) $data['stock'] ); }

        // CIG-specific meta stored on the WC product
        $id = $product->get_id();
        if ( $id ) {
            if ( isset( $data['nameKa'] ) || isset( $data['name_ka'] ) ) {
                update_post_meta( $id, '_cig_name_ka', $data['nameKa'] ?? $data['name_ka'] );
            }
            if ( isset( $data['reserved'] ) ) {
                update_post_meta( $id, '_cig_reserved', (int) $data['reserved'] );
            }
        }
    }

    // ── Table CRUD (fallback) ─────────────────────

    private static function create_table( $data ) {
        global $wpdb;
        $fields = self::extract_fields( $data );
        $wpdb->insert( self::table(), $fields );
        $id = $wpdb->insert_id;
        if ( ! $id ) {
            return new WP_Error( 'cig_create_failed', 'Failed to create product.', [ 'status' => 500 ] );
        }
        return self::find_table( $id );
    }

    private static function update_table( $id, $data ) {
        global $wpdb;
        $fields = self::extract_fields( $data );
        $wpdb->update( self::table(), $fields, [ 'id' => $id ] );
        return self::find_table( $id );
    }

    // ── Hydration ─────────────────────────────────

    /**
     * Return a map of product_id => reserved_qty by counting invoice items
     * that are currently in 'reserved' status across all standard invoices.
     * One query for any number of products.
     *
     * @param  int[] $product_ids
     * @return array<int,int>
     */
    private static function batch_reserved_counts( array $product_ids ) {
        if ( empty( $product_ids ) ) {
            return [];
        }

        global $wpdb;
        $items_table = $wpdb->prefix . 'cig_invoice_items';
        $inv_table   = $wpdb->prefix . 'cig_invoices';
        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ii.product_id, COALESCE(SUM(ii.qty), 0) AS reserved_qty
                 FROM {$items_table} ii
                 INNER JOIN {$inv_table} i ON ii.invoice_id = i.id
                 WHERE ii.item_status = 'reserved'
                   AND i.status = 'standard'
                   AND ii.product_id IN ({$placeholders})
                 GROUP BY ii.product_id",
                ...$product_ids
            ),
            ARRAY_A
        );

        $map = [];
        foreach ( $rows as $row ) {
            $map[ (int) $row['product_id'] ] = (int) $row['reserved_qty'];
        }
        return $map;
    }

    /**
     * Hydrate a WooCommerce product object into the CIG format.
     */
    private static function hydrate_wc( $wc ) {
        $id = $wc->get_id();

        // Get brand from attribute or meta
        $brand = '';
        $brand_attr = $wc->get_attribute( 'brand' );
        if ( $brand_attr ) {
            $brand = $brand_attr;
        } else {
            $brand = get_post_meta( $id, '_cig_brand', true ) ?: '';
        }

        // Get category names
        $categories = wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'names' ] );
        $category = ! empty( $categories ) && ! is_wp_error( $categories ) ? $categories[0] : '';

        // Get image URL
        $image_id  = $wc->get_image_id();
        $image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';

        return [
            'id'          => $id,
            'sku'         => $wc->get_sku() ?: 'N/A',
            'name'        => $wc->get_name(),
            'nameKa'      => get_post_meta( $id, '_cig_name_ka', true ) ?: '',
            'brand'       => $brand,
            'description' => $wc->get_short_description() ?: wp_trim_words( $wc->get_description(), 30 ),
            'imageUrl'    => $image_url,
            'price'       => (float) $wc->get_price(),
            'stock'       => (int) ( $wc->get_stock_quantity() ?? 0 ),
            'reserved'    => (int) get_post_meta( $id, '_cig_reserved', true ),
            'category'    => $category,
            'isActive'    => $wc->get_status() === 'publish',
        ];
    }

    /**
     * Hydrate a custom table row into the CIG format.
     */
    private static function hydrate( $row ) {
        return [
            'id'          => (int) $row['id'],
            'sku'         => $row['sku'],
            'name'        => $row['name'],
            'nameKa'      => $row['name_ka'],
            'brand'       => $row['brand'],
            'description' => $row['description'] ?? '',
            'imageUrl'    => $row['image_url'],
            'price'       => (float) $row['price'],
            'stock'       => (int) $row['stock'],
            'reserved'    => (int) $row['reserved'],
            'category'    => $row['category'],
            'isActive'    => (bool) $row['is_active'],
        ];
    }

    /**
     * Atomically adjust a product's physical stock by $delta.
     * Positive delta = restore to stock; negative = consume from stock.
     */
    public static function adjust_stock( int $id, int $delta ) {
        if ( $delta === 0 || ! $id ) return;

        if ( self::use_woocommerce() ) {
            if ( $delta > 0 ) {
                wc_update_product_stock( $id, $delta, 'increase' );
            } else {
                wc_update_product_stock( $id, abs( $delta ), 'decrease' );
            }
            // Ensure manage_stock is on (idempotent, cheap)
            $wc = wc_get_product( $id );
            if ( $wc && ! $wc->get_manage_stock() ) {
                $wc->set_manage_stock( true );
                $wc->save();
            }
            // Out-of-stock notification when stock hits 0 after consuming
            if ( $delta < 0 ) {
                $new_stock = (int) ( wc_get_product( $id )?->get_stock_quantity() ?? 0 );
                if ( $new_stock <= 0 ) {
                    $product_name = wc_get_product( $id )?->get_name() ?: "Product #{$id}";
                    CIG_Notification::create( 'stock', 'Out of stock', $product_name . ' is now out of stock', 'alert-triangle', '/stock' );
                }
            }
        } else {
            global $wpdb;
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}cig_products
                 SET stock = GREATEST(0, stock + %d) WHERE id = %d",
                $delta, $id
            ));
            // Out-of-stock notification when stock hits 0 after consuming
            if ( $delta < 0 ) {
                $new_stock = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT stock FROM {$wpdb->prefix}cig_products WHERE id = %d", $id
                ) );
                if ( $new_stock <= 0 ) {
                    $product_name = $wpdb->get_var( $wpdb->prepare(
                        "SELECT name FROM {$wpdb->prefix}cig_products WHERE id = %d", $id
                    ) ) ?: "Product #{$id}";
                    CIG_Notification::create( 'stock', 'Out of stock', $product_name . ' is now out of stock', 'alert-triangle', '/stock' );
                }
            }
        }
    }

    private static function extract_fields( $data ) {
        $fields = [];
        $map = [
            'sku'             => [ 'sku' ],
            'name'            => [ 'name' ],
            'name_ka'         => [ 'nameKa', 'name_ka' ],
            'brand'           => [ 'brand' ],
            'description'     => [ 'description' ],
            'image_url'       => [ 'imageUrl', 'image_url' ],
            'price'           => [ 'price' ],
            'stock'           => [ 'stock' ],
            'reserved'        => [ 'reserved' ],
            'category'        => [ 'category' ],
            'is_active'       => [ 'isActive', 'is_active' ],
            'legacy_post_id'  => [ 'legacyPostId', 'legacy_post_id' ],
        ];

        foreach ( $map as $db_col => $keys ) {
            foreach ( $keys as $key ) {
                if ( array_key_exists( $key, $data ) ) {
                    $fields[ $db_col ] = $data[ $key ];
                    break;
                }
            }
        }

        if ( isset( $fields['is_active'] ) ) {
            $fields['is_active'] = $fields['is_active'] ? 1 : 0;
        }

        return $fields;
    }
}
