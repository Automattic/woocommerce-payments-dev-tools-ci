<?php
/**
 * A class for adding and handling subscription actions to interact with the test clock.
 *
 * @package WC_Pay_Dev_Billing_Clock_Admin_Actions
 */

defined( 'ABSPATH' ) || exit;

class WC_Pay_Dev_Billing_Clock_Admin_Actions {

	/**
	 * Init class.
	 */
	public static function init() {
		// Edit Subscription Schedule
		add_action( 'wcs_subscription_schedule_after_billing_schedule', [ __CLASS__, 'display_current_clock_time' ] );

		add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_subscription_actions' ], 15, 1 );
		add_action( 'woocommerce_order_action_wcpd_billing_clock_set_up', [ __CLASS__, 'setup_testing_clock' ], 10, 1 );

		add_action( 'woocommerce_order_action_wcpd_billing_clock_upcoming_invoice', [ __CLASS__, 'progress_clock_to_upcoming_invoice' ], 10, 1 );
		add_action( 'woocommerce_order_action_wcpd_billing_clock_invoice_created', [ __CLASS__, 'progress_clock_to_invoice_created' ], 10, 1 );
		add_action( 'woocommerce_order_action_wcpd_billing_clock_process_renewal', [ __CLASS__, 'progress_clock_to_process_renewal' ], 10, 1 );
		add_action( 'woocommerce_order_action_wcpd_billing_clock_process_fail_renewal', [ __CLASS__, 'progress_clock_to_process_renewal' ], 10, 1 );

		add_action( 'init', [ __CLASS__, 'handle_action_request' ] );
	}

	/**
	 * Displays the current time Stripe has on record for this subscription's clock.
	 *
	 * @param WC_Subscription $subscription
	 */
	public static function display_current_clock_time( $subscription ) {
		$stripe_subscription = WC_Pay_Dev_Billing_Renewal_Tester::get_wcpay_subscription( $subscription );

		if ( ! $stripe_subscription ) {
			return;
		}

		$subscription_clock = WC_Pay_Dev_Billing_Renewal_Tester::get_subscription_clock( $subscription );

		if ( ! $subscription_clock ) {
			// Display a button users can click to set up the test clock.
			$query_args = [
				'wcpd_billing_clock_action' => 'set_up',
				'subscription_id'           => $subscription->get_id(),
			];

			$setup_billing_clock_url = wp_nonce_url( add_query_arg( $query_args, admin_url() ), 'wcpd_billing_clock_action' );
			$styles                  = "width: 100%; color: #5b841b; border-color: #5b841b; background: #c6e1c6; text-align: center; vertical-align: inherit;";
			$enable_button           = "<a class='button' href='{$setup_billing_clock_url}' style='{$styles}'>Enable</a>";

			echo '<hr><h3 style="text-align: center;">🛠 Dev Testing Mode: <span style="color: #dc9e10;">Disabled</span></h3>';
			echo $enable_button;
			echo '<hr>';
			return;
		}

		echo '<hr><h3 style="text-align: center;">🛠 Dev Testing Mode: <span style="color: #5b841b">Enabled</span></h3>';

		$account_id = WC_Pay_Dev_Billing_Clock_Client::get_account_id();

		echo '<p style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">';
		echo '<strong>Subscription:</strong>';
		echo " <a style='font-family:monospace' href='https://dashboard.stripe.com/{$account_id}/test/subscriptions/{$stripe_subscription['id']}'>{$stripe_subscription['id']}</a>";
		echo "</br>";
		$format = 'M j, Y ' . wc_time_format();

		echo '<strong>Clock time (GMT):</strong> ' . gmdate( $format, $subscription_clock['frozen_time'] );
		echo '</p>';
		echo '<hr>';

		$latest_invoice = WC_Pay_Dev_Billing_Renewal_Tester::get_wcpay_invoice( $stripe_subscription['latest_invoice'] );
		$next_event     = WC_Pay_Dev_Billing_Renewal_Tester::get_next_event( $subscription );

		// Display invoice information.
		if ( 'past_due' === $stripe_subscription['status'] ) {
			// If the subscription is past due, it likely has a failed renewal order.
			$invoice_id = $latest_invoice['id'];

			echo '<strong><span style="color:#d63638" class="dashicons dashicons-warning"></span> Subscription is past due</strong>';
			echo "<p style='white-space: nowrap;'><strong>Invoice:</strong> <a style='font-family:monospace' href='https://dashboard.stripe.com/{$account_id}/test/invoices/{$invoice_id}'>{$invoice_id}</a></br>";
			echo '<hr>';

			return;
		} elseif ( $latest_invoice && isset( $latest_invoice['status'], $latest_invoice['id'] ) && 'draft' === $latest_invoice['status'] ) {
			$invoice_id = $latest_invoice['id'];
			echo "<p><strong>Next event:</strong> <a href='https://dashboard.stripe.com/{$account_id}/test/invoices/{$invoice_id}'>Invoice</a> payment</br>";
		} else {
			echo '<p><strong>Next event:</strong> <span style="font-family:monospace">' . $next_event . '</span></br>';
		}

		self::print_run_next_event_button( $subscription, $next_event );
		echo "<sub>This is a best guess based on what the last event that was triggered.</sub>";
		echo '<hr>';
	}

