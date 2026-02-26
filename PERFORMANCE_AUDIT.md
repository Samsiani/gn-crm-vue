# Performance Audit Report — CIG Headless API v4.4.5

**Date:** 2026-02-26  
**Scope:** Full PHP codebase (`api/`, `models/`, `middleware/`, `includes/`, `migration/`, `cli/`)  
**Status:** Report only — no code changes made

---

## Executive Summary

The codebase is well-structured with good patterns already in place (batch-fetched invoice items/payments, KPI transient caching, proper pagination). This audit identifies **32 improvement opportunities** across 6 categories, prioritised by impact.

### Scorecard

| Category | Issues | Critical | High | Medium | Low |
|----------|--------|----------|------|--------|-----|
| SQL Safety & Prepared Statements | 4 | 2 | 1 | 1 | — |
| Query Performance & Indexing | 8 | — | 4 | 3 | 1 |
| Caching Gaps | 5 | — | 2 | 2 | 1 |
| N+1 & Redundant Queries | 4 | — | 2 | 1 | 1 |
| Input Validation | 4 | — | 1 | 2 | 1 |
| Bootstrap & Loading | 3 | — | 1 | 1 | 1 |
| Migration-specific | 4 | — | 1 | 2 | 1 |

---

## 1. SQL Safety & Prepared Statements

### 1.1 ⛔ CRITICAL — Unescaped `$prefix` in `generate_number()`

**File:** `models/class-cig-invoice.php` — Lines 315–319

```php
$last_number = $wpdb->get_var(
    "SELECT MAX(CAST(REPLACE(invoice_number, '{$prefix}', '') AS UNSIGNED))
     FROM " . self::table() . "
     WHERE invoice_number LIKE '{$prefix}%'"
);
```

**Risk:** The `$prefix` comes from `CIG_Company::get()['invoicePrefix']`, which is user-editable via the Company Settings UI. A value containing `'` or `%` would break the query or alter its logic.

**Recommendation:** Use `$wpdb->prepare()`:
```php
$wpdb->prepare(
    "SELECT MAX(CAST(REPLACE(invoice_number, %s, '') AS UNSIGNED))
     FROM " . self::table() . " WHERE invoice_number LIKE %s",
    $prefix,
    $wpdb->esc_like( $prefix ) . '%'
);
```

---

### 1.2 ⛔ CRITICAL — Raw SQL in `clear_kpi_cache()`

**File:** `models/class-cig-invoice.php` — Lines 816–820

```php
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_cig_kpi_%'
        OR option_name LIKE '_transient_timeout_cig_kpi_%'"
);
```

**Risk:** While the LIKE patterns are hardcoded strings (low injection risk), this bypasses `$wpdb->prepare()` which is a WordPress best practice for all queries. It also directly manipulates the options table instead of using the Transients API.

**Recommendation:** Either wrap in `$wpdb->prepare()` or use `delete_transient()` with enumerated keys.

---

### 1.3 🟡 HIGH — `intval`-concatenated IN clauses

**Files:**
- `models/class-cig-invoice.php` — Lines 187, 197 (batch item/payment fetch)
- `models/class-cig-user.php` — Line 201 (`batch_invoice_stats`)

```php
$ids_sql = implode( ',', array_map( 'intval', $invoice_ids ) );
$all_items = $wpdb->get_results(
    "SELECT * FROM {$items_table} WHERE invoice_id IN ({$ids_sql}) ..."
);
```

**Risk:** `intval()` sanitises the values, so this is safe. However, it bypasses `$wpdb->prepare()`, making it harder to audit. With very large result sets (thousands of IDs), the SQL string can become extremely long.

**Recommendation:** Use `$wpdb->prepare()` with dynamically generated placeholders:
```php
$placeholders = implode( ',', array_fill( 0, count( $invoice_ids ), '%d' ) );
$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", ...$invoice_ids );
```

---

### 1.4 🟠 MEDIUM — Concatenated date conditions in KPI methods

