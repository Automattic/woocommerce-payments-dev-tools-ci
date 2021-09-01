# woocommerce-payments-dev-tools

A suite of tools helpful when developing WooCommerce Payments.

## How to use

- From the [Code tab](https://github.com/Automattic/woocommerce-payments-dev-tools), click on the Code button to expose a dropdown. This will open a small drop down menu. Select `Download ZIP` from that menu and save it somewhere convenient.
- Navigate to wp-admin on your test site and then `Plugins` > `Add New`. Upload the ZIP you downloaded in the previous step. Activate the plugin after upload completes.
- After activating the plugin, you should have a new top level menu item `WCPay Dev` on your wp-admin sidebar (all the way at the bottom). Click on it.
- For general use (e.g. creating a test account), only the `Dev mode enabled` checkbox and `Display notice about dev settings` should be checked. Make sure everything else is unchecked. If you have to make changes, be sure to click `Submit` to apply them.

### Billing Clocks

In order to test WCPay Billing Subscriptions without needing to wait for each renewal, you'll need to use Stripe's Billing Clock feature. A billing clock is essentially a "frozen clock" assigned to a WCPay subscription that can be advanced manually via API requests. This enables us to move a subscription's internal clock forward to a time in the future when an event is due, Stripe will then trigger the events sending the related webhooks - enabling us to observe them. This is particularly helpful in testing successful and unsuccessful renewal orders without needing to wait days.

It's important to remember that when a subscription is set up with a billing clock, **the subscription is frozen and will not advance by itself. You can no longer wait for a renewal - you can only advance the subscription's clock manually**. Because of this, it's possible that if a clock falls behind world time, things may go awry on WC's end. To prevent issues, its is not adivised to advance a billing clock that has fallen behind.
#### To set up a Billing Clock test subscription:

**Initial setup:**
1. Go to `WCPay Dev` on your wp-admin sidebar
2. Check the `Billing clocks (WCPay Subscriptions renewal testing)` option.
3. Enter the WC Pay Stripe account's secret key in the `WC Pay Secret Test Key` text box. The secret test key can be found here: https://dashboard.stripe.com/test/apikeys

**Create a billing-clock-enabled subscription:**
1. Purchase a subscription via the checkout.
2. Go to the edit subscription screen and click on the actions dropdown:
   1. If the account has billing clocks enabled, there should be a **Set up custom billing clock** option.
   2. Select it
   3. Save
3. Once the subscription is saved you'll notice a couple of things.
     - The subscription will have a new Stripe Subscription ID and the old one will have been cancelled. A new subscription is required because billing clocks are assigned to Stripe customer objects and so a new stripe customer and by extension, a new stripe subscription is needed.
     - The subscription will have a new customer. This WP user has a username that follows the format `test-subscription_{subscription_id}`. This new user is necessary because we need to store the Stripe Customer's payment methods as tokens against the user ID and we don't want to get them tangled with your admin user.
     - There's a new section in the subscription's schedule metabox that includes some information about the subscription's billing clock like the current clock's time, and the next event we expect based on the state of the subscription in Stripe.

<img width="292" alt="" src="https://user-images.githubusercontent.com/8490476/130905590-ea741b3d-ff26-4462-bec9-b68564d1d164.png">

<sub>\* Stripe Billing clocks are assigned to a Stripe Customer and each customer with a clock can only have 3 subscriptions maximum. So, in order to test renewals independently, we need a 1:1 relationship between Stripe customers and WC subscriptions. Because of this 1:1 relationship you'll notice when you setup a Billing clock subscription, in your Stripe account's dashboard there will be a new customer for each Billing clock corresponding to the WC subscription. See screenshot below.</sub>

<img width="743" alt="" src="https://user-images.githubusercontent.com/8490476/130904850-db997e23-6503-4edf-93c4-6fcb7ce369af.png">

### To test renewals:

To process a renewal, there is a 3 multi-step process involving upcoming invoice, invoice created and paid/failed invoice. That whole process is described below.

1. On the edit subscription screen for a subscription with a Billing Clock go to the actions dropdown and select **Trigger upcoming invoice** and **Save**.
2. This will move the billing clock to be half an hour before the next renewal, and cause Stripe to trigger the `invoice.upcoming` webhook. <sub>Stripe sends this webhook depending on the platform's setting. 3 days is the minimum time window option, however we move the clock to be half an hour just for daily subscriptions.</sub>
3. The next milestone in Stripe's multi-step renewal process is to create the invoice. From the Actions dropdown, select **Trigger invoice created** and save. If this option hasn't appeared, try refreshing the page.
3. At this stage you have 2 options. From the Actions dropdown you can:
     - process the payment successfully with **Process latest invoice**.
     - fail the renewal payment with **Fail the next invoice**. _Note: to fail the renewal order we set the subscription's default payment method to a card with a failing card number (`4000000000000341`)_.
4. Select one of the options and click **Save**.
5. Wait and possibly reload the page depending on how long the requests take to process on either end.
6. The latest renewal invoice should then process as expected depending on which option you chose.

<img width="309" alt="" src="https://user-images.githubusercontent.com/8490476/130906781-1821a926-32aa-4944-89fc-671fefbfe3c2.png">

### To pay for failed renewals:

1. Follow the steps above to create a failed renewal order/invoice.
2. In an incognito browser window or a different browser log in as the billing clock customer.
    - Username: `test-subscription_{subscription_id}` eg test-subscription_123.
    - Password: `password`
3. Go to My Account > My Subscription.
4. Click the **Update payment** or scroll down to the renewal order and click **Pay**
5. Enter a new payment method or choose a saved method.
6. Submit the form.
7. If the new payment method you selected is successful, the subscription should activate.