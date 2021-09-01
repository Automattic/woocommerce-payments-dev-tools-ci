<?php
/**
 * A class for help interact with the Stripe billing clock API.
 *
 * @package WC_Pay_Dev_Billing_Renewal_Tester
 */

defined( 'ABSPATH' ) || exit;

class WC_Pay_Dev_Billing_Renewal_Tester {

	/**
	 * The billing clock client.
	 *
	 * @var WC_Pay_Dev_Billing_Clock_Client
	 */
	private static $client;

	/**
	 * The Stripe Billing client
	 *
	 * @var WC_Payments_Subscription_Service
	 */
	private static $subscription_service;

	/**
	 * A memory cache of WCPay Subscriptions.
	 *
	 * @var array
	 */
	private static $wcpay_subscription_cache = [];

	/**
	 * A memory cache of WCPay Billing Clocks.
	 *
	 * @var array
	 */
	private static $wcpay_clock_cache = [];

	/**
	 * Init the class.
	 */
	public static function init() {
		require_once __DIR__ . '/class-wc-pay-dev-billing-clock-client.php';

		self::$client               = new WC_Pay_Dev_Billing_Clock_Client();
		self::$subscription_service = WC_Payments_Subscriptions::get_subscription_service();

		if ( ! self::is_feature_enabled() ) {
			return;
		}

		// Edit Subscription Actions
		require_once __DIR__ . '/class-wc-pay-dev-billing-clock-admin-actions.php';
		WC_Pay_Dev_Billing_Clock_Admin_Actions::init();
	}

	/**
	 * Checks if the Billing Clocks feature is enabled on the Account.
	 *
	 * @return bool
	 */
	public static function is_feature_enabled() {
		if ( 'yes' === get_option( 'wcs-billing-clocks-enabled', null ) ) {
			return true;
		}

		$billing_clock = self::$client->post(
			'/test/billing_clocks',
			array(
				'frozen_time' => gmdate( 'U' ),
			)
		);

		$is_enabled = ! is_wp_error( $billing_clock );

		update_option( 'wcs-billing-clocks-enabled', $is_enabled ? 'yes' : 'no' );
		return $is_enabled;
	}

	/**
	 * Gets a Billing clock from a subscription.
	 *
	 * @param WC_Subscription $subscription
	 * @return array|bool The billing clock object data, otherwise false.
	 */
	public static function get_subscription_clock( $subscription ) {
		$subscription = self::get_wcpay_subscription( $subscription );

		if ( ! $subscription || empty( $subscription['billing_clock'] ) ) {
			return false;
		}

		if ( ! isset( self::$wcpay_clock_cache[ $subscription['billing_clock'] ] ) ) {
			$clock = self::$client->get( "/test/billing_clocks/{$subscription['billing_clock']}" );
			self::$wcpay_clock_cache[ $subscription['billing_clock'] ] = is_wp_error( $clock ) ? false : $clock;
		}

		return self::$wcpay_clock_cache[ $subscription['billing_clock'] ];
	}

	/**
	 * Gets an Invoice object.
	 *
	 * @param string $invoice_id The invoice ID.
	 * @return array|bool The invoice object data, otherwise false.
	 */
	public static function get_wcpay_invoice( $invoice_id ) {
		$invoice = self::$client->get( "/invoices/{$invoice_id}" );
		return is_wp_error( $invoice ) ? false : $invoice;
	}

	/**
	 * Gets a subscription's Upcoming invoice.
	 *
	 * @param string $subscription_id The WC Pay Subscription ID
	 * @return array|bool The WC Pay invoice object data, otherwise false
	 */
	public static function get_upcoming_invoice( $subscription_id ) {
		$invoice = self::$client->get( "/invoices/upcoming?subscription={$subscription_id}" );
		return is_wp_error( $invoice ) ? false : $invoice;
	}

	/**
	 * Advances a testing clock to a given timestamp.
	 *
	 * @param string $clock_id
	 * @return array|bool The resulting clock response, otherwise false on failure.
	 */
	public static function advance_clock( $clock_id, $timestamp ) {
		$clock = self::$client->post(
			"/test/billing_clocks/$clock_id/advance",
			array(
				'frozen_time' => $timestamp
			)
		);

		return is_wp_error( $clock ) ? false : $clock;
	}

