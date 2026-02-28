<?php
/**
 * CIG Exporter — exports all data from the new custom tables in the same JSON
 * structure as export-old-plugin.php, enabling direct field-by-field comparison.
 *
 * Key additions vs old-plugin export:
 *   new_id           — PK in the new DB (for cross-referencing)
 *   customer_id      — new DB customer ID (alongside customer_post_id)
 *   author_id        — new DB user ID (alongside author_wp_user_id)
 *   is_rs_uploaded   — expanded accounting flags (in addition to acc_status string)
 *   content_hash     — freshly computed from current data
 *   content_hash_db  — hash stored from last sync (from old site)
 *   synced_at        — timestamp of last sync
 */
class CIG_Exporter {

    // ── Public entry point ────────────────────────────────────────────────────

    public static function export(): array {
        $invoices  = self::export_invoices();
        $customers = self::export_customers();
        $deposits  = self::export_deposits();
        $users     = self::export_users();

        return [
            'meta' => [
                'version'     => '2.0',
                'exported_at' => gmdate( 'c' ),
                'source_url'  => home_url(),
                'source'      => 'gn-crm-vue',
                'counts'      => [
                    'invoices'  => count( $invoices ),
                    'customers' => count( $customers ),
                    'deposits'  => count( $deposits ),
                    'users'     => count( $users ),
                ],
            ],
            'users'     => $users,
            'customers' => $customers,
            'deposits'  => $deposits,
            'invoices'  => $invoices,
        ];
    }

    // ── Users ────────────────────────────────────────────────────────────────

    private static function export_users(): array {
        global $wpdb;
        $user_t = $wpdb->prefix . 'cig_users';
        $wp_t   = $wpdb->users;

        $rows = $wpdb->get_results(
            "SELECT u.*, wu.user_login, wu.user_email, wu.display_name AS wp_display_name
             FROM {$user_t} u
             LEFT JOIN {$wp_t} wu ON wu.ID = u.wp_user_id
             ORDER BY u.id ASC",
            ARRAY_A
        );

        $result = [];
        foreach ( (array) $rows as $u ) {
            $result[] = [
                'new_id'       => (int) $u['id'],
                'wp_user_id'   => $u['wp_user_id'] ? (int) $u['wp_user_id'] : null,
                'login'        => $u['user_login'] ?? '',
                'email'        => $u['user_email'] ?? '',
                'display_name' => $u['wp_display_name'] ?? $u['name'],
                'name'         => $u['name'],
                'name_en'      => $u['name_en'],
                'avatar'       => $u['avatar'],
                'role'         => $u['role'],
                'wp_role'      => self::cig_role_to_wp_role( $u['role'] ),
                'is_active'    => (bool) $u['is_active'],
            ];
        }
        return $result;
    }

    // ── Customers ────────────────────────────────────────────────────────────

    private static function export_customers(): array {
        global $wpdb;
        $cust_t = $wpdb->prefix . 'cig_customers';

        $rows = $wpdb->get_results(
            "SELECT * FROM {$cust_t} ORDER BY id ASC",
            ARRAY_A
        );

        $result = [];
        foreach ( (array) $rows as $c ) {
            $result[] = [
                'new_id'         => (int) $c['id'],
                'legacy_post_id' => $c['legacy_post_id'] ? (int) $c['legacy_post_id'] : null,
                'name'           => $c['name'],
                'name_en'        => $c['name_en'],
                'tax_id'         => $c['tax_id'],
                'phone'          => $c['phone'],
                'email'          => $c['email'],
                'address'        => $c['address'],
            ];
        }
        return $result;
    }

    // ── Deposits ─────────────────────────────────────────────────────────────

    private static function export_deposits(): array {
        global $wpdb;
        $dep_t = $wpdb->prefix . 'cig_deposits';

        $rows = $wpdb->get_results(
            "SELECT * FROM {$dep_t} ORDER BY id ASC",
            ARRAY_A
        );

        $result = [];
        foreach ( (array) $rows as $d ) {
            $result[] = [
                'new_id'         => (int) $d['id'],
                'legacy_post_id' => $d['legacy_post_id'] ? (int) $d['legacy_post_id'] : null,
                'amount'         => self::fmt( $d['amount'] ),
                'deposit_date'   => $d['deposit_date'],
                'type'           => $d['type'],
                'note'           => $d['note'] ?? '',
            ];
        }
        return $result;
    }

    // ── Invoices ─────────────────────────────────────────────────────────────

