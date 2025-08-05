<?php
/**
 * Barebones Theme Manager
 *
 * Handles theme registration with remote admin panel, update checks against GitHub releases,
 * and daily site-health reporting via WP-Cron.
 *
 * IMPORTANT: Replace the placeholder URL (YC_ADMIN_API_URL) with your actual admin panel base URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direct access not allowed.
}

// --- Configuration ---------------------------------------------------------
// Define the remote admin API root. Example: 'https://yc.com/api'.
if ( ! defined( 'YC_ADMIN_API_URL' ) ) {
    define( 'YC_ADMIN_API_URL', 'https://yc.com/api' ); // TODO: change to production URL.
}

// GitHub repo in the format 'vendor/repository'. Ensure the repo is public or an auth token is provided.
if ( ! defined( 'BAREBONES_GITHUB_REPO' ) ) {
    define( 'BAREBONES_GITHUB_REPO', 'vendor/barebones-theme' ); // TODO: change to actual repo slug.
}

// Optional GitHub auth token to increase rate limits. Leave empty for public repos.
if ( ! defined( 'BAREBONES_GITHUB_TOKEN' ) ) {
    define( 'BAREBONES_GITHUB_TOKEN', '' );
}

class Barebones_Theme_Manager {

    /** @var Barebones_Theme_Manager */
    private static $instance;

    /** Option key where activation key is stored */
    const OPTION_ACTIVATION_KEY = 'barebones_activation_key';

    /** Option key for the last registration status */
    const OPTION_REGISTRATION_STATUS = 'barebones_registration_status';

    /** Option key when the initial registration was triggered (unix timestamp) */
    const OPTION_REGISTRATION_TIME = 'barebones_registration_time';

    /** WP-Cron hook name */
    const CRON_HOOK = 'barebones_daily_health_event';

    /**
     * Singleton accessor
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Register hooks.
        add_action( 'after_switch_theme', array( $this, 'register_installation' ) );
        add_action( 'admin_init', array( $this, 'maybe_register_installation' ) );
        add_filter( 'pre_set_site_transient_update_themes', array( $this, 'github_theme_update' ) );

        // Show admin notice if registration still pending.
        add_action( 'admin_notices', array( $this, 'maybe_show_pending_notice' ) );
        add_action( 'admin_init', array( $this, 'handle_notice_dismiss' ) );

        // Cron health reporting.
        add_action( self::CRON_HOOK, array( $this, 'send_daily_health_report' ) );
        $this->schedule_daily_event();
    }

    // ---------------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------------

    /**
     * Trigger initial registration immediately after theme activation.
     */
    public function register_installation() {
        delete_option( self::OPTION_ACTIVATION_KEY ); // reset on re-activation
        update_option( self::OPTION_REGISTRATION_STATUS, 'pending' );
        update_option( self::OPTION_REGISTRATION_TIME, time() );
        $this->send_registration_request();
    }

    /**
     * If not activated yet, retry registration once per admin page load.
     */
    public function maybe_register_installation() {
        $status = get_option( self::OPTION_REGISTRATION_STATUS );
        if ( 'activated' !== $status ) {
            // prevent excessive retries – only once per 12h
            $last = get_transient( 'barebones_last_registration_attempt' );
            if ( false === $last ) {
                $this->send_registration_request();
                set_transient( 'barebones_last_registration_attempt', time(), 12 * HOUR_IN_SECONDS );
            }
        }
    }

    /**
     * Build payload and POST to remote admin panel.
     */
    private function send_registration_request() {
        $payload = array(
            'site_url'      => get_site_url(),
            'theme_version' => wp_get_theme()->get( 'Version' ),
            'wp_version'    => get_bloginfo( 'version' ),
            'plugins'       => json_encode( get_option( 'active_plugins', array() ) ),
            'php_version'   => phpversion(),
        );

        $response = wp_remote_post( trailingslashit( YC_ADMIN_API_URL ) . 'register', array(
            'timeout' => 15,
            'body'    => $payload,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Barebones registration error: ' . $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 === $code && isset( $body['status'] ) ) {
            update_option( self::OPTION_REGISTRATION_STATUS, $body['status'] );
            if ( 'activated' === $body['status'] && isset( $body['activation_key'] ) ) {
                update_option( self::OPTION_ACTIVATION_KEY, sanitize_text_field( $body['activation_key'] ) );
            }
        }
    }

    // ---------------------------------------------------------------------
    // Update Checker
    // ---------------------------------------------------------------------

    /**
     * Inject update information from GitHub releases into WordPress theme updates.
     *
     * @param object $transient Data passed through filter.
     * @return object
     */
    public function github_theme_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $theme_slug = wp_get_theme()->get_stylesheet();
        $current_version = wp_get_theme()->get( 'Version' );

        $release = $this->get_latest_github_release();
        if ( $release && version_compare( ltrim( $release['tag_name'], 'v' ), $current_version, '>' ) ) {
            $package = $release['zipball_url'];
            $transient->response[ $theme_slug ] = array(
                'theme'       => $theme_slug,
                'new_version' => ltrim( $release['tag_name'], 'v' ),
                'url'         => $release['html_url'],
                'package'     => $package,
            );
        }
        return $transient;
    }

    /**
     * Fetch latest GitHub release (cached via transient).
     *
     * @return array|null
     */
    private function get_latest_github_release() {
        $cache_key = 'barebones_github_latest_release';
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $url = 'https://api.github.com/repos/' . BAREBONES_GITHUB_REPO . '/releases/latest';
        $args = array(
            'timeout' => 15,
            'headers' => array( 'Accept' => 'application/vnd.github.v3+json' ),
        );
        if ( BAREBONES_GITHUB_TOKEN ) {
            $args['headers']['Authorization'] = 'token ' . BAREBONES_GITHUB_TOKEN;
        }

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            error_log( 'GitHub release fetch error: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            error_log( 'GitHub release fetch HTTP error code: ' . $code );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
            return null;
        }

        // Cache for 6 hours.
        set_transient( $cache_key, $body, 6 * HOUR_IN_SECONDS );

        return $body;
    }

    // ---------------------------------------------------------------------
    // Site Health Reporting
    // ---------------------------------------------------------------------

    /**
     * Ensure daily WP-Cron event is scheduled.
     */
    private function schedule_daily_event() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 30, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Collect basic Site Health data and send to admin panel.
     */
    public function send_daily_health_report() {
        // Build minimal metrics. Extend as needed.
        $metrics = array(
            'php_version'   => phpversion(),
            'wp_version'    => get_bloginfo( 'version' ),
            'theme_version' => wp_get_theme()->get( 'Version' ),
            'plugins'       => get_option( 'active_plugins', array() ),
        );

        $payload = array(
            'site_url'       => get_site_url(),
            'health_metrics' => json_encode( $metrics ),
        );

        $response = wp_remote_post( trailingslashit( YC_ADMIN_API_URL ) . 'update', array(
            'timeout' => 15,
            'body'    => $payload,
            'headers' => array( 'Accept' => 'application/json' ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Barebones health report error: ' . $response->get_error_message() );
        }
    }

    /**
     * Display a dismissible admin notice if the registration is still pending after 24h.
     */
    public function maybe_show_pending_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // Check dismissal.
        if ( isset( $_COOKIE['barebones_pending_notice_dismissed'] ) ) {
            return;
        }

        $status = get_option( self::OPTION_REGISTRATION_STATUS );
        $start  = (int) get_option( self::OPTION_REGISTRATION_TIME );

        // Older than 24h (86400 seconds)?
        if ( 'pending' === $status && $start && ( time() - $start ) > DAY_IN_SECONDS ) {
            $dismiss_url = wp_nonce_url( add_query_arg( 'barebones_dismiss_notice', '1' ), 'barebones_dismiss_notice' );
            echo '<div class="notice notice-warning is-dismissible barebones-pending-notice">
                    <p>' . esc_html__( 'Barebones theme registration is still pending approval. Please approve the installation in the Admin Panel or contact support.', 'barebones' ) . '</p>
                    <p><a href="' . esc_url( $dismiss_url ) . '" class="button">' . esc_html__( 'Dismiss', 'barebones' ) . '</a></p>
                </div>';
            // Also allow WP to auto-dismiss (built–in is-dismissible JS), but we keep cookie fallback.
        }
    }

    /**
     * Handle dismissal of the pending notice via query param.
     */
    public function handle_notice_dismiss() {
        if ( isset( $_GET['barebones_dismiss_notice'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'barebones_dismiss_notice' ) ) {
            // Set cookie for 7 days.
            setcookie( 'barebones_pending_notice_dismissed', '1', time() + WEEK_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
            // Redirect to remove query args.
            wp_safe_redirect( remove_query_arg( array( 'barebones_dismiss_notice', '_wpnonce' ) ) );
            exit;
        }
    }
}

// Bootstrap.
Barebones_Theme_Manager::instance(); 