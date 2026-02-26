<?php
/**
 * Customer model — CRUD + computed stats (totalSpent, invoiceCount, outstanding).
 */
class CIG_Customer {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cig_customers';
    }

    public static function find( $id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $row ) return null;
        return self::hydrate( $row, true );
    }

    public static function list( $args = [] ) {
        global $wpdb;

        $defaults = [
            'search'   => '',
            'sort'     => 'name',
            'order'    => 'ASC',
            'page'     => 1,
            'per_page' => 25,
        ];
        $args = wp_parse_args( $args, $defaults );

        $table = self::table();
        $where = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(name LIKE %s OR name_en LIKE %s OR tax_id LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $where_sql = implode( ' AND ', $where );

        $allowed_sorts = [ 'name', 'name_en', 'tax_id', 'created_at', 'id' ];
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

        $customers = array_map( function( $row ) {
            return self::hydrate( $row, true );
        }, $rows );

        return [
            'data'     => $customers,
            'total'    => $total,
            'page'     => (int) $args['page'],
            'per_page' => $limit,
            'pages'    => $limit > 0 ? ceil( $total / $limit ) : 1,
        ];
    }

    public static function create( $data ) {
        global $wpdb;

        $fields = self::extract_fields( $data );
        $wpdb->insert( self::table(), $fields );
        $id = $wpdb->insert_id;

        if ( ! $id ) {
            return new WP_Error( 'cig_create_failed', 'Failed to create customer.', [ 'status' => 500 ] );
        }

        return self::find( $id );
    }

    public static function update( $id, $data ) {
        global $wpdb;

        $fields = self::extract_fields( $data );
        $wpdb->update( self::table(), $fields, [ 'id' => $id ] );

        return self::find( $id );
    }

    public static function delete( $id ) {
        global $wpdb;
        return $wpdb->delete( self::table(), [ 'id' => $id ] );
    }

    private static function hydrate( $row, $with_stats = false ) {
        $customer = [
            'id'      => (int) $row['id'],
            'name'    => $row['name'],
            'nameEn'  => $row['name_en'],
            'taxId'   => $row['tax_id'],
            'address' => $row['address'],
            'phone'   => $row['phone'],
            'email'   => $row['email'],
        ];

        if ( $with_stats ) {
            $stats = self::compute_stats( $row['id'] );
            $customer['totalSpent']    = $stats['total_spent'];
            $customer['invoiceCount']  = $stats['invoice_count'];
            $customer['outstanding']   = $stats['outstanding'];
        }

        return $customer;
    }

    /**
     * Compute financial stats for a customer from the invoices table.
     */
    private static function compute_stats( $customer_id ) {
        global $wpdb;
        $invoices_table = $wpdb->prefix . 'cig_invoices';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COALESCE(SUM(paid_amount), 0) AS total_spent,
                COUNT(*) AS invoice_count,
                COALESCE(SUM(GREATEST(0, total_amount - paid_amount)), 0) AS outstanding
             FROM {$invoices_table}
             WHERE customer_id = %d AND status = 'standard' AND lifecycle_status != 'draft'",
            $customer_id
        ), ARRAY_A );

        return [
            'total_spent'   => (float) ( $row['total_spent'] ?? 0 ),
            'invoice_count' => (int) ( $row['invoice_count'] ?? 0 ),
            'outstanding'   => (float) ( $row['outstanding'] ?? 0 ),
        ];
    }

    private static function extract_fields( $data ) {
        $fields = [];
        $map = [
            'name'            => [ 'name' ],
            'name_en'         => [ 'nameEn', 'name_en' ],
            'tax_id'          => [ 'taxId', 'tax_id' ],
            'address'         => [ 'address' ],
            'phone'           => [ 'phone' ],
            'email'           => [ 'email' ],
            'legacy_post_id'  => [ 'legacyPostId', 'legacy_post_id' ],
            'legacy_term_id'  => [ 'legacyTermId', 'legacy_term_id' ],
        ];

        foreach ( $map as $db_col => $keys ) {
            foreach ( $keys as $key ) {
                if ( array_key_exists( $key, $data ) ) {
                    $fields[ $db_col ] = $data[ $key ];
                    break;
                }
            }
        }

        return $fields;
    }
}
