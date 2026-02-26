<?php
/**
 * Plugin activator — creates all custom database tables via dbDelta().
 */
class CIG_Activator {

    public static function activate() {
        self::create_tables();
        self::seed_company();
        update_option( 'cig_db_version', CIG_VERSION );
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix . 'cig_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Invoices ──
        dbDelta( "CREATE TABLE {$prefix}invoices (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            legacy_post_id        BIGINT UNSIGNED NULL,
            invoice_number        VARCHAR(30) NOT NULL,
            customer_id           BIGINT UNSIGNED NULL,
            status                VARCHAR(20) NOT NULL DEFAULT 'standard',
            lifecycle_status      VARCHAR(20) NOT NULL DEFAULT 'draft',
            total_amount          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            paid_amount           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at            DATE NOT NULL,
            sold_date             DATE NULL,
            sale_date             DATE NULL,
            author_id             BIGINT UNSIGNED NULL,
            buyer_name            VARCHAR(500) NOT NULL DEFAULT '',
            buyer_tax_id          VARCHAR(100) NOT NULL DEFAULT '',
            buyer_phone           VARCHAR(100) NOT NULL DEFAULT '',
            buyer_address         VARCHAR(500) NOT NULL DEFAULT '',
            buyer_email           VARCHAR(200) NOT NULL DEFAULT '',
            general_note          TEXT,
            consultant_note       TEXT,
            accountant_note       TEXT,
            is_rs_uploaded        TINYINT(1) NOT NULL DEFAULT 0,
            is_credit_checked     TINYINT(1) NOT NULL DEFAULT 0,
            is_receipt_checked    TINYINT(1) NOT NULL DEFAULT 0,
            is_corrected          TINYINT(1) NOT NULL DEFAULT 0,
            rs_uploaded_by        BIGINT UNSIGNED NULL,
            rs_uploaded_date      DATETIME NULL,
            created_datetime      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_datetime      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_invoice_number (invoice_number),
            KEY idx_customer (customer_id),
            KEY idx_author (author_id),
            KEY idx_status (status),
            KEY idx_lifecycle (lifecycle_status),
            KEY idx_created (created_at),
            KEY idx_sold (sold_date),
            KEY idx_status_lifecycle (status, lifecycle_status),
            KEY idx_created_status (created_at, status),
            KEY idx_buyer_tax (buyer_tax_id(20)),
            KEY idx_buyer_name (buyer_name(50))
        ) $charset;" );

        // ── Invoice Items ──
        dbDelta( "CREATE TABLE {$prefix}invoice_items (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id          BIGINT UNSIGNED NOT NULL,
            sort_order          INT NOT NULL DEFAULT 0,
            product_id          BIGINT UNSIGNED NULL,
            legacy_product_id   BIGINT UNSIGNED NULL,
            name                VARCHAR(500) NOT NULL DEFAULT '',
            brand               VARCHAR(200) NOT NULL DEFAULT '',
            sku                 VARCHAR(100) NOT NULL DEFAULT '',
            description         TEXT,
            image_url           VARCHAR(500) NOT NULL DEFAULT '',
            qty                 DECIMAL(10,2) NOT NULL DEFAULT 1,
            price               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            item_status         VARCHAR(20) NOT NULL DEFAULT 'none',
            reservation_days    INT NOT NULL DEFAULT 0,
            warranty            VARCHAR(20) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_invoice (invoice_id),
            KEY idx_product (product_id)
        ) $charset;" );

        // ── Payments ──
        dbDelta( "CREATE TABLE {$prefix}payments (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id        BIGINT UNSIGNED NOT NULL,
            payment_date      DATE NOT NULL,
            amount            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            method            VARCHAR(30) NOT NULL,
            comment           TEXT,
            user_id           BIGINT UNSIGNED NULL,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_invoice (invoice_id),
            KEY idx_date (payment_date),
            KEY idx_method (method)
        ) $charset;" );

        // ── Customers ──
        dbDelta( "CREATE TABLE {$prefix}customers (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            legacy_post_id    BIGINT UNSIGNED NULL,
            legacy_term_id    INT UNSIGNED NULL,
            name              VARCHAR(500) NOT NULL DEFAULT '',
            name_en           VARCHAR(500) NOT NULL DEFAULT '',
            tax_id            VARCHAR(100) NOT NULL DEFAULT '',
            address           VARCHAR(500) NOT NULL DEFAULT '',
            phone             VARCHAR(100) NOT NULL DEFAULT '',
            email             VARCHAR(200) NOT NULL DEFAULT '',
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_tax_id (tax_id),
            KEY idx_legacy_term (legacy_term_id),
            KEY idx_legacy_post (legacy_post_id),
            KEY idx_name (name(50)),
            KEY idx_name_en (name_en(50))
        ) $charset;" );

        // ── Products ──
        dbDelta( "CREATE TABLE {$prefix}products (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            legacy_post_id      BIGINT UNSIGNED NULL,
            sku                 VARCHAR(100) NOT NULL DEFAULT '',
            name                VARCHAR(500) NOT NULL DEFAULT '',
            name_ka             VARCHAR(500) NOT NULL DEFAULT '',
            brand               VARCHAR(200) NOT NULL DEFAULT '',
            description         TEXT,
            image_url           VARCHAR(500) NOT NULL DEFAULT '',
            price               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            stock               INT NOT NULL DEFAULT 0,
            reserved            INT NOT NULL DEFAULT 0,
            category            VARCHAR(100) NOT NULL DEFAULT '',
            is_active           TINYINT(1) NOT NULL DEFAULT 1,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_sku (sku),
            KEY idx_legacy (legacy_post_id)
        ) $charset;" );

        // ── Deposits ──
        dbDelta( "CREATE TABLE {$prefix}deposits (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            legacy_post_id    BIGINT UNSIGNED NULL,
            deposit_date      DATE NOT NULL,
            amount            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            type              VARCHAR(10) NOT NULL DEFAULT 'credit',
            note              TEXT,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_date (deposit_date)
        ) $charset;" );

        // ── Other Deliveries ──
        dbDelta( "CREATE TABLE {$prefix}other_deliveries (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            delivery_date     DATE NOT NULL,
            amount            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            note              TEXT,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_date (delivery_date)
        ) $charset;" );

        // ── Users ──
        dbDelta( "CREATE TABLE {$prefix}users (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id        BIGINT UNSIGNED NULL,
            name              VARCHAR(200) NOT NULL DEFAULT '',
            name_en           VARCHAR(200) NOT NULL DEFAULT '',
            avatar            VARCHAR(10) NOT NULL DEFAULT '',
            role              VARCHAR(20) NOT NULL DEFAULT 'sales',
            is_active         TINYINT(1) NOT NULL DEFAULT 1,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_wp_user (wp_user_id)
        ) $charset;" );

        // ── Company (single-row config) ──
        dbDelta( "CREATE TABLE {$prefix}company (
            id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name                    VARCHAR(200) NOT NULL DEFAULT '',
            name_ka                 VARCHAR(200) NOT NULL DEFAULT '',
            tax_id                  VARCHAR(50) NOT NULL DEFAULT '',
            address                 VARCHAR(500) NOT NULL DEFAULT '',
            phone                   VARCHAR(50) NOT NULL DEFAULT '',
            email                   VARCHAR(200) NOT NULL DEFAULT '',
            website                 VARCHAR(200) NOT NULL DEFAULT '',
            bank_name_1             VARCHAR(200) NOT NULL DEFAULT '',
            iban_1                  VARCHAR(50) NOT NULL DEFAULT '',
            bank_name_2             VARCHAR(200) NOT NULL DEFAULT '',
            iban_2                  VARCHAR(50) NOT NULL DEFAULT '',
            director_name           VARCHAR(200) NOT NULL DEFAULT '',
            reservation_days        INT NOT NULL DEFAULT 14,
            starting_invoice_number INT NOT NULL DEFAULT 1001,
            invoice_prefix          VARCHAR(10) NOT NULL DEFAULT 'GN',
            hide_wp_admin_bar       TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset;" );

        // ── Stock Requests ──
        dbDelta( "CREATE TABLE {$prefix}stock_requests (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            legacy_post_id        BIGINT UNSIGNED NULL,
            product_id            BIGINT UNSIGNED NOT NULL,
            status                VARCHAR(20) NOT NULL DEFAULT 'pending',
            request_date          DATETIME NOT NULL,
            changes               LONGTEXT NOT NULL,
            approver_id           BIGINT UNSIGNED NULL,
            processed_date        DATETIME NULL,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_product (product_id),
            KEY idx_status (status)
        ) $charset;" );

        // ── ID Map (temporary migration audit) ──
        dbDelta( "CREATE TABLE {$prefix}id_map (
            entity_type         VARCHAR(30) NOT NULL,
            legacy_id           BIGINT UNSIGNED NOT NULL,
            new_id              BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (entity_type, legacy_id),
            KEY idx_reverse (entity_type, new_id)
        ) $charset;" );

        // Add foreign keys after table creation (dbDelta doesn't handle FK)
        self::add_foreign_keys();
    }

    private static function add_foreign_keys() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        // Suppress errors — FK may already exist on re-activation
        $wpdb->suppress_errors( true );

        $wpdb->query( "ALTER TABLE {$prefix}invoice_items
            ADD CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id)
            REFERENCES {$prefix}invoices(id) ON DELETE CASCADE" );

        $wpdb->query( "ALTER TABLE {$prefix}payments
            ADD CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id)
            REFERENCES {$prefix}invoices(id) ON DELETE CASCADE" );

        $wpdb->suppress_errors( false );
    }

