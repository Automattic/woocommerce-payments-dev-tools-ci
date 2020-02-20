<?php
/*
Plugin Name: WooCommerce Payments Dev Mode
Plugin URI: https://woocommerce.com
Description: Sets WCPAY_DEV_MODE to true
Author: allendav
Version: 0.1
Author URI: https://allendav.com
*/

define( 'WCPAY_DEV_MODE', true );

function wcpay_dev_mode_notice() {
    $class = 'notice notice-error';
    $message = __( 'WARNING: WCPAY_DEV_MODE enabled' );
    echo '<div class="notice notice-error"><p>WARNING: WCPAY_DEV_MODE enabled</p></div>';
}
add_action( 'admin_notices', 'wcpay_dev_mode_notice' );
