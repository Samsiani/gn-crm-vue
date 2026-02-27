<?php
/**
 * WooCommerce admin stock display for CIG reserved counts.
 * Loaded only when WooCommerce is active (guarded in cig-headless.php).
 *
 * Adds:
 *  - "Reserved" column to the WC product list (zero extra queries via batch cache)
 *  - Stock breakdown panel in the product edit Inventory tab
 */
class CIG_WC_Stock {

    /** @var array<int,int>|null  product_id => reserved_qty, null = not primed */
    private static ?array $reserved_cache = null;

    public static function init(): void {
        add_filter( 'the_posts',                          [ __CLASS__, 'prime_cache' ], 10, 2 );
        add_filter( 'manage_product_posts_columns',       [ __CLASS__, 'add_column' ] );
        add_action( 'manage_product_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
        add_action( 'woocommerce_product_options_stock_fields', [ __CLASS__, 'render_metabox' ] );
    }

    /**
     * Fires once when WP loads the product list. Collects all post IDs,
     * runs ONE batch query, stores result in static cache.
     */
    public static function prime_cache( array $posts, \WP_Query $query ): array {
        if ( ! is_admin() || $query->get('post_type') !== 'product' || ! $query->is_main_query() ) {
            return $posts;
        }
        $ids = array_map( fn($p) => (int)$p->ID, $posts );
        if ( empty( $ids ) ) return $posts;

        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count($ids), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ii.product_id, COALESCE(SUM(ii.qty),0) AS reserved_qty
             FROM {$wpdb->prefix}cig_invoice_items ii
             INNER JOIN {$wpdb->prefix}cig_invoices i ON ii.invoice_id = i.id
             WHERE ii.item_status = 'reserved'
               AND i.status = 'standard'
               AND ii.product_id IN ({$ph})
             GROUP BY ii.product_id",
            ...$ids
        ), ARRAY_A );

        self::$reserved_cache = [];
        foreach ( $rows as $row ) {
            self::$reserved_cache[ (int)$row['product_id'] ] = (int)$row['reserved_qty'];
        }
        return $posts;
    }

    /**
     * Insert "Reserved" column after the stock column in the product list.
     */
    public static function add_column( array $cols ): array {
        $pos  = array_search( 'is_in_stock', array_keys($cols), true );
        $head = [ 'cig_reserved' => '<span title="Reserved in CIG invoices">Reserved</span>' ];
        return $pos !== false
            ? array_slice($cols, 0, $pos + 1, true) + $head + array_slice($cols, $pos + 1, null, true)
            : $cols + $head;
    }

    /**
     * Render the reserved count cell — pure array lookup, 0 extra queries.
     */
    public static function render_column( string $col, int $post_id ): void {
        if ( $col !== 'cig_reserved' ) return;
        $reserved = self::$reserved_cache[ $post_id ] ?? 0;
        if ( $reserved > 0 ) {
            echo "<mark style='background:#fef3c7;color:#b45309;padding:2px 6px;"
               . "border-radius:3px;font-weight:600'>{$reserved} reserved</mark>";
        } else {
            echo '<span style="color:#aaa">—</span>';
        }
    }

    /**
     * Render stock breakdown inside the Inventory tab on the product edit page.
     * Single targeted query — acceptable for single-product context.
     */
    public static function render_metabox(): void {
        global $post, $wpdb;
        $pid      = (int) $post->ID;
        $reserved = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(ii.qty),0)
             FROM {$wpdb->prefix}cig_invoice_items ii
             INNER JOIN {$wpdb->prefix}cig_invoices i ON ii.invoice_id = i.id
             WHERE ii.product_id = %d AND ii.item_status = 'reserved' AND i.status = 'standard'",
            $pid
        ) );
        $wc_product = wc_get_product( $pid );
        $stock      = (int)( $wc_product ? $wc_product->get_stock_quantity() ?? 0 : 0 );
        $available  = max( 0, $stock - $reserved );
        $avail_color = $available > 0 ? '#16a34a' : '#dc2626';

        echo '<div class="options_group" style="border-top:1px solid #eee;padding:10px 12px 4px">';
        echo '<p style="margin:0 0 4px;font-weight:600;color:#1d2327">CIG Invoice Stock Breakdown</p>';
        echo "<p style='margin:2px 0'>Total stock: <strong>{$stock}</strong></p>";
        echo "<p style='margin:2px 0'>Reserved in invoices: <strong style='color:#b45309'>{$reserved}</strong></p>";
        echo "<p style='margin:2px 0'>Available: <strong style='color:{$avail_color}'>{$available}</strong></p>";
        echo '</div>';
    }

} // end class CIG_WC_Stock