**File:** `models/class-cig-invoice.php` — Lines 386–393, 604–611

```php
$inv_date = '';
if ( $from ) $inv_date .= $wpdb->prepare( ' AND i.created_at >= %s', $from );
```

These are correctly prepared individually, but they are then appended to un-prepared base queries (e.g., line 405: `WHERE i.status = 'standard' {$inv_date}`). This mixed approach works but makes auditing difficult.

**Recommendation:** Use a consistent `$where[]` / `$params[]` builder pattern (as already done in `list()`).

---

## 2. Query Performance & Indexing

### Already Existing Indexes (Good ✅)

The activator already creates a comprehensive set:
- `invoices`: idx_customer, idx_author, idx_status, idx_lifecycle, idx_created, idx_sold, idx_status_lifecycle, idx_created_status, idx_buyer_tax, idx_buyer_name, ft_buyer
- `invoice_items`: idx_invoice, idx_product, idx_invoice_status, idx_item_status, idx_product_status
- `payments`: idx_invoice, idx_date, idx_method, idx_invoice_method, idx_method_date
- `customers`: idx_tax_id, idx_name, idx_name_en, ft_customer_name
- `deposits`: idx_date
- `users`: idx_wp_user, idx_role, idx_is_active_role

### 2.1 🟡 HIGH — Correlated EXISTS subqueries in lifecycle filters

**File:** `models/class-cig-invoice.php` — Lines 78–98

```sql
-- 'sold' lifecycle filter
(i.lifecycle_status IN ('sold','completed') OR (
    i.lifecycle_status = 'active' AND NOT EXISTS (
        SELECT 1 FROM wp_cig_invoice_items it
        WHERE it.invoice_id = i.id AND it.item_status != 'sold'
    ) AND EXISTS (
        SELECT 1 FROM wp_cig_invoice_items it2 WHERE it2.invoice_id = i.id
    )
))
```

**Impact:** Two correlated subqueries execute per invoice row. With thousands of invoices, this is O(N × M) where M is the average item count.

**Recommendation:**
- **Short-term:** The existing `idx_invoice_status (invoice_id, item_status)` index covers this — verify it's being used via `EXPLAIN`.
- **Long-term:** Denormalise lifecycle status on invoice save (trigger or application-level). The `lifecycle_status` column already exists — ensure it's always kept in sync so the EXISTS subqueries can be eliminated entirely.

---

### 2.2 🟡 HIGH — Payment method EXISTS filter

**File:** `models/class-cig-invoice.php` — Lines 110–115

```sql
EXISTS (
    SELECT 1 FROM wp_cig_payments p
    WHERE p.invoice_id = i.id AND p.method = %s
)
```

**Impact:** Correlated subquery per invoice row. The `idx_invoice_method (invoice_id, method)` index exists, which helps, but a JOIN would let MySQL optimise better.

**Recommendation:** Rewrite as:
```sql
INNER JOIN (SELECT DISTINCT invoice_id FROM wp_cig_payments WHERE method = %s) pm
    ON pm.invoice_id = i.id
```

---

### 2.3 🟡 HIGH — Unbounded `top_users` query

**File:** `models/class-cig-invoice.php` — Lines 649–655

```sql
SELECT i.author_id, COUNT(*), SUM(i.paid_amount) AS revenue
FROM wp_cig_invoices i
WHERE i.status = 'standard' AND i.author_id IS NOT NULL
GROUP BY i.author_id
ORDER BY revenue DESC
-- NO LIMIT
```

**Impact:** Returns every user. With many authors, unnecessary data is fetched and serialised.

**Recommendation:** Add `LIMIT 20` (or however many the frontend needs).

---

### 2.4 🟡 HIGH — Unbounded product performance query

**File:** `models/class-cig-invoice.php` — Lines 744–760

```sql
SELECT it.product_id, ..., SUM(it.qty * it.price) AS revenue
FROM wp_cig_invoice_items it
JOIN wp_cig_invoices i ON i.id = it.invoice_id
WHERE i.status = 'standard' AND it.item_status != 'canceled'
GROUP BY it.product_id
ORDER BY revenue DESC
-- NO LIMIT
```

