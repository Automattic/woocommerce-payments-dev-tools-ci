<?php

/**
 * Plugin Name: WooCommerce Payments Dev Tools
 * Description: Dev tools for WooCommerce Payments
 * Author: Automattic
 * Author URI: https://woocommerce.com/
 */

class WC_Payments_Dev_Tools {
	private const ID = 'wcpaydev';
	private const DEV_MODE_OPTION = 'wcpaydev_dev_mode';
	private const FORCE_DISCONNECTED_OPTION = 'wcpaydev_force_disconnected';
	private const FORCE_ONBOARDING_OPTION = 'wcpaydev_force_onboarding';
	private const REDIRECT_OPTION = 'wcpaydev_redirect';
	private const REDIRECT_TO_OPTION = 'wcpaydev_redirect_to';
	private const DISPLAY_NOTICE = 'wcpaydev_display_notice';

	/**
	 * Entry point of the plugin
	 */
	public static function init() {
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
			'WcPay Dev',
			'WcPay Dev',
			'manage_options',
			self::ID,
			[ __CLASS__, 'admin_page' ]
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
	 * Enables the dev mode, based on this plugin's settings
	 */
	public static function maybe_enable_dev_mode( $dev_mode ) {
		return boolval( get_option( self::DEV_MODE_OPTION, true ) );
	}

	/**
	 * Detects outgoing WcPay API requests and redirects them based on this plugin's settings
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
	 * Adds xdebug cookie to the WcPay API requests
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
		<h1>WcPay Dev Utils</h1>
		<form action="<?php echo( self::get_settings_url() ) ?>" method="post">
			<?php
			wp_nonce_field( 'wcpaydev-save-settings', 'wcpaydev-save-settings' );
			self::render_checkbox( self::DEV_MODE_OPTION, 'Dev mode enabled', true );
			self::render_checkbox( self::FORCE_ONBOARDING_OPTION, 'Force onboarding' );
			self::render_checkbox( self::FORCE_DISCONNECTED_OPTION, 'Force the plugin to act as disconnected from WcPay' );
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
		<p>
			<a href="<?php echo wp_nonce_url( add_query_arg( [ 'wcpaydev-clear-cache' => '1' ], self::get_settings_url() ), 'wcpaydev-clear-cache' ); ?>">Clear Account cache</a>
		</p>
		<p>
			<a href="<?php echo wp_nonce_url( add_query_arg( [ 'wcpay-connect' => '1' ], WC_Payment_Gateway_WCPay::get_settings_url() ), 'wcpay-connect' ) ?>">Reonboard</a>
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

		$notice = '<strong>WcPay dev tools enabled: </strong>';
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
}

function wcpay_dev_tools_init() {
	WC_Payments_Dev_Tools::init();
}

add_action( 'plugins_loaded', 'wcpay_dev_tools_init' );
