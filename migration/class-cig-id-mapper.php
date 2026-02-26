<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * ID Mapper — manages cross-reference between legacy and new IDs.
 */
class CIG_ID_Mapper {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cig_id_map';
    }

    /**
     * Record a mapping: legacy_id → new_id for a given entity type.
     */
    public static function set( $entity_type, $legacy_id, $new_id ) {
        global $wpdb;
        $wpdb->replace( self::table(), [
            'entity_type' => $entity_type,
            'legacy_id'   => $legacy_id,
            'new_id'      => $new_id,
        ] );
    }

    /**
     * Look up the new ID for a legacy entity.
     */
    public static function get_new_id( $entity_type, $legacy_id ) {
        global $wpdb;
        if ( ! $legacy_id ) return null;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT new_id FROM " . self::table() . " WHERE entity_type = %s AND legacy_id = %d",
            $entity_type, $legacy_id
        ) );
    }

    /**
     * Look up the legacy ID for a new entity.
     */
    public static function get_legacy_id( $entity_type, $new_id ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT legacy_id FROM " . self::table() . " WHERE entity_type = %s AND new_id = %d",
            $entity_type, $new_id
        ) );
    }

    /**
     * Get all mappings for an entity type.
     */
    public static function get_all( $entity_type ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT legacy_id, new_id FROM " . self::table() . " WHERE entity_type = %s",
            $entity_type
        ), ARRAY_A );
    }

    /**
     * Clear all mappings (for rollback).
     */
    public static function clear_all() {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE " . self::table() );
    }

    /**
     * Count mappings for an entity type.
     */
    public static function count( $entity_type ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE entity_type = %s",
            $entity_type
        ) );
    }
}
