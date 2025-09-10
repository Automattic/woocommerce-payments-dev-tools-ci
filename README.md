# woocommerce-payments-dev-tools

A suite of tools helpful when developing WooCommerce Payments.

⚠️ **Note:** This is a development tool and **should not** be used on production WooCommerce stores. ⚠️

## How to use

- From the [Code tab](https://github.com/Automattic/woocommerce-payments-dev-tools), click on the Code button to expose a dropdown. This will open a small drop down menu. Select `Download ZIP` from that menu and save it somewhere convenient.
- Navigate to wp-admin on your test site and then `Plugins` > `Add New`. Upload the ZIP you downloaded in the previous step. Activate the plugin after upload completes.
- After activating the plugin, you should have a new top level menu item `WCPay Dev` on your wp-admin sidebar (all the way at the bottom). Click on it.
- For general use (e.g. creating a test account), only the `Dev mode enabled` checkbox and `Display notice about dev settings` should be checked. Make sure everything else is unchecked. If you have to make changes, be sure to click `Submit` to apply them.

For additional information and instructions see PCYsg-DQ4-p2

## Public repo and Jurassic Ninja (JN)

This plugin has [another GitHub public repo](https://github.com/Automattic/woocommerce-payments-dev-tools-ci). Active development is still on this private repo while the public repo is only used for delivering this plugin to [Jurassic Ninja](https://jurassic.ninja/).

Since JN uses [the latest release version](https://github.com/Automattic/woocommerce-payments-dev-tools-ci/releases) of the public repo ([code reference](https://github.com/Automattic/jurassic.ninja/blob/be89bf1e4b4fccf2f3813bed0100d0f51277a0bc/features/woocommerce-payments.php#L97-L97) on JN repo), to deliver a new version of this plugin, these are the suggested steps: 

1. Download [the zip file](https://github.com/Automattic/woocommerce-payments-dev-tools/archive/refs/heads/trunk.zip) from the private repo.
2. Create a new release for the public repo https://github.com/Automattic/woocommerce-payments-dev-tools-ci/releases/new:
- See [this screenshot](https://github.com/Automattic/woocommerce-payments-dev-tools/assets/10045087/c139cdad-9a9c-4a3c-9905-a753ac15eb7e) and follow steps below.
- Tag version in format: `yyyy.mm.dd` such as `2023.10.25`. This can target `trunk`.
- Release title: `Ver yyyy.mm.dd` Recommended
- Describe the release: Add the latest commit hash from this private repo.
- Attach the zip file downloaded from step 1.
- Choose `Set as the latest release`.
- Publish release.
3. All done! Creating a new JN site with WooPayment Dev Tools will use the recent uploaded zip file. 

## Shortcuts

This plugin will add a small admin-bar section with shortcuts for various development-related functions.

Shortcuts are only available on the following domains:

- `localhost`
- `*.jurassic.tube`
- `*.atomicsites.blog`
- `*.ngrok.io`

To use on a different domain, either open a PR in this repository, or use the following constant in `wp-config.php`:

```php
define( 'WCPAY_DEV_TOOLS_ENABLE_SHORTCUTS', true );
```
