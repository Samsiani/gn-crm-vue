<?php
/**
 * Role-Based Access Control permission callbacks for REST API endpoints.
 */
class CIG_RBAC {

    /**
     * Any authenticated user can read.
     */
    public static function can_read( $request ) {
        $user = CIG_Auth_Middleware::get_current_user( $request );
        if ( is_wp_error( $user ) ) {
            return $user;
        }
        // Attach user to request for downstream use
        $request->set_param( '_cig_user', $user );
        return true;
    }

    /**
     * Admin, manager, or sales can write. Accountant is read-only.
     */
    public static function can_write( $request ) {
        $user = CIG_Auth_Middleware::get_current_user( $request );
        if ( is_wp_error( $user ) ) {
            return $user;
        }

        $role = $user['role'];
        if ( in_array( $role, [ 'admin', 'manager', 'sales' ], true ) ) {
            $request->set_param( '_cig_user', $user );
            return true;
        }

        return new WP_Error(
            'cig_forbidden',
            'You do not have permission to perform this action.',
            [ 'status' => 403 ]
        );
    }

    /**
     * Only admin or manager roles.
     */
    public static function is_admin( $request ) {
        $user = CIG_Auth_Middleware::get_current_user( $request );
        if ( is_wp_error( $user ) ) {
            return $user;
        }

        $role = $user['role'];
        if ( in_array( $role, [ 'admin', 'manager' ], true ) ) {
            $request->set_param( '_cig_user', $user );
            return true;
        }

        return new WP_Error(
            'cig_forbidden',
            'Admin access required.',
            [ 'status' => 403 ]
        );
    }

    /**
     * Only admin role (not manager).
     */
    public static function is_admin_only( $request ) {
        $user = CIG_Auth_Middleware::get_current_user( $request );
        if ( is_wp_error( $user ) ) {
            return $user;
        }

        if ( $user['role'] === 'admin' ) {
            $request->set_param( '_cig_user', $user );
            return true;
        }

        return new WP_Error(
            'cig_forbidden',
            'Admin-only access required.',
            [ 'status' => 403 ]
        );
    }

    /**
     * Accountant can update certain invoice fields (checkboxes, notes).
     */
    public static function can_update_accountant_fields( $request ) {
        $user = CIG_Auth_Middleware::get_current_user( $request );
        if ( is_wp_error( $user ) ) {
            return $user;
        }

        $request->set_param( '_cig_user', $user );
        return true; // All authenticated users can update accountant fields
    }

    /**
     * Helper: check if the current user is admin/manager.
     */
    public static function user_is_admin( $user ) {
        return in_array( $user['role'], [ 'admin', 'manager' ], true );
    }

    /**
     * Helper: check if the current user is sales (consultant).
     */
    public static function user_is_consultant( $user ) {
        return ! self::user_is_admin( $user );
    }
}
