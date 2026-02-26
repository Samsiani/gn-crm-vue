<?php
/**
 * Plugin loader — registers all REST API routes.
 */
class CIG_Loader {

    public function init() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        // Allow JWT auth via Authorization header (some hosts strip it)
        add_filter( 'rest_pre_dispatch', [ $this, 'set_jwt_user' ], 10, 3 );

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
        ];

        foreach ( $controllers as $controller ) {
            $controller->register_routes();
        }
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
