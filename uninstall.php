<?php
/**
 * WCPay Dev Tools Uninstall
 *
 * Deletes all specific options and files. It does not touch WCPay feature flag options.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * We can't use any plugin classes or functions as the plugin is deactivated by this point.
 */

delete_option( 'wcpaydev_dev_mode' );
delete_option( 'wcpaydev_force_disconnected' );
delete_option( 'retry_server_wp_cron_redirects' );
delete_option( 'wcpaydev_display_notice' );
delete_option( 'wcpaydev_redirect' );
delete_option( 'wcpaydev_redirect_localhost' );
delete_option( 'wcpaydev_redirect_to' );
delete_option( 'wcpaydev_proxy' );
delete_option( 'wcpaydev_proxy_via' );
delete_option( 'wcpaydev_wcpay_release_tag' );
delete_option( 'wcpaydev_wcpay_billing_clock' );
delete_option( 'wcpay_billing_clock_secret' );
delete_option( 'override_woopay_eligible' );
delete_option( 'override_woopay_eligible_value' );

// Unlink our GitHub releases cache filename before deleting option.
$github_releases_cache_filename = get_option( 'wcpaydev_wcpay_releases_list_filename' );
if ( $github_releases_cache_filename ) {
	unlink( $github_releases_cache_filename );
}
delete_option( 'wcpaydev_wcpay_releases_list_filename' );