	/**
	 * Creates a new WP user and Stripe Customer will a Stripe billing clock and assigns them to a subscription.
	 *
	 * @param WC_Subscription The subscription to set up a new test customer for.
	 */
	public static function setup_test_customer( $subscription ) {
		$name  = array( 'Test-Subscription', $subscription->get_id());
		$email = strtolower( implode( '_', $name ) . '@example.com' );

		$user_id = wc_create_new_customer(
			$email,
			wc_create_new_customer_username( $email, $name ),
			'password',
			$name
		);

		$payment_method = self::$client->post(
			'/payment_methods',
			array(
				'type'             => 'card',
				'card' => array(
					'number'    => 4242424242424242, // Successful card
					'exp_month' => 1,
					'exp_year'  => 2030,
					'cvc'       => 123
				),
			)
		);

		if ( is_wp_error( $payment_method ) ) {
			$subscription->add_order_note( "Failed to create payment method for the new customer. {$payment_method->get_error_message()}." );
			return;
		}

		$billing_clock = self::$client->post(
			'/test/billing_clocks',
			array(
				'frozen_time' => gmdate( 'U' ),
			)
		);

		if ( is_wp_error( $billing_clock ) ) {
			$subscription->add_order_note( "Failed to create the billing clock for the new customer. {$billing_clock->get_error_message()}." );
			return;
		}

		$customer = self::$client->post(
			'/customers',
			array(
				'name'             => implode( ' ', $name ),
				'email'            => $email,
				'payment_method'   => $payment_method['id'],
				'billing_clock'    => $billing_clock['id'],
				'invoice_settings' => array(
					'default_payment_method' => $payment_method['id'],
				),
			)
		);

		if ( is_wp_error( $customer ) ) {
			$subscription->add_order_note( "Failed to create the Stripe Billing customer for the new customer. {$customer->get_error_message()}." );
			return;
		}

		$failure_payment_method = self::$client->post(
			'/payment_methods',
			array(
				'type' => 'card',
				'card' => array(
					'number'    => 4000000000000341, // Successful card
					'exp_month' => 1,
					'exp_year'  => 2030,
					'cvc'       => 123
				),
			)
		);

		if ( is_wp_error( $failure_payment_method ) ) {
			$subscription->add_order_note( "Failed to create the failing payment method for the new customer. {$failure_payment_method->get_error_message()}." );
			return;
		}

		$payment_method_attach = self::$client->post(
			"/payment_methods/{$failure_payment_method['id']}/attach",
			array(
				'customer' => $customer['id'],
			)
		);

		if ( is_wp_error( $payment_method_attach ) ) {
			$subscription->add_order_note( "Failed to assign the failing payment method to the new customer. {$payment_method_attach->get_error_message()}." );
			return;
		}

		update_user_meta( $user_id, WC_Payments_Customer_Service::WCPAY_TEST_CUSTOMER_ID_OPTION, $customer['id'] );

		// Set the subscription's customer to the Billing Clock enabled customer.
		$subscription->set_customer_id( $user_id );

		// Set the Stripe Customer ID in subscription meta.
		$subscription->update_meta_data( '_wcsbrt_billing_clock_customer_id', $customer['id'] );

		// Create the WC Tokens.
		// Failing payment token.
		$fail_token = new WC_Payment_Token_CC();
		$fail_token->set_gateway_id( 'woocommerce_payments' );
		$fail_token->set_expiry_month( $failure_payment_method['card']['exp_month'] );
		$fail_token->set_expiry_year( $failure_payment_method['card']['exp_year'] );
		$fail_token->set_card_type( strtolower( $failure_payment_method['card']['brand'] ) );
		$fail_token->set_last4( $failure_payment_method['card']['last4'] );

		$fail_token->set_token( $failure_payment_method['id'] );
		$fail_token->set_user_id( $user_id );
		$fail_token->save();

		// Successful payment token.
		$success_token = new WC_Payment_Token_CC();
		$success_token->set_gateway_id( 'woocommerce_payments' );
		$success_token->set_expiry_month( $payment_method['card']['exp_month'] );
		$success_token->set_expiry_year( $payment_method['card']['exp_year'] );
		$success_token->set_card_type( strtolower( $payment_method['card']['brand'] ) );
		$success_token->set_last4( $payment_method['card']['last4'] );

		$success_token->set_token( $payment_method['id'] );
		$success_token->set_user_id( $user_id );
		$success_token->save();

		$subscription->update_meta_data( '_wcpd_billing_clock_failure_token_id', $fail_token->get_id() );
		$subscription->update_meta_data( '_wcpd_billing_clock_successful_token_id', $success_token->get_id() );

		// Set the subscription token to be the successful payment method by default.
		$subscription->add_payment_token( $fail_token );
		$subscription->add_payment_token( $success_token );
		$subscription->update_meta_data( '_payment_method_id', $payment_method['id'] );

		$subscription->save();
	}