	/**
	 * Adds actions to setup, and process Stripe billing testing clocks.
	 *
	 * @param array $actions
	 * @return array Subscription admin actions dropdown.
	 */
	public static function add_subscription_actions( $actions ) {
		global $theorder;

		// Check that this is a subscription.
		if ( ! wcs_is_subscription( $theorder ) ) {
			return $actions;
		}

		$stripe_subscription = WC_Pay_Dev_Billing_Renewal_Tester::get_wcpay_subscription( $theorder );

		// Check that this is a WCPay subscription.
		if ( ! $stripe_subscription ) {
			return $actions;
		}

		$subscription_clock = WC_Pay_Dev_Billing_Renewal_Tester::get_subscription_clock( $theorder );

		// If there's no clock. Add an action to set up the test.
		if ( ! $subscription_clock ) {
			$actions['wcpd_billing_clock_set_up'] = '🛠 Set up custom test clock';
			return $actions;
		}

		// Remove the default renewal processing actions.
		unset( $actions['wcs_process_renewal'], $actions['wcs_create_pending_renewal'], $actions['wcs_create_pending_parent'] );

		// Subscriptions which are past due have a failed last invoice that will need to be handled first.
		if ( 'past_due' === $stripe_subscription['status'] ) {
			return $actions;
		}

		// Add an action depending on what the next event should be.
		switch ( WC_Pay_Dev_Billing_Renewal_Tester::get_next_event( $theorder ) ) {
			case 'invoice.paid':
				$actions['wcpd_billing_clock_process_renewal']      = '🛠 Process latest invoice';
				$actions['wcpd_billing_clock_process_fail_renewal'] = '🛠 Fail the next invoice';
				break;
			case 'invoice.created':
				$actions['wcpd_billing_clock_invoice_created'] = '🛠 Trigger invoice creation';
				break;
			case 'invoice.upcoming':
			default:
				$actions['wcpd_billing_clock_upcoming_invoice'] = '🛠 Trigger upcoming invoice';
				break;
		}

		return $actions;
	}

