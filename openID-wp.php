<?php
/**
 * OpenID_Wp class.
 *
 * Defines plugin initialization functionality.
 *
 * @package OpenID_Wp
 * @category  General
 */
class OpenID_Wp {

	/**
	 * Plugin version.
	 *
	 * @var
	 */
	const VERSION = '1.0';

	/**
	 * Plugin settings.
	 *
	 * @var OpenID_Wp_Option_Settings
	 */
	private $settings;

	/**
	 * Plugin logs.
	 *
	 * @var OpenID_Wp_Option_Logger
	 */
	private $logger;

	/**
	 * Openid Connect Generic client
	 *
	 * @var OpenID_Wp_Client
	 */
	private $client;

	/**
	 * Client wrapper.
	 *
	 * @var OpenID_Wp_Client_Wrapper
	 */
	private $client_wrapper;

	/**
	 * Setup the plugin
	 *
	 * @param OpenID_Wp_Option_Settings $settings The settings object.
	 * @param OpenID_Wp_Option_Logger   $logger   The loggin object.
	 *
	 * @return void
	 */
	public function __construct( OpenID_Wp_Option_Settings $settings, OpenID_Wp_Option_Logger $logger ) {
		$this->settings = $settings;
		$this->logger = $logger;
	}

	/**
	 * WordPress Hook 'init'.
	 *
	 * @return void
	 */
	public function init() {

		wp_enqueue_style( 'unifyia-openID-wp-admin', plugin_dir_url( __FILE__ ) . 'css/styles-admin.css', array(), self::VERSION, 'all' );

		$redirect_uri = admin_url( 'admin-ajax.php?action=openid-connect-authorize' );

		if ( $this->settings->alternate_redirect_uri ) {
			$redirect_uri = site_url( '/openid-connect-authorize' );
		}

		$state_time_limit = 180;
		if ( $this->settings->state_time_limit ) {
			$state_time_limit = intval( $this->settings->state_time_limit );
		}

		$this->client = new OpenID_Wp_Client(
			$this->settings->client_id,
			$this->settings->client_secret,
			$this->settings->scope,
			$this->settings->endpoint_login,
			$this->settings->endpoint_userinfo,
			$this->settings->endpoint_token,
			$redirect_uri,
			$state_time_limit,
			$this->logger
		);

		$this->client_wrapper = OpenID_Wp_Client_Wrapper::register( $this->client, $this->settings, $this->logger );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		OpenID_Wp_Login_Form::register( $this->settings, $this->client_wrapper );

		// Add a shortcode to get the auth URL.
		add_shortcode( 'OpenID_Wp_auth_url', array( $this->client_wrapper, 'get_authentication_url' ) );

		// Add actions to our scheduled cron jobs.
		add_action( 'openID-wp-cron-daily', array( $this, 'cron_states_garbage_collection' ) );

		$this->upgrade();

		if ( is_admin() ) {
			OpenID_Wp_Settings_Page::register( $this->settings, $this->logger );
		}
	}

