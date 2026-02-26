<?php
/**
 * Plugin deactivator — optional cleanup on deactivation.
 * Does NOT drop tables (use WP-CLI rollback for that).
 */
class CIG_Deactivator {

    public static function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();

        // Optionally clear transients
        delete_transient( 'cig_migration_status' );
    }
}
