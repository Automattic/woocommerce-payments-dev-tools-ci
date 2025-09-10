<?php

/**
 * Adds dev shortcuts to the WordPress admin bar.
 */
class WooCommerce_Payments_Dev_Shortcuts {
	const NONCE_ACTION = 'wcpay-dev-shortcut';

	/**
	 * Regular expressions for white-listed domains.
	 *
	 * @var string[]
	 */
	const WHITELISTED_DOMAINS = [
		'~^localhost$~',
		'~jurassic\.tube$~',
		'~atomicsites\.blog$~',
		'~ngrok.io$~',
	];

	/**
	 * Adds the necessary hooks for shortcuts to function.
	 */
	public function add_hooks() {
		add_action( 'admin_bar_menu', [ $this, 'add_menu' ], 300 );
		add_action( 'template_redirect', [ $this, 'act' ] );
	}

	/**
	 * Adds the necessary actions to the admin bar.
	 */
	public function add_menu( WP_Admin_Bar $admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; // Only available for admins in the front-end.
		}

		$root_id       = 'wcpay-root';
		$dev_tools_url = admin_url( 'admin.php?page=wcpaydev' );
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments' );
		$whitelisted   = $this->is_domain_whitelisted();

		$separator = '<span style="display:block; border-bottom: 1px solid grey; margin: 3px;"></span>';

		$root_meta = [];
		if ( $whitelisted ) {
			$root_meta['title'] = 'WCPay dev shortcuts. Do not use unless you know what you are doing.';
		}

		$admin_bar->add_menu(
			[
				'id'    => $root_id,
				'title' => $this->get_menu_icon() . 'WCPay',
				'meta'  => $root_meta,
			]
		);

		if ( ! $whitelisted ) {
			$admin_bar->add_menu(
				[
					'id'     => 'wcpay-placeholder',
					'parent' => $root_id,
					'title'  => 'Shortcuts are unavailable on this domain. Check the dev tools repo for details.',
				]
			);

			return;
		}

		$admin_bar->add_menu(
			[
				'id'     => 'wcpay-settings',
				'parent' => $root_id,
				'title'  => $this->emoji( '⚙️' ) . 'Settings',
				'href'   => $settings_url,
			]
		);

		$admin_bar->add_menu(
			[
				'id'     => 'wcpay-dev-settings',
				'parent' => $root_id,
				'title'  => $this->emoji( '🛠️' ) . 'Dev Tools',
				'href'   => $dev_tools_url,
			]
		);

		$admin_bar->add_menu(
			[
				'id'     => 'wcpay-dev-logs',
				'parent' => $root_id,
				'title'  => $this->emoji( '📜' ) . 'View the latest log',
				'href'   => $this->get_action_url( 'view_latest_log' ),
			]
		);

		$admin_bar->add_menu(
			[
				'id'     => 'wcpay-dev-order',
				'parent' => $root_id,
				'title'  => $this->emoji( '🛍️' ) . 'View the latest order',
				'href'   => $this->get_action_url( 'view_latest_order' ),
				'meta'   => [
					'html' => $separator,
				],
			]
		);

		$admin_bar->add_menu(
			[
				'id'     => 'wcpay-beanie',
				'parent' => $root_id,
				'title'  => $this->emoji( '🛒' ) . 'Add beanie and go to classic checkout',
				'href'   => $this->get_action_url( 'add_beanie_and_checkout' ),
			]
		);

		$admin_bar->add_menu(
			[
				'id'     => 'wcpay-beanie-block',
				'parent' => $root_id,
				'title'  => $this->emoji( '🧱' ) . 'Add beanie and go to the checkout block',
				'href'   => $this->get_action_url( 'add_beanie_and_checkout&block' ),
				'meta'   => [
					'html' => $separator,
				],
			]
		);

