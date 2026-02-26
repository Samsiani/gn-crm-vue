<?php
/**
 * WP-CLI commands for CIG migration management.
 *
 * Usage:
 *   wp cig migrate --batch-size=50 --dry-run
 *   wp cig migrate --batch-size=50
 *   wp cig verify
 *   wp cig rollback
 *   wp cig status
 */
class CIG_CLI_Commands {

    /**
     * Run the legacy data migration.
     *
     * ## OPTIONS
     *
     * [--batch-size=<size>]
     * : Number of records per batch transaction.
     * ---
     * default: 50
     * ---
     *
     * [--dry-run]
     * : Run without writing to the database.
     *
     * ## EXAMPLES
     *
     *     wp cig migrate --batch-size=50
     *     wp cig migrate --dry-run
     *
     * @subcommand migrate
     */
    public function migrate( $args, $assoc_args ) {
        $batch_size = (int) ( $assoc_args['batch-size'] ?? 50 );
        $dry_run    = isset( $assoc_args['dry-run'] );

        // Ensure tables exist
        if ( ! $dry_run ) {
            WP_CLI::log( 'Creating/verifying tables...' );
            CIG_Activator::create_tables();
        }

        $migrator = new CIG_Migrator( $batch_size, $dry_run, function( $msg ) {
            WP_CLI::log( $msg );
        } );

        $result = $migrator->run();

        WP_CLI::log( '' );
        WP_CLI::log( '=== Summary ===' );
        foreach ( $result['stats'] as $label => $count ) {
            WP_CLI::log( "  {$label}: {$count}" );
        }

        if ( ! empty( $result['errors'] ) ) {
            WP_CLI::log( '' );
            WP_CLI::warning( count( $result['errors'] ) . ' errors occurred:' );
            foreach ( $result['errors'] as $error ) {
                WP_CLI::log( "  - {$error}" );
            }
        } else {
            WP_CLI::success( 'Migration completed successfully!' );
        }
    }

    /**
     * Verify migration integrity.
     *
     * ## EXAMPLES
     *
     *     wp cig verify
     *
     * @subcommand verify
     */
    public function verify( $args, $assoc_args ) {
        $validator = new CIG_Data_Validator();
        $results = $validator->validate();

        $pass_count = 0;
        $fail_count = 0;

        foreach ( $results as $r ) {
            $status = $r['pass'] ? "\033[32m[PASS]\033[0m" : "\033[31m[FAIL]\033[0m";
            $detail = "Expected: {$r['expected']}, Actual: {$r['actual']}";

            WP_CLI::log( "{$status} {$r['label']} — {$detail}" );

            if ( $r['pass'] ) {
                $pass_count++;
            } else {
                $fail_count++;
            }
        }

        WP_CLI::log( '' );
        WP_CLI::log( "Results: {$pass_count} passed, {$fail_count} failed" );

        if ( $fail_count > 0 ) {
            WP_CLI::warning( 'Some verification checks failed. Review before deploying.' );
        } else {
            WP_CLI::success( 'All verification checks passed!' );
        }
    }

    /**
     * Rollback migration — drops all custom CIG tables.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp cig rollback --yes
     *
     * @subcommand rollback
     */
    public function rollback( $args, $assoc_args ) {
        WP_CLI::confirm( 'This will DROP all CIG custom tables. Are you sure?', $assoc_args );

        CIG_Migrator::rollback();

        WP_CLI::success( 'All CIG tables dropped. Plugin data has been removed.' );
    }

    /**
     * Show current migration status and record counts.
     *
     * ## EXAMPLES
     *
     *     wp cig status
     *
     * @subcommand status
     */
    public function status( $args, $assoc_args ) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        $tables = [
            'invoices'         => 'Invoices',
            'invoice_items'    => 'Invoice Items',
            'payments'         => 'Payments',
            'customers'        => 'Customers',
            'products'         => 'Products',
            'users'            => 'Users',
            'deposits'         => 'Deposits',
            'other_deliveries' => 'Other Deliveries',
            'stock_requests'   => 'Stock Requests',
            'id_map'           => 'ID Mappings',
        ];

        $data = [];
        foreach ( $tables as $table => $label ) {
            $full_table = $prefix . $table;

            // Check if table exists
            $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$full_table}'" );
            if ( ! $exists ) {
                $data[] = [ $label, 'TABLE NOT FOUND', '' ];
                continue;
            }

            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$full_table}" );
            $data[] = [ $label, $count, $exists ? 'OK' : 'MISSING' ];
        }

        WP_CLI\Utils\format_items( 'table', $data, [ 'Entity', 'Count', 'Status' ] );

        // DB version
        $version = get_option( 'cig_db_version', 'not set' );
        WP_CLI::log( "DB Version: {$version}" );
    }
}
