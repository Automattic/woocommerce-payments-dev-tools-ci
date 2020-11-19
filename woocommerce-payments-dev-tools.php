<?php

/**
 * Plugin Name: WooCommerce Payments Dev Tools
 * Description: Dev tools for WooCommerce Payments
 * Author: Automattic
 * Author URI: https://woocommerce.com/
 */

class WC_Payments_Dev_Tools {
	public const ID = 'wcpaydev';
	public const DEV_MODE_OPTION = 'wcpaydev_dev_mode';
	public const FORCE_DISCONNECTED_OPTION = 'wcpaydev_force_disconnected';
	public const FORCE_ONBOARDING_OPTION = 'wcpaydev_force_onboarding';
	public const REDIRECT_OPTION = 'wcpaydev_redirect';
	public const REDIRECT_TO_OPTION = 'wcpaydev_redirect_to';
	public const DISPLAY_NOTICE = 'wcpaydev_display_notice';

	/**
	 * Entry point of the plugin
	 */
	public static function init() {
		if ( ! class_exists( 'WC_Payments_Account' ) ) {
			add_action( 'admin_menu', [ __CLASS__, 'add_disabled_page' ] );
			return;
		}

		add_action( 'admin_menu', [ __CLASS__, 'add_admin_page' ] );
		add_action( 'admin_notices', [ __CLASS__, 'add_notices' ] );

		add_filter( 'wcpay_dev_mode', [ __CLASS__, 'maybe_enable_dev_mode' ], 10, 1 );
		add_filter( 'pre_http_request', [ __CLASS__, 'maybe_redirect_api_request' ], 10, 3 );
		add_filter( 'wc_payments_get_oauth_data_args', [ __CLASS__, 'maybe_force_on_boarding' ], 10, 1 );
		add_filter( 'wcpay_api_request_headers', [ __CLASS__, 'add_wcpay_request_headers' ], 10, 1 );
		add_action( 'init', [ __CLASS__, 'maybe_force_disconnected' ] );
	}

	/**
	 * Hooks into admin_menu and adds an admin page for this plugin
	 */
	public static function add_admin_page() {
		add_menu_page(
			'WCPay Dev',
			'WCPay Dev',
			'manage_options',
			self::ID,
			[ __CLASS__, 'admin_page' ],
			'dashicons-palmtree'
		);
	}

	/**
	 * Admin page handler
	 */
	public static function admin_page() {
		self::maybe_handle_settings_save();
		self::admin_page_output();
	}

	/**
	 * Adds a placeholder page for when dependencies could not be loaded
	 */
	public static function add_disabled_page() {
		add_menu_page(
			'WCPay Dev',
			'WCPay Dev',
			'manage_options',
			self::ID,
			[ __CLASS__, 'disabled_page' ],
			'dashicons-warning'
		);
	}

	/**
	 * Outputs the disabled page.
	 */
	public static function disabled_page() {
		?>
		<h1>WCPay Dev Utils (disabled)</h1>
		<p>Dev utils have been disabled due to missing dependencies.</p>
		<p>Make sure that the WCPay plugin and all its dependencies are installed and active, Jetpack is connected, and then try again.</p>
		<?php
	}

	/**
	 * Enables the dev mode, based on this plugin's settings
	 */
	public static function maybe_enable_dev_mode( $dev_mode ) {
		return boolval( get_option( self::DEV_MODE_OPTION, true ) );
	}

	/**
	 * Detects outgoing WCPay API requests and redirects them based on this plugin's settings
	 * @param mixed $preempt
	 * @param array $args
	 * @param string $url
	 */
	public static function maybe_redirect_api_request( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		// detect the wcpay requests
		if ( 1 !== preg_match( '/^https?:\/\/public-api\.wordpress\.com\/(.+?wcpay.+)/', $url, $matches ) ) {
			return $preempt;
		}

		if ( ! get_option( self::REDIRECT_OPTION, false ) ) {
			return $preempt;
		}

		$redirect_to = trailingslashit( self::get_redirect_to() );
		return wp_remote_request( $redirect_to . $matches[1], $args );
	}

	/**
	 * Adds force_on_boarding param to the onboarding request
	 * @param array $args
	 */
	public static function maybe_force_on_boarding( $args ) {
		if ( ! get_option( self::FORCE_ONBOARDING_OPTION, false ) ) {
			return $args;
		}

		$args['force_on_boarding'] = true;
		return $args;
	}

	/**
	 * Adds xdebug cookie to the WCPay API requests
	 * @param array $headers
	 */
	public static function add_wcpay_request_headers( $headers ) {
		$headers['Cookie'] = 'XDEBUG_SESSION=XDEBUG_OMATTIC';
		return $headers;
	}

	/**
	 * Forces the plugin to act as disconnected by injecting an empty array into account cache
	 */
	public static function maybe_force_disconnected() {
		if ( ! get_option( self::FORCE_DISCONNECTED_OPTION, false ) ) {
			return;
		}

		set_transient( WC_Payments_Account::ACCOUNT_TRANSIENT, array() );
	}

