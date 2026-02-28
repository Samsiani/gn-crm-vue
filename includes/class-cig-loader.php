<?php
/**
 * Plugin loader — registers all REST API routes.
 */
class CIG_Loader {

    public function init() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        // Allow JWT auth via Authorization header (some hosts strip it)
        add_filter( 'rest_pre_dispatch', [ $this, 'set_jwt_user' ], 10, 3 );

        // Bypass WordPress cookie nonce check when a JWT is present.
        // Without this, requests that carry both a valid auth cookie AND a JWT
        // (but no X-WP-Nonce) get rejected with 403 "Cookie check failed"
        // because rest_cookie_check_errors runs before our RBAC callbacks.
        add_filter( 'rest_authentication_errors', [ $this, 'bypass_cookie_check_for_jwt' ], 99 );

        // Add Cache-Control headers for read-heavy GET endpoints
        add_filter( 'rest_post_dispatch', function( $response, $server, $request ) {
            if ( $request->get_method() !== 'GET' ) {
                return $response;
            }
            $route = $request->get_route();
            if ( strpos( $route, '/cig/v1/kpi' ) !== false ) {
                $response->header( 'Cache-Control', 'private, max-age=30' );
            } elseif ( preg_match( '#/cig/v1/(customers|users|company)\b#', $route ) ) {
                $response->header( 'Cache-Control', 'private, max-age=60' );
            }
            return $response;
        }, 10, 3 );
    }

    public function register_routes() {
        $controllers = [
            new CIG_Auth_Controller(),
            new CIG_KPI_Controller(),
            new CIG_Invoices_Controller(),
            new CIG_Customers_Controller(),
            new CIG_Products_Controller(),
            new CIG_Users_Controller(),
            new CIG_Company_Controller(),
            new CIG_Deposits_Controller(),
            new CIG_Deliveries_Controller(),
            new CIG_Notifications_Controller(),
            new CIG_Import_Controller(),
        ];

        foreach ( $controllers as $controller ) {
            $controller->register_routes();
        }
    }

    /**
     * If the request carries a Bearer JWT, suppress the WP cookie nonce error.
     * WordPress fires rest_cookie_check_errors (priority 100) which returns 403
     * when a valid auth cookie exists but no X-WP-Nonce is sent. This happens
     * after login because wp_set_auth_cookie() sets the cookie on the response,
     * and the very next request carries that cookie alongside the JWT.
     * We run at priority 99 (just before the cookie check) and return true to
     * short-circuit the error — our own JWT validation in the RBAC permission
     * callbacks will handle authentication.
     */
    public function bypass_cookie_check_for_jwt( $result ) {
        if ( ! empty( $result ) ) {
            return $result;
        }

        $auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if ( empty( $auth ) && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if ( ! empty( $auth ) && preg_match( '/^Bearer\s+.+$/i', $auth ) ) {
            return true;
        }

        return $result;
    }

    /**
     * Ensure Authorization header is available even on hosts that strip it.
     */
    public function set_jwt_user( $result, $server, $request ) {
        // If no Authorization header, check for alternative
        if ( ! $request->get_header( 'Authorization' ) ) {
            // Check PHP_AUTH_BEARER or HTTP_AUTHORIZATION
            if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
                $request->set_header( 'Authorization', $_SERVER['HTTP_AUTHORIZATION'] );
            } elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
                $request->set_header( 'Authorization', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
            }
        }
        return $result;
    }
}
