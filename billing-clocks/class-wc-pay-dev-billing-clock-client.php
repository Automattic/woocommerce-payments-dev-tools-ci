<?php
/**
 * Stripe client.
 *
 * NOTE: This functionality already exists in WCPay/Server.
 */
class WC_Pay_Dev_Billing_Clock_Client {
	const STRIPE_ENDPOINT = 'https://api.stripe.com/v1';
	const STRIPE_TIMEOUT  = 70;

	/**
	 * STRIPE_API_VERSION.
	 */
	const STRIPE_API_VERSION = '2019-08-14';

	public function get( $api_path, $args = array() ) {
		$method  = 'GET';
		$body    = array();
		$args    = wp_parse_args(
			$args,
			array(
				'headers' => array(),
				'account' => true,
			)
		);

		return $this->request( $method, $api_path, $body, $args );
	}

	public function post( $api_path, $body = array(), $args = array() ) {
		$method  = 'POST';
		$args    = wp_parse_args(
			$args,
			array(
				'headers' => array(),
				'account' => true,
			)
		);

		return $this->request( $method, $api_path, $body, $args );
	}

	public function delete( $api_path, $args = array() ) {
		$method  = 'DELETE';
		$body    = array();
		$args    = wp_parse_args(
			$args,
			array(
				'headers' => array(),
				'account' => true,
			)
		);

		return $this->request( $method, $api_path, $body, $args );
	}

	/**
	 * Send request to stripe
	 *
	 * @return WP_Error|array Response or error
	 */
	public function request( $method, $api_path, $body = array(), $args = array() ) {
		$headers = isset( $args['header'] ) ? $args['header'] : array();
		$account = isset( $args['account'] );
		$request = array(
			'method'  => $method,
			'body'    => $body,
			'headers' => $this->get_headers( $headers, $account ),
			'timeout' => self::STRIPE_TIMEOUT
		);

		$response = wp_safe_remote_request(
			self::STRIPE_ENDPOINT . $api_path,
			$request
		);

		return $this->get_response_body( $response );
	}

	/**
	 * Get the Stripe headers for performing API requests
	 */
	private function get_headers( $headers = array(), $account = false ) {
		$api_key                   = $this->get_api_key();
		$headers['Authorization']  = 'Basic ' . base64_encode( $api_key . ':' );
		$headers['Stripe-Version'] = self::STRIPE_API_VERSION;

		if( $account ) {
			$headers['Stripe-Account'] = self::get_account_id();
		}

		return $headers;
	}

	/**
	 * Get the body from Stripe response
	 */
	private function get_response_body( $response ) {
		if ( $this->has_errors( $response ) ) {
			return new WP_Error( 'stripe_client_unexpected', 'Unexpected Stripe response: ' . json_encode( $response ) );
		}

		$response_body = json_decode( $response['body'], true );

		if ( isset( $response_body['error'] ) ) {
			return new WP_Error( 'stripe_client_failed', 'Stripe request failed: ' . json_encode( $response_body ) );
		}

		return $response_body;
	}

	/**
	 * Check response for errors
	 */
	private function has_errors( $response ) {
		if (
			is_wp_error( $response )
			|| ! is_array( $response )
			|| empty ( $response['body'] )
			|| empty ( $response['response'] )
			|| ! isset( $response['response']['code'] )
			|| 401 === $response['response']['code']
			|| 403 === $response['response']['code']
		) {
			return true;
		}
		return false;
	}

	/**
	 * Get the API key.
	 */
	private function get_api_key() {
		return get_option( WC_Payments_Dev_Tools::BILLING_CLOCK_SECRET_KEY_OPTION, '' );
	}

	/**
	 * Get the WC Pay account id associated with the site
	 */
	public static function get_account_id() {
		$account_data = get_option( 'wcpay_account_data' );
		return isset( $account_data['data']['account_id'] ) ? $account_data['data']['account_id'] : '';
	}
}
