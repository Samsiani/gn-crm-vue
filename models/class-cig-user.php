<?php
/**
 * CIG User model — decoupled from wp_users, has own table.
 */
class CIG_User {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cig_users';
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

    /**
     * Find a CIG user by their WordPress user ID.
     */
    public static function find_by_wp_user( $wp_user_id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE wp_user_id = %d", $wp_user_id ),
            ARRAY_A
        );
        if ( ! $row ) return null;
        return self::hydrate( $row );
    }

    /**
     * Find a user by name matching (for login).
     * Matches: nameEn, first name of nameEn, avatar — all case-insensitive.
     */
    public static function find_by_login( $username ) {
        global $wpdb;
        $search = strtolower( trim( $username ) );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE is_active = 1
               AND ( LOWER(name_en) = %s
                  OR LOWER(SUBSTRING_INDEX(name_en, ' ', 1)) = %s
                  OR LOWER(avatar) = %s )
             LIMIT 1",
            $search, $search, $search
        ), ARRAY_A );
        return $row ? self::hydrate( $row ) : null;
    }

    public static function list( $args = [] ) {
        global $wpdb;

        $defaults = [
            'search'   => '',
            'role'     => '',
            'sort'     => 'name_en',
            'order'    => 'ASC',
            'page'     => 1,
            'per_page' => 50,
        ];
        $args = wp_parse_args( $args, $defaults );

        $table = self::table();
        $where = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(name LIKE %s OR name_en LIKE %s)';
            $params[] = $search;
            $params[] = $search;
        }

        if ( ! empty( $args['role'] ) ) {
            $where[] = 'role = %s';
            $params[] = $args['role'];
        }

        $where_sql = implode( ' AND ', $where );

        $allowed_sorts = [ 'name', 'name_en', 'role', 'id' ];
        $sort  = in_array( $args['sort'], $allowed_sorts, true ) ? $args['sort'] : 'name_en';
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

        $users = array_map( [ __CLASS__, 'hydrate' ], $rows );

        return [
            'data'     => $users,
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
            return new WP_Error( 'cig_create_failed', 'Failed to create user.', [ 'status' => 500 ] );
        }
        return self::find( $id );
    }

    public static function update( $id, $data ) {
        global $wpdb;
        $fields = self::extract_fields( $data );
        $wpdb->update( self::table(), $fields, [ 'id' => $id ] );
        return self::find( $id );
    }

    /**
     * Auto-create a CIG user from a WordPress user.
     * Maps WP roles → CIG roles, generates avatar from initials.
     */
    public static function create_from_wp_user( $wp_user ) {
        global $wpdb;

        // Build display name
        $first = $wp_user->first_name ?: '';
        $last  = $wp_user->last_name ?: '';
        $name_en = trim( "{$first} {$last}" );
        if ( empty( $name_en ) ) {
            $name_en = $wp_user->display_name ?: $wp_user->user_login;
        }

        // Generate avatar from initials
        $parts = preg_split( '/\s+/', $name_en );
        if ( count( $parts ) >= 2 ) {
            $avatar = strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[1], 0, 1 ) );
        } else {
            $avatar = strtoupper( mb_substr( $name_en, 0, 2 ) );
        }

        // Map WP role → CIG role
        $role = 'none'; // default — unknown WP roles get no CRM access; admin assigns role manually
        $wp_roles = $wp_user->roles;
        if ( in_array( 'administrator', $wp_roles, true ) ) {
            $role = 'admin';
        } elseif ( in_array( 'editor', $wp_roles, true ) || in_array( 'shop_manager', $wp_roles, true ) ) {
            $role = 'manager';
        } elseif ( in_array( 'contributor', $wp_roles, true ) || in_array( 'subscriber', $wp_roles, true ) ) {
            $role = 'accountant';
        }

        $wpdb->insert( self::table(), [
            'wp_user_id' => $wp_user->ID,
            'name'       => $wp_user->display_name ?: $name_en,
            'name_en'    => $name_en,
            'avatar'     => $avatar,
            'role'       => $role,
            'is_active'  => 1,
        ] );

        $id = $wpdb->insert_id;
        if ( ! $id ) {
            return null;
        }

        return self::find( $id );
    }

    public static function delete( $id ) {
        global $wpdb;
        return $wpdb->delete( self::table(), [ 'id' => $id ] );
    }

    /**
     * Batch-fetch invoice stats for a set of user IDs in a single SQL query.
     * Returns [ user_id => [ revenue, invoiceCount, outstanding ] ]
     *
     * @param int[] $user_ids
     * @return array
     */
    public static function batch_invoice_stats( array $user_ids ) {
        if ( empty( $user_ids ) ) {
            return [];
        }

        global $wpdb;
        $invoices_t = $wpdb->prefix . 'cig_invoices';
        $ids_sql    = implode( ',', array_map( 'intval', $user_ids ) );

        $rows = $wpdb->get_results(
            "SELECT
                author_id,
                COALESCE(SUM(paid_amount), 0) AS revenue,
                COUNT(*) AS invoice_count,
                COALESCE(SUM(GREATEST(0, total_amount - paid_amount)), 0) AS outstanding
             FROM {$invoices_t}
             WHERE status = 'standard' AND author_id IN ({$ids_sql})
             GROUP BY author_id",
            ARRAY_A
        );

        $map = [];
        foreach ( $rows as $r ) {
            $map[ (int) $r['author_id'] ] = [
                'revenue'      => (float) $r['revenue'],
                'invoiceCount' => (int)   $r['invoice_count'],
                'outstanding'  => (float) $r['outstanding'],
            ];
        }
        return $map;
    }

    private static function hydrate( $row ) {
        return [
            'id'        => (int) $row['id'],
            'wpUserId'  => $row['wp_user_id'] ? (int) $row['wp_user_id'] : null,
            'name'      => $row['name'],
            'nameEn'    => $row['name_en'],
            'avatar'    => $row['avatar'],
            'role'      => $row['role'],
            'isActive'  => (bool) $row['is_active'],
        ];
    }

    private static function extract_fields( $data ) {
        $fields = [];
        $map = [
            'wp_user_id' => [ 'wpUserId', 'wp_user_id' ],
            'name'       => [ 'name' ],
            'name_en'    => [ 'nameEn', 'name_en' ],
            'avatar'     => [ 'avatar' ],
            'role'       => [ 'role' ],
            'is_active'  => [ 'isActive', 'is_active' ],
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
