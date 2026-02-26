<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Other Delivery model — tracks delivery records for "Other" payment balance.
 */
class CIG_Delivery {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cig_other_deliveries';
    }

    public static function find( $id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $row ) return null;
        return self::hydrate( $row );
    }

    public static function list( $args = [] ) {
        global $wpdb;

        $defaults = [
            'date_from' => '',
            'date_to'   => '',
            'sort'      => 'delivery_date',
            'order'     => 'DESC',
            'page'      => 1,
            'per_page'  => 100,
        ];
        $args = wp_parse_args( $args, $defaults );

        $table = self::table();
        $where = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['date_from'] ) ) {
            $where[] = 'delivery_date >= %s';
            $params[] = $args['date_from'];
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[] = 'delivery_date <= %s';
            $params[] = $args['date_to'];
        }

        $where_sql = implode( ' AND ', $where );
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        if ( ! empty( $params ) ) {
            $count_sql = $wpdb->prepare( $count_sql, ...$params );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        $offset = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
        $limit  = (int) $args['per_page'];

        $query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY delivery_date {$order} LIMIT %d OFFSET %d";
        $query_params = array_merge( $params, [ $limit, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $query, ...$query_params ), ARRAY_A );

        return [
            'data'     => array_map( [ __CLASS__, 'hydrate' ], $rows ),
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
            return new WP_Error( 'cig_create_failed', 'Failed to create delivery record.', [ 'status' => 500 ] );
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

    private static function hydrate( $row ) {
        return [
            'id'     => (int) $row['id'],
            'date'   => $row['delivery_date'],
            'amount' => (float) $row['amount'],
            'note'   => $row['note'] ?? '',
        ];
    }

    private static function extract_fields( $data ) {
        $fields = [];

        if ( isset( $data['date'] ) )   $fields['delivery_date'] = sanitize_text_field( $data['date'] );
        if ( isset( $data['amount'] ) )  $fields['amount'] = (float) $data['amount'];
        if ( isset( $data['note'] ) )    $fields['note'] = sanitize_textarea_field( $data['note'] );

        return $fields;
    }
}
