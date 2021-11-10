<?php
/**
 * WCPay dev tools CLI commands
 */
class WC_Payments_Dev_Tools_CLI extends WP_CLI_Command {
	/**
	 * Sets fake Jetpack options required to send requests to the server on behalf of the blog id.
	 *
	 * It sets fake Jetpack tokens so it can be used only without request validation on the server, i.e. with local server only.
	 *
	 * ## OPTIONS
	 *
	 * <blog_id>
	 * : The blog ID.
	 *
	 * [--blog_token=<value>]
     * : Jetpack blog token. Values should be wrapped in quotes.
	 *
	 * [--user_token=<value>]
     * : Jetpack user token. Values should be wrapped in quotes.
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcpay_dev set_blog_id <blog_id>
	 */
	public function set_blog_id( $args, $assoc_args ) {
		$blog_id = $args[0];
		if ( ! is_numeric( $blog_id ) ) {
			WP_CLI::error( 'Please provide a numeric blog ID.' );
			return 1;
		}

		if ( ! class_exists( 'Jetpack_Options' ) ) {
			WP_CLI::error( 'Jetpack_Options class does not exist. Please check your Jetpack installation.' );
			return 1;
		}

		$blog_token = ! empty( $assoc_args['blog_token'] ) ? $assoc_args['blog_token'] : '123.ABC';
		$user_token = array(
			1 => ! empty( $assoc_args['user_token'] ) ? $assoc_args['user_token'] : '123.ABC.1',
		);

		Jetpack_Options::update_option( 'id', intval( $blog_id ) );
		Jetpack_Options::update_option( 'master_user', 1 );
		Jetpack_Options::update_option( 'blog_token', $blog_token );
		Jetpack_Options::update_option( 'user_tokens', $user_token );

		WP_CLI::success( "Set Jetpack blog id to $blog_id" );
	}

	/**
	 * Redirects WCPay server requests to specified URL.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Server URL. E.g. http://host.docker.internal:8086/wp-json/
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcpay_dev redirect_to <url>
	 */
	public function redirect_to( $args, $assoc_args ) {
		$url = $args[0];
		if ( ! is_string( $url ) || empty( $url ) ) {
			WP_CLI::error( 'Please provide a URL.' );
			return 1;
		}

		update_option( WC_Payments_Dev_Tools::DEV_MODE_OPTION, '1' );
		update_option( WC_Payments_Dev_Tools::REDIRECT_OPTION, '1' );
		update_option( WC_Payments_Dev_Tools::REDIRECT_TO_OPTION, $url );
		WP_CLI::success( "Enabled WCPay redirect to $url" );
	}
}

WP_CLI::add_command( 'wcpay_dev', WC_Payments_Dev_Tools_CLI::class );