	/**
	 * Gets the next expected event from the WCPay/Stripe Subscription state.
	 *
	 * @param array $stripe_subscription The stripe subscription record.
	 * @param array $billing_clock       The stripe test clock record.
	 *
	 * @return string The next event. Can be: 'invoice.paid', 'invoice.upcoming', 'invoice.created' or an empty string.
	 */
	protected static function get_next_event_from_wc_pay_subscription( $stripe_subscription, $billing_clock ) {

		// If there's a draft invoice, we might need to add an action to process it.
		if ( isset( $stripe_subscription['latest_invoice'] ) ) {
			$latest_invoice = WC_Pay_Dev_Billing_Renewal_Tester::get_wcpay_invoice( $stripe_subscription['latest_invoice'] );

			if ( isset( $latest_invoice['status'] ) && 'draft' === $latest_invoice['status'] ) {
				return 'invoice.paid';
			}
		}

		$next_payment_within_two_days = ( $stripe_subscription['current_period_end'] ) <= $billing_clock['frozen_time'] + ( 2 * DAY_IN_SECONDS );

		// If all action criteria has failed, add an action to advance the clock to the next renewal time.
		if ( isset( $stripe_subscription['status'] ) && 'active' === $stripe_subscription['status'] && ! $next_payment_within_two_days ) {
			return 'invoice.upcoming';
		}

		$upcoming_invoice = WC_Pay_Dev_Billing_Renewal_Tester::get_upcoming_invoice( $stripe_subscription['id'] );

		// If there's an invoice, we might need to add an action to process it.
		if ( $upcoming_invoice ) {
			return 'invoice.created';
		}

		// There's no event(?)
		return '';
	}

	/**
	 * Sets up a subscription with a test customer and test clock.
	 *
	 * @param WC_Subscription $subscription
	 */
	public static function setup_testing_clock( $subscription ) {
		$subscription_clock = WC_Pay_Dev_Billing_Renewal_Tester::get_subscription_clock( $subscription );

		if ( $subscription_clock ) {
			$subscription->add_order_note( "Request to set up the clock failed. The subscription already has a clock assigned." );
			return;
		}

		// Cancel the current subscription if it exists.
		$wc_pay_subscription_id = WC_Payments_Subscription_Service::get_wcpay_subscription_id( $subscription );

		if ( $wc_pay_subscription_id ) {
			WC_Payments::get_payments_api_client()->cancel_subscription( $wc_pay_subscription_id );
		}

		// We need to create a test customer with test clock enabled.
		WC_Pay_Dev_Billing_Renewal_Tester::setup_test_customer( $subscription );

		// Now that we have a Stripe billing customer with a test clock assigned, we need to recreate the subscription in Stripe assigned to this customer.
		WC_Pay_Dev_Billing_Renewal_Tester::create_billing_clock_subscription( $subscription );

		// Record that the subscription was set up. Signalling that the next event should be invoice.upcoming.
		WC_Pay_Dev_Billing_Renewal_Tester::record_event_trigger( $subscription, 'setup' );
	}

	/**
	 * Progress the Stripe clock to the subscription's next payment method. This triggers the Stripe Upcoming Invoice webhook.
	 *
	 * @param WC_Subscription $subscription
	 */
	public static function progress_clock_to_upcoming_invoice( $subscription ) {

		if ( ! self::validate_triggering_event( $subscription, 'invoice.upcoming' ) ) {
			return;
		}

		$subscription_clock  = WC_Pay_Dev_Billing_Renewal_Tester::get_subscription_clock( $subscription );
		$stripe_subscription = WC_Pay_Dev_Billing_Renewal_Tester::get_wcpay_subscription( $subscription );

		if ( isset( $stripe_subscription['status'], $stripe_subscription['current_period_end'] ) ) {
			$new_clock_time = $stripe_subscription['current_period_end'] - ( 2 * DAY_IN_SECONDS );

			// Move the test clock to be half an hour before the next payment.
			$clock = WC_Pay_Dev_Billing_Renewal_Tester::advance_clock( $subscription_clock['id'], $new_clock_time );
			$date  = wcs_get_datetime_from( $new_clock_time )->date_i18n( wc_date_format() . ' ' . wc_time_format() );

			if ( ! $clock ) {
				$subscription->add_order_note( "Upcoming invoice triggered. Error occured trying to advanced the Stripe Test clock to 2 days prior to renewal - {$date}. {$clock->get_error_message()}." );
			} else {
				$subscription->add_order_note( "Upcoming invoice triggered. Advanced the Stripe Test clock to 2 days prior to renewal - {$date}." );
			}

			WC_Pay_Dev_Billing_Renewal_Tester::record_event_trigger( $subscription, 'invoice.upcoming' );
		}
	}

