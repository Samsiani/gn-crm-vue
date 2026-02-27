<?php
/**
 * Auth controller — session (cookie), JWT login, logout, me endpoints.
 */
class CIG_Auth_Controller extends CIG_REST_Controller {

    public function register_routes() {
        // WordPress cookie session check (auto-login)
        register_rest_route( $this->namespace, '/auth/session', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'session' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $this->namespace, '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'login' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $this->namespace, '/auth/logout', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'logout' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $this->namespace, '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'me' ],
            'permission_callback' => [ 'CIG_RBAC', 'can_read' ],
        ] );
    }

    /**
     * GET /auth/session
     * Returns the CIG user if the caller has a valid WordPress session (cookie + nonce).
     * Used by the Vue app on page load to auto-login.
     */
    public function session( $request ) {
        $wp_user_id = get_current_user_id();

        if ( ! $wp_user_id ) {
            return new WP_Error(
                'cig_not_logged_in',
                'No active WordPress session.',
                [ 'status' => 401 ]
            );
        }

        // Find or auto-create CIG user
        $cig_user = CIG_User::find_by_wp_user( $wp_user_id );

        if ( ! $cig_user ) {
            $wp_user = get_user_by( 'ID', $wp_user_id );
            if ( ! $wp_user ) {
                return new WP_Error(
                    'cig_user_not_found',
                    'WordPress user not found.',
                    [ 'status' => 404 ]
                );
            }

            $cig_user = CIG_User::create_from_wp_user( $wp_user );
            if ( ! $cig_user ) {
                return new WP_Error(
                    'cig_user_create_failed',
                    'Failed to create user account.',
                    [ 'status' => 500 ]
                );
            }
        }

        if ( ! $cig_user['isActive'] ) {
            return new WP_Error(
                'cig_account_disabled',
                'Your account has been disabled.',
                [ 'status' => 403 ]
            );
        }

        return rest_ensure_response( [
            'user' => $cig_user,
        ] );
    }

    /**
     * POST /auth/login
     * Accepts: { username, password }
     * Returns: { token (optional), user }
     */
    public function login( $request ) {
        $username = sanitize_text_field( $request->get_param( 'username' ) );
        $password = $request->get_param( 'password' );

        if ( empty( $username ) || empty( $password ) ) {
            return new WP_Error(
                'cig_missing_credentials',
                'Username and password are required.',
                [ 'status' => 400 ]
            );
        }

        // Authenticate against WordPress (accepts username or email)
        $wp_user = wp_authenticate( $username, $password );

        if ( is_wp_error( $wp_user ) ) {
            // Fallback: try email login if username failed
            if ( is_email( $username ) ) {
                $wp_user_by_email = get_user_by( 'email', $username );
                if ( $wp_user_by_email ) {
                    $wp_user = wp_authenticate( $wp_user_by_email->user_login, $password );
                }
            }

            if ( is_wp_error( $wp_user ) ) {
                // Fallback: try CIG user name matching + WP password
                $cig_user = CIG_User::find_by_login( $username );
                if ( $cig_user && $cig_user['wpUserId'] ) {
                    $wp_user_obj = get_user_by( 'ID', $cig_user['wpUserId'] );
                    if ( $wp_user_obj && wp_check_password( $password, $wp_user_obj->user_pass, $wp_user_obj->ID ) ) {
                        if ( ! $cig_user['isActive'] ) {
                            return new WP_Error(
                                'cig_account_disabled',
                                'Your account has been disabled.',
                                [ 'status' => 403 ]
                            );
                        }

                        // Set WordPress session cookies so cookie auth works going forward
                        wp_set_current_user( $wp_user_obj->ID );
                        wp_set_auth_cookie( $wp_user_obj->ID, true );

                        $token = CIG_Auth_Middleware::generate_token( $cig_user );
                        $response = [ 'user' => $cig_user ];
                        if ( $token ) {
                            $response['token'] = $token;
                        }
                        return rest_ensure_response( $response );
                    }
                }

                return new WP_Error(
                    'cig_invalid_credentials',
                    'Invalid username or password.',
                    [ 'status' => 401 ]
                );
            }
        }

        // Find or auto-create CIG user from the authenticated WP user
        $cig_user = CIG_User::find_by_wp_user( $wp_user->ID );

        if ( ! $cig_user ) {
            $cig_user = CIG_User::create_from_wp_user( $wp_user );

            if ( ! $cig_user ) {
                return new WP_Error(
                    'cig_user_create_failed',
                    'Failed to create user account. Please contact administrator.',
                    [ 'status' => 500 ]
                );
            }
        }

        if ( ! $cig_user['isActive'] ) {
            return new WP_Error(
                'cig_account_disabled',
                'Your account has been disabled.',
                [ 'status' => 403 ]
            );
        }

        // Set WordPress session cookies so cookie auth works going forward
        wp_set_current_user( $wp_user->ID );
        wp_set_auth_cookie( $wp_user->ID, true );

        $token = CIG_Auth_Middleware::generate_token( $cig_user );
        $response = [ 'user' => $cig_user ];
        if ( $token ) {
            $response['token'] = $token;
        }

        return rest_ensure_response( $response );
    }

    /**
     * POST /auth/logout
     * Clears the WordPress auth cookie so a subsequent /auth/session call returns 401.
     */
    public function logout( $request ) {
        wp_clear_auth_cookie();
        return rest_ensure_response( [ 'success' => true ] );
    }

    /**
     * GET /auth/me
     * Returns current authenticated user (JWT or cookie).
     */
    public function me( $request ) {
        $user = $this->get_user( $request );
        return rest_ensure_response( $user );
    }
}