**Impact:** Returns every product ever invoiced. Could be thousands of rows.

**Recommendation:** Add `LIMIT 50` or paginate, depending on frontend needs.

---

### 2.5 🟠 MEDIUM — Unbounded customer insights query

**File:** `models/class-cig-invoice.php` — Lines 773–783

```sql
SELECT i.customer_id, SUM(...), COUNT(*)
FROM wp_cig_invoices i
WHERE i.status = 'standard' AND i.customer_id IS NOT NULL
GROUP BY i.customer_id
-- NO LIMIT
```

**Impact:** Same issue — returns every customer who has ever been invoiced.

---

### 2.6 🟠 MEDIUM — PHP-side sorting for expiring reservations

**File:** `models/class-cig-invoice.php` — Lines 503–548

```php
// SQL fetches 50 rows
LIMIT 50
// Then PHP calculates daysRemaining for each
foreach ( $res_rows as $r ) {
    $days_elapsed = (int) floor( ( time() - strtotime( $r['created_at'] ) ) / DAY_IN_SECONDS );
    ...
}
usort( $expiring, fn( $a, $b ) => $a['daysRemaining'] - $b['daysRemaining'] );
$expiring = array_slice( $expiring, 0, 20 );
```

**Impact:** Fetches 50 rows, calculates in PHP, sorts, then discards 30. The SQL could handle this directly.

**Recommendation:** Move calculation to SQL:
```sql
SELECT ...,
    (reservation_days - DATEDIFF(CURDATE(), i.created_at)) AS days_remaining
...
ORDER BY days_remaining ASC
LIMIT 20
```

---

### 2.7 🟠 MEDIUM — `get_balance()` full-table SUM

**File:** `models/class-cig-deposit.php` — Lines 92–96

```php
return (float) $wpdb->get_var(
    "SELECT COALESCE(SUM(amount), 0) FROM " . self::table()
);
```

**Impact:** Scans the entire deposits table on every call. No WHERE clause, no caching.

**Recommendation:** Cache with a transient (clear on deposit create/delete) or maintain a running balance.

---

### 2.8 🔵 LOW — LIKE-based search without FULLTEXT fallback

**File:** `models/class-cig-invoice.php` — Lines 148–153

```sql
(i.invoice_number LIKE %s OR i.buyer_name LIKE %s OR i.buyer_tax_id LIKE %s)
```

**Note:** A FULLTEXT index `ft_buyer` on `(buyer_name, buyer_tax_id)` already exists but isn't used here. The LIKE with leading `%` prevents index usage on buyer_name and buyer_tax_id columns.

**Recommendation:** For short search terms, keep LIKE. For longer terms (3+ chars), switch to `MATCH() AGAINST()` using the existing FULLTEXT index for `buyer_name` and `buyer_tax_id`. Keep `invoice_number LIKE` separately since it's an exact prefix match (covered by the UNIQUE index).

---

## 3. Caching Gaps

### 3.1 🟡 HIGH — Company config not cached

**File:** `models/class-cig-company.php` — Lines 15–20

```php
public static function get() {
    global $wpdb;
    $row = $wpdb->get_row( "SELECT * FROM " . self::table() . " LIMIT 1", ARRAY_A );
    ...
}
```

**Impact:** This single-row table is queried on every request that needs company data — at minimum: `generate_number()`, `maybe_hide_admin_bar()`, and potentially others. The data rarely changes.

**Recommendation:** Add a static property cache + WordPress object cache:
```php
private static $cached = null;

public static function get() {
    if ( self::$cached !== null ) return self::$cached;
    // ... query ...
    self::$cached = self::hydrate( $row );
    return self::$cached;
}

public static function update( $data ) {
    self::$cached = null; // Invalidate
    // ... existing update logic ...
}
```

---

### 3.2 🟡 HIGH — Authenticated user not cached per-request