	/**
	 * Progress the test clock to trigger the invoice.created webhook from Stripe.
	 *
	 * @param WC_Subscription $subscription
	 */
	public static function progress_clock_to_invoice_created( $subscription ) {

		if ( ! self::validate_triggering_event( $subscription, 'invoice.created' ) ) {
			return;
		}

		$subscription_clock  = WC_Pay_Dev_Billing_Renewal_Tester::get_subscription_clock( $subscription );
		$stripe_subscription = WC_Pay_Dev_Billing_Renewal_Tester::get_wcpay_subscription( $subscription );

		if ( isset( $stripe_subscription['status'], $stripe_subscription['current_period_end'] ) ) {
			$next_payment_time = $stripe_subscription['current_period_end'];

			// Move the test clock to the next payment date.
			$clock = WC_Pay_Dev_Billing_Renewal_Tester::advance_clock( $subscription_clock['id'], $next_payment_time );
			$date  = wcs_get_datetime_from( $next_payment_time )->date_i18n( wc_date_format() . ' ' . wc_time_format() );

			if ( ! $clock ) {
				$subscription->add_order_note( "Invoice creation triggered. Error occured trying to advanced the Stripe Test clock to the next payment (current_period_end) {$date}. {$clock->get_error_message()}." );
			} else {
				$subscription->add_order_note( "Invoice creation triggered. Advanced the Stripe Test clock to the next payment (current_period_end) {$date}." );
			}

			WC_Pay_Dev_Billing_Renewal_Tester::record_event_trigger( $subscription, 'invoice.created' );
		}
	}

	/**
	 * Progress the Stripe clock to the subscription's next payment method. This triggers the Stripe Upcoming Invoice webhook.
	 *
	 * @param WC_Subscription $subscription
	 */
	public static function progress_clock_to_process_renewal( $subscription ) {

		if ( ! self::validate_triggering_event( $subscription, 'invoice.paid' ) ) {
			return;
		}

		$subscription_clock  = WC_Pay_Dev_Billing_Renewal_Tester::get_subscription_clock( $subscription );
		$stripe_subscription = WC_Pay_Dev_Billing_Renewal_Tester::get_wcpay_subscription( $subscription );
		$invoice             = WC_Pay_Dev_Billing_Renewal_Tester::get_wcpay_invoice( $stripe_subscription['latest_invoice'] );

		// Before advancing the clock, make sure we set the right payment method to trigger a success or failure.
		$payment_type = 'woocommerce_order_action_wcpd_billing_clock_process_fail_renewal' === current_filter() ? 'fail' : 'success';
		WC_Pay_Dev_Billing_Renewal_Tester::set_payment_method_type( $subscription, $payment_type );

		$clock = WC_Pay_Dev_Billing_Renewal_Tester::advance_clock( $subscription_clock['id'], $invoice['next_payment_attempt'] + MINUTE_IN_SECONDS );

		if ( is_wp_error( $clock ) ) {
			$date = wcs_get_datetime_from( $stripe_subscription['current_period_end'] )->date_i18n( wc_date_format() . ' ' . wc_time_format() );
			$subscription->add_order_note( ucfirst( $payment_type ) . " latest invoice triggered. Error occured trying to advanced the Stripe Test clock to invoice date: {$date}. {$clock->get_error_message()}." );
		} else {
			$date = wcs_get_datetime_from( $clock['frozen_time'] )->date_i18n( wc_date_format() . ' ' . wc_time_format() );
			$subscription->add_order_note( ucfirst( $payment_type ) . " latest invoice triggered. Advanced the Stripe Test clock to invoice date: {$date}." );
		}

		if ( 'success' === $payment_type ) {
			WC_Pay_Dev_Billing_Renewal_Tester::record_event_trigger( $subscription, 'invoice.paid' );
		} else {
			WC_Pay_Dev_Billing_Renewal_Tester::record_event_trigger( $subscription, 'invoice.failed' );
		}
	}

