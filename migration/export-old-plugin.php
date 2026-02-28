<?php
/**
 * Old Plugin Data Export Script
 *
 * Exports all invoices, customers, deposits, and WP users from the old
 * gn-industrial-custom-invoice-generator plugin into a structured JSON file.
 *
 * Usage:
 *   WP-CLI:  wp eval-file export-old-plugin.php
 *   Browser: /path/to/this-file.php?secret=YOUR_SECRET_TOKEN
 *            (Requires CIG_EXPORT_SECRET constant in wp-config.php)
 *
 * Drop this file anywhere inside the old WordPress install and run it.
 */

// Security: must run inside WordPress
if ( ! defined( 'ABSPATH' ) ) {
    // When accessed directly via browser, bootstrap WordPress
    $wp_root = dirname( __FILE__ );
    // Walk up until we find wp-load.php
    for ( $i = 0; $i < 8; $i++ ) {
        if ( file_exists( $wp_root . '/wp-load.php' ) ) {
            require_once $wp_root . '/wp-load.php';
            break;
        }
        $wp_root = dirname( $wp_root );
    }
    if ( ! defined( 'ABSPATH' ) ) {
        die( 'Could not find WordPress installation.' );
    }
}

// Security: allow only CLI or valid secret token
$is_cli = ( PHP_SAPI === 'cli' );
if ( ! $is_cli ) {
    $provided_secret = isset( $_GET['secret'] ) ? $_GET['secret'] : '';
    $expected_secret = defined( 'CIG_EXPORT_SECRET' ) ? CIG_EXPORT_SECRET : '';
    if ( empty( $expected_secret ) || ! hash_equals( $expected_secret, $provided_secret ) ) {
        http_response_code( 403 );
        die( 'Forbidden. Pass ?secret=TOKEN or run via WP-CLI.' );
    }
    // Note: no current_user_can() check — secret token is sufficient,
    // and server-to-server requests have no WP session.
}

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Safely unserialize a value that may be double-serialised PHP.
 * Loops until result is an array (max 3 iterations).
 */
function cig_export_unserialize( $value ) {
    if ( is_array( $value ) ) return $value;
    $max = 3;
    for ( $i = 0; $i < $max; $i++ ) {
        $result = maybe_unserialize( $value );
        if ( is_array( $result ) ) return $result;
        if ( $result === $value ) break; // no change → stop
        $value = $result;
    }
    return [];
}

/**
 * Get a single postmeta value for a post.
 */
function cig_export_meta( $post_id, $key ) {
    return get_post_meta( $post_id, $key, true );
}

// ── Export Users ─────────────────────────────────────────────────────────────

function cig_export_users() {
    $wp_users = get_users( [
        'role__in' => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'shop_manager' ],
        'number'   => 500,
        'fields'   => 'all',
    ] );

    $users = [];
    foreach ( $wp_users as $user ) {
        $roles = (array) $user->roles;
        $users[] = [
            'wp_user_id'   => (int) $user->ID,
            'login'        => $user->user_login,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
            'wp_role'      => ! empty( $roles ) ? $roles[0] : 'subscriber',
        ];
    }
    return $users;
}

// ── Export Customers ──────────────────────────────────────────────────────────