**File:** `middleware/class-cig-rbac.php` — Lines 10–96

Every RBAC callback calls `CIG_Auth_Middleware::get_current_user( $request )`, which:
1. Decodes the JWT token (CPU-intensive)
2. Runs `CIG_User::find()` (database query)
3. Or falls back to `get_current_user_id()` + `CIG_User::find_by_wp_user()` (another DB query)

The RBAC layer already sets `$request->set_param( '_cig_user', $user )`, but `get_current_user()` doesn't check for this cached value.

**Recommendation:** Add early return in `get_current_user()`:
```php
public static function get_current_user( $request ) {
    $cached = $request->get_param( '_cig_user' );
    if ( $cached && is_array( $cached ) ) {
        return $cached;
    }
    // ... existing logic ...
}
```
This ensures the user is resolved only once per request, even if multiple permission callbacks fire.

---

### 3.3 🟠 MEDIUM — Deposit balance not cached

**File:** `models/class-cig-deposit.php` — Lines 92–96

`get_balance()` runs `SUM(amount)` across all deposits with no caching.

**Recommendation:** Cache with transient, invalidate on `create()` and `delete()`.

---

### 3.4 🟠 MEDIUM — KPI cache invalidation is too aggressive

**File:** `models/class-cig-invoice.php` — Lines 814–821

```php
private static function clear_kpi_cache() {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_cig_kpi_%' ..."
    );
}
```

**Impact:** Every invoice create/update/delete wipes ALL KPI transients, even if the change only affects one time period. This causes unnecessary cache rebuilds.

**Recommendation:** Use targeted invalidation — delete only transients whose date range overlaps the modified invoice's `created_at`.

---

### 3.5 🔵 LOW — Vite manifest file I/O on every page load

**File:** `includes/class-cig-frontend.php` — Lines 107–117

```php
$manifest_path = CIG_PLUGIN_DIR . 'dist/.vite/manifest.json';
if ( ! file_exists( $manifest_path ) ) { ... }
$contents = file_get_contents( $manifest_path );
$this->manifest = json_decode( $contents, true ) ?: false;
```

**Impact:** File I/O on every page with the shortcode. The manifest only changes on deployment.

**Current mitigation:** Already cached in `$this->manifest` per-instance. The overhead is negligible with OPcache enabled. No action required unless profiling shows a bottleneck.

---

## 4. N+1 & Redundant Queries

### 4.1 🟡 HIGH — WooCommerce product hydration (N+1)

**File:** `models/class-cig-product.php` — Lines 270–304 (`hydrate_wc`)

```php
private static function hydrate_wc( $wc ) {
    $id = $wc->get_id();
    $brand = get_post_meta( $id, '_cig_brand', true );           // 1 query per product
    $categories = wp_get_post_terms( $id, 'product_cat', ... );  // 1 query per product
    $image_url = $image_id ? wp_get_attachment_url( $image_id ) : ''; // 1 query per product
    $name_ka = get_post_meta( $id, '_cig_name_ka', true );       // 1 query per product
    $reserved = get_post_meta( $id, '_cig_reserved', true );     // 1 query per product
}
```

**Impact:** For a list of 100 products, this generates ~500 additional queries.

**Recommendation:** Before the `array_map` in `list_wc()`, batch-prime the WP object cache:
```php
// Prime meta cache for all product IDs
$product_ids = wp_list_pluck( $products, 'id' );
update_meta_cache( 'post', $product_ids );

// Prime term cache
update_object_term_cache( $product_ids, 'product' );
```
This makes subsequent `get_post_meta()` and `wp_get_post_terms()` calls use the in-memory cache.

---

### 4.2 🟡 HIGH — `find()` triggers 2 extra queries

**File:** `models/class-cig-invoice.php` — Lines 25–33, 838–845

When called via `find()` (single invoice lookup), `hydrate()` receives `null` for `$pre_items` and `$pre_pays`, triggering individual queries:
```php
$items_rows = $wpdb->get_results( ... WHERE invoice_id = %d ... );
$payment_rows = $wpdb->get_results( ... WHERE invoice_id = %d ... );
```

