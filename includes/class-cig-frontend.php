<?php
/**
 * Handles [cig_app] shortcode registration and asset enqueuing.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CIG_Frontend {

    private $enqueue_assets    = false;
    private $manifest          = null;
    private $footer_buffering  = false;

    public function init() {
        add_shortcode( 'cig_app', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'detect_shortcode' ] );
        add_action( 'wp_head',            [ $this, 'inject_page_overrides' ] );
        add_action( 'wp_footer',          [ $this, 'maybe_enqueue_assets' ] );
        add_action( 'wp_footer',          [ $this, 'start_footer_buffer' ], 0 );
        add_action( 'wp_footer',          [ $this, 'end_footer_buffer' ], PHP_INT_MAX );
        add_action( 'wp_print_styles',    [ $this, 'dequeue_theme_styles' ], 9999 );
        add_action( 'wp_print_scripts',   [ $this, 'dequeue_theme_scripts' ], 9999 );
    }

    /**
     * Early shortcode detection from post content.
     */
    public function detect_shortcode() {
        global $post;
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'cig_app' ) ) {
            $this->enqueue_assets = true;
            $this->maybe_hide_admin_bar();

            // WoodMart theme: remove skip-links HTML injected via wp_body_open.
            remove_action( 'wp_body_open', 'woodmart_skip_links' );
        }
    }

    /**
     * Inject CSS/JS overrides into <head> on CIG pages.
     * Also outputs loader CSS and the dark-mode script here so both are
     * available before the first paint.
     */
    public function inject_page_overrides() {
        if ( ! $this->enqueue_assets ) {
            return;
        }

        // 1. Dark mode — synchronous, before first paint.
        echo '<script>!function(){if("dark"===localStorage.getItem("gn-theme"))document.documentElement.classList.add("dark")}()</script>' . "\n";

        // 2. Full-page loader CSS — in <head> so it applies the moment the div appears.
        echo '<style>'
            . '#cig-loader{position:fixed;inset:0;z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;background:#fff;transition:opacity .45s cubic-bezier(.4,0,.2,1),transform .45s cubic-bezier(.4,0,.2,1)}'
            . 'html.dark #cig-loader{background:#0f172a}'
            . '#cig-loader.out{opacity:0;transform:scale(.94);pointer-events:none}'
            . '.cig-ld-g{display:grid;grid-template-columns:1fr 1fr;gap:7px}'
            . '.cig-ld-g i{display:block;width:11px;height:11px;border-radius:3px;background:#3b82f6;animation:cig-p 1.4s ease-in-out infinite}'
            . '.cig-ld-g i:nth-child(1){animation-delay:0s}'
            . '.cig-ld-g i:nth-child(2){animation-delay:.12s}'
            . '.cig-ld-g i:nth-child(3){animation-delay:.12s}'
            . '.cig-ld-g i:nth-child(4){animation-delay:.24s}'
            . '@keyframes cig-p{0%,100%{transform:scale(.45);opacity:.2}50%{transform:scale(1.15);opacity:1}}'
            . '.cig-ld-t{width:72px;height:2px;border-radius:9px;background:rgba(59,130,246,.13);overflow:hidden}'
            . '.cig-ld-f{height:100%;width:44%;border-radius:9px;background:#3b82f6;animation:cig-s 1.5s ease-in-out infinite}'
            . '@keyframes cig-s{0%{transform:translateX(-220%)}100%{transform:translateX(380%)}}'
            . '.wd-skip-links{display:none!important}'
            . '</style>' . "\n";
    }

    /**
     * Start output buffering at the very beginning of wp_footer so we can
     * strip inline scripts that bypass WordPress's enqueue system (e.g.
     * WoodMart's BrowserSync / refresh.js injected directly via wp_footer).
     */
    public function start_footer_buffer() {
        if ( ! $this->enqueue_assets ) {
            return;
        }
        $this->footer_buffering = true;
        ob_start();
    }

    /**
     * Flush the footer buffer, stripping any BrowserSync/dev-reload script tags.
     */
    public function end_footer_buffer() {
        if ( ! $this->footer_buffering ) {
            return;
        }
        $output = ob_get_clean();
        // Strip <script src="...refresh.js..."></script> (WoodMart BrowserSync dev script).
        $output = preg_replace( '/<script[^>]+["\'][^"\']*refresh\.js[^"\']*["\'][^>]*>\s*<\/script>/i', '', $output );
        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Hide WP admin bar if company setting is enabled.
     */
    private function maybe_hide_admin_bar() {
        $company = CIG_Company::get();
        if ( $company && ! empty( $company['hideWpAdminBar'] ) ) {
            add_filter( 'show_admin_bar', '__return_false' );
        }
    }

    /**
     * [cig_app] shortcode callback.
     */
    public function render_shortcode( $atts ) {
        $this->enqueue_assets = true;

        // The loader is created via JS so it becomes a direct child of <body>,
        // sitting above all theme markup regardless of where the shortcode is placed.
        // CSS is already output in inject_page_overrides() via wp_head.
        return '<script>!function(){'
            . 'if(document.getElementById("cig-loader"))return;'
            . 'var e=document.createElement("div");'
            . 'e.id="cig-loader";'
            . 'e.setAttribute("aria-hidden","true");'
            . 'e.innerHTML=\'<div class="cig-ld-g"><i></i><i></i><i></i><i></i></div><div class="cig-ld-t"><div class="cig-ld-f"></div></div>\';'
            . 'document.body.prepend(e);'
            . '}()</script>'
            . '<div id="app"></div>';
    }

    /**
     * Read and cache the Vite manifest.json.
     * Returns associative array or null if not found.
     */
    private function get_asset_manifest() {
        if ( $this->manifest !== null ) {
            return $this->manifest;
        }

        $manifest_path = CIG_PLUGIN_DIR . 'dist/.vite/manifest.json';

        if ( ! file_exists( $manifest_path ) ) {
            $this->manifest = false;
            return false;
        }

        $contents = file_get_contents( $manifest_path );
        $this->manifest = json_decode( $contents, true ) ?: false;

        return $this->manifest;
    }

    /**
     * Enqueue JS/CSS only on pages that use the shortcode.
     * Resolves hashed filenames from Vite manifest when available.
     */
    public function maybe_enqueue_assets() {
        if ( ! $this->enqueue_assets ) {
            return;
        }

        $dist_dir = CIG_PLUGIN_DIR . 'dist/';
        $dist_url = CIG_PLUGIN_URL . 'dist/';
        $manifest = $this->get_asset_manifest();

        // Resolve filenames from Vite manifest (hashed builds)
        if ( $manifest ) {
            $js_file  = null;
            $css_file = null;

            // Vite 5+ uses 'index.html' as the entry key; older builds used 'src/main.js'
            foreach ( [ 'index.html', 'src/main.js' ] as $entry_key ) {
                if ( isset( $manifest[ $entry_key ] ) && ! empty( $manifest[ $entry_key ]['isEntry'] ) ) {
                    $entry   = $manifest[ $entry_key ];
                    $js_file = $entry['file'] ?? null;
                    // CSS may be inlined under the entry's 'css' array
                    $css_file = isset( $entry['css'][0] ) ? $entry['css'][0] : null;
                    break;
                }
            }

            // Fallback: first chunk with isEntry=true
            if ( ! $js_file ) {
                foreach ( $manifest as $chunk ) {
                    if ( ! empty( $chunk['isEntry'] ) ) {
                        $js_file  = $chunk['file'] ?? null;
                        $css_file = isset( $chunk['css'][0] ) ? $chunk['css'][0] : null;
                        break;
                    }
                }
            }

            // Vite 5 emits CSS as a separate top-level manifest entry ('style.css')
            if ( ! $css_file ) {
                foreach ( $manifest as $key => $chunk ) {
                    if ( isset( $chunk['file'] ) && substr( $chunk['file'], -4 ) === '.css' ) {
                        $css_file = $chunk['file'];
                        break;
                    }
                }
            }

            if ( $js_file ) {
                $js_path = $dist_dir . $js_file;
                $js_ver  = file_exists( $js_path ) ? filemtime( $js_path ) : CIG_VERSION;
                if ( $css_file ) {
                    wp_enqueue_style( 'cig-app', $dist_url . $css_file, [], $js_ver );
                }
                wp_enqueue_script( 'cig-app', $dist_url . $js_file, [], $js_ver, true );
                add_filter( 'script_loader_tag', [ $this, 'add_module_type' ], 10, 3 );
                $company = CIG_Company::get();
                wp_localize_script( 'cig-app', 'CIG_CONFIG', [
                    'apiUrl'          => '/' . rest_get_url_prefix() . '/' . CIG_API_NAMESPACE,
                    'nonce'           => wp_create_nonce( 'wp_rest' ),
                    'loginFooterNote' => $company ? ( $company['loginFooterNote'] ?? '' ) : '',
                ] );
                return;
            }
        }

        // Fallback: fixed filenames (development or pre-manifest builds)
        $js_file  = $dist_dir . 'gn-invoice.js';
        $css_file = $dist_dir . 'gn-invoice.css';
        $js_ver   = file_exists( $js_file ) ? filemtime( $js_file ) : CIG_VERSION;
        $css_ver  = file_exists( $css_file ) ? filemtime( $css_file ) : CIG_VERSION;

        wp_enqueue_style( 'cig-app', $dist_url . 'gn-invoice.css', [], $css_ver );
        wp_enqueue_script( 'cig-app', $dist_url . 'gn-invoice.js', [], $js_ver, true );
        add_filter( 'script_loader_tag', [ $this, 'add_module_type' ], 10, 3 );
        $company = CIG_Company::get();
        wp_localize_script( 'cig-app', 'CIG_CONFIG', [
            'apiUrl'          => '/' . rest_get_url_prefix() . '/' . CIG_API_NAMESPACE,
            'nonce'           => wp_create_nonce( 'wp_rest' ),
            'loginFooterNote' => $company ? ( $company['loginFooterNote'] ?? '' ) : '',
        ] );
    }

    /**
     * Remove all theme/plugin styles on pages with our shortcode.
     */
    public function dequeue_theme_styles() {
        if ( ! $this->enqueue_assets ) {
            return;
        }

        $keep = [ 'cig-app', 'admin-bar', 'dashicons' ];

        global $wp_styles;
        if ( ! $wp_styles ) {
            return;
        }

        foreach ( $wp_styles->registered as $handle => $dep ) {
            if ( ! in_array( $handle, $keep, true ) ) {
                wp_dequeue_style( $handle );
            }
        }
    }

    /**
     * Remove all theme/plugin scripts on pages with our shortcode.
     */
    public function dequeue_theme_scripts() {
        if ( ! $this->enqueue_assets ) {
            return;
        }

        $keep = [ 'cig-app', 'admin-bar', 'jquery', 'jquery-core', 'jquery-migrate', 'wp-hooks' ];

        global $wp_scripts;
        if ( ! $wp_scripts ) {
            return;
        }

        foreach ( $wp_scripts->registered as $handle => $dep ) {
            if ( ! in_array( $handle, $keep, true ) && strpos( $handle, 'cig-' ) !== 0 ) {
                wp_dequeue_script( $handle );
            }
        }
    }

    /**
     * Add type="module" attribute to our script tag.
     */
    public function add_module_type( $tag, $handle, $src ) {
        if ( $handle !== 'cig-app' ) {
            return $tag;
        }
        return '<script type="module" src="' . esc_url( $src ) . '"></script>';
    }
}
