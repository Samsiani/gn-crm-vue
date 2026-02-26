<?php
/**
 * Hooks into the WordPress update system to check GitHub Releases for plugin updates.
 *
 * Usage (from cig-headless.php inside plugins_loaded):
 *   new CIG_Updater( __FILE__, 'Samsiani', 'gn-crm-vue', CIG_VERSION );
 *
 * Checks https://api.github.com/repos/{owner}/{repo}/releases/latest
 * and injects update data when a newer tag is found. Response is cached
 * for 12 hours via a WP transient to stay well within GitHub's rate limits.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CIG_Updater {

    /** @var string  Absolute path to cig-headless.php. */
    private $plugin_file;

    /** @var string  WordPress plugin identifier: "gn-crm-vue/cig-headless.php". */
    private $plugin_slug;

    /** @var string  GitHub repository owner. */
    private $github_owner;

    /** @var string  GitHub repository name. */
    private $github_repo;

    /** @var string  Currently installed version (e.g. "4.1.2"). */
    private $current_version;

    /** @var string  WP transient key for caching the API response. */
    private $transient_key = 'cig_updater_response';

    /** @var int  Cache lifetime in seconds (12 hours). */
    private $cache_ttl = 43200;

    /**
     * @param string $plugin_file     __FILE__ from cig-headless.php.
     * @param string $github_owner    GitHub username / org.
     * @param string $github_repo     GitHub repository name.
     * @param string $current_version CIG_VERSION constant.
     */
    public function __construct( $plugin_file, $github_owner, $github_repo, $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename( $plugin_file );  // "gn-crm-vue/cig-headless.php"
        $this->github_owner    = $github_owner;
        $this->github_repo     = $github_repo;
        $this->current_version = $current_version;

        $this->register_hooks();
    }

    /**
     * Attach all WordPress update-system hooks.
     */
    private function register_hooks() {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',                          [ $this, 'plugin_info'       ], 10, 3 );
        add_action( 'upgrader_process_complete',            [ $this, 'purge_transient'   ], 10, 2 );

        // Clear our GitHub cache whenever WordPress forces a fresh update check
        // (e.g. user clicks "Check Again" in Dashboard → Updates).
        add_action( 'delete_site_transient_update_plugins', [ $this, 'purge_github_cache' ] );
    }

    /**
     * Fetch the latest release from GitHub, with caching.
     *
     * @return array|false  Associative array with 'version', 'package_url', 'body',
     *                      'published'; or false on failure.
     */
    private function get_latest_release() {
        $cached = get_transient( $this->transient_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $api_url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode( $this->github_owner ),
            rawurlencode( $this->github_repo )
        );

        $response = wp_remote_get( $api_url, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            'headers'    => [ 'Accept' => 'application/vnd.github+json' ],
        ] );

        if ( is_wp_error( $response ) ) {
            // Network error — do not cache so we retry next time.
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            // Non-200 (404 = no releases yet, 403 = rate-limited, etc.)
            // Cache a short-lived false so we don't hammer the API.
            set_transient( $this->transient_key, false, 300 );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
            set_transient( $this->transient_key, false, 300 );
            return false;
        }

        // Find the plugin zip among the release assets (prefer our named zip over zipball).
        $package_url = '';
        if ( ! empty( $data['assets'] ) ) {
            foreach ( $data['assets'] as $asset ) {
                if ( ! empty( $asset['browser_download_url'] )
                    && substr( $asset['browser_download_url'], -4 ) === '.zip' ) {
                    $package_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $release = [
            'version'     => ltrim( $data['tag_name'], 'v' ),   // "v4.2.0" → "4.2.0"
            'package_url' => $package_url,
            'body'        => $data['body']         ?? '',
            'published'   => $data['published_at'] ?? '',
        ];

        set_transient( $this->transient_key, $release, $this->cache_ttl );

        return $release;
    }

    /**
     * `pre_set_site_transient_update_plugins` callback.
     * Injects our update info when a newer version is available on GitHub.
     *
     * @param  object $transient  WordPress update transient.
     * @return object
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        if ( version_compare( $release['version'], $this->current_version, '>' ) ) {
            $update              = new stdClass();
            $update->id          = $this->github_repo;
            $update->slug        = dirname( $this->plugin_slug );   // "gn-crm-vue"
            $update->plugin      = $this->plugin_slug;              // "gn-crm-vue/cig-headless.php"
            $update->new_version = $release['version'];
            $update->url         = 'https://github.com/' . $this->github_owner . '/' . $this->github_repo;
            $update->package     = $release['package_url'];
            $update->icons       = [];
            $update->banners     = [];
            $update->tested      = get_bloginfo( 'version' );
            $update->requires_php = '7.4';
            $update->compatibility = new stdClass();

            $transient->response[ $this->plugin_slug ] = $update;
        } else {
            // Explicitly clear stale update notices.
            unset( $transient->response[ $this->plugin_slug ] );
        }

        return $transient;
    }

    /**
     * `plugins_api` callback.
     * Provides plugin info for the "View version details" modal in WP Admin.
     *
     * @param  false|object $result
     * @param  string       $action
     * @param  object       $args
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( empty( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
            return $result;
        }

        $release     = $this->get_latest_release();
        $plugin_data = get_plugin_data( $this->plugin_file );

        $info                = new stdClass();
        $info->name          = $plugin_data['Name'];
        $info->slug          = dirname( $this->plugin_slug );
        $info->version       = $release ? $release['version'] : $this->current_version;
        $info->author        = $plugin_data['Author'];
        $info->homepage      = 'https://github.com/' . $this->github_owner . '/' . $this->github_repo;
        $info->requires      = '5.8';
        $info->requires_php  = '7.4';
        $info->tested        = get_bloginfo( 'version' );
        $info->last_updated  = $release ? $release['published'] : '';
        $info->download_link = $release ? $release['package_url'] : '';
        $info->sections      = [
            'description' => $plugin_data['Description'],
            'changelog'   => ( $release && ! empty( $release['body'] ) )
                ? nl2br( esc_html( $release['body'] ) )
                : 'See <a href="https://github.com/' . esc_attr( $this->github_owner ) . '/' . esc_attr( $this->github_repo ) . '/releases" target="_blank">GitHub Releases</a> for the full changelog.',
        ];

        return $info;
    }

    /**
     * `delete_site_transient_update_plugins` callback.
     * Fires when WordPress deletes the update_plugins transient (i.e. "Check Again").
     * Clears our GitHub cache so the next check always hits the API fresh.
     */
    public function purge_github_cache() {
        delete_transient( $this->transient_key );
    }

    /**
     * `upgrader_process_complete` callback.
     * Clears the cached release so the next check fetches fresh data.
     *
     * @param  WP_Upgrader $upgrader
     * @param  array       $hook_extra
     */
    public function purge_transient( $upgrader, $hook_extra ) {
        if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
            return;
        }

        $updated = [];
        if ( ! empty( $hook_extra['plugins'] ) ) {
            $updated = (array) $hook_extra['plugins'];
        } elseif ( ! empty( $hook_extra['plugin'] ) ) {
            $updated = [ $hook_extra['plugin'] ];
        }

        if ( in_array( $this->plugin_slug, $updated, true ) ) {
            delete_transient( $this->transient_key );
        }
    }
}
