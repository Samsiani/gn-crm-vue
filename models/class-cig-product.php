<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
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
        return self::hydrate_wc( $wc );
    }

    private static function find_table( $id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $row ) return null;
        return self::hydrate( $row );
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

        return [
            'data'     => array_map( [ __CLASS__, 'hydrate_wc' ], $products ),
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

        // Sanitize text fields
        $text_fields = [ 'sku', 'name', 'name_ka', 'brand', 'category' ];
        foreach ( $text_fields as $tf ) {
            if ( isset( $fields[ $tf ] ) ) {
                $fields[ $tf ] = sanitize_text_field( $fields[ $tf ] );
            }
        }
        if ( isset( $fields['description'] ) ) {
            $fields['description'] = sanitize_textarea_field( $fields['description'] );
        }
        if ( isset( $fields['image_url'] ) ) {
            $fields['image_url'] = esc_url_raw( $fields['image_url'] );
        }

        // Cast numeric fields
        if ( isset( $fields['price'] ) )    $fields['price']    = (float) $fields['price'];
        if ( isset( $fields['stock'] ) )    $fields['stock']    = (int) $fields['stock'];
        if ( isset( $fields['reserved'] ) ) $fields['reserved'] = max( 0, (int) $fields['reserved'] );

        if ( isset( $fields['is_active'] ) ) {
            $fields['is_active'] = $fields['is_active'] ? 1 : 0;
        }

        return $fields;
    }
}