	/**
	 * Creates a subscription in Stripe.
	 *
	 * @param WC_Subscription $subscription
	 */
	public static function create_billing_clock_subscription( $subscription ) {
		self::$subscription_service->create_subscription( $subscription );

		$invoice_id = WC_Payments_Subscriptions::get_invoice_service()->get_subscription_invoice_id( $subscription );

		if ( ! $invoice_id ) {
			return;
		}

		// Update the status of the invoice but don't charge the customer by using paid_out_of_band parameter.
		WC_Payments::get_payments_api_client()->charge_invoice( $invoice_id, [ 'paid_out_of_band' => 'true' ] );
	}

	/**
	 * Sets the customer's default payment method to a successful or failed card.
	 *
	 * @param WC_Subscription The subscription to set the payment method for.
	 * @param string          The type of payment. Can be 'fail' or 'successful'.
	 */
	public static function set_payment_method_type( $subscription, $payment_type ) {
		$wc_pay_subscription = self::get_wcpay_subscription( $subscription );

		if ( ! $wc_pay_subscription ) {
			$subscription->add_order_note( "Unable to locate the subscription in Stripe billing." );
			return;
		}

		$token_meta_key = 'fail' === $payment_type ? '_wcpd_billing_clock_failure_token_id' : '_wcpd_billing_clock_successful_token_id';
		$token_id       = $subscription->get_meta( $token_meta_key, true );

		if ( ! $token_id ) {
			$subscription->add_order_note( "Unable to locate the customer's '{$payment_type}' token ID in user meta." );
			return;
		}

		$token = WC_Payment_Tokens::get( $token_id );

		if ( ! $token ) {
			$subscription->add_order_note( "Unable to load the customer's '{$payment_type}' token." );
			return;
		}

		$payment_id = $token->get_token();
		$result     = self::$client->post( "/subscriptions/{$wc_pay_subscription['id']}", array( 'default_payment_method' => $payment_id ) );

		// Also change the payment method stored on the subscription so future changes trigger the token to update for retries.
		$subscription->add_payment_token( $token );
		$subscription->update_meta_data( '_payment_method_id', $payment_id );
		$subscription->save();

		if ( is_wp_error( $result ) ) {
			$subscription->add_order_note( "Unable to set subscription's default payment method: " . $result->get_error_message() );
		}
	}

	/**
	 * Attempts to get the billing clock customer from a subscription if there's a specific event.
	 * eg Changing a payment method.
	 *
	 * @return void
	 */
	public static function maybe_get_billing_clock_wcpay_customer_from_event( $value ) {
		global $wp;
		$subscription = null;

		if ( isset( $wp->query_vars['order-pay'] ) && class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) {
			$subscription = wcs_get_subscription( absint( $wp->query_vars['order-pay'] ) );
		}

		if ( $subscription && $subscription->meta_exists( '_wcsbrt_billing_clock_customer_id' ) ) {
			return $subscription->get_meta( '_wcsbrt_billing_clock_customer_id', true );
		} else {
			return $value;
		}
	}

	/**
	 * Gets the WCPay Subscription from a WC Subscription.
	 *
	 * @param WC_Subscription $subscription
	 * @return array
	 */
	public static function get_wcpay_subscription( $subscription ) {

		if ( ! isset( self::$wcpay_subscription_cache[ $subscription->get_id() ] ) ) {
			self::$wcpay_subscription_cache[ $subscription->get_id() ] = self::$subscription_service->get_wcpay_subscription( $subscription );
		}

		return self::$wcpay_subscription_cache[ $subscription->get_id() ];
	}
}