**Impact:** 3 queries per `find()` call. This is called after every `create()` and `update()` (lines 260, 293), so each write operation runs 3 extra queries to return the updated entity.

**Recommendation:** This is acceptable for single-entity operations. However, consider whether `create()` and `update()` need to return the full hydrated entity, or could return a lighter response.

---

### 4.3 🟠 MEDIUM — Double query in WooCommerce product count

**File:** `models/class-cig-product.php` — Lines 92–101

```php
$products = wc_get_products( $wc_args );           // Query 1: fetch products
$count_args['paginate'] = true;
$count_result = wc_get_products( $count_args );     // Query 2: count total
$total = $count_result->total;
```

**Recommendation:** Fetch with `paginate = true` in a single call:
```php
$wc_args['paginate'] = true;
$result = wc_get_products( $wc_args );
$products = $result->products;
$total = $result->total;
```

---

### 4.4 🔵 LOW — `update()` calls `find()` before and after

**File:** `models/class-cig-invoice.php` — Lines 269, 293

```php
$existing = self::find( $id );   // 3 queries (verify exists)
// ... update logic ...
return self::find( $id );        // 3 queries (return updated)
```

**Impact:** 6 queries just for existence check + return. The existence check could use a lighter query.

**Recommendation:** Replace the existence check with:
```php
$exists = $wpdb->get_var( $wpdb->prepare(
    "SELECT id FROM " . self::table() . " WHERE id = %d", $id
) );
```

---

## 5. Input Validation

### 5.1 🟡 HIGH — Date parameters not format-validated

**Files:**
- `models/class-cig-invoice.php` — Lines 382–383 (`get_dashboard_kpi`)
- `models/class-cig-invoice.php` — Lines 600–601 (`get_statistics_kpi`)
- `api/class-cig-invoices-controller.php`, `api/class-cig-deposits-controller.php`, `api/class-cig-deliveries-controller.php` — date_from/date_to parameters

```php
$from = sanitize_text_field( $args['date_from'] ?? '' );
```

**Issue:** `sanitize_text_field()` removes HTML but doesn't validate date format. Invalid strings like `"not-a-date"` are passed to SQL comparisons, which silently evaluate to `0000-00-00`.

**Recommendation:** Validate format at the controller level:
```php
$date = sanitize_text_field( $request->get_param( 'date_from' ) ?: '' );
if ( $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
    return new WP_Error( 'invalid_date', 'date_from must be YYYY-MM-DD', [ 'status' => 400 ] );
}
```

---

### 5.2 🟠 MEDIUM — No max length on search parameters

**Files:** All `list()` methods in models

```php
$search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
```

**Issue:** An extremely long search string (e.g., 10,000+ characters) creates a very large LIKE pattern, which is slow and wastes memory.

**Recommendation:** Truncate at the controller level:
```php
$args['search'] = mb_substr( sanitize_text_field( $request->get_param('search') ?: '' ), 0, 200 );
```

---

### 5.3 🟠 MEDIUM — `find_by_login` function-based matching prevents index use

**File:** `models/class-cig-user.php` — Lines 42–50

```sql
WHERE is_active = 1
  AND ( LOWER(name_en) = %s
     OR LOWER(SUBSTRING_INDEX(name_en, ' ', 1)) = %s
     OR LOWER(avatar) = %s )
```

**Issue:** `LOWER()` and `SUBSTRING_INDEX()` functions on columns prevent index usage. Full table scan on every login.

**Recommendation:** Since the users table is small (typically < 50 rows), this is acceptable. For future-proofing, consider storing a normalised `login_key` column with a computed index.

---

### 5.4 🔵 LOW — Category slug not validated for WooCommerce

**File:** `models/class-cig-product.php` — Line 88–90

```php
if ( ! empty( $args['category'] ) ) {
    $wc_args['category'] = [ $args['category'] ];
}
```

