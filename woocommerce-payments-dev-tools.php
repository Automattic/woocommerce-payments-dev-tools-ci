<?php
/**
 * Plugin Name: WooCommerce Payments Dev Tools
 * Description: Dev tools for WooCommerce Payments. Only effective when WooCommerce Payments is active.
 * Author: Automattic
 * Author URI: https://woocommerce.com/
 */

use WCPay\Database_Cache;
use WCPay\Internal\Payment\Factor;

class WC_Payments_Dev_Tools {
	const ID = 'wcpaydev';
	const DEV_MODE_OPTION = 'wcpaydev_dev_mode';
	const FORCE_DISCONNECTED_OPTION = 'wcpaydev_force_disconnected';
	const FORCE_CARD_TESTING_PROTECTION_ON = 'wcpaydev_force_card_testing_protection_on';
	const RETRY_SERVER_WP_CRON_REDIRECTS = 'retry_server_wp_cron_redirects';
	const DISPLAY_NOTICE = 'wcpaydev_display_notice';
	const REDIRECT_OPTION = 'wcpaydev_redirect';
	const REDIRECT_LOCALHOST_OPTION = 'wcpaydev_redirect_localhost';
	const REDIRECT_TO_OPTION = 'wcpaydev_redirect_to';
	const PROXY_OPTION = 'wcpaydev_proxy';
	const PROXY_VIA_OPTION = 'wcpaydev_proxy_via';
	const JETPACK_AUTHENTICATION_MOCKING = 'wcpaydev_jetpack_authentication_mocking';

	const WCPAY_RELEASE_TAG = 'wcpaydev_wcpay_release_tag';
	const BILLING_CLOCKS_OPTION = 'wcpaydev_wcpay_billing_clock';
	const BILLING_CLOCK_SECRET_KEY_OPTION = 'wcpay_billing_clock_secret';
	const SUBSCRIPTIONS = '_wcpay_feature_subscriptions';
	const CAPITAL = '_wcpay_feature_capital';
	const DOCUMENTS = '_wcpay_feature_documents';
	const WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE = 'override_woopay_eligible';
	const WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE_VALUE = 'override_woopay_eligible_value';
	const WOOPAY_EXPRESS_CHECKOUT_FLAG_NAME = '_wcpay_feature_woopay_express_checkout';
	const OVERWRITE_PAYMENT_PROCESS_FACTORS_FLAG_NAME = '_wcpay_overwrite_payment_process_factors';
	const PAYMENT_PROCESS_FACTOR_PREFIX = '_wcpay_payment_factor_';
	const PAY_FOR_ORDER_FLOW = '_wcpay_feature_pay_for_order_flow';
	const UPE_APPEARANCE_TRANSIENT = 'wcpay_upe_appearance';
	const WC_BLOCKS_UPE_APPEARANCE_TRANSIENT = 'wcpay_wc_blocks_upe_appearance';
	const UPE_APPEARANCE_THEME_TRANSIENT = 'wcpay_upe_appearance_theme';
	const WC_BLOCKS_UPE_APPEARANCE_THEME_TRANSIENT = 'wcpay_wc_blocks_upe_appearance_theme';

	/**
	 * Helpers for GitHub access
	 */
	const WCPAY_PLUGIN_REPOSITORY = 'Automattic/woocommerce-payments';
	const WCPAY_PLUGIN_SLUG = 'woocommerce-payments';
	const WCPAY_PLUGIN_RE = '/\/woocommerce-payments\./';
	const WCPAY_RELEASE_LIST_FILENAME_OPTION = 'wcpaydev_wcpay_releases_list_filename';
	const WCPAY_RELEASE_CACHE_TTL_IN_SEC = 600;
	const WCPAY_ASSET_FILENAME = 'woocommerce-payments.zip';

	const SERVER_API_TIMEOUT_SECONDS = 70;

	private static $database_cache = null;

	/**
	 * Entry point of the plugin.
	 */
	public static function init() {
		include_once __DIR__ . '/woocommerce-payments-dev-shortcuts.php';

		( new WooCommerce_Payments_Dev_Shortcuts() )->add_hooks();

		if ( class_exists( 'WC_Payments_Subscriptions' ) && get_option( self::BILLING_CLOCKS_OPTION, false ) ) {
			require_once 'billing-clocks/class-wc-pay-dev-billing-renewal-tester.php';
			WC_Pay_Dev_Billing_Renewal_Tester::init();
		}
	}

	public static function init_hooks() {
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_head-toplevel_page_' . self::ID, [ __CLASS__, 'admin_page_head' ] );
		add_action( 'woocommerce_payments_account_refreshed', [ __CLASS__, 'add_account_cache_refresh_notice' ] );

		add_action( 'admin_notices', [ __CLASS__, 'maybe_display_settings_notice' ] );
		add_filter( 'wcpay_dev_mode', [ __CLASS__, 'should_activate_dev_mode' ], 10, 1 );
		add_filter( 'pre_http_request', [ __CLASS__, 'maybe_redirect_api_request' ], 10, 3 );
		add_action( 'http_api_curl', [ __CLASS__, 'maybe_proxy_wpcom_request' ], 10, 3 );
		add_filter( 'wc_payments_get_onboarding_data_args', [ __CLASS__, 'maybe_force_re_onboarding' ], 10, 1 );
		add_filter( 'wcpay_api_request_headers', [ __CLASS__, 'add_wcpay_request_headers' ], 10, 1 );
		add_filter( 'upgrader_pre_download', [ __CLASS__, 'maybe_override_wcpay_version' ], 10, 4 );
		add_filter( 'wcpay_api_request_response', [ __CLASS__, 'maybe_retry_server_wp_cron_redirects' ], 10, 4 );
		add_action( 'init', [ __CLASS__, 'maybe_force_disconnected' ] );
		add_action( 'init', [ __CLASS__, 'maybe_override_woopay_eligible' ] );

		add_action( 'woocommerce_payments_account_refreshed', [ __CLASS__, 'maybe_force_card_testing_protection_on' ] );

		add_filter( 'wcpay_new_payment_process_enabled_factors', [ __CLASS__, 'maybe_overwrite_payment_process_factors' ], 10, 4 );
	}

	/**
	 * Gets JETPACK_AUTHENTICATION_MOCKING setting. Fall back to the dev mode setting in case it's not been set.
	 *
	 * @return bool
	 */
	public static function is_jetpack_authentication_mocking_enabled(): bool {
		return boolval( get_option( self::JETPACK_AUTHENTICATION_MOCKING, self::should_activate_dev_mode() ) );
	}
	/**
	 * Hooks into admin_menu and adds an admin page for this plugin.
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
	 * Handle things that need to happen in the admin page head.
	 *
	 * @return void
	 */
	public static function admin_page_head() {
		// Add the admin page slide-in help section.
		get_current_screen()->add_help_tab(
			array(
				'id'      => 'overview',
				'title'   => esc_html__( 'Overview', 'wcpaydev' ),
				'content' => '<p>This page empowers WCPay developers to test and experiment with all sorts of scenarios and features that our merchants might encounter.</p>' .
				             '<p>The bottom sections give you visibility into the merchant account (cache) data and WCPay payment gateway settings present on this store.</p><hr>' .
				             '<p>Read <a href="https://wcpay.wordpress.com/2022/06/09/wc-payments-is-more-than-just-a-plugin/" target="_blank">WC Payments is more than just a plugin</a> and stick it to your mind!</p>',
			)
		);

		// The help sidebar contains useful links to our documentation and field guides.
		get_current_screen()->set_help_sidebar(
			'<p><strong>' . esc_html__( 'For more information:', 'wcpaydev' ) . '</strong></p>' .
			'<p><a href="https://fieldguide.automattic.com/developing-woocommerce-payments/" target="_blank">' . esc_html__( 'WCPay Development Guide', 'wcpaydev' ) . '</a></p>' .
			'<p><a href="https://fieldguide.automattic.com/developing-woocommerce-payments/woocommerce-payments-development-tools/" target="_blank">' . esc_html__( 'WCPay Dev Tools Guide', 'wcpaydev' ) . '</a></p>' .
			'<p><a href="https://fieldguide.automattic.com/developing-woocommerce-payments/development-faq/" target="_blank">' . esc_html__( 'WCPay Development FAQ', 'wcpaydev' ) . '</a></p>'
		);

		// Add a success notice if the settings were updated.
		if ( isset( $_GET['settings-updated'] ) && 'success' === $_GET['settings-updated'] ) {
			add_settings_error( 'actions', 'settings_updated', esc_html__( 'Settings saved — account contents cache automatically cleared.', 'wcpaydev' ), 'success' );
		}

		// Add a success notice if the account cache was cleared.
		if ( isset( $_GET['account-cache-cleared'] ) && 'success' === $_GET['account-cache-cleared'] ) {
			add_settings_error( 'actions', 'account_cache_cleared', esc_html__( 'Cleared the store account cache contents.', 'wcpaydev' ), 'success' );
		}

		// Add a success notice if the WCPay inbox notes were cleared.
		if ( isset( $_GET['inbox-notes-cleared'] ) && 'success' === $_GET['inbox-notes-cleared'] ) {
			add_settings_error( 'actions', 'inbox_notes_cleared', esc_html__( 'Deleted WCPay inbox notes from the WC Admin.', 'wcpaydev' ), 'success' );
		}

		// Add a success notice if the WCPay payment gateway settings were reset.
		if ( isset( $_GET['gateway-settings-cleared'] ) && 'success' === $_GET['gateway-settings-cleared'] ) {
			add_settings_error( 'actions', 'wcpay_options_cleared', esc_html__( 'The WCPay payment gateway saved settings were removed.', 'wcpaydev' ), 'success' );
		}

		// Add a success notice if multi-currency live rates were fetched.
		if ( isset( $_GET['fetched-live-rates'] ) ) {
			if ( 'success' === $_GET['fetched-live-rates'] ) {
				add_settings_error( 'actions', 'fetched_live_rates', esc_html__( 'Fetched live rates from the WCPay server.', 'wcpaydev' ), 'success' );
			} else {
				add_settings_error( 'actions', 'fetched_live_rates_error', esc_html__( 'Failed to fetched live rates from the WCPay server.', 'wcpaydev' ), 'error' );
			}
		}

		// Add a success notice if the server Stripe account cached data was refreshed.
		if ( isset( $_GET['updated-stripe-data-on-server'] ) ) {
			if ( 'success' === $_GET['updated-stripe-data-on-server'] ) {
				add_settings_error( 'actions', 'updated_stripe_data_on_server', esc_html__( 'Forced the update of the cached Stripe account data on the WCPay server. The store\'s account cache data was updated also.', 'wcpaydev' ), 'success' );
			} else {
				add_settings_error( 'actions', 'updated_stripe_data_on_server_error', esc_html__( 'Failed to force the update of the cached Stripe account data on the WCPay server.', 'wcpaydev' ), 'error' );
			}
		}

		// Add a success notice if appearance transients were cleared.
		if ( isset( $_GET['cleared-appearance-transients'] ) ) {
			if ( 'success' === $_GET['cleared-appearance-transients'] ) {
				add_settings_error( 'actions', 'cleared_appearance_transients', esc_html__( 'Cleared appearance transients.', 'wcpaydev' ), 'success' );
			} else {
				add_settings_error( 'actions', 'cleared_appearance_transients_error', esc_html__( 'Failed to clear appearance transients.', 'wcpaydev' ), 'error' );
			}
		}
	}