function cig_export_customers() {
    $posts = get_posts( [
        'post_type'      => 'cig_customer',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    $customers = [];
    foreach ( $posts as $post_id ) {
        $post = get_post( $post_id );
        $customers[] = [
            'legacy_post_id' => (int) $post_id,
            'name'           => $post->post_title,
            'name_en'        => cig_export_meta( $post_id, '_cig_customer_name_en' ) ?: '',
            'tax_id'         => cig_export_meta( $post_id, '_cig_customer_tax_id' ) ?: '',
            'phone'          => cig_export_meta( $post_id, '_cig_customer_phone' ) ?: '',
            'email'          => cig_export_meta( $post_id, '_cig_customer_email' ) ?: '',
            'address'        => cig_export_meta( $post_id, '_cig_customer_address' ) ?: '',
        ];
    }
    return $customers;
}

// ── Export Deposits ───────────────────────────────────────────────────────────

function cig_export_deposits() {
    $posts = get_posts( [
        'post_type'      => 'cig_deposit',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    $deposits = [];
    foreach ( $posts as $post_id ) {
        $raw_amount = cig_export_meta( $post_id, '_cig_deposit_amount' );
        $raw_date   = cig_export_meta( $post_id, '_cig_deposit_date' );
        // Normalise date
        if ( ! empty( $raw_date ) ) {
            $ts = strtotime( $raw_date );
            $raw_date = $ts ? date( 'Y-m-d', $ts ) : $raw_date;
        }
        $deposits[] = [
            'legacy_post_id' => (int) $post_id,
            'amount'         => (string) ( $raw_amount ?: '0' ),
            'deposit_date'   => $raw_date ?: '',
            'note'           => cig_export_meta( $post_id, '_cig_deposit_note' ) ?: '',
        ];
    }
    return $deposits;
}

// ── Export Invoices ───────────────────────────────────────────────────────────

function cig_export_invoices() {
    global $wpdb;

    // Get all invoice post IDs (excluding trash and auto-draft)
    $post_ids = $wpdb->get_col( "
        SELECT ID FROM {$wpdb->posts}
        WHERE post_type = 'invoice'
          AND post_status NOT IN ('trash', 'auto-draft')
        ORDER BY ID ASC
    " );

    $invoices = [];
    foreach ( $post_ids as $post_id ) {
        $post_id = (int) $post_id;
        $post    = get_post( $post_id );
        if ( ! $post ) continue;

        // ── Items ──
        $raw_items = cig_export_meta( $post_id, '_cig_items' );
        $items     = cig_export_unserialize( $raw_items );
        $export_items = [];
        foreach ( $items as $item ) {
            $item_status = isset( $item['item_status'] ) ? $item['item_status']
                         : ( isset( $item['status'] ) ? $item['status'] : 'none' );
            // Quantity: old plugin may use 'qty' or 'quantity' as the key
            $export_qty   = isset( $item['qty'] ) ? (float) $item['qty']
                          : ( isset( $item['quantity'] ) ? (float) $item['quantity'] : 1 );
            $export_price = (float) ( $item['price'] ?? 0 );
            $export_total = (float) ( $item['total'] ?? 0 );
            // Recalculate total if missing (trust qty×price)
            if ( $export_total <= 0 && $export_qty > 0 && $export_price > 0 ) {
                $export_total = $export_qty * $export_price;
            }
            $export_items[] = [
                'product_id'       => isset( $item['product_id'] ) ? (int) $item['product_id'] : null,
                'name'             => $item['name'] ?? '',
                'brand'            => $item['brand'] ?? '',
                'sku'              => $item['sku'] ?? '',
                'description'      => $item['description'] ?? '',
                'image_url'        => $item['image_url'] ?? ( $item['imageUrl'] ?? '' ),
                'qty'              => (string) $export_qty,
                'price'            => (string) $export_price,
                'total'            => (string) $export_total,
                'item_status'      => $item_status,
                'reservation_days' => isset( $item['reservation_days'] ) ? (int) $item['reservation_days'] : 0,
                'warranty'         => $item['warranty'] ?? '',
            ];
        }

        // ── Payments ──
        $raw_payments = cig_export_meta( $post_id, '_cig_payment_history' );
        $payments     = cig_export_unserialize( $raw_payments );
        $export_payments = [];
        foreach ( $payments as $pay ) {
            // Old plugin may use 'date' key; normalise
            $pay_date_raw = $pay['date'] ?? ( $pay['payment_date'] ?? '' );
            if ( ! empty( $pay_date_raw ) ) {
                $ts = strtotime( $pay_date_raw );
                // Keep as datetime string if it has time component, else just date
                if ( strpos( $pay_date_raw, ':' ) !== false ) {
                    $pay_date = $ts ? date( 'Y-m-d H:i:s', $ts ) : $pay_date_raw;
                } else {
                    $pay_date = $ts ? date( 'Y-m-d', $ts ) : $pay_date_raw;
                }
            } else {
                $pay_date = '';
            }
            $export_payments[] = [
                'date'    => $pay_date,
                'amount'  => (string) ( $pay['amount'] ?? '0' ),
                'method'  => $pay['method'] ?? 'cash',
                'user_id' => isset( $pay['user_id'] ) ? (int) $pay['user_id'] : null,
                'comment' => $pay['comment'] ?? ( $pay['note'] ?? '' ),
            ];
        }

        // ── Accounting status ──
        $acc_status = cig_export_meta( $post_id, '_cig_acc_status' ) ?: '';

        // ── Dates ──
        $post_date = $post->post_date; // "Y-m-d H:i:s"
        $created_date = substr( $post_date, 0, 10 ); // "Y-m-d"

        $sold_date_raw = cig_export_meta( $post_id, '_cig_sold_date' );
        $sold_date = null;
        if ( ! empty( $sold_date_raw ) ) {
            $ts = strtotime( $sold_date_raw );
            $sold_date = $ts ? date( 'Y-m-d', $ts ) : null;
        }

        $invoice_row = [
            'legacy_post_id'    => $post_id,
            'post_date'         => $post_date,
            'post_modified'     => $post->post_modified,
            'post_status'       => $post->post_status,
            'invoice_number'    => cig_export_meta( $post_id, '_cig_invoice_number' ) ?: '',
            'invoice_status'    => cig_export_meta( $post_id, '_cig_invoice_status' ) ?: 'standard',
            'lifecycle_status'  => cig_export_meta( $post_id, '_cig_lifecycle_status' ) ?: 'draft',
            'sold_date'         => $sold_date,
            'buyer_name'        => cig_export_meta( $post_id, '_cig_buyer_name' ) ?: '',
            'buyer_tax_id'      => cig_export_meta( $post_id, '_cig_buyer_tax_id' ) ?: '',
            'buyer_phone'       => cig_export_meta( $post_id, '_cig_buyer_phone' ) ?: '',
            'buyer_address'     => cig_export_meta( $post_id, '_cig_buyer_address' ) ?: '',
            'buyer_email'       => cig_export_meta( $post_id, '_cig_buyer_email' ) ?: '',
            'customer_post_id'  => (int) ( cig_export_meta( $post_id, '_cig_customer_id' ) ?: 0 ) ?: null,
            'author_wp_user_id' => (int) $post->post_author ?: null,
            'invoice_total'     => (string) ( cig_export_meta( $post_id, '_cig_invoice_total' ) ?: '0' ),
            'paid_amount'       => (string) ( cig_export_meta( $post_id, '_cig_paid_amount' ) ?: '0' ),
            'general_note'      => cig_export_meta( $post_id, '_cig_general_note' ) ?: '',
            'consultant_note'   => cig_export_meta( $post_id, '_cig_consultant_note' ) ?: '',
            'accountant_note'   => cig_export_meta( $post_id, '_cig_accountant_note' ) ?: '',
            'acc_status'        => $acc_status,
            'items'             => $export_items,
            'payments'          => $export_payments,
        ];

        // MD5 hash of all mutable fields — used for change detection on sync
        // post_modified alone is unreliable (wp_postmeta updates don't touch it)
        $invoice_row['content_hash'] = md5( json_encode( [
            $invoice_row['post_status'],
            $invoice_row['invoice_status'],
            $invoice_row['lifecycle_status'],
            $invoice_row['invoice_total'],
            $invoice_row['paid_amount'],
            $invoice_row['buyer_name'],
            $invoice_row['buyer_tax_id'],
            $invoice_row['buyer_phone'],
            $invoice_row['buyer_address'],
            $invoice_row['buyer_email'],
            $invoice_row['general_note'],
            $invoice_row['consultant_note'],
            $invoice_row['accountant_note'],
            $invoice_row['acc_status'],
            $invoice_row['sold_date'],
            $invoice_row['items'],
            $invoice_row['payments'],
        ] ) );

        $invoices[] = $invoice_row;
    }

    return $invoices;
}

// ── Main export ───────────────────────────────────────────────────────────────

$users     = cig_export_users();
$customers = cig_export_customers();
$deposits  = cig_export_deposits();
$invoices  = cig_export_invoices();

$output = [
    'meta' => [
        'version'     => '1.1',
        'exported_at' => date( 'c' ),
        'source_url'  => get_site_url(),
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

$json = json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

if ( $is_cli ) {
    $filename = 'gn-export-' . date( 'Y-m-d-His' ) . '.json';
    file_put_contents( $filename, $json );
    echo "Export complete: {$filename}\n";
    echo "Invoices: " . count( $invoices ) . "\n";
    echo "Customers: " . count( $customers ) . "\n";
    echo "Deposits: " . count( $deposits ) . "\n";
    echo "Users: " . count( $users ) . "\n";
} else {
    $filename = 'gn-export-' . date( 'Y-m-d-His' ) . '.json';
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . strlen( $json ) );
    echo $json;
}