	/**
	 * Processes form submission on the settings page
	 */
	private static function maybe_handle_settings_save() {
		if ( isset( $_GET['wcpaydev-clear-cache'] ) ) {
			check_admin_referer( 'wcpaydev-clear-cache' );

			self::clear_account_cache();

			wp_safe_redirect( self::get_settings_url() );
		}

		if ( isset( $_GET['wcpaydev-clear-notes'] ) ) {
			check_admin_referer( 'wcpaydev-clear-notes' );

			WC_Payments::remove_woo_admin_notes();

			wp_safe_redirect( self::get_settings_url() );
		}

		if ( isset( $_POST['wcpaydev-save-settings'] ) ) {
			check_admin_referer( 'wcpaydev-save-settings', 'wcpaydev-save-settings' );

			self::update_option_from_checkbox( self::DEV_MODE_OPTION );
			self::update_option_from_checkbox( self::FORCE_ONBOARDING_OPTION );
			self::update_option_from_checkbox( self::FORCE_DISCONNECTED_OPTION );
			self::update_option_from_checkbox( self::REDIRECT_OPTION );
			if ( isset( $_POST[ self::REDIRECT_TO_OPTION ] ) ) {
				update_option( self::REDIRECT_TO_OPTION, $_POST[ self::REDIRECT_TO_OPTION ] );
			}
			self::update_option_from_checkbox( self::DISPLAY_NOTICE );

			self::clear_account_cache();

			wp_safe_redirect( self::get_settings_url() );
		}
	}

	/**
	 * Updates the given option name from submitted POST values
	 *
	 * @param string $option_name
	 */
	private static function update_option_from_checkbox( $option_name ) {
		$value = isset( $_POST[ $option_name ] ) && 'on' === $_POST[ $option_name ];
		update_option( $option_name, $value );
	}