    private static function export_invoices(): array {
        global $wpdb;
        $inv_t  = $wpdb->prefix . 'cig_invoices';
        $user_t = $wpdb->prefix . 'cig_users';
        $item_t = $wpdb->prefix . 'cig_invoice_items';
        $pay_t  = $wpdb->prefix . 'cig_payments';
        $cust_t = $wpdb->prefix . 'cig_customers';

        // ── 1. All invoices with author wp_user_id + customer legacy_post_id ──
        $rows = $wpdb->get_results(
            "SELECT i.*,
                    u.wp_user_id         AS author_wp_user_id,
                    c.legacy_post_id     AS customer_legacy_post_id,
                    c.name               AS customer_name
             FROM {$inv_t} i
             LEFT JOIN {$user_t} u ON u.id = i.author_id
             LEFT JOIN {$cust_t} c ON c.id = i.customer_id
             ORDER BY i.id ASC",
            ARRAY_A
        );

        if ( empty( $rows ) ) return [];

        $ids    = array_column( $rows, 'id' );
        $ids_in = implode( ',', array_map( 'intval', $ids ) );

        // ── 2. Batch-fetch items ───────────────────────────────────────────────
        $item_rows = $wpdb->get_results(
            "SELECT * FROM {$item_t}
             WHERE invoice_id IN ({$ids_in})
             ORDER BY invoice_id ASC, sort_order ASC",
            ARRAY_A
        );
        $items_map = [];
        foreach ( (array) $item_rows as $ir ) {
            $items_map[ (int) $ir['invoice_id'] ][] = $ir;
        }

        // ── 3. Batch-fetch payments with user wp_user_id ──────────────────────
        $pay_rows = $wpdb->get_results(
            "SELECT p.*, u2.wp_user_id AS pay_wp_user_id
             FROM {$pay_t} p
             LEFT JOIN {$user_t} u2 ON u2.id = p.user_id
             WHERE p.invoice_id IN ({$ids_in})
             ORDER BY p.invoice_id ASC, p.payment_date ASC, p.id ASC",
            ARRAY_A
        );
        $pays_map = [];
        foreach ( (array) $pay_rows as $pr ) {
            $pays_map[ (int) $pr['invoice_id'] ][] = $pr;
        }

        // ── 4. Build export objects ───────────────────────────────────────────
        $result = [];
        foreach ( $rows as $inv ) {
            $inv_id = (int) $inv['id'];

            // Items
            $export_items = [];
            foreach ( $items_map[ $inv_id ] ?? [] as $item ) {
                $qty   = (float) $item['qty'];
                $price = (float) $item['price'];
                $export_items[] = [
                    'product_id'       => $item['legacy_product_id'] ? (int) $item['legacy_product_id'] : null,
                    'name'             => (string) $item['name'],
                    'brand'            => (string) $item['brand'],
                    'sku'              => (string) $item['sku'],
                    'description'      => (string) ( $item['description'] ?? '' ),
                    'image_url'        => (string) ( $item['image_url'] ?? '' ),
                    'qty'              => self::fmt( $qty ),
                    'price'            => self::fmt( $price ),
                    'total'            => self::fmt( $qty * $price ),
                    'item_status'      => (string) $item['item_status'],
                    'reservation_days' => (int) $item['reservation_days'],
                    'warranty'         => (string) ( $item['warranty'] ?? '' ),
                ];
            }

            // Payments
            $export_payments = [];
            foreach ( $pays_map[ $inv_id ] ?? [] as $pay ) {
                $export_payments[] = [
                    'date'    => (string) $pay['payment_date'],
                    'amount'  => self::fmt( $pay['amount'] ),
                    'method'  => (string) $pay['method'],
                    'user_id' => $pay['pay_wp_user_id'] ? (int) $pay['pay_wp_user_id'] : null,
                    'comment' => (string) ( $pay['comment'] ?? '' ),
                ];
            }

            $acc_status  = self::acc_flags_to_status( $inv );
            $post_status = self::lifecycle_to_post_status( $inv['status'], $inv['lifecycle_status'] );
            $inv_total   = self::fmt( $inv['total_amount'] );
            $paid_amount = self::fmt( $inv['paid_amount'] );
            $sold_date   = ! empty( $inv['sold_date'] ) ? $inv['sold_date'] : null;

            // Compute content_hash with identical algorithm to export-old-plugin.php
            // Uses json_encode (not wp_json_encode) to match old script exactly
            $content_hash = md5( json_encode( [
                $post_status,
                $inv['status'],           // invoice_status
                $inv['lifecycle_status'], // lifecycle_status
                $inv_total,               // invoice_total
                $paid_amount,             // paid_amount
                $inv['buyer_name'],
                $inv['buyer_tax_id'],
                $inv['buyer_phone'],
                $inv['buyer_address'],
                $inv['buyer_email'],
                (string) ( $inv['general_note']    ?? '' ),
                (string) ( $inv['consultant_note'] ?? '' ),
                (string) ( $inv['accountant_note'] ?? '' ),
                $acc_status,
                $sold_date,
                $export_items,
                $export_payments,
            ] ) );

            $result[] = [
                // ── New-site identification ──────────────────────────────────
                'new_id'             => $inv_id,
                'legacy_post_id'     => $inv['legacy_post_id'] ? (int) $inv['legacy_post_id'] : null,

                // ── Date / status (old-format field names) ───────────────────
                'post_date'          => $inv['created_at'] ? $inv['created_at'] . ' 00:00:00' : null,
                'post_status'        => $post_status,
                'invoice_number'     => $inv['invoice_number'],
                'invoice_status'     => $inv['status'],
                'lifecycle_status'   => $inv['lifecycle_status'],
                'sold_date'          => $sold_date,

                // ── Buyer info ───────────────────────────────────────────────
                'buyer_name'         => $inv['buyer_name'],
                'buyer_tax_id'       => $inv['buyer_tax_id'],
                'buyer_phone'        => $inv['buyer_phone'],
                'buyer_address'      => $inv['buyer_address'],
                'buyer_email'        => $inv['buyer_email'],

                // ── Relations (both old and new IDs for full traceability) ───
                'customer_post_id'   => $inv['customer_legacy_post_id'] ? (int) $inv['customer_legacy_post_id'] : null,
                'customer_id'        => $inv['customer_id'] ? (int) $inv['customer_id'] : null,
                'customer_name'      => $inv['customer_name'] ?? '',
                'author_wp_user_id'  => $inv['author_wp_user_id'] ? (int) $inv['author_wp_user_id'] : null,
                'author_id'          => $inv['author_id'] ? (int) $inv['author_id'] : null,

                // ── Financials ───────────────────────────────────────────────
                'invoice_total'      => $inv_total,
                'paid_amount'        => $paid_amount,

                // ── Notes ────────────────────────────────────────────────────
                'general_note'       => (string) ( $inv['general_note']    ?? '' ),
                'consultant_note'    => (string) ( $inv['consultant_note'] ?? '' ),
                'accountant_note'    => (string) ( $inv['accountant_note'] ?? '' ),

                // ── Accounting flags (both formats) ───────────────────────────
                'acc_status'         => $acc_status,
                'is_rs_uploaded'     => (bool) $inv['is_rs_uploaded'],
                'is_credit_checked'  => (bool) $inv['is_credit_checked'],
                'is_receipt_checked' => (bool) $inv['is_receipt_checked'],
                'is_corrected'       => (bool) $inv['is_corrected'],

                // ── Sync metadata ────────────────────────────────────────────
                'content_hash'       => $content_hash,          // freshly computed
                'content_hash_db'    => $inv['content_hash'],   // stored from last sync
                'synced_at'          => $inv['synced_at'],

                // ── Items & Payments ─────────────────────────────────────────
                'items'              => $export_items,
                'payments'           => $export_payments,
            ];
        }

        return $result;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Format a numeric value as "N.NN" string (2 decimal places, no thousands sep).
     */
    private static function fmt( $value ): string {
        return number_format( (float) $value, 2, '.', '' );
    }

    /**
     * Reverse-map 4 accounting boolean flags to single acc_status string.
     */
    private static function acc_flags_to_status( array $inv ): string {
        if ( ! $inv['is_rs_uploaded'] ) return '';
        if ( $inv['is_corrected'] )     return 'corrected';
        if ( $inv['is_credit_checked'] ) return 'credit';
        if ( $inv['is_receipt_checked'] ) return 'receipt';
        return 'rs';
    }

    /**
     * Derive old-style post_status from invoice status + lifecycle.
     */
    private static function lifecycle_to_post_status( string $status, string $lifecycle ): string {
        if ( $status === 'fictive' ) return 'draft';
        switch ( $lifecycle ) {
            case 'sold':     return 'publish';
            case 'canceled': return 'canceled';
            case 'draft':    return 'draft';
            default:         return 'reserved'; // reserved / active
        }
    }

    /**
     * Map CIG role back to nearest WP role equivalent.
     */
    private static function cig_role_to_wp_role( string $role ): string {
        $map = [
            'admin'     => 'administrator',
            'manager'   => 'editor',
            'sales'     => 'author',
            'accountant'=> 'contributor',
        ];
        return $map[ $role ] ?? 'subscriber';
    }
}