**Issue:** An invalid category slug silently returns no results.

**Recommendation:** Validate the slug exists before querying, or return a clear error.

---

## 6. Bootstrap & Loading

### 6.1 🟡 HIGH — All PHP classes eagerly loaded

**File:** `cig-headless.php` — Lines 38–66

```php
// 19 require_once statements executed on EVERY WordPress request
require_once CIG_PLUGIN_DIR . 'models/class-cig-invoice.php';
require_once CIG_PLUGIN_DIR . 'models/class-cig-customer.php';
// ... 17 more files ...
```

**Impact:** Every WordPress page load (admin, frontend, AJAX, cron) parses and compiles 19 PHP files, even when no CIG functionality is needed.

**Recommendation:**
- **Quick win:** Wrap model/controller loading inside the `plugins_loaded` callback or use a PSR-4 autoloader with `spl_autoload_register()` so files are loaded on demand.
- **With OPcache:** If OPcache is enabled (as it should be in production), the parsing overhead is negligible after the first request. Monitor before optimising.

---

### 6.2 🟠 MEDIUM — DB version check on every request

**File:** `cig-headless.php` — Lines 73–80

```php
add_action( 'plugins_loaded', function() {
    if ( get_option( 'cig_db_version' ) !== CIG_DB_VERSION ) {
        CIG_Activator::add_fulltext_indexes();
        CIG_Activator::add_media_columns();
        CIG_Activator::add_performance_indexes();
        update_option( 'cig_db_version', CIG_DB_VERSION );
    }
    // ...
});
```

**Impact:** Calls `get_option()` on every request. WordPress autoloads options, so this is typically a memory read, not a DB query. The impact is negligible.

**Recommendation:** No change needed — this is a standard WordPress pattern. If concerned, wrap in `wp_cache_get()` with a longer TTL.

---

### 6.3 🔵 LOW — Plugin update checker initialised eagerly

**File:** `cig-headless.php` — Lines 28–35

**Impact:** The update checker library is loaded and initialised on every request. However, `yahnis-elsts/plugin-update-checker` internally uses transients and only makes HTTP requests periodically. No action needed.

---

## 7. Migration-Specific Issues

> These only apply during data migration (WP-CLI) and don't affect runtime performance.

### 7.1 🟡 HIGH — N+1 queries in `migrate_products()`

**File:** `migration/class-cig-migrator.php` — Lines 175–210

```php
foreach ( array_keys( $all_product_post_ids ) as $post_id ) {
    $post  = get_post( $post_id );                          // 1 query
    $sku   = get_post_meta( $post_id, '_sku', true );       // 1 query
    $price = get_post_meta( $post_id, '_price', true );     // 1 query
    $stock = get_post_meta( $post_id, '_stock', true );     // 1 query
}
```

**Impact:** 4 queries per product × N products.

**Recommendation:** Batch with `update_meta_cache( 'post', $product_ids )` before the loop.

---

### 7.2 🟠 MEDIUM — No database transactions in migration

**Files:** `migration/class-cig-migrator.php` — Lines 582–800

Invoice items, payments, deposits, and stock requests are inserted in loops without wrapping in a transaction.

**Risk:** Partial migration on crash/timeout. No rollback capability.

**Recommendation:** Wrap each entity batch in:
```php
$wpdb->query( 'START TRANSACTION' );
try {
    // ... inserts ...
    $wpdb->query( 'COMMIT' );
} catch ( Exception $e ) {
    $wpdb->query( 'ROLLBACK' );
    throw $e;
}
```

---

### 7.3 🟠 MEDIUM — ID mapper uses single inserts

**File:** `migration/class-cig-id-mapper.php` — Line 15–22

```php
public static function set( $entity_type, $legacy_id, $new_id ) {
    $wpdb->replace( self::table(), [ ... ] );
}
```

**Impact:** Called hundreds/thousands of times during migration. Each call is a separate SQL statement.