	/**
	 * Check if privacy enforcement is enabled, and redirect users that aren't
	 * logged in.
	 *
	 * @return void
	 */
	public function enforce_privacy_redirect() {
		if ( $this->settings->enforce_privacy && ! is_user_logged_in() ) {
			// The client endpoint relies on the wp admind ajax endpoint.
			if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX || ! isset( $_GET['action'] ) || 'openid-connect-authorize' != $_GET['action'] ) {
				auth_redirect();
			}
		}
	}

	/**
	 * Enforce privacy settings for rss feeds.
	 *
	 * @param string $content The content.
	 *
	 * @return mixed
	 */
	public function enforce_privacy_feeds( $content ) {
		if ( $this->settings->enforce_privacy && ! is_user_logged_in() ) {
			$content = __( 'Private site', 'unifyia-openID-wp' );
		}
		return $content;
	}

	/**
	 * Handle plugin upgrades
	 *
	 * @return void
	 */
	public function upgrade() {
		$last_version = get_option( 'openID-wp-plugin-version', 0 );
		$settings = $this->settings;

		if ( version_compare( self::VERSION, $last_version, '>' ) ) {
			// An upgrade is required.
			self::setup_cron_jobs();

			// @todo move this to another file for upgrade scripts
			if ( isset( $settings->ep_login ) ) {
				$settings->endpoint_login = $settings->ep_login;
				$settings->endpoint_token = $settings->ep_token;
				$settings->endpoint_userinfo = $settings->ep_userinfo;

				unset( $settings->ep_login, $settings->ep_token, $settings->ep_userinfo );
				$settings->save();
			}

			// Update the stored version number.
			update_option( 'openID-wp-plugin-version', self::VERSION );
		}
	}

	/**
	 * Expire state transients by attempting to access them and allowing the
	 * transient's own mechanisms to delete any that have expired.
	 *
	 * @return void
	 */
	public function cron_states_garbage_collection() {
		global $wpdb;
		$states = $wpdb->get_col( "SELECT `option_name` FROM {$wpdb->options} WHERE `option_name` LIKE '_transient_openID-wp-state--%'" );

		if ( ! empty( $states ) ) {
			foreach ( $states as $state ) {
				$transient = str_replace( '_transient_', '', $state );
				get_transient( $transient );
			}
		}
	}

	/**
	 * Ensure cron jobs are added to the schedule.
	 *
	 * @return void
	 */
	public static function setup_cron_jobs() {
		if ( ! wp_next_scheduled( 'openID-wp-cron-daily' ) ) {
			wp_schedule_event( time(), 'daily', 'openID-wp-cron-daily' );
		}
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activation() {
		self::setup_cron_jobs();
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivation() {
		wp_clear_scheduled_hook( 'openID-wp-cron-daily' );
	}

	/**
	 * Simple autoloader.
	 *
	 * @param string $class The class name.
	 *
	 * @return void
	 */
	public static function autoload( $class ) {
		$prefix = 'OpenID_Wp_';

		if ( stripos( $class, $prefix ) !== 0 ) {
			return;
		}

		$filename = $class . '.php';

		// Internal files are all lowercase and use dashes in filenames.
		if ( false === strpos( $filename, '\\' ) ) {
			$filename = strtolower( str_replace( '_', '-', $filename ) );
		} else {
			$filename  = str_replace( '\\', DIRECTORY_SEPARATOR, $filename );
		}

		$filepath = dirname( __FILE__ ) . '/includes/' . $filename;

		if ( file_exists( $filepath ) ) {
			require_once $filepath;
		}
	}

	/**
	 * Instantiate the plugin and hook into WordPress.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		/**
		 * This is a documented valid call for spl_autoload_register.
		 *
		 * @link https://www.php.net/manual/en/function.spl-autoload-register.php#71155
		 */
		spl_autoload_register( array( 'OpenID_Wp', 'autoload' ) );

		$settings = new OpenID_Wp_Option_Settings(
			'OpenID_Wp_settings',
			// Default settings values.
			array(
				// OAuth client settings.
				'login_type'           => 'button',
				'client_id'            => defined( 'OIDC_CLIENT_ID' ) ? OIDC_CLIENT_ID : '',
				'client_secret'        => defined( 'OIDC_CLIENT_SECRET' ) ? OIDC_CLIENT_SECRET : '',
				'scope'                => '',
				'endpoint_login'       => defined( 'OIDC_ENDPOINT_LOGIN_URL' ) ? OIDC_ENDPOINT_LOGIN_URL : '',
				'endpoint_userinfo'    => defined( 'OIDC_ENDPOINT_USERINFO_URL' ) ? OIDC_ENDPOINT_USERINFO_URL : '',
				'endpoint_token'       => defined( 'OIDC_ENDPOINT_TOKEN_URL' ) ? OIDC_ENDPOINT_TOKEN_URL : '',
				'endpoint_end_session' => defined( 'OIDC_ENDPOINT_LOGOUT_URL' ) ? OIDC_ENDPOINT_LOGOUT_URL : '',

				// Non-standard settings.
				'no_sslverify'    => 0,
				'http_request_timeout' => 5,
				'identity_key'    => 'preferred_username',
				'nickname_key'    => 'preferred_username',
				'email_format'       => '{email}',
				'displayname_format' => '',
				'identify_with_username' => false,

				// Plugin settings.
				'enforce_privacy' => 0,
				'alternate_redirect_uri' => 0,
				'token_refresh_enable' => 1,
				'link_existing_users' => 0,
				'create_if_does_not_exist' => 1,
				'redirect_user_back' => 0,
				'redirect_on_logout' => 1,
				'enable_logging'  => 0,
				'log_limit'       => 1000,
			)
		);

		$logger = new OpenID_Wp_Option_Logger( 'openID-wp-logs', 'error', $settings->enable_logging, $settings->log_limit );

		$plugin = new self( $settings, $logger );

		add_action( 'init', array( $plugin, 'init' ) );

		// Privacy hooks.
		add_action( 'template_redirect', array( $plugin, 'enforce_privacy_redirect' ), 0 );
		add_filter( 'the_content_feed', array( $plugin, 'enforce_privacy_feeds' ), 999 );
		add_filter( 'the_excerpt_rss', array( $plugin, 'enforce_privacy_feeds' ), 999 );
		add_filter( 'comment_text_rss', array( $plugin, 'enforce_privacy_feeds' ), 999 );
	}
}

OpenID_Wp::bootstrap();

register_activation_hook( __FILE__, array( 'OpenID_Wp', 'activation' ) );
register_deactivation_hook( __FILE__, array( 'OpenID_Wp', 'deactivation' ) );
