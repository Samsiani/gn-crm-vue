<?php
/**
 * Authentication middleware.
 * Primary: WordPress cookie auth (nonce + session).
 * Fallback: JWT token (for external API clients).
 */

class CIG_Auth_Middleware {

    private static $secret_key = null;

    /**
     * Get the JWT secret key. Uses wp_salt() for security.
     */
    public static function get_secret_key() {
        if ( self::$secret_key === null ) {
            self::$secret_key = defined( 'CIG_JWT_SECRET' )
                ? CIG_JWT_SECRET
                : wp_salt( 'auth' );
        }
        return self::$secret_key;
    }

    /**
     * Check if the firebase/php-jwt library is available.
     */
    private static function jwt_available() {
        return class_exists( '\Firebase\JWT\JWT' );
    }

    /**
     * Generate a JWT token for a CIG user.
     * Returns null if JWT library is not installed.
     */
    public static function generate_token( $cig_user ) {
        if ( ! self::jwt_available() ) {
            return null;
        }

        $issued_at  = time();
        $expiration = $issued_at + ( 2 * HOUR_IN_SECONDS ); // 2 hours (refresh interceptor handles silent renewal)

        $payload = [
            'iss'        => get_bloginfo( 'url' ),
            'iat'        => $issued_at,
            'exp'        => $expiration,
            'sub'        => $cig_user['id'],
            'role'       => $cig_user['role'],
            'wp_user_id' => $cig_user['wpUserId'] ?? null,
        ];

        return \Firebase\JWT\JWT::encode( $payload, self::get_secret_key(), 'HS256' );
    }

    /**
     * Validate a JWT token from the Authorization header.
     * Returns decoded payload or WP_Error.
     */
    public static function validate_token( $request ) {
        if ( ! self::jwt_available() ) {
            return new WP_Error(
                'cig_jwt_unavailable',
                'JWT library not installed.',
                [ 'status' => 500 ]
            );
        }

        $auth_header = $request->get_header( 'Authorization' );

        if ( empty( $auth_header ) ) {
            return new WP_Error(
                'cig_no_auth',
                'Authorization header missing.',
                [ 'status' => 401 ]
            );
        }

        // Extract Bearer token
        if ( ! preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
            return new WP_Error(
                'cig_invalid_auth',
                'Invalid Authorization header format. Use: Bearer <token>',
                [ 'status' => 401 ]
            );
        }

        $token = $matches[1];

        try {
            $decoded = \Firebase\JWT\JWT::decode( $token, new \Firebase\JWT\Key( self::get_secret_key(), 'HS256' ) );
            return (array) $decoded;
        } catch ( \Firebase\JWT\ExpiredException $e ) {
            return new WP_Error(
                'cig_token_expired',
                'Token has expired. Please log in again.',
                [ 'status' => 401 ]
            );
        } catch ( \Exception $e ) {
            return new WP_Error(
                'cig_token_invalid',
                'Invalid token: ' . $e->getMessage(),
                [ 'status' => 401 ]
            );
        }
    }

    /**
     * Get the authenticated CIG user.
     * 1. Try JWT token (Authorization header)
     * 2. Fall back to WordPress cookie auth (nonce + session)
     */
    public static function get_current_user( $request ) {
        // 1. Try JWT if Authorization header is present
        $auth_header = $request->get_header( 'Authorization' );
        if ( ! empty( $auth_header ) && self::jwt_available() ) {
            $payload = self::validate_token( $request );
            if ( ! is_wp_error( $payload ) ) {
                $user = CIG_User::find( $payload['sub'] );
                if ( $user ) {
                    if ( ! $user['isActive'] ) {
                        return new WP_Error( 'cig_account_disabled', 'Your account has been disabled.', [ 'status' => 403 ] );
                    }
                    return $user;
                }
            }
        }

        // 2. Fall back to WordPress cookie auth
        $wp_user_id = get_current_user_id();
        if ( $wp_user_id ) {
            $cig_user = CIG_User::find_by_wp_user( $wp_user_id );
            if ( $cig_user ) {
                if ( ! $cig_user['isActive'] ) {
                    return new WP_Error( 'cig_account_disabled', 'Your account has been disabled.', [ 'status' => 403 ] );
                }
                return $cig_user;
            }

            // Auto-create CIG user from WordPress user
            $wp_user = get_user_by( 'ID', $wp_user_id );
            if ( $wp_user ) {
                $cig_user = CIG_User::create_from_wp_user( $wp_user );
                if ( $cig_user ) {
                    if ( ! $cig_user['isActive'] ) {
                        return new WP_Error( 'cig_account_disabled', 'Your account has been disabled.', [ 'status' => 403 ] );
                    }
                    return $cig_user;
                }
            }
        }

        return new WP_Error(
            'cig_not_authenticated',
            'Authentication required. Please log in.',
            [ 'status' => 401 ]
        );
    }
}
