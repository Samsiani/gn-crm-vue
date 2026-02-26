<?php
/**
 * Base REST controller — shared helpers for pagination, sorting, response formatting.
 */
abstract class CIG_REST_Controller {

    protected $namespace = CIG_API_NAMESPACE;

    abstract public function register_routes();

    /**
     * Extract pagination params from a WP_REST_Request.
     */
    protected function get_pagination_params( $request ) {
        return [
            'page'     => max( 1, (int) $request->get_param( 'page' ) ?: 1 ),
            'per_page' => min( 500, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) ),
        ];
    }

    /**
     * Extract sort params from a WP_REST_Request.
     */
    protected function get_sort_params( $request ) {
        return [
            'sort'  => sanitize_text_field( $request->get_param( 'sort' ) ?: '' ),
            'order' => sanitize_text_field( $request->get_param( 'order' ) ?: 'DESC' ),
        ];
    }

    /**
     * Create a paginated REST response with pagination headers.
     */
    protected function paginated_response( $result ) {
        $response = new WP_REST_Response( $result['data'], 200 );
        $response->header( 'X-WP-Total', $result['total'] );
        $response->header( 'X-WP-TotalPages', $result['pages'] );
        return $response;
    }

    /**
     * Get the authenticated CIG user from the request.
     */
    protected function get_user( $request ) {
        return $request->get_param( '_cig_user' );
    }

    /**
     * Map camelCase → snake_case for a sort field.
     */
    protected function camel_to_snake( $str ) {
        return strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $str ) );
    }
}