	/**
	 * Renders a checkbox for the given option name with the given label
	 *
	 * @param string $option_name
	 * @param string $label
	 * @param bool   $default
	 */
	private static function render_checkbox( $option_name, $label, $default = false ) {
		?>
		<p>
			<input
				type="checkbox"
				id="<?php echo( $option_name ) ?>"
				name="<?php echo( $option_name ) ?>"
				<?php echo( get_option( $option_name, $default ) ? 'checked' : '' ); ?>
			/>
			<label for="<?php echo( $option_name ) ?>">
				<?php echo( $label ) ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Outputs the markup for the admin page
	 */
	private static function admin_page_output() {
		?>
		<h1>WCPay Dev Utils</h1>
		<p>
			<h2>Util settings:</h2>
			<form action="<?php echo( self::get_settings_url() ) ?>" method="post">
				<?php
				wp_nonce_field( 'wcpaydev-save-settings', 'wcpaydev-save-settings' );
				self::render_checkbox( self::DEV_MODE_OPTION, 'Dev mode enabled', true );
				self::render_checkbox( self::FORCE_ONBOARDING_OPTION, 'Force onboarding' );
				self::render_checkbox( self::FORCE_DISCONNECTED_OPTION, 'Force the plugin to act as disconnected from WCPay' );
				self::render_checkbox( self::REDIRECT_OPTION, 'Enable API request redirection' );
				?>
				<p>
					<label for="wcpaydev-redirect-to">
						Redirect API requests to:
					</label>
					<input
						type="text"
						id="<?php echo( self::REDIRECT_TO_OPTION ); ?>"
						name="<?php echo( self::REDIRECT_TO_OPTION ); ?>"
						size="50"
						value="<?php echo( self::get_redirect_to() );?>"
					/>
				</p>
				<?php
				self::render_checkbox( self::DISPLAY_NOTICE, 'Display notice about dev settings', true );
				?>
				<p>
					<input type="submit" value="Submit" />
				</p>
			</form>
		</p>
		<p>
			<h2>WP.com blog ID: <?php echo( self::get_blog_id() ); ?></h2>
		</p>
		<p>
			<h2>Account cache contents <a href="<?php echo wp_nonce_url( add_query_arg( [ 'wcpaydev-clear-cache' => '1' ], self::get_settings_url() ), 'wcpaydev-clear-cache' ); ?>">(clear)</a>:</h2>
			<textarea rows="15" cols="100"><?php echo esc_html( var_export( get_transient( WC_Payments_Account::ACCOUNT_TRANSIENT ), true ) ) ?></textarea>
		</p>
		<p>
			<h2>Gateway settings <a href="<?php echo WC_Payment_Gateway_WCPay::get_settings_url(); ?>">(edit)</a>:</h2>
			<textarea rows="15" cols="100"><?php echo esc_html( var_export( get_option( 'woocommerce_woocommerce_payments_settings' ), true ) ) ?></textarea>
		</p>
		<p>
			<h2><a href="<?php echo wp_nonce_url( add_query_arg( [ 'wcpaydev-clear-notes' => '1' ], self::get_settings_url() ), 'wcpaydev-clear-notes' ); ?>">Delete all WCPay inbox notes</a></h2>
		</p>
		<p>
			<h2><a href="<?php echo wp_nonce_url( add_query_arg( [ 'wcpay-connect' => '1' ], WC_Payment_Gateway_WCPay::get_settings_url() ), 'wcpay-connect' ) ?>">Reonboard</a></h2>
		</p>
		<p>
			<h2><a href="<?php echo self::get_log_url(); ?>">Latest logs</a></h2>
		</p>
		<?php
	}

	/**
	 * Displays a notice about all the settings enabled in this plugin
	 */
	public static function add_notices() {
		if ( ! get_option( self::DISPLAY_NOTICE, true ) ) {
			return;
		}

		$enabled_options = [];

		$notice = '<strong>WCPay dev tools enabled: </strong>';
		if ( get_option( self::DEV_MODE_OPTION, true ) ) {
			$enabled_options[] = 'Dev mode enabled';
		}

		if ( get_option( self::REDIRECT_OPTION, false ) ) {
			$enabled_options[] = 'Redirecting API requests to ' . self::get_redirect_to();
		}

		if ( get_option( self::FORCE_ONBOARDING_OPTION, false ) ) {
			$enabled_options[] = 'Forced onboarding';
		}

		if ( get_option( self::FORCE_DISCONNECTED_OPTION, false ) ) {
			$enabled_options[] = 'Plugin forced to act as disconnected';
		}

		if ( empty( $enabled_options ) ) {
			return;
		}

		$notice .= implode( ', ', $enabled_options );

		echo '<div class="notice notice-warning wcpay-settings-notice"><p>' . $notice . '</p></div>';
	}

	/**
	 * Gets the redirect target url
	 *
	 * @return string
	 */
	private static function get_redirect_to() {
		return get_option( self::REDIRECT_TO_OPTION, 'http://host.docker.internal:8086/wp-json/' );
	}

	/**
	 * Gets the url for the admin page of this plugin
	 *
	 * @return string
	 */
	private static function get_settings_url() {
		return admin_url( 'admin.php?page=' . self::ID );
	}

	/**
	 * Clears the wcpay account cache
	 */
	private static function clear_account_cache() {
		delete_transient( WC_Payments_Account::ACCOUNT_TRANSIENT );
	}

	/**
	 * Authenticates requests from Jetpack server to WP REST API endpoints. However, we don't enforce that the signature
	 * is valid so that we can accept connections from our local development server (which doesn't have any details
	 * about this site's Jetpack connection).
	 *
	 * @param $user
	 *
	 * @return mixed|null
	 */
	public static function mock_rest_authenticate( $user ) {
		if ( ! empty( $user ) ) {
			// Another authentication method is in effect.
			return $user;
		}

		if ( ! isset( $_GET['_for'] ) || $_GET['_for'] !== 'mock_jetpack' ) {
			// Nothing to do for this authentication method.
			return null;
		}

		// Making an assumption here that user 1 owns the Jetpack connection.
		$verified = array(
			'type'    => 'user',
			'user_id' => 1,
		);

		if (
			$verified &&
			isset( $verified['type'] ) &&
			'user' === $verified['type'] &&
			! empty( $verified['user_id'] )
		) {
			return $verified['user_id'];
		}

		return null;
	}

	/**
	 * Returns any authentication errors associated with mock_rest_authenticate (we don't create any, but this check
	 * is required for the authentication to pass).
	 *
	 * @param $value
	 *
	 * @return bool
	 */
	public static function mock_rest_authentication_errors( $value ) {
		if ( $value !== null ) {
			return $value;
		}
		return true;
	}

	/**
	 * Returns a url to the latest WCPay log file
	 *
	 * @return string
	 */
	private static function get_log_url() {
		$logs             = WC_Admin_Status::scan_log_files();
		$latest_file_date = 0;
		$latest_log_key   = '';

		foreach ( $logs as $log_key => $log_file ) {
			if ( ! preg_match( '/^woocommerce-payments-.*$/', $log_key ) ) {
				continue;
			}
			$log_file_path = WC_LOG_DIR . $log_file;
			$file_date     = filemtime( $log_file_path );

			if ( $latest_file_date < $file_date ) {
				$latest_file_date = $file_date;
				$latest_log_key   = $log_key;
			}
		}

		return admin_url( 'admin.php?page=wc-status&tab=logs&log_file=' . $latest_log_key );
	}

	/**
	 * Gets a .com blog_id associated with the site, if possible
	 *
	 * @return int
	 */
	private static function get_blog_id() {
		if ( ! class_exists( 'Jetpack_Options' ) ) {
			return 'could not retrieve';
		}

		return Jetpack_Options::get_option( 'id' );
	}
}

function wcpay_dev_tools_init() {
	WC_Payments_Dev_Tools::init();

	// load the CLI source if required
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		include_once dirname( __FILE__ ) . '/cli-commands.php';
	}
}

add_action( 'plugins_loaded', 'wcpay_dev_tools_init', 999 );

// Register these filters here since user authentication happens before our init function gets a chance to run.
add_filter( 'determine_current_user', [ WC_Payments_Dev_Tools::class, 'mock_rest_authenticate' ], 999 );
add_filter( 'rest_authentication_errors', [ WC_Payments_Dev_Tools::class, 'mock_rest_authentication_errors' ], 999 );