	/**
	 * Validate that the subscription is valid to trigger a given event.
	 *
	 * @param WC_Subscription $subscription The WC Subscription we're validating an event for.
	 * @param string          $event        The event being triggered.
	 *
	 * @return bool Whether the event is valid or not.
	 */
	private static function validate_triggering_event( $subscription, $event ) {
		$subscription_clock = WC_Pay_Dev_Billing_Renewal_Tester::get_subscription_clock( $subscription );

		if ( ! $subscription_clock ) {
			$subscription->add_order_note( "Request to advance the clock failed. The subscription doesn't have a clock object assigned." );
			return false;
		}

		$stripe_subscription = WC_Pay_Dev_Billing_Renewal_Tester::get_wcpay_subscription( $subscription );

		if ( ! $stripe_subscription ) {
			$subscription->add_order_note( "Request to advance the clock failed. Failed to load the corresponding Stripe Billing Subscription." );
			return false;
		}

		if ( 'past_due' === $stripe_subscription['status'] ) {
			return false;
		}

		$next_event = self::get_next_event_from_wc_pay_subscription( $stripe_subscription, $subscription_clock );

		if ( $event !== $next_event ) {
			$next_event_for_display = $next_event;

			// If for whatever reason we couldn't determine the next event from the Billing Subscription. Fallback to 'invoice.upcoming'.
			if ( '' === $next_event ) {
				$next_event_for_display = '{unknown}';
				$next_event             = 'invoice.upcoming';
			}

			$subscription->add_order_note( "Request to trigger the '{$event}' failed. The next expected event should be '{$next_event_for_display}'." );

			// Reset the next expected event.
			WC_Pay_Dev_Billing_Renewal_Tester::set_next_event( $subscription, $next_event );
			return false;
		}

		return true;
	}

	/**
	 * Prints a button which when pressed will trigger the next event.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 * @param string          $next_event The subscription's next event.
	 */
	private static function print_run_next_event_button( $subscription, $next_event ) {

		switch ( $next_event ) {
			case 'invoice.upcoming':
				$action = 'upcoming_invoice';
				break;
			case 'invoice.created':
				$action = 'invoice_created';
				break;
			case 'invoice.paid':
				// We need to print the failed renewal action too.
				$query_args = [
					'wcpd_billing_clock_action' => 'process_fail_renewal',
					'subscription_id'           => $subscription->get_id(),
				];

				$setup_billing_clock_url = wp_nonce_url( add_query_arg( $query_args, admin_url() ), 'wcpd_billing_clock_action' );
				$styles                  = "width: 100%; color: #761919; border-color: #761919; background: #eba3a3; text-align: center; vertical-align: inherit;";
				echo "<p><a class='button' href='{$setup_billing_clock_url}' style='{$styles}'>⇪ Trigger <span style='font-weight: bold; font-family:monospace'>invoice.failed</span></a></p>";

				$action = 'process_renewal';

				break;
			default:
				return;
		}

		$query_args = [
			'wcpd_billing_clock_action' => $action,
			'subscription_id'           => $subscription->get_id(),
		];

		$setup_billing_clock_url = wp_nonce_url( add_query_arg( $query_args, admin_url() ), 'wcpd_billing_clock_action' );
		$styles                  = "width: 100%; color: #5b841b; border-color: #5b841b; background: #c6e1c6; text-align: center; vertical-align: inherit;";
		echo "<p><a class='button' href='{$setup_billing_clock_url}' style='{$styles}'>⇪ Trigger <span style='font-weight: bold; font-family:monospace'>{$next_event}</span></a></p>";
	}

	/**
	 * Handle the request to trigger a test clock event.
	 */
	public static function handle_action_request() {
		if ( isset( $_GET['wcpd_billing_clock_action'], $_GET['subscription_id'], $_GET['_wpnonce'] ) && check_admin_referer( 'wcpd_billing_clock_action' ) ) {
			$subscription = wcs_get_subscription( absint( $_GET['subscription_id'] ) );

			if ( $subscription ) {
				// Trigger the relevent test clock action and then redirect back to the edit subscription screen.
				do_action( 'woocommerce_order_action_wcpd_billing_clock_' . wc_clean( $_GET['wcpd_billing_clock_action'] ), $subscription );
				wp_safe_redirect( wcs_get_edit_post_link( $subscription->get_id() ) );
				exit;
			}

		}
	}
}