    /**
     * Add FULLTEXT indexes (dbDelta can't create these — must use ALTER TABLE).
     * Idempotent: checks INFORMATION_SCHEMA before adding.
     */
    public static function add_fulltext_indexes() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';
        $db     = DB_NAME;

        $wpdb->suppress_errors( true );

        // Invoices: FULLTEXT on buyer_name + buyer_tax_id for fast text search
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $db, $prefix . 'invoices', 'ft_buyer'
        ) );
        if ( ! $exists ) {
            $wpdb->query( "ALTER TABLE {$prefix}invoices
                ADD FULLTEXT KEY ft_buyer (buyer_name, buyer_tax_id)" );
        }

        // Customers: FULLTEXT on name + name_en
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $db, $prefix . 'customers', 'ft_customer_name'
        ) );
        if ( ! $exists ) {
            $wpdb->query( "ALTER TABLE {$prefix}customers
                ADD FULLTEXT KEY ft_customer_name (name, name_en)" );
        }

        // Products: FULLTEXT on name + name_ka + sku
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $db, $prefix . 'products', 'ft_product_name'
        ) );
        if ( ! $exists ) {
            $wpdb->query( "ALTER TABLE {$prefix}products
                ADD FULLTEXT KEY ft_product_name (name, name_ka, sku)" );
        }

        $wpdb->suppress_errors( false );
    }

    private static function seed_company() {
        global $wpdb;
        $table = $wpdb->prefix . 'cig_company';

        $exists = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $exists > 0 ) {
            return;
        }

        $wpdb->insert( $table, [
            'name'                    => 'GN Industrial',
            'name_ka'                 => 'GN ინდასტრიალ',
            'tax_id'                  => '404476218',
            'address'                 => 'თბილისი, ვაჟა-ფშაველას გამზ. 71',
            'phone'                   => '+995 599 123 456',
            'email'                   => 'info@gn-industrial.ge',
            'website'                 => 'www.gn-industrial.ge',
            'bank_name_1'             => 'საქართველოს ბანკი',
            'iban_1'                  => 'GE29BG0000000541851100',
            'bank_name_2'             => 'თიბისი ბანკი',
            'iban_2'                  => 'GE10TB7774936615100003',
            'director_name'           => 'გიორგი ნოზაძე',
            'reservation_days'        => 14,
            'starting_invoice_number' => 1001,
            'invoice_prefix'          => 'GN',
        ] );
    }
}