**Recommendation:** Batch into multi-row INSERT statements or use `INSERT ... ON DUPLICATE KEY UPDATE`.

---

### 7.4 🔵 LOW — `recalculate_all_totals()` updates all rows at once

**File:** `migration/class-cig-migrator.php` — Lines 805–827

```sql
UPDATE wp_cig_invoices i SET total_amount = (
    SELECT COALESCE(SUM(ii.qty * ii.price), 0) FROM wp_cig_invoice_items ii WHERE ...
)
```

**Impact:** Updates every invoice in one statement. Could lock the table for a long time on large datasets.

**Recommendation:** Batch by ID range (e.g., 500 rows at a time).

---

## 8. Positive Patterns (Already Done Well ✅)

These patterns demonstrate good performance practices already in the codebase:

1. **Batch-fetched invoice items & payments** (`list()` method, lines 185–204) — Avoids N+1 for list endpoints
2. **KPI transient caching** with content-based cache keys (`get_dashboard_kpi`, `get_statistics_kpi`) — 5-minute TTL
3. **Proper pagination** on all list endpoints with `LIMIT`/`OFFSET`
4. **Sort column whitelisting** prevents SQL injection in ORDER BY clauses
5. **ORDER BY direction validation** (`ASC`/`DESC` only) across all models
6. **Conditional WP-CLI loading** — Migration code only loaded when WP_CLI is active
7. **Shortcode-conditional asset loading** — JS/CSS only enqueued on pages with `[cig_app]`
8. **Theme script/style dequeuing** — Reduces frontend payload on CIG pages
9. **Batch customer stats** (`CIG_Customer::list()`) — Stats fetched in single query, not per-customer

---

## 9. Recommended Action Plan

### Phase 1 — Quick Wins (1–2 hours)
- [ ] Fix `generate_number()` SQL injection (§1.1)
- [ ] Cache `CIG_Company::get()` with static property (§3.1)
- [ ] Cache authenticated user per-request in `get_current_user()` (§3.2)
- [ ] Add `LIMIT` to `top_users` and `product performance` queries (§2.3, §2.4, §2.5)
- [ ] Add date format validation in controllers (§5.1)

### Phase 2 — Medium Effort (half day)
- [ ] Move reservation expiry calculation to SQL (§2.6)
- [ ] Cache deposit balance with transient (§3.3)
- [ ] Use WC paginate=true for single-query product listing (§4.3)
- [ ] Batch-prime WP object cache before WC product hydration (§4.1)
- [ ] Add max length on search parameters (§5.2)

### Phase 3 — Architecture (1–2 days)
- [ ] Implement PSR-4 autoloader or lazy-load classes (§6.1)
- [ ] Refactor KPI cache invalidation to be targeted, not global (§3.4)
- [ ] Denormalise lifecycle_status to eliminate EXISTS subqueries (§2.1)
- [ ] Wrap migration in transactions (§7.2)
- [ ] Batch ID mapper inserts (§7.3)

---

## Appendix: Query Count Per Endpoint

| Endpoint | Queries (est.) | Notes |
|----------|---------------|-------|
| `GET /invoices` (list, 25/page) | 4 | 1 count + 1 list + 1 items batch + 1 payments batch |
| `GET /invoices/:id` | 3 | 1 invoice + 1 items + 1 payments |
| `POST /invoices` | ~8 | 1 company + 1 generate_number + 1 insert + N item inserts + 1 recalc + 3 find |
| `GET /kpi/dashboard` | 1 (cached) / 8 (cold) | Transient: 5 min TTL |
| `GET /kpi/statistics` | 1 (cached) / 12 (cold) | Transient: 5 min TTL |
| `GET /products` (WC mode, 100/page) | ~502 | 2 WC queries + 5 meta/term queries × 100 products |
| `GET /products` (table mode) | 2 | 1 count + 1 list |
| `GET /deposits/balance` | 1 | Full table SUM, uncached |
| Auth (per request) | 1–3 | JWT decode + user lookup (not cached per-request) |