	/**
	 * Add a WP admin notice about the account cache refresh.
	 *
	 * @return void
	 */
	public static function add_account_cache_refresh_notice() {
		// Only do this for WP admin pages.
		if ( ! is_admin() ) {
			return;
		}

		// Make sure the needed functionality is loaded, in case the cache refresh happened too early.
		if ( ! function_exists( 'add_settings_error' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}

		add_settings_error( 'info', 'account_cache_refresh', esc_html__( 'The account contents cache was refreshed.', 'wcpaydev' ), 'info' );
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public static function enqueue_assets( $hook ) {
		// Only enqueue scripts and styles on our admin page.
		if ( 'toplevel_page_' . self::ID !== $hook ) {
			return;
		}

		// Enqueue the Prism.js assets.
		wp_enqueue_script( self::ID . 'prismjs', plugin_dir_url( __FILE__ ) . '3rd-party/prism/prism.js', [], '1.29.0' );
		wp_enqueue_style( self::ID . 'prismjs_theme', plugin_dir_url( __FILE__ ) . '3rd-party/prism/prism.css', [], '1.29.0' );

		// Enqueue our main script file.
		wp_enqueue_script( self::ID . '_admin_script', plugin_dir_url( __FILE__ ) . 'assets/script.js', [
			'jquery',
			self::ID . 'prismjs',
		], '1.0' );

		// Enqueue our main style file.
		wp_enqueue_style( self::ID . '_admin_style', plugin_dir_url( __FILE__ ) . 'assets/style.css', [ self::ID . 'prismjs_theme' ], '1.0' );
	}

	/**
	 * Admin page handler.
	 */
	public static function admin_page() {
		self::maybe_handle_actions();
		self::maybe_handle_settings_save();
		self::admin_page_output();
	}

	/**
	 * Determines if the WCPay dev mode should be active or not based on this plugin's settings.
	 */
	public static function should_activate_dev_mode(): bool {
		return boolval( get_option( self::DEV_MODE_OPTION, true ) );
	}

	/**
	 * Detects outgoing WCPay API requests and redirects them based on this plugin's settings.
	 *
	 * @param mixed  $preempt A preemptive return value of an HTTP request. Default false.
	 * @param array  $args    HTTP request arguments.
	 * @param string $url     The request URL.
	 */
	public static function maybe_redirect_api_request( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		// First, handle the WCPay API requests.
		if ( get_option( self::REDIRECT_OPTION, false ) &&
		     1 === preg_match( '/^https?:\/\/public-api\.wordpress\.com\/(.+?(?:wcpay|tumblrpay|woopayments).+)/', $url, $matches ) ) {
			$redirect_to = trailingslashit( self::get_redirect_to() );

			return wp_remote_request( $redirect_to . $matches[1], $args );
		}

		// Second, handle any localhost requests if the current request is not a WCPay API request
		// and the WCPay API redirection is not active.
		if ( get_option( self::REDIRECT_LOCALHOST_OPTION, false ) &&
		     1 === preg_match( '/^https?:\/\/localhost/', $url, $matches ) ) {
			$redirect_to = str_replace( 'localhost', 'host.docker.internal', $url );

			return wp_remote_request( $redirect_to, $args );
		}

		return $preempt;
	}

	/**
	 * If enabled, sets *only* the *.wordpress.com requests to be sent via the a8c proxy.
	 * Works only if cURL is used as a method of HTTP transport.
	 *
	 * @param resource $handle      The cURL handle returned by curl_init() (passed by reference).
	 * @param array    $parsed_args The HTTP request arguments.
	 * @param string   $url         The request URL.
	 *
	 * @return void
	 */
	public static function maybe_proxy_wpcom_request( &$handle, $parsed_args, $url ) {
		// Return early if the proxy option is disabled.
		if ( ! get_option( self::PROXY_OPTION, false ) ) {
			return;
		}

		// Return early if the request is not for wpcom.
		if ( 1 !== preg_match( '/^(https?:\/\/)?([^\/]+\.)?wordpress.com/', $url ) ) {
			return;
		}

		// Return early if the proxy url can't be parsed.
		$proxy = untrailingslashit( self::get_proxy_via() );
		if ( 1 !== preg_match( '/(.+):(\d+)/', $proxy, $proxy_matches ) ) {
			return;
		}

		$proxy_host = $proxy_matches[1];
		$proxy_port = $proxy_matches[2];

		curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
		curl_setopt( $handle, CURLOPT_PROXY, $proxy_host );
		curl_setopt( $handle, CURLOPT_PROXYPORT, $proxy_port );
	}

	/**
	 * Adds force_on_boarding flag to the onboarding request.
	 *
	 * @param array $args The WCPay onboarding arguments.
	 *
	 * @return array The changed arguments.
	 */
	public static function maybe_force_re_onboarding( array $args ): array {
		// Be extra sure when we add the force_on_boarding flag to the WCPay server onboarding request. Just to be safe.
		if ( isset( $_GET['force-reonboarding'] )
		     && 'yes' === $_GET['force-reonboarding']
		     && isset( $_GET['wcpay-connect'] )
		     && isset( $_REQUEST['_frobnonce'] )
		     && wp_verify_nonce( $_REQUEST['_frobnonce'], 'force-reonboarding' ) ) {

			$args['force_on_boarding'] = true;
		}

		return $args;
	}

	/**
	 * Adds xdebug cookie to the WCPay API requests.
	 *
	 * @param array $headers
	 *
	 * @return array
	 */
	public static function add_wcpay_request_headers( array $headers ): array {
		if ( isset( $_COOKIE['XDEBUG_SESSION'] ) ) {
			$headers['Cookie'] = 'XDEBUG_SESSION=' . sanitize_text_field( wp_unslash( $_COOKIE['XDEBUG_SESSION'] ) );
		}

		return $headers;
	}

	/**
	 * Forces the plugin to act as disconnected by injecting an empty array into account cache.
	 */
	public static function maybe_force_disconnected() {
		if ( ! get_option( self::FORCE_DISCONNECTED_OPTION, false ) ) {
			return;
		}

		self::get_database_cache() && self::get_database_cache()->add( Database_Cache::ACCOUNT_KEY, [] );
	}

	public static function maybe_force_card_testing_protection_on() {
		if ( ! self::get_database_cache() ) {
			return;
		}

		$account_cache = self::get_database_cache()->get( Database_Cache::ACCOUNT_KEY );
		if ( empty( $account_cache ) || ! is_array( $account_cache ) ) {
			return;
		}

		$should_override_card_testing_protection_flag = boolval( get_option( self::FORCE_CARD_TESTING_PROTECTION_ON, '0' ) );
		if( $should_override_card_testing_protection_flag ) {
			$account_cache['card_testing_protection_eligible'] = boolval( $should_override_card_testing_protection_flag );

			self::get_database_cache()->add( Database_Cache::ACCOUNT_KEY, $account_cache );
		}
	}

	/**
	 * If RETRY_SERVER_WP_CRON_REDIRECTS is checked, look for 302 response code and doing_wp_cron query parameter and if
	 * both are present - retry the request, as it's caused by the WP Cron job running on the local WCPay Server env.
	 *
	 * @param array  $response The response to check if it needs a retry
	 * @param string $method   The HTTP request method.
	 * @param string $url      The request URL.
	 * @param string $api      The called API endpoint.
	 */
	public static function maybe_retry_server_wp_cron_redirects( $response, $method, $url, $api ) {
		if ( ! get_option( self::RETRY_SERVER_WP_CRON_REDIRECTS, false ) ) {
			return $response;
		}

		if ( wp_remote_retrieve_response_code( $response ) == 302 && self::is_wp_cron_query_parameter_present( $response ) ) {
			$url_with_injected_blog_id = str_replace( '%s', self::get_blog_id(), $url );

			return wp_remote_request(
				$url_with_injected_blog_id,
				[
					'method'          => $method,
					'timeout'         => self::SERVER_API_TIMEOUT_SECONDS,
					'connect_timeout' => self::SERVER_API_TIMEOUT_SECONDS,
				]
			);
		}

		return $response;
	}

	/**
	 * True if Location header is present and contains doing_wp_cron query parameter, false otherwise.
	 *
	 * @param array $response
	 *
	 * @return bool
	 */
	private static function is_wp_cron_query_parameter_present( $response ): bool {
		return isset( $response['headers']['location'] ) && strpos( $response['headers']['location'], 'doing_wp_cron' ) !== false;
	}

	/**
	 * Overrides plugin api to inject a download link to a specified WCPay release.
	 *
	 * @param bool        $result     Whether to bail without returning the package.
	 *                                Default false.
	 * @param string      $package    The package file name.
	 * @param WP_Upgrader $upgrader   The WP_Upgrader instance.
	 * @param array       $hook_extra Extra arguments passed to hooked filters.
	 *
	 * @return bool|string|WP_Error
	 */
	public static function maybe_override_wcpay_version( $result, $package, $upgrader, $hook_extra ) {
		if (
			preg_match( self::WCPAY_PLUGIN_RE, $package )
			&& preg_match( '!^(http|https|ftp)://!i', $package )
			&& self::get_wcpay_release_tag()
		) {
			$wcpay_release_tag = self::get_wcpay_release_tag();
			foreach ( self::get_github_releases() as $wcpay_release ) {
				if ( $wcpay_release['tag_name'] === $wcpay_release_tag ) {
					// First get a final GitHub redirect URL.
					$headers      = get_headers( $wcpay_release['download_url'], 1 );
					$download_url = $headers['Location'];

					$upgrader->skin->feedback( 'downloading_package', $download_url );
					$download_file = download_url( $download_url, 300, false );
					if ( is_wp_error( $download_file ) && ! $download_file->get_error_data( 'softfail-filename' ) ) {
						return new WP_Error( 'download_failed', $upgrader->strings['download_failed'], $download_file->get_error_message() );
					}

					return $download_file;
				}
			}

			// We have an old tag name in the options and cannot find it in the cache.
			return new WP_Error(
				'download_failed',
				$upgrader->strings['download_failed'],
				sprintf(
					esc_html__( 'Release with tag "%s" cannot be found in the cache. Please visit WCPay Dev and choose a more recent release tag for override.', 'wcpaydev' ),
					$wcpay_release_tag
				)
			);
		}

		return $result;
	}

	/**
	 * Override the platform checkout merchant eligibility.
	 *
	 * @return void
	 */
	public static function maybe_override_woopay_eligible() {
		if ( ! self::get_database_cache() ) {
			return;
		}

		$account_cache = self::get_database_cache()->get( Database_Cache::ACCOUNT_KEY );
		if ( empty( $account_cache ) || ! is_array( $account_cache ) ) {
			return;
		}

		$should_override_woopay_eligible = boolval( get_option( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE, '0' ) );
		if ( $should_override_woopay_eligible ) {
			$override_woopay_eligible_value   = get_option( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE_VALUE, '0' );
			$account_cache['platform_checkout_eligible'] = boolval( $override_woopay_eligible_value );

			self::get_database_cache()->add( Database_Cache::ACCOUNT_KEY, $account_cache );
		}
	}

	/**
	 * Returns the settings URL of WCPay.
	 *
	 * @return string The URL.
	 */
	public static function get_wcpay_settings_url(): string {
		if ( class_exists( 'WC_Payments_Admin_Settings' ) ) {
			return WC_Payments_Admin_Settings::get_settings_url();
		} else {
			return WC_Payment_Gateway_WCPay::get_settings_url();
		}
	}

	/**
	 * Returns the URL to use for reaching the WCPay onboarding screen.
	 *
	 * @param bool $force_re_onboard Optional. Whether to force the creation of a new Stripe account when
	 *                               following the onboarding URL. This behavior is controlled by the WCPay Server,
	 *                               and it is only available to proxied A12s.
	 *
	 * @return string The URL.
	 */
	public static function get_onboarding_url( bool $force_re_onboard = false ): string {
		$onboarding_url = self::get_connect_url();

		if ( $force_re_onboard ) {
			// Add dedicated nonce for the force reonboarding action.
			$onboarding_url = wp_nonce_url(
				add_query_arg( [ 'force-reonboarding' => 'yes' ], $onboarding_url ),
				'force-reonboarding',
				'_frobnonce'
			);
		}

		return $onboarding_url;
	}

	/**
	 * Returns the WCPay URL to use for the WPCOM/Jetpack/Stripe connect flow.
	 *
	 * While this URL says it is for connect (as in WPCOM connection), internally, the WCPay client will redirect
	 * to the appropriate place (e.g. Stripe KYC if the merchant account has pending KYC, WCPay overview if everything
	 * about the merchant account is properly set up).
	 *
	 * @return string The URL.
	 */
	public static function get_connect_url(): string {
		// Use a standard way to get the WCPay connect URL.
		if ( class_exists( 'WC_Payments_Account' ) && method_exists( 'WC_Payments_Account', 'get_connect_url' ) ) {
			return WC_Payments_Account::get_connect_url();
		}

		return wp_nonce_url(
			add_query_arg(
				[
					'wcpay-connect' => '1',
				],
				self::get_wcpay_settings_url()
			),
			'wcpay-connect'
		);
	}

	/**
	 * Overwrites payment process factors when chosen.
	 *
	 * @param array $factors The factors in the account cache, provided by the server.
	 * @return array         Either the same factors, or the ones enabled in dev tools.
	 */
	public static function maybe_overwrite_payment_process_factors( $factors ) {
		if (
			! get_option( self::OVERWRITE_PAYMENT_PROCESS_FACTORS_FLAG_NAME, false )
			|| ! class_exists( Factor::class )
		) {
			return $factors;
		}

		$factors = [];
		foreach ( Factor::get_all_factors() as $factor ) {
			if ( get_option( self::PAYMENT_PROCESS_FACTOR_PREFIX . $factor, false ) ) {
				$factors[] = $factor;
			}
		}

		return $factors;
	}

	/**
	 * Handles settings page actions.
	 */
	private static function maybe_handle_actions() {
		if ( isset( $_GET['wcpaydev-clear-cache'] ) ) {
			check_admin_referer( 'wcpaydev-clear-cache' );

			self::clear_account_cache();

			if ( wp_safe_redirect( add_query_arg( [ 'account-cache-cleared' => 'success' ], self::get_settings_url() ) ) ) {
				exit;
			}
		}

		if ( isset( $_GET['wcpaydev-clear-notes'] ) ) {
			check_admin_referer( 'wcpaydev-clear-notes' );

			WC_Payments::remove_woo_admin_notes();
			if ( class_exists( 'WCPay\MultiCurrency\MultiCurrency' ) ) {
				WCPay\MultiCurrency\MultiCurrency::remove_woo_admin_notes();
			}

			if ( wp_safe_redirect( add_query_arg( [ 'inbox-notes-cleared' => 'success' ], self::get_settings_url() ) ) ) {
				exit;
			}
		}

		if ( isset( $_GET['wcpaydev-clear-options'] ) ) {
			check_admin_referer( 'wcpaydev-clear-options' );

			delete_option( 'woocommerce_woocommerce_payments_settings' );

			if ( wp_safe_redirect( add_query_arg( [ 'gateway-settings-cleared' => 'success' ], self::get_settings_url() ) ) ) {
				exit;
			}
		}

		if ( isset( $_GET['wcpaydev-fetch-live-rates'] ) ) {
			check_admin_referer( 'wcpaydev-fetch-live-rates' );

			$result = self::fetch_live_rates_from_server();

			if ( wp_safe_redirect( add_query_arg( [ 'fetched-live-rates' => $result ? 'success' : 'error' ], self::get_settings_url() ) ) ) {
				exit;
			}
		}

		if ( isset( $_GET['wcpaydev-update-stripe-on-server'] ) ) {
			check_admin_referer( 'wcpaydev-update-stripe-on-server' );

			$result = self::force_update_stripe_account_data_on_server();

			if ( wp_safe_redirect( add_query_arg( [ 'updated-stripe-data-on-server' => $result ? 'success' : 'error' ], self::get_settings_url() ) ) ) {
				exit;
			}
		}

		if ( isset( $_GET['wcpaydev-clear-appearance-transients'] ) ) {
			check_admin_referer( 'wcpaydev-clear-appearance-transients' );

			self::clear_appearance_transients();

			if ( wp_safe_redirect( add_query_arg( [ 'cleared-appearance-transients' => 'success' ], self::get_settings_url() ) ) ) {
				exit;
			}
		}
	}

	/**
	 * Processes form submission on the settings page.
	 */
	private static function maybe_handle_settings_save() {
		if ( ! isset( $_POST['wcpaydev-save-settings'] ) ) {
			return;
		}

		check_admin_referer( 'wcpaydev-save-settings', 'wcpaydev-save-settings' );

		self::save_option_from_checkbox( self::DEV_MODE_OPTION );
		self::save_option_from_checkbox( self::FORCE_DISCONNECTED_OPTION );

		self::save_option_from_checkbox( self::REDIRECT_OPTION );
		// If we receive an empty string, delete the option to allow the default to kick in.
		if ( isset( $_POST[ self::REDIRECT_TO_OPTION ] ) && '' === $_POST[ self::REDIRECT_TO_OPTION ] ) {
			delete_option( self::REDIRECT_TO_OPTION );
		} else {
			self::save_option( self::REDIRECT_TO_OPTION, '' );
		}
		self::save_option_from_checkbox( self::PROXY_OPTION );
		// If we receive an empty string, delete the option to allow the default to kick in.
		if ( isset( $_POST[ self::PROXY_VIA_OPTION ] ) && '' === $_POST[ self::PROXY_VIA_OPTION ] ) {
			delete_option( self::PROXY_VIA_OPTION );
		} else {
			self::save_option( self::PROXY_VIA_OPTION, '' );
		}
		self::save_option_from_checkbox( self::REDIRECT_LOCALHOST_OPTION );
		self::save_option_from_checkbox( self::JETPACK_AUTHENTICATION_MOCKING );

		self::save_option_from_checkbox( self::SUBSCRIPTIONS, true );
		self::save_option_from_checkbox( self::CAPITAL );
		self::save_option_from_checkbox( self::DOCUMENTS );

		self::save_option_from_checkbox( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE, true );
		self::save_option( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE_VALUE );
		self::save_option_from_checkbox( self::WOOPAY_EXPRESS_CHECKOUT_FLAG_NAME, true );
		self::save_option_from_checkbox( self::PAY_FOR_ORDER_FLOW, true );
		self::save_option_from_checkbox( self::RETRY_SERVER_WP_CRON_REDIRECTS );
		self::save_option_from_checkbox( self::FORCE_CARD_TESTING_PROTECTION_ON, true );

		if ( class_exists( Factor::class ) ) {
			self::save_option_from_checkbox( self::OVERWRITE_PAYMENT_PROCESS_FACTORS_FLAG_NAME );
			foreach ( Factor::get_all_factors() as $factor ) {
				self::save_option_from_checkbox( self::PAYMENT_PROCESS_FACTOR_PREFIX . $factor );
			}
		}

		self::save_option_from_checkbox( self::DISPLAY_NOTICE );
		self::save_option( self::WCPAY_RELEASE_TAG, '' );

		self::save_option_from_checkbox( self::BILLING_CLOCKS_OPTION, true );
		if ( isset( $_POST[ self::BILLING_CLOCKS_OPTION ] ) ) {
			// Only update this option if the WCPay Subscriptions Renewal testing is checked.
			self::save_option( self::BILLING_CLOCK_SECRET_KEY_OPTION );
		}

		self::clear_account_cache();

		if ( wp_safe_redirect( add_query_arg( [ 'settings-updated' => 'success' ], self::get_settings_url() ) ) ) {
			exit;
		}
	}

	/**
	 * Saves the given checkbox option name from submitted POST values.
	 *
	 * @param string $option_name         The option name to look for and use to update in the DB.
	 * @param bool   $delete_if_unchecked Optional. If the option is not present among the POST values or is falsy,
	 *                                    when this parameter is true we will delete the DB option, rather than update it.
	 */
	private static function save_option_from_checkbox( string $option_name, bool $delete_if_unchecked = false ) {
		$value = isset( $_POST[ $option_name ] ) && ( boolval( $_POST[ $option_name ] ) || 'on' === $_POST[ $option_name ] ) ? '1' : '0';

		if ( $delete_if_unchecked && '0' === $value ) {
			delete_option( $option_name );

			return;
		}

		update_option( $option_name, $value );
	}

	/**
	 * Saves the given option name from submitted POST values.
	 *
	 * @param string     $option_name       The option name to look for and use to update in the DB.
	 * @param mixed|null $default           Optional. The default value to use if the option is not present.
	 * @param bool       $delete_if_missing Optional. If the option is not present among the POST values,
	 *                                      when this parameter is true we will delete the DB option.
	 */
	private static function save_option( string $option_name, $default = null, bool $delete_if_missing = false ) {
		if ( ! isset( $_POST[ $option_name ] ) && $delete_if_missing ) {
			// Delete the DB option if we have no value and were instructed to delete.
			delete_option( $option_name );

			return;
		}

		if ( ! isset( $_POST[ $option_name ] ) && is_null( $default ) ) {
			// Bail if we have no value and no default.
			return;
		}

		update_option( $option_name, $_POST[ $option_name ] ?? $default );
	}

	/**
	 * Outputs the markup for the admin page
	 */
	private static function admin_page_output() {
		// Display our actions and info notices, if they are present.
		settings_errors( 'actions' );
		settings_errors( 'info' );
		?>
<div class="wrap">

	<h1>WCPay Dev Tools Settings</h1>

	<div id="wcpay-dev-tools-settings" class="columns-2 wp-clearfix has-right-sidebar">
		<div class="main-column">
			<form action="<?php echo esc_url( self::get_settings_url() ); ?>" method="post">
				<?php wp_nonce_field( 'wcpaydev-save-settings', 'wcpaydev-save-settings' ); ?>

				<table class="form-table" role="presentation">
					<?php self::admin_page_general_settings_output(); ?>
					<?php self::admin_page_requests_settings_output(); ?>
					<?php self::admin_page_feature_flags_settings_output(); ?>
					<?php self::admin_page_woopay_settings_output(); ?>
				</table>

				<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Changes', 'wcpaydev' ); ?>"></p>
			</form>
		</div><!-- .main-column -->

		<div class="secondary-column">
			<div class="box-container">

				<?php self::admin_page_sidebar_store_details_box_output(); ?>
				<?php self::admin_page_sidebar_actions_box_output(); ?>
				<?php self::admin_page_sidebar_links_box_output(); ?>

			</div><!-- .box-container -->
		</div><!-- .secondary-column -->

	</div><!-- #wcpay-dev-tools-settings -->

	<?php
	$account_section_active = class_exists( 'WC_Payments_Account' ) && class_exists( 'WCPay\Database_Cache' );
	if ( $account_section_active ) {
		$account_cache = get_option( Database_Cache::ACCOUNT_KEY );
		?>
		<h2>Account cache contents <a href="<?php echo wp_nonce_url( add_query_arg( [ 'wcpaydev-clear-cache' => 'yes' ], self::get_settings_url() ), 'wcpaydev-clear-cache' ); ?>">(clear)</a></h2>
		<?php if ( ! empty( $account_cache['fetched'] ) ) { ?>
			<p>The account data was last fetched from the WCPay Server: <strong><?php echo human_time_diff( intval( $account_cache['fetched'] ) ) ?> ago.</strong></p>
		<?php }

		if ( is_array( $account_cache )
			&& ! empty( $account_cache['errored'] ) ) { ?>
			<p>❗️ There was a problem getting the account data. If you target <strong>your local WCPay server,</strong> make sure it is running and you are redirecting WCPay API requests to it 🤔</p>
		<?php } elseif ( is_array( $account_cache )
						&& empty( $account_cache['data'] )
						&& get_option( self::FORCE_DISCONNECTED_OPTION, false ) ) { ?>
			<p>ℹ️ The cache contents are empty because you have the "Force the WCPay plugin to act as disconnected from the WCPay Server" option enabled.</p>
		<?php } ?>
		<div class="code-container">
			<pre><code class="language-php"><?php
					if ( false === $account_cache ) {
						echo 'The account cache hasn\'t been saved into the database, yet.';
					} else if ( ! array_key_exists( 'data', $account_cache ) ) {
						if ( empty( $account_cache['errored'] ) ) {
							echo 'There is no data entry in the account cache and no error has been reported!';
						} else {
							echo 'There is no data entry in the account cache!';
						}
					} else {
						self::custom_var_export( $account_cache['data'] );
					} ?></code></pre>
		</div>

		<h2>WCPay Payment Gateway settings <a href="<?php echo self::get_wcpay_settings_url(); ?>">(edit)</a></h2>
		<?php
		$gateway_settings = get_option( 'woocommerce_woocommerce_payments_settings' );
		if ( false === $gateway_settings ) { ?>
			<p>ℹ️ There are no WCPay Payment Gateway settings saved in the DB. Go to the <a href="<?php echo self::get_wcpay_settings_url(); ?>">settings page</a> to change and/or save them.</p>
		<?php } else { ?>
		<div class="code-container">
			<pre><code class="language-php"><?php self::custom_var_export( $gateway_settings ); ?></code></pre>
		</div>
	<?php }
		} else {
			self::disabled_section();
		} ?>
	</div><!-- .wrap -->
		<?php
	}

	/**
	 * Output markup for a disabled section.
	 */
	private static function disabled_section() {
		?>
		<h2>Disabled section</h2>
		<p>You can't access certain settings and information due to missing dependencies.</p>
		<p>Make sure that the WCPay plugin and all its dependencies are installed and active, Jetpack is connected, and
			then try again.</p>
		<?php
	}

	/**
	 * Outputs the markup for the admin page General Settings section.
	 */
	private static function admin_page_general_settings_output() { ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'General settings', 'wcpaydev' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><span><?php esc_html_e( 'General WCPay Dev Tools settings', 'wcpaydev' ); ?></span>
					</legend>

					<?php self::render_checkbox(
						self::DEV_MODE_OPTION,
						'Enable the WCPay <code>dev mode</code>',
						'Most of the time, this should be enabled! It controls many development must-haves, like Stripe test accounts, debug logging, etc.',
						true
					); ?>
					<?php self::render_checkbox(
						self::FORCE_DISCONNECTED_OPTION, 'Force the WCPay plugin to act as <strong>disconnected from the WCPay Server</strong>',
						'As long as this is checked, the WCPay account\'s cache contents are set to an empty array, regardless of what other steps are taken (reonboarding, etc.).'
					); ?>
					<?php self::render_checkbox(
						self::FORCE_CARD_TESTING_PROTECTION_ON, 'Force the WCPay plugin to act with <strong>Card testing mitigations enabled on the WCPay Server</strong>',
						'As long as this is checked, the WCPay client will act as the card testing mitigations are activated.'
					); ?>

					<?php self::render_checkbox( self::RETRY_SERVER_WP_CRON_REDIRECTS, 'Retry WP-Cron requests to WCPay server that result in a redirect response (<code>302</code> status code)' ); ?>
					<?php self::render_checkbox( self::DISPLAY_NOTICE, 'Display a <strong>WP admin-wide notice</strong> with the currently enabled WCPay Dev Tools settings', '', true ); ?>

					<label for="<?php echo( self::WCPAY_RELEASE_TAG ); ?>">
						Force this <strong>WCPay plugin version</strong> during install/update:
						<select
							id="<?php echo( self::WCPAY_RELEASE_TAG ); ?>"
							name="<?php echo( self::WCPAY_RELEASE_TAG ); ?>"
						>
							<option value="">Latest stable version</option>
							<?php
							$wcpay_release_tag = self::get_wcpay_release_tag();
							foreach ( self::get_github_releases() as $wcpay_release ) { ?>
								<option value="<?php echo esc_attr( $wcpay_release['tag_name'] ); ?>" <?php selected( $wcpay_release['tag_name'], $wcpay_release_tag ); ?>><?php echo $wcpay_release['tag_name']; ?></option>
							<?php } ?>
						</select>
					</label>

				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Outputs the markup for the admin page Requests settings section.
	 */
	private static function admin_page_requests_settings_output() { ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Requests settings', 'wcpaydev' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><span><?php esc_html_e( 'Requests related settings', 'wcpaydev' ); ?></span></legend>

					<?php self::render_checkbox(
						self::REDIRECT_LOCALHOST_OPTION,
						'Redirect <code>localhost</code> API requests to <code>host.docker.internal</code>',
						'<code>host.docker.internal</code> will <a class="external-link" href="https://docs.docker.com/desktop/networking/#i-want-to-connect-from-a-container-to-a-service-on-the-host">resolve</a> to your OS host\'s internal IP. Useful if your site runs inside a Docker container and expects to make requests to other services running on the host machine.'
					); ?>

					<label for="<?php echo esc_attr( self::REDIRECT_OPTION ); ?>">
						<input name="<?php echo esc_attr( self::REDIRECT_OPTION ); ?>" type="checkbox"
						       id="<?php echo esc_attr( self::REDIRECT_OPTION ); ?>"
						       value="1" <?php checked( '1', get_option( self::REDIRECT_OPTION ) ); ?> />
						Redirect WCPay API requests to </label>
					<label for="<?php echo esc_attr( self::REDIRECT_TO_OPTION ); ?>">
						<input
							type="text"
							id="<?php echo( self::REDIRECT_TO_OPTION ); ?>"
							name="<?php echo( self::REDIRECT_TO_OPTION ); ?>"
							size="40"
							value="<?php echo( self::get_redirect_to() ); ?>"
						/>
					</label><br/>
					<p class="description checkbox-description">All WCPay WPCOM public API requests (<code>https://public-api.wordpress.com</code>)
						will be redirected.<br>The default value redirects to your local WCPay Server REST API (Docker
						container). Empty and save to <em>revert</em> to the default redirect.</p>

					<label for="<?php echo esc_attr( self::PROXY_OPTION ); ?>">
						<input name="<?php echo esc_attr( self::PROXY_OPTION ); ?>" type="checkbox"
						       id="<?php echo esc_attr( self::PROXY_OPTION ); ?>"
						       value="1" <?php checked( '1', get_option( self::PROXY_OPTION ) ); ?> />
						Proxy WPCOM requests through </label>
					<label for="<?php echo esc_attr( self::PROXY_VIA_OPTION ); ?>">
						<input
							type="text"
							id="<?php echo( self::PROXY_VIA_OPTION ); ?>"
							name="<?php echo( self::PROXY_VIA_OPTION ); ?>"
							size="40"
							value="<?php echo( self::get_proxy_via() ); ?>"
						/>
					</label><br/>
					<p class="description checkbox-description">All WPCOM requests (<code>*.wordpress.com</code>) will
						be proxied through the given proxy.<br>By default it proxies through your local WCPay Server
						(Docker container). Empty and save to <em>revert</em> to the default proxy.<br>Note: <strong>In
							general, you don't need to proxy.</strong> If you <em>"Redirect WCPay API requests"</em>
						then you probably want to proxy them also.</p>
					<?php
					self::render_checkbox(
						self::JETPACK_AUTHENTICATION_MOCKING,
						'Enable Jetpack authentication mocking',
						'Allow incoming REST requests from servers to pass Jetpack authentication. This is <strong>required</strong> for testing with a local server, but unnecessary for sandboxed or production WPCOM servers. Most of the time you just need this feature on for your localhost environment. <br><strong>Note: Extremely careful when enabling this option for sites in production or with public access, even with Jurassic Ninja, as it opens access to WooPayments REST API without authentication.</strong>',
						self::is_jetpack_authentication_mocking_enabled()
					);
					?>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Outputs the markup for the admin page Feature Flags settings section.
	 */
	private static function admin_page_feature_flags_settings_output() { ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Feature Flags', 'wcpaydev' ); ?></th>
			<td>
				<p class="group-description">Force activate WCPay features that are under a feature flag.</p>
				<fieldset>
					<legend class="screen-reader-text"><span><?php esc_html_e( 'WCPay Feature flags settings', 'wcpaydev' ); ?></span></legend>

					<?php self::render_checkbox( self::SUBSCRIPTIONS, 'Enable WCPay Subscriptions' ); ?>

					<?php
					// These options are dependent on the WCPay subscriptions feature being enabled.
					$subscriptions_enabled               = get_option( self::SUBSCRIPTIONS );
					$subscriptions_renewal_testing_class = '';
					if ( ! $subscriptions_enabled ) {
						$subscriptions_renewal_testing_class = ' hide-if-js';
					} ?>
					<div
						class="wcpay-subscriptions-settings indentation-1 <?php echo esc_attr( $subscriptions_renewal_testing_class ); ?>">
						<?php self::render_checkbox( self::BILLING_CLOCKS_OPTION, 'Enable WCPay Subscriptions renewal testing (Test clocks)' ); ?>

						<?php
						// These options are dependent on WCPay subscriptions renewal testing being enabled.
						$subscriptions_renewal_testing_enabled          = get_option( self::BILLING_CLOCKS_OPTION );
						$subscriptions_renewal_testing_secret_key_class = '';
						if ( ! $subscriptions_renewal_testing_enabled ) {
							$subscriptions_renewal_testing_secret_key_class = ' hide-if-js';
						} ?>
						<div class="wcpay-subscriptions-renewal-testing-settings <?php echo esc_attr( $subscriptions_renewal_testing_secret_key_class ); ?>" >
							<label for="<?php echo esc_attr( self::BILLING_CLOCK_SECRET_KEY_OPTION ) ?>">WCPay Secret Test Key (required)
								<input
									type="text"
									size="35"
									id="<?php echo esc_attr( self::BILLING_CLOCK_SECRET_KEY_OPTION ) ?>"
									name="<?php echo esc_attr( self::BILLING_CLOCK_SECRET_KEY_OPTION ) ?>"
									value="<?php echo esc_html( get_option( self::BILLING_CLOCK_SECRET_KEY_OPTION, '' ) ) ?>"
								/>
								<span id="copyButton" type="button" class="inline-emoji-button"
								      title="Copy to Clipboard" style="cursor:pointer"
								      data-copy-target="<?php echo esc_attr( self::BILLING_CLOCK_SECRET_KEY_OPTION ) ?>">📋</span>
							</label>
							<br/>
						</div>
					</div>

					<?php self::render_checkbox( self::CAPITAL, 'Enable Stripe Capital' ); ?>
					<?php self::render_checkbox( self::DOCUMENTS, 'Enable WCPay Documents section' ); ?>
					<?php self::render_checkbox( self::PAY_FOR_ORDER_FLOW, "Enable the Pay-for-order flow for WooPay", false ); ?>

					<h4>New Payment Process Factors</h4>

					<?php self::render_checkbox( self::OVERWRITE_PAYMENT_PROCESS_FACTORS_FLAG_NAME, 'Overwrite payment process factors' ); ?>

					<p style="border: 1px solid rgba(0,0,0,0.3); padding: 1em;">
						<?php
						if ( class_exists( Factor::class ) ) {
							foreach ( Factor::get_all_factors() as $factor ) {
								self::render_checkbox(
									self::PAYMENT_PROCESS_FACTOR_PREFIX . $factor,
									'<code>' . $factor . '</code>',
									'',
									false,
									false
								);
							}
						} else {
							echo 'You are running a version of WooPayments, which does not support the new payment process.';
						}
						?>
					</p>

					<p>Checking supported factors will enable using the new payment process whenever they are present. Learn more <a class="external-link" class="external-link" href="https://wp.me/paJDYF-9hL">here</a></p>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Outputs the markup for the admin page WooPay settings section.
	 */
	private static function admin_page_woopay_settings_output() { ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'WooPay', 'wcpaydev' ); ?></th>
			<td>
				<p class="group-description">Settings related to WooPay (WCPay-as-a-platform). Learn more <a
						class="external-link"
						href="https://wcpay.wordpress.com/2022/06/22/project-thread-woocommerce-payments-as-a-platform/">here</a>
					and <a class="external-link" href="https://fieldguide.automattic.com/woopay-guide/">here</a></p>
				<fieldset>
					<legend class="screen-reader-text"><span><?php esc_html_e( 'WooPay settings', 'wcpaydev' ); ?></span></legend>

					<label for="<?php echo esc_attr( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE ); ?>">
						<input name="<?php echo esc_attr( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE ); ?>"
						       type="checkbox"
						       id="<?php echo esc_attr( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE ); ?>"
						       value="1" <?php checked( '1', get_option( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE ) ); ?> />
						Force the <code>platform_checkout_eligible</code> flag (aka WooPay eligible) in the account cache to be </label>
					<label for="<?php echo esc_attr( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE_VALUE ); ?>">
						<?php $current_override_value = get_option( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE_VALUE ); ?>
						<select name="<?php echo esc_attr( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE_VALUE ); ?>"
						        id="<?php echo esc_attr( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE_VALUE ); ?>">
							<option value="1" <?php selected( '1', $current_override_value ); ?>>true</option>
							<option value="0" <?php selected( '0', $current_override_value ); ?>>false</option>
						</select>
					</label><br/>

					<?php self::render_checkbox( self::WOOPAY_EXPRESS_CHECKOUT_FLAG_NAME, 'Enable the WooPay Express Checkout button' ); ?>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Outputs the markup for the admin page sidebar store details box.
	 */
	private static function admin_page_sidebar_store_details_box_output() { ?>
		<div class="stuffbox">
			<h2>Store details</h2>
			<div class="inside">
				<?php
				$connected_to_server = self::is_connected_to_server();
				if ( ! $connected_to_server ) { ?>
					<h3 class="has-description">⛔️ The store can't talk with the WCPay server!</h3>
					<p class="description">Check your environment and make sure that WPCOM/Jetpack connection is set up.</p>
				<?php } else {
					$wpcom_blog_id     = self::get_blog_id();
					$stripe_account_id = WC_Payments::get_account_service()->get_stripe_account_id();
					?>
					<h3 class="has-description">✅ The store is connected to the WCPay server!</h3>

					<h3 class="has-description">
						WP.com blog ID:
						<span id="blogId"><?php echo $wpcom_blog_id ?? 'could not retrieve'; ?></span>
						<span id="copyButton" type="button" title="Copy to Clipboard" style="cursor:pointer"
						      data-copy-target="blogId">📋</span>
						<?php if ( $wpcom_blog_id ) { ?>
							<a class="external-link"
							   href="<?php echo esc_url( self::get_mc_account_url( $wpcom_blog_id ) ); ?>"
							   target="_blank">MC&nbsp;WCPay</a>
							<a class="external-link"
							   href="<?php echo esc_url( self::get_mc_blog_rc_url( $wpcom_blog_id ) ); ?>"
							   target="_blank">Blog&nbsp;RC</a>
						<?php } ?>
					</h3>
					<p class="description">Unique ID for this site on WPCOM (via Jetpack connection).</p>

					<?php if ( $stripe_account_id ) { ?>
						<h3 class="has-description">
							Stripe ID:
							<span id="stripeId"><?php echo esc_html( $stripe_account_id ); ?></span>
							<span id="copyButton" type="button" title="Copy to Clipboard" style="cursor:pointer"
							      data-copy-target="stripeId">📋</span>
							<a class="external-link"
							   href="<?php echo esc_url( self::get_stripe_dashboard_url( $stripe_account_id, $account_data_cache['data']['is_live'] ?? false ) ); ?>"
							   target="_blank">Stripe</a>
						</h3>
						<p class="description">In the Stripe dashboard use "WCPay Dev (Express)" for dev accounts or "WooCommerce Payments" for live ones.</p>
					<?php }
				} ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Outputs the markup for the admin page sidebar actions box.
	 */
	private static function admin_page_sidebar_actions_box_output() { ?>
		<div class="stuffbox">
			<h2>Actions</h2>
			<div class="inside">
				<h3 class="has-description"><a href="<?php echo self::get_onboarding_url( true ) ?>" onclick='return confirm("Are you sure?\nA new test Stripe account will be created for your store during the onboarding process.");'>Re-onboard with WCPay</a></h3>
				<p class="description">Onboard with a new Stripe account (proxied A12s only).</p>
				<h3 class="has-description"><a href="<?php echo wp_nonce_url( add_query_arg( [ 'wcpaydev-update-stripe-on-server' => 'yes' ], self::get_settings_url() ), 'wcpaydev-update-stripe-on-server' ); ?>">Force update WCPay Server Stripe cache</a></h3>
				<p class="description">Useful when you don't use <a class="external-link" href="https://github.com/Automattic/woocommerce-payments-server/blob/trunk/local/README.md#5-listen-to-webhooks" target="_blank">webhooks listening</a> on your local server.</p>
				<h3 class="has-description"><a href="<?php echo wp_nonce_url( add_query_arg( [ 'wcpaydev-clear-options' => 'yes' ], self::get_settings_url() ), 'wcpaydev-clear-options' ); ?>">Delete saved WCPay Gateway settings</a></h3>
				<p class="description">Deletes the DB option. Go to <a href="<?php echo self::get_wcpay_settings_url(); ?>">the settings page</a> to save/update.</p>
				<h3><a href="<?php echo wp_nonce_url( add_query_arg( [ 'wcpaydev-clear-notes' => 'yes' ], self::get_settings_url() ), 'wcpaydev-clear-notes' ); ?>">Delete all WCPay notes from WC inbox</a></h3>

				<h3 class="has-description"><a href="<?php echo wp_nonce_url( add_query_arg( [ 'wcpaydev-fetch-live-rates' => 'yes' ], self::get_settings_url() ), 'wcpaydev-fetch-live-rates' ); ?>">Fetch latest currency rates</a></h3>
				<p class="description">Works when <a class="external-link" href="https://woocommerce.com/document/payments/currencies/multi-currency-setup" target="_blank">WCPay Multi-Currency</a> is enabled.</p>
				<h3 class="has-description"><a href="<?php echo wp_nonce_url( add_query_arg( [ 'wcpaydev-clear-appearance-transients' => 'yes' ], self::get_settings_url() ), 'wcpaydev-clear-appearance-transients' ); ?>">Delete UPE appearance transients</a></h3>
				<p class="description">Deletes the UPE appearance transients and forces the appearance styles to be regenerated.</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Outputs the markup for the admin page sidebar actions box.
	 */
	private static function admin_page_sidebar_links_box_output() { ?>
		<div class="stuffbox">
			<h2>Useful links</h2>
			<div class="inside">
				<h3 class="has-description"><a href="<?php echo self::get_onboarding_url() ?>">Go to WCPay Onboarding</a></h3>
				<p class="description">This link will be redirected according to the KYC status of the merchant account (e.g., Stripe KYC, WCPay overview page).</p>

				<h3><a href="<?php echo self::get_log_url(); ?>">Latest WCPay logs</a></h3>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a checkbox for the given option name with the given label.
	 *
	 * @param string $option_name The id to use as the option name.
	 * @param string $label       The label for this checkbox.
	 * @param string $description Optional. The description for this checkbox.
	 * @param bool   $default     Optional. The default value of this checkbox.
	 * @param bool   $break       Optional. Whether to add a line break.
	 */
	private static function render_checkbox( string $option_name, string $label, string $description = '', bool $default = false, bool $break = true ) {
		global $allowedtags;
		?>
		<label for="<?php echo esc_attr( $option_name ); ?>" style="display: inline-block; padding-right: .5em">
			<input name="<?php echo esc_attr( $option_name ); ?>" type="checkbox"
			       id="<?php echo esc_attr( $option_name ); ?>"
			       value="1" <?php checked( '1', get_option( $option_name, $default ) ); ?> />
			<?php echo wp_kses_data( $label ); ?></label>
		<?php if ( $break ): ?>
		<br/>
		<?php endif; ?>
		<?php
		if ( ! empty( $description ) ) {
			echo '<p class="description checkbox-description">' . wp_kses( $description,
					array_map( '_wp_add_global_attributes', $allowedtags + [
							'br' => [],
							'a'  => [
								'class'  => true,
								'href'   => true,
								'title'  => true,
								'target' => true,
							],
						] )
				) . '</p>';
		}
	}

	/**
	 * Displays a notice about all the settings enabled in this plugin.
	 */
	public static function maybe_display_settings_notice() {
		if ( ! get_option( self::DISPLAY_NOTICE, true ) ) {
			return;
		}

		$enabled_options = [];

		$notice = '<strong>WCPay Dev Tools enabled: </strong>';
		if ( get_option( self::DEV_MODE_OPTION, true ) ) {
			$enabled_options[] = 'Dev mode';
		}

		if ( get_option( self::FORCE_DISCONNECTED_OPTION, false ) ) {
			$enabled_options[] = 'Plugin forced to act as disconnected';
		}

		if ( get_option( self::WCPAY_RELEASE_TAG, false ) ) {
			$enabled_options[] = 'WCPay plugin installation will use release <code>' . self::get_wcpay_release_tag() . '</code>';
		}

		if ( get_option( self::REDIRECT_OPTION, false ) ) {
			$enabled_options[] = 'Redirecting WCPay API requests to <code>' . self::get_redirect_to() . '</code>';
		}

		if ( get_option( self::PROXY_OPTION, false ) ) {
			$enabled_options[] = 'Proxying WPCOM requests through <code>' . self::get_proxy_via() . '</code>';
		}

		if ( get_option( self::SUBSCRIPTIONS, false ) ) {
			$enabled_options[] = 'WCPay Subscriptions';
		}

		if ( get_option( self::BILLING_CLOCKS_OPTION, false ) ) {
			$enabled_options[] = 'WCPay Subscriptions renewal testing';
		}

		if ( get_option( self::CAPITAL, false ) ) {
			$enabled_options[] = 'Stripe Capital';
		}

		if ( get_option( self::DOCUMENTS, false ) ) {
			$enabled_options[] = 'Account Documents section';
		}

		if ( get_option( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE, '0' ) ) {
			$overriding_value  = get_option( self::WOOPAY_OVERRIDE_PLATFORM_CHECKOUT_ELIGIBLE_VALUE, '0' ) ? 'true' : 'false';
			$enabled_options[] = 'Overriding the platform_checkout_eligible flag in the account cache to <code>' . $overriding_value . '</code>';
		}

		if ( get_option( self::WOOPAY_EXPRESS_CHECKOUT_FLAG_NAME, '0' ) ) {
			$enabled_options[] = 'WooPay Express Checkout button';
		}

		if ( empty( $enabled_options ) ) {
			return;
		}

		$notice .= self::natural_language_join( $enabled_options );

		echo '<div class="notice notice-warning wcpay-settings-notice"><p>' . $notice . '.</p></div>';
	}

	/**
	 * Join a list of strings with a natural language conjunction at the end.
	 *
	 * Inspired by https://stackoverflow.com/a/25057951
	 *
	 * @param array  $list        The list of parts to join.
	 * @param string $conjunction The conjunction to use for the last part join.
	 *
	 * @return string The resulting string. Empty string on empty list.
	 */
	public static function natural_language_join( array $list, string $conjunction = 'and' ): string {
		if ( empty( $list ) ) {
			return '';
		}

		$oxford_separator = count( $list ) == 2 ? ' ' : ', ';
		$last             = array_pop( $list );
		if ( $list ) {
			return implode( ', ', $list ) . $oxford_separator . $conjunction . ' ' . $last;
		}

		return $last;
	}

	/**
	 * Clears the wcpay account cache.
	 */
	public static function clear_account_cache() {
		self::get_database_cache() && self::get_database_cache()->delete( Database_Cache::ACCOUNT_KEY );
	}

	/**
	 * Gets the redirect target url.
	 *
	 * @return string
	 */
	private static function get_redirect_to(): string {
		return get_option( self::REDIRECT_TO_OPTION, 'http://host.docker.internal:8086/wp-json/' );
	}

	/**
	 * Gets the proxy url.
	 *
	 * @return string
	 */
	private static function get_proxy_via(): string {
		return get_option( self::PROXY_VIA_OPTION, 'socks5://host.docker.internal:8080' );
	}

	/**
	 * Returns version number override.
	 *
	 * @return string
	 */
	private static function get_wcpay_release_tag(): string {
		return get_option( self::WCPAY_RELEASE_TAG, '' );
	}

	/**
	 * Gets the url for the admin page of this plugin.
	 *
	 * @return string
	 */
	private static function get_settings_url(): string {
		return admin_url( 'admin.php?page=' . self::ID );
	}

	/**
	 * Updates the rates from the live server.
	 *
	 * @return bool
	 */
	private static function fetch_live_rates_from_server(): bool {
		// If Multi-currency isn't loaded, skip this.
		if ( ! function_exists( 'WC_Payments_Multi_Currency' ) ) {
			return false;
		}

		// Store previous settings to variables.
		$proxy_status    = get_option( self::PROXY_OPTION, '0' );
		$api_redirection = get_option( self::REDIRECT_OPTION, '0' );

		// Forcibly disable proxy and redirect, so we are sure to hit the WPCOM public API.
		update_option( self::PROXY_OPTION, '0' );
		update_option( self::REDIRECT_OPTION, '0' );

		// Do the live fetch.
		$multi_currency = WC_Payments_Multi_Currency();
		$multi_currency->clear_cache();
		$multi_currency->get_cached_currencies();

		// Revert back the settings.
		update_option( self::PROXY_OPTION, $proxy_status );
		update_option( self::REDIRECT_OPTION, $api_redirection );

		return true;
	}

	/**
	 * Forces the update of the cached Stripe account data on the WCPay server.
	 *
	 * @return bool
	 */
	private static function force_update_stripe_account_data_on_server(): bool {
		$account_cache = self::get_database_cache()->get( Database_Cache::ACCOUNT_KEY );
		// Don't change anything.
		// We rely on the fact that right now the server does an update (and cache refresh) even if the data doesn't change.
		$account_settings = [
			'business_name' => $account_cache['business_profile']['name'] ?? '',
		];

		if ( method_exists( '\WCPay\Core\Server\Request\Update_Account', 'from_account_settings' ) ) {
			$request         = \WCPay\Core\Server\Request\Update_Account::from_account_settings( $account_settings );
			$response        = $request->send( 'wcpay_update_account_settings' );
			$updated_account = $response->to_array();
		} elseif ( method_exists( self::get_wcpay_api_client(), 'update_account' ) ) {
			$updated_account = self::get_wcpay_api_client()->update_account( $account_settings );
		}

		self::get_database_cache() && self::get_database_cache()->add( Database_Cache::ACCOUNT_KEY, $updated_account );

		return true;
	}

	/**
	 * Deletes the WooPayments appearance transients.
	 *
	 * @return boolean
	 */
	private static function clear_appearance_transients(): bool {
		$transients = [
			self::UPE_APPEARANCE_TRANSIENT,
			self::WC_BLOCKS_UPE_APPEARANCE_TRANSIENT,
			self::UPE_APPEARANCE_THEME_TRANSIENT,
			self::WC_BLOCKS_UPE_APPEARANCE_THEME_TRANSIENT
		];

		foreach ( $transients as $transient ) {
			delete_transient( $transient );
		}

		return true;
	}

	/**
	 * Authenticates requests from Jetpack server to WP REST API endpoints. However, we don't enforce that the signature
	 * is valid so that we can accept connections from our local development server (which doesn't have any details
	 * about this site's Jetpack connection).
	 *
	 * @param int|false $user User ID if one has been determined, false otherwise.
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

		if ( false === self::is_jetpack_authentication_mocking_enabled() ) {
			exit( 'Option "Jetpack authentication mocking" is disabled in WooPayments Dev Tools' );
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
	 * @param WP_Error|null|true $errors WP_Error if authentication error, null if authentication
	 *                                   method wasn't used, true if authentication succeeded.
	 *
	 * @return bool
	 */
	public static function mock_rest_authentication_errors( $errors ) {
		if ( $errors !== null ) {
			return $errors;
		}

		return true;
	}

	/**
	 * Authenticates requests from local WooPay to WP REST API endpoints.
	 *
  	 * @param bool $is_signed_with_blog_token
	 * @return bool
	 */
	public static function mock_rest_authentication_is_signed_with_blog_token( $is_signed_with_blog_token ) {
		if ( ! isset( $_GET['_for'] ) || $_GET['_for'] !== 'mock_jetpack_woopay' ) {
			return $is_signed_with_blog_token;
		}

		if ( false === self::is_jetpack_authentication_mocking_enabled() ) {
			exit( 'Option "Jetpack authentication mocking" is disabled in WooPayments Dev Tools.' );
		}

		return true;
	}

	/**
	 * Returns a url to the latest WCPay log file
	 *
	 * @return string
	 */
	private static function get_log_url(): string {
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
	 * Get the WCPay API client instance used by WCPay.
	 *
	 * @return false|WC_Payments_API_Client
	 */
	private static function get_wcpay_api_client() {
		if ( ! is_callable( 'WC_Payments::get_payments_api_client' ) ) {
			return false;
		}

		return WC_Payments::get_payments_api_client();
	}

	/**
	 * Gets the current WP.com blog ID, if the Jetpack connection has been set up.
	 *
	 * @return int|null Current WPCOM blog ID, or NULL if not connected yet.
	 */
	private static function get_blog_id() {
		$api_client = self::get_wcpay_api_client();
		if ( ! $api_client ) {
			return null;
		}

		return $api_client->get_blog_id();
	}

	/**
	 * Gets the MC WCPay page URL for the provided WP.com blog ID.
	 *
	 * @param int $blog_id The WPCOM blog ID.
	 *
	 * @return string The MC WCPay account URL.
	 */
	private static function get_mc_account_url( int $blog_id ): string {
		return add_query_arg( [ 'account_id' => $blog_id ], 'https://mc.a8c.com/woocommerce-payments/account.php' );
	}

	/**
	 * Gets the MC Blog RC page URL for the provided WP.com blog ID.
	 *
	 * @param int $blog_id The WPCOM blog ID.
	 *
	 * @return string The MC WCPay account URL.
	 */
	private static function get_mc_blog_rc_url( int $blog_id ): string {
		return add_query_arg( [ 'blog_id' => $blog_id ], 'https://mc.a8c.com/tools/reportcard/blog/' );
	}

	/**
	 * Gets the Stripe dashboard account page URL for the provided Stripe account ID.
	 *
	 * @param string $stripe_id The Stripe account ID.
	 * @param bool   $live      Whether this is a live account or a test one.
	 *
	 * @return string The Stripe dashboard account URL.
	 */
	private static function get_stripe_dashboard_url( string $stripe_id, bool $live = false ): string {
		$base_url = $live ? 'https://dashboard.stripe.com/connect/accounts/' : 'https://dashboard.stripe.com/test/connect/accounts/';

		return $base_url . $stripe_id;
	}

	/**
	 * Whether the site can communicate with the WCPay server (i.e. Jetpack connection has been established).
	 *
	 * @return bool
	 */
	private static function is_connected_to_server(): bool {
		$api_client = self::get_wcpay_api_client();
		if ( ! $api_client ) {
			return false;
		}

		return $api_client->is_server_connected();
	}

	/**
	 * Gets an instance of Database_Cache
	 *
	 * @return Database_Cache|null The instance, if the class is available
	 */
	public static function get_database_cache(): ?Database_Cache {
		if ( ! class_exists( Database_Cache::class ) ) {
			return null;
		}

		if ( null === self::$database_cache ) {
			self::$database_cache = new Database_Cache();
		}

		return self::$database_cache;
	}

	/**
	 * Retrieves list of releases from GitHub, stores it in a file cache, and returns a parsed version.
	 *
	 * @return false|string
	 */
	private static function get_github_releases_contents() {
		$cache_filename = get_option( self::WCPAY_RELEASE_LIST_FILENAME_OPTION );
		if ( ! $cache_filename ) {
			$cache_filename = wp_tempnam( 'wcpaydev-wcpay-releases-cache.json' );
			// If we have failed to get a writable file in the system tmp directory, try the WP uploads directory.
			if ( ! $cache_filename ) {
				$upload_dir = wp_get_upload_dir();
				if ( $upload_dir && false === $upload_dir['error'] ) {
					$cache_filename = wp_tempnam( 'wcpaydev-wcpay-releases-cache.json', trailingslashit( $upload_dir['basedir'] ) );
				}
			}
			// Store the filename, so we can unlink it when uninstalling the plugin.
			update_option( self::WCPAY_RELEASE_LIST_FILENAME_OPTION, $cache_filename );
		}

		$cache_modified = file_exists( $cache_filename ) ? filemtime( $cache_filename ) : 0;
		$cache_contents = '[]'; // empty JSON array.
		if ( time() - $cache_modified > self::WCPAY_RELEASE_CACHE_TTL_IN_SEC ) {
			$response = wp_safe_remote_get(
				sprintf(
					'https://api.github.com/repos/%s/releases?per_page=50',
					self::WCPAY_PLUGIN_REPOSITORY
				)
			);
			if ( ! is_wp_error( $response ) ) {
				$cache_contents = wp_remote_retrieve_body( $response );
				file_put_contents( $cache_filename, $cache_contents );
			}
		} else {
			$cache_contents = file_get_contents( $cache_filename );
		}

		return $cache_contents;
	}

	/**
	 * Returns a formatted list of GitHub releases where key is a release tag
	 * and value is a download filename
	 *
	 * @return array[]
	 */
	private static function get_github_releases(): array {
		$releases_cache = json_decode( self::get_github_releases_contents(), true );
		// Bail if error.
		if ( ! is_array( $releases_cache ) ) {
			return [];
		}

		$release_map_func = function ( $value ) {
			$assets_filter_func = function ( $value ) {
				return self::WCPAY_ASSET_FILENAME === $value['name'];
			};

			$assets = array_filter( $value['assets'], $assets_filter_func );

			if ( empty( $assets ) ) {
				return [];
			}

			return array(
				'tag_name'     => $value['tag_name'],
				'name'         => $value['name'],
				'created_at'   => $value['created_at'],
				'download_url' => $assets[0]['browser_download_url'],
			);
		};

		return array_filter( array_map( $release_map_func, $releases_cache ) );
	}

	/**
	 * Apply var_export to an array, but with niceties.
	 *
	 * We will use short array syntax, inline numerical keyed lists, and handle i18n calls and make them actual function calls.
	 *
	 * @param mixed $expression The expression to export.
	 * @param bool  $return     Whether to return or just echo.
	 *
	 * @return array|string|string[]|void|null
	 */
	private static function custom_var_export( $expression, bool $return = false ) {
		if ( ! is_array( $expression ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			return var_export( $expression, $return );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$export = var_export( $expression, true );
		$export = preg_replace( '/^([ ]*)(.*)/m', '$1$1$2', $export );
		$array  = preg_split( "/\r\n|\n|\r/", $export );
		$array  = preg_replace( [ '/\s*array\s\($/', '/\)(,)?$/', '/\s=>\s$/' ], [ null, ']$1', ' => [' ], $array );
		$export = join( PHP_EOL, array_filter( [ '[' ] + $array ) );
		// Empty arrays sit on the same line.
		$export = preg_replace( '/\[\s*\]/', '[]', $export );
		// Numerical keyed lists don't need to use keys.
		$export = preg_replace( "/\d+ => '/", '\'', $export );
		$export = preg_replace( '/\d+ => \[/', '[', $export );
		$export = preg_replace( '/\d+ => (\d)/', '$1', $export );
		// Put scalar lists on the same line.
		$export  = preg_replace( "/\[(\s*)('[\w.]+',)/", '[ $2', $export );
		$export  = preg_replace( "/('[\w.]+',)(\s*)(?='[\w.]+',)/", '$1 ', $export );
		$export  = preg_replace( "/(',)\s*('[\w.]+'),\s*]/", '$1 $2 ]', $export );
		$export  = preg_replace( "/\[\s*('[\w.]+'),\s*]/", '[ $1 ]', $export );

		if ( $return ) {
			return $export;
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $export;
		}
	}
}

/**
 * Load anything required and run the initialization logic for the plugin.
 *
 * @return void
 */
function wcpay_dev_tools_init() {
	// Do not initialize if the WooCommerce Payments plugin is not active.
	if ( ! defined( 'WCPAY_PLUGIN_FILE' ) ) {
		return;
	}

	WC_Payments_Dev_Tools::init();

	// load the CLI source if required
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		include_once dirname( __FILE__ ) . '/cli-commands.php';
	}
}

// Make sure we initialize after the init of the WooCommerce Payments plugin (currently at priority 11).
add_action( 'plugins_loaded', 'wcpay_dev_tools_init', 999 );
add_action( 'plugins_loaded', function() {
		WC_Payments_Dev_Tools::init_hooks();
	}
);

// Register these filters here since user authentication happens before our init function gets a chance to run.
add_filter( 'determine_current_user', [ WC_Payments_Dev_Tools::class, 'mock_rest_authenticate' ], 999 );
add_filter( 'rest_authentication_errors', [ WC_Payments_Dev_Tools::class, 'mock_rest_authentication_errors' ], 999 );
add_filter( 'wcpay_woopay_is_signed_with_blog_token', [ WC_Payments_Dev_Tools::class, 'mock_rest_authentication_is_signed_with_blog_token'], 10, 1 );
