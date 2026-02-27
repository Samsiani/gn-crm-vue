<?php
/**
 * Company model — single-row configuration table.
 */
class CIG_Company {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cig_company';
    }

    /**
     * Get the company config (always a single row).
     */
    public static function get() {
        global $wpdb;
        $row = $wpdb->get_row( "SELECT * FROM " . self::table() . " LIMIT 1", ARRAY_A );
        if ( ! $row ) return null;
        return self::hydrate( $row );
    }

    /**
     * Update company config (upsert).
     */
    public static function update( $data ) {
        global $wpdb;
        $table = self::table();
        $fields = self::extract_fields( $data );

        $exists = $wpdb->get_var( "SELECT id FROM {$table} LIMIT 1" );
        if ( $exists ) {
            $wpdb->update( $table, $fields, [ 'id' => $exists ] );
        } else {
            $wpdb->insert( $table, $fields );
        }

        return self::get();
    }

    private static function hydrate( $row ) {
        return [
            'id'                    => (int) $row['id'],
            'name'                  => $row['name'],
            'nameKa'                => $row['name_ka'],
            'taxId'                 => $row['tax_id'],
            'address'               => $row['address'],
            'phone'                 => $row['phone'],
            'email'                 => $row['email'],
            'website'               => $row['website'],
            'bankName1'             => $row['bank_name_1'],
            'iban1'                 => $row['iban_1'],
            'bankName2'             => $row['bank_name_2'],
            'iban2'                 => $row['iban_2'],
            'directorName'          => $row['director_name'],
            'logoUrl'               => $row['logo_url'] ?? '',
            'signatureUrl'          => $row['signature_url'] ?? '',
            'reservationDays'       => (int) $row['reservation_days'],
            'startingInvoiceNumber' => (int) $row['starting_invoice_number'],
            'invoicePrefix'         => $row['invoice_prefix'],
            'hideWpAdminBar'        => (bool) ( $row['hide_wp_admin_bar'] ?? 0 ),
            'loginFooterNote'       => $row['login_footer_note'] ?? '',
        ];
    }

    private static function extract_fields( $data ) {
        $fields = [];
        $map = [
            'name'                    => [ 'name' ],
            'name_ka'                 => [ 'nameKa', 'name_ka' ],
            'tax_id'                  => [ 'taxId', 'tax_id' ],
            'address'                 => [ 'address' ],
            'phone'                   => [ 'phone' ],
            'email'                   => [ 'email' ],
            'website'                 => [ 'website' ],
            'bank_name_1'             => [ 'bankName1', 'bank_name_1' ],
            'iban_1'                  => [ 'iban1', 'iban_1' ],
            'bank_name_2'             => [ 'bankName2', 'bank_name_2' ],
            'iban_2'                  => [ 'iban2', 'iban_2' ],
            'director_name'           => [ 'directorName', 'director_name' ],
            'logo_url'                => [ 'logoUrl', 'logo_url' ],
            'signature_url'           => [ 'signatureUrl', 'signature_url' ],
            'reservation_days'        => [ 'reservationDays', 'reservation_days' ],
            'starting_invoice_number' => [ 'startingInvoiceNumber', 'starting_invoice_number' ],
            'invoice_prefix'          => [ 'invoicePrefix', 'invoice_prefix' ],
            'hide_wp_admin_bar'       => [ 'hideWpAdminBar', 'hide_wp_admin_bar' ],
            'login_footer_note'       => [ 'loginFooterNote', 'login_footer_note' ],
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