		if ( get_option( WC_Payments_Dev_Tools::REDIRECT_OPTION, false ) ) {
			$admin_bar->add_menu(
				[
					'id'     => 'wcpay-use-sandbox',
					'parent' => $root_id,
					'title'  => $this->emoji( '☁️' ) . 'Use sandbox/live server',
					'href'   => $this->get_action_url( 'use_sandbox', true ),
					'meta'   => [
						'html' => $separator,
					],
				]
			);
		} else {
			$admin_bar->add_menu(
				[
					'id'     => 'wcpay-use-local-server',
					'parent' => $root_id,
					'title'  => $this->emoji( '💻' ) . 'Use local server',
					'href'   => $this->get_action_url( 'use_local_server', true ),
					'meta'   => [
						'html' => $separator,
					],
				]
			);
		}

		$admin_bar->add_menu(
			[
				'id'     => 'wcpay-reonboard',
				'parent' => $root_id,
				'title'  => $this->emoji( '♻' ) . 'Re-onboard',
				'href'   => $this->get_action_url( 'reonboard' ),
				'meta'   => [
					'onclick' => 'return confirm("Are you sure?");'
				],
			]
		);
	}

	/**
	 * Checks if an action should be performed, and performs it.
	 *
	 * This is done on `template_redirect`, meaning that most changes
	 * will necessitate a refresh/redirect to the same page.
	 */
	public function act() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! isset( $_GET['wcpay_dev_action'] ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], self::NONCE_ACTION ) ) {
			echo 'Invalid nonce. Go back, refresh, and try again!';
			exit;
		}

		$return_url = remove_query_arg( 'wcpay_dev_action' );
		$return_url = remove_query_arg( '_wpnonce', $return_url );

		switch ( $_GET['wcpay_dev_action'] ) {
			case 'add_beanie_and_checkout':
				$this->add_beanie_and_checkout();
				break;

			case 'use_sandbox':
					$this->use_sandbox( $return_url );
					break;

			case 'use_local_server':
					$this->use_local_server( $return_url );
					break;

			case 'reonboard':
					$this->reonboard();
					break;

			case 'view_latest_log':
					$this->view_latest_log();
					break;

			case 'view_latest_order':
					$this->view_latest_order();
					break;

			// Do nothing by default.
		}
	}

	/**
	 * Adds a beanie and redirects to the checkout page (classic or block-based).
	 */
	public function add_beanie_and_checkout() {
		$beanie = null;
		$products = wc_get_products(
			[
				'sku' => 'woo-beanie'
			]
		);

		foreach ( $products as $product ) {
			if ( 'woo-beanie' === $product->get_sku() ) {
				$beanie = $product;
				break;
			}
		}

		if ( empty( $products ) ) {
			echo 'Could not find a product with SKU &quot;woo-beanie&quot;. Please add the demo products from the Storefront theme for create your own product with SKU &quot;woo-beanie&quot;.';
			exit;
		}

		// Add the beanie to the cart.
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $beanie->get_id(), 1 );

		// Redirect to checkout.
		if ( isset( $_GET['block'] ) ) {
			$pages = get_posts(
				[
					'post_type' => 'page',
					'post_name' => 'blocks-checkout',
				]
			);

			if ( empty( $pages ) ) {
				echo 'Please create a page with slug <code>blocks-checkout</code> to use this shortcut.';
				exit;
			}

			$checkout_url = get_permalink( array_shift( $pages )->ID );
		} else {
			$checkout_url = wc_get_checkout_url();
		}

		wp_safe_redirect( $checkout_url );
		exit;
	}

	/**
	 * Removes the request redirection.
	 *
	 * @param string $return_url The URL of the current page, to be redirected back to.
	 */
	public function use_sandbox( $return_url ) {
		update_option( WC_Payments_Dev_Tools::REDIRECT_OPTION, '0' );
		WC_Payments_Dev_Tools::clear_account_cache();
		wp_safe_redirect( $return_url );
		exit;
	}

	/**
	 * Enables request redirection.
	 *
	 * @param string $return_url The URL of the current page, to be redirected back to.
	 */
	public function use_local_server( $return_url ) {
		update_option( WC_Payments_Dev_Tools::REDIRECT_OPTION, '1' );
		WC_Payments_Dev_Tools::clear_account_cache();
		wp_safe_redirect( $return_url );
		exit;
	}

	/**
	 * Attempts re-onboarding.
	 */
	public function reonboard() {
		wp_safe_redirect( WC_Payments_Dev_Tools::get_onboarding_url( true ) );
		exit;
	}

	/**
	 * Redirects to the latest WCPay log.
	 */
	public function view_latest_log() {
		$log_files       = WC_Log_Handler_File::get_log_files();
		$prefix          = 'woopayments-';
		$regex           = '/^' . preg_quote( $prefix, '/' ) . '(\d+)-(\d+)-(\d+)-\w+-log$/';
		$latest_log_file = null;
		$latest_log_time = null;

		// The key is what's used in the URL, so the filename is not needed.
		foreach ( $log_files as $key => $filename ) {
			if ( 0 !== strpos( $key, $prefix ) ) {
				continue;
			}

			if ( ! preg_match( $regex, $key, $matches ) ) {
				continue;
			}

			// Get this to an int timestamp to compare.
			$timestamp = strtotime( $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' 00:00' );

			if ( is_null( $latest_log_time ) || $latest_log_time < $timestamp ) {
				$latest_log_file = preg_replace('/-\w+\.log$/', '', $filename);
				$latest_log_time = $timestamp;
			}
		}

		if ( is_null( $latest_log_file ) ) {
			printf(
				'Could not find a %s log file. Do any logs exist?',
				$prefix
			);
			exit;
		}

		$url = add_query_arg( 'file_id', $latest_log_file, admin_url( 'admin.php?page=wc-status&tab=logs&view=single_file' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Redirects to the latest order.
	 */
	public function view_latest_order() {
		$orders = wc_get_orders(
			[
				'limit' => 1,
			]
		);

		if ( empty( $orders ) ) {
			echo 'No orders found. Please create one first.';
			exit;
		}

		$order = array_shift( $orders );
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * A lot of hard-coded HTML, hence a separate method.
	 *
	 * @return string
	 */
	protected function get_menu_icon() {
		$icon_styles = 'display: inline-block;
			vertical-align: middle;
			width: 16px;
			height: 12px;
			margin: -2px 6px 0 0';

		// Same as `WC_Payments_Admin::add_payments_menu()`.
		$menu_image = 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiIHN0YW5kYWxvbmU9Im5vIj8+CjxzdmcKICAgdmVyc2lvbj0iMS4xIgogICBpZD0ic3ZnNjciCiAgIHNvZGlwb2RpOmRvY25hbWU9IndjcGF5X21lbnVfaWNvbi5zdmciCiAgIHdpZHRoPSI4NTIiCiAgIGhlaWdodD0iNjg0IgogICBpbmtzY2FwZTp2ZXJzaW9uPSIxLjEgKGM0ZThmOWUsIDIwMjEtMDUtMjQpIgogICB4bWxuczppbmtzY2FwZT0iaHR0cDovL3d3dy5pbmtzY2FwZS5vcmcvbmFtZXNwYWNlcy9pbmtzY2FwZSIKICAgeG1sbnM6c29kaXBvZGk9Imh0dHA6Ly9zb2RpcG9kaS5zb3VyY2Vmb3JnZS5uZXQvRFREL3NvZGlwb2RpLTAuZHRkIgogICB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciCiAgIHhtbG5zOnN2Zz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPgogIDxkZWZzCiAgICAgaWQ9ImRlZnM3MSIgLz4KICA8c29kaXBvZGk6bmFtZWR2aWV3CiAgICAgaWQ9Im5hbWVkdmlldzY5IgogICAgIHBhZ2Vjb2xvcj0iI2ZmZmZmZiIKICAgICBib3JkZXJjb2xvcj0iIzY2NjY2NiIKICAgICBib3JkZXJvcGFjaXR5PSIxLjAiCiAgICAgaW5rc2NhcGU6cGFnZXNoYWRvdz0iMiIKICAgICBpbmtzY2FwZTpwYWdlb3BhY2l0eT0iMC4wIgogICAgIGlua3NjYXBlOnBhZ2VjaGVja2VyYm9hcmQ9IjAiCiAgICAgc2hvd2dyaWQ9ImZhbHNlIgogICAgIGZpdC1tYXJnaW4tdG9wPSIwIgogICAgIGZpdC1tYXJnaW4tbGVmdD0iMCIKICAgICBmaXQtbWFyZ2luLXJpZ2h0PSIwIgogICAgIGZpdC1tYXJnaW4tYm90dG9tPSIwIgogICAgIGlua3NjYXBlOnpvb209IjI1NiIKICAgICBpbmtzY2FwZTpjeD0iLTg0Ljg1NzQyMiIKICAgICBpbmtzY2FwZTpjeT0iLTgzLjI5NDkyMiIKICAgICBpbmtzY2FwZTp3aW5kb3ctd2lkdGg9IjEzMTIiCiAgICAgaW5rc2NhcGU6d2luZG93LWhlaWdodD0iMTA4MSIKICAgICBpbmtzY2FwZTp3aW5kb3cteD0iMTE2IgogICAgIGlua3NjYXBlOndpbmRvdy15PSIyMDIiCiAgICAgaW5rc2NhcGU6d2luZG93LW1heGltaXplZD0iMCIKICAgICBpbmtzY2FwZTpjdXJyZW50LWxheWVyPSJzdmc2NyIgLz4KICA8cGF0aAogICAgIHRyYW5zZm9ybT0ic2NhbGUoLTEsIDEpIHRyYW5zbGF0ZSgtODUwLCAwKSIKICAgICBkPSJNIDc2OCw4NiBWIDU5OCBIIDg0IFYgODYgWiBtIDAsNTk4IGMgNDgsMCA4NCwtMzggODQsLTg2IFYgODYgQyA4NTIsMzggODE2LDAgNzY4LDAgSCA4NCBDIDM2LDAgMCwzOCAwLDg2IHYgNTEyIGMgMCw0OCAzNiw4NiA4NCw4NiB6IE0gMzg0LDEyOCB2IDQ0IGggLTg2IHYgODQgaCAxNzAgdiA0NCBIIDM0MCBjIC0yNCwwIC00MiwxOCAtNDIsNDIgdiAxMjggYyAwLDI0IDE4LDQyIDQyLDQyIGggNDQgdiA0NCBoIDg0IHYgLTQ0IGggODYgViA0MjggSCAzODQgdiAtNDQgaCAxMjggYyAyNCwwIDQyLC0xOCA0MiwtNDIgViAyMTQgYyAwLC0yNCAtMTgsLTQyIC00MiwtNDIgaCAtNDQgdiAtNDQgeiIKICAgICBmaWxsPSIjYTJhYWIyIgogICAgIGlkPSJwYXRoNjUiIC8+Cjwvc3ZnPgo=';

		return '<img src="' . $menu_image . '" alt="WCPay" style="' . $icon_styles . '" />';
	}

	/**
	 * Adds the necessary styles to emojis to fix rendering.
	 *
	 * @param string $emoji The emoji that requires styles.
	 * @return string       HTML for the icon.
	 */
	protected function emoji( $emoji ) {
		return '<span style="display: inline-block; width: 14px; text-align: center; margin-right: 5px">' . $emoji . '</span>';
	}

	/**
	 * Generates an action URL with a nonce.
	 *
	 * @param string $action       The action, which should be performed, see `act()`.
	 * @param bool   $preserve_url Whether the action should land you on the same page.
	 * @return string
	 */
	protected function get_action_url( $action, $preserve_url = false ) {
		if ( $preserve_url && ! is_admin() ) {
			// Use the default current query for `add_query_arg` in the front-end.
			$url = add_query_arg( 'wcpay_dev_action', $action );
		} else {
			$url = add_query_arg( 'wcpay_dev_action', $action, home_url( '/' ) );
		}

		return wp_nonce_url(
			$url,
			self::NONCE_ACTION
		);
	}

	/**
	 * Checks if the current domain is white-listed.
	 *
	 * @return bool
	 */
	protected function is_domain_whitelisted() : bool {
		$parts       = parse_url( home_url() );
		$domain_host = $parts['host'];

		if ( defined( 'WCPAY_DEV_TOOLS_ENABLE_SHORTCUTS' ) && WCPAY_DEV_TOOLS_ENABLE_SHORTCUTS ) {
			return true;
		}

		foreach ( self::WHITELISTED_DOMAINS as $whitelisted_host ) {
			if ( preg_match( $whitelisted_host, $domain_host ) ) {
				return true;
			}
		}

		return false;
	}
}
