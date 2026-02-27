<?php
/**
 * Post-migration data validator — verifies integrity of migrated data.
 */
class CIG_Data_Validator {

    private $results = [];

    /**
     * Run all validation checks.
     * Returns array of [ 'label' => string, 'pass' => bool, 'expected' => mixed, 'actual' => mixed ]
     */
    public function validate() {
        $this->results = [];

        $this->check_record_counts();
        $this->check_financial_integrity();
        $this->check_accounting_status();
        $this->check_lifecycle_mapping();
        $this->check_referential_integrity();

        return $this->results;
    }

    private function check_record_counts() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        // Invoice count
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}invoices" );
        $legacy_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'cig_invoice' AND post_status = 'publish'"
        );
        $this->add_result( 'Invoice count', $count === $legacy_count, $legacy_count, $count );

        // Customer count
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}customers" );
        $this->add_result( 'Customer count', $count > 0, '> 0', $count );

        // Deposit count
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}deposits" );
        $legacy_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'cig_deposit' AND post_status = 'publish'"
        );
        $this->add_result( 'Deposit count', $count === $legacy_count, $legacy_count, $count );

        // User count
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}users" );
        $this->add_result( 'User count', $count > 0, '> 0', $count );
    }

    private function check_financial_integrity() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        // Total invoice amounts
        $total = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(total_amount), 0) FROM {$prefix}invoices WHERE status = 'standard'"
        );
        $this->add_result( 'Total invoice revenue', $total > 0, '> 0', number_format( $total, 2 ) . ' GEL' );

        // Deposit ledger
        $deposits = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(amount), 0) FROM {$prefix}deposits"
        );
        $this->add_result( 'Deposit ledger sum', true, 'N/A', number_format( $deposits, 2 ) . ' GEL' );

        // Check paid_amount consistency: every invoice's paid_amount should match its payments
        $mismatched = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}invoices i
             WHERE ABS(i.paid_amount - (
                SELECT COALESCE(SUM(p.amount), 0) FROM {$prefix}payments p
                WHERE p.invoice_id = i.id AND p.method != 'consignment'
             )) > 0.01"
        );
        $this->add_result( 'Paid amount consistency', $mismatched === 0, 0, $mismatched . ' mismatched' );
    }

    private function check_accounting_status() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        $rs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}invoices WHERE is_rs_uploaded = 1" );
        $this->add_result( 'RS uploaded count', $rs > 0, '> 0', $rs );

        $corrected = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}invoices WHERE is_corrected = 1" );
        $this->add_result( 'Corrected count', true, 'N/A', $corrected );

        $credit = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}invoices WHERE is_credit_checked = 1" );
        $this->add_result( 'Credit checked count', true, 'N/A', $credit );

        $receipt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}invoices WHERE is_receipt_checked = 1" );
        $this->add_result( 'Receipt checked count', true, 'N/A', $receipt );
    }

    private function check_lifecycle_mapping() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        $sold = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}invoices WHERE lifecycle_status = 'sold'"
        );
        $this->add_result( 'Sold (was completed)', $sold > 0, '> 0', $sold );

        $reserved = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}invoices WHERE lifecycle_status = 'reserved'"
        );
        $this->add_result( 'Reserved (was reserved)', $reserved >= 0, 'N/A', $reserved );

        // Check no invoices are stuck with legacy 'active' value (indicates unfixed migration)
        $active_stuck = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}invoices WHERE lifecycle_status = 'active'"
        );
        $this->add_result( 'No stuck active lifecycle', $active_stuck === 0, 0, $active_stuck );

        $draft = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}invoices WHERE lifecycle_status = 'draft'"
        );
        $this->add_result( 'Draft (was unfinished)', true, 'N/A', $draft );
    }

    private function check_referential_integrity() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'cig_';

        // Orphaned items (invoice_id not in invoices)
        $orphaned_items = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}invoice_items ii
             LEFT JOIN {$prefix}invoices i ON ii.invoice_id = i.id
             WHERE i.id IS NULL"
        );
        $this->add_result( 'No orphaned items', $orphaned_items === 0, 0, $orphaned_items );

        // Orphaned payments
        $orphaned_payments = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}payments p
             LEFT JOIN {$prefix}invoices i ON p.invoice_id = i.id
             WHERE i.id IS NULL"
        );
        $this->add_result( 'No orphaned payments', $orphaned_payments === 0, 0, $orphaned_payments );

        // Invoice customer references
        $bad_customers = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}invoices i
             WHERE i.customer_id IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM {$prefix}customers c WHERE c.id = i.customer_id)"
        );
        $this->add_result( 'All customer refs valid', $bad_customers === 0, 0, $bad_customers );

        // Invoice author references
        $bad_authors = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}invoices i
             WHERE i.author_id IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM {$prefix}users u WHERE u.id = i.author_id)"
        );
        $this->add_result( 'All author refs valid', $bad_authors === 0, 0, $bad_authors );
    }

    private function add_result( $label, $pass, $expected, $actual ) {
        $this->results[] = [
            'label'    => $label,
            'pass'     => $pass,
            'expected' => $expected,
            'actual'   => $actual,
        ];
    }
}
