# Teya Payments for HikaShop

Accept card payments through Teya Hosted Checkout. Developed by Webensol; requires an eligible Teya merchant account. The installation ZIP bundles the payment gateway and its administration companion. Install both together using the package; merchants do not need to assemble separate plugins.

## Requirements and verified versions

- Joomla 5.4 or Joomla 6, PHP 8.2+ with OpenSSL and cURL, HikaShop 6.6.0+.
- A public HTTPS shop with reachable Teya webhook URLs and outbound HTTPS access to Teya.
- MySQL/MariaDB with transactional InnoDB HikaShop tables and advisory lock support.
- Native integration verified for the 1.0.1 review build: Joomla 6.1.0 / HikaShop Starter 6.6.0 / PHP 8.5.9, and Joomla 5.4.8 / HikaShop Starter 6.6.0 / PHP 8.3.35, with MariaDB 11.4.
- Merchant-reported Teya sandbox acceptance for 1.0.0 passed on 2026-10-29. Version 1.0.1 awaits the marketplace team's real sandbox retest. Automated tests use a simulated provider; no independent live-money validation is claimed.
- This source prepares version 1.0.1 for HikaShop Marketplace review. The current public stable release is [1.0.0](https://github.com/webensol/teya-payments-for-joomla-hikashop/releases/tag/v1.0.0). The review ZIP can be shared as an attachment; GitHub draft assets require repository access.

## Install or update

1. Back up the database and Joomla configuration, including its site secret.
2. In Joomla, open System → Install → Extensions and upload `pkg_teya_hikashop-1.0.1.zip`.
3. Keep both bundled Teya plugins enabled. Do not install their inner ZIPs separately.
4. In HikaShop payment methods, create a Teya method and save it before connecting.
5. Select Test mode and save. Click Connect to Teya, finish setup in the new window, and wait for the original settings page to confirm the connection.
6. Configure the method's availability, currencies, payment statuses and checkout restrictions; publish it only when appropriate for the shop.
7. Complete a test payment and verify its signed callback changes the order to the configured confirmed status. Test partial/full refunds and failed/cancelled checkout before switching to production.
8. For production, select Live, save, and connect the live merchant account separately. A test connection does not establish a live connection.

Update by uploading the full package over the installation. Do not uninstall/recreate methods to update: existing orders retain their original payment-method IDs. Onboarding windows opened before an update should be closed and a fresh connection started.

## Payment settings

The plugin supports redirect or Teya success-page behavior, detailed or aggregate line items, merchant success emails and test/live connections. `Send customer payment email` defaults to No. Existing methods explicitly saved as Yes keep their choice. This setting controls the Teya API email request; HikaShop's native order-status emails have their own configuration.

`Send HikaShop payment notification to merchant` is a separate, optional setting, disabled by default. When enabled, a verified successful payment calls HikaShop's native payment-notification email using its configured recipients and template. Duplicate callbacks do not send it again. This does not change Teya's customer/merchant email settings or HikaShop's customer order-status emails.

All payment settings, connection feedback, refund controls and safe API errors use Joomla language strings in English, Hungarian, Czech, Slovak and German.

Use the order currency. For HUF, configure whole-forint final order totals: the gateway rejects fractional HUF rather than silently charging a different total. Actual accepted currencies and card brands depend on the merchant's Teya account.

## Refund an order

Open the HikaShop administrator order and scroll to **Teya refunds**. A verified successful Teya payment is required.

- Partial refund: enter a positive amount smaller than the remaining refundable balance.
- Full refund: use the full remaining balance.
- Click Refund through Teya and confirm. This is a financial API operation.
- A pending/unknown result retains its original request reference. Retry existing refund uses that reference and amount. It is a replay, not a read-only lookup.
- A 401/403 refusal pauses automatic retries. Resolve access/gateway failures before manually retrying the saved request.
- Do not independently refund the same operation in the Teya dashboard or with curl without reconciling the local order ledger afterward.

Changing a HikaShop order status to refunded alone does not execute a Teya refund. There is no shopper-triggered money-return endpoint.

## Optional background recovery

From the installed Joomla root:

```sh
php plugins/hikashoppayment/teya/cli/refunds.php --check
php plugins/hikashoppayment/teya/cli/refunds.php --site-url=https://your-shop.example
```

`--check` bootstraps only. The second command may process pending refunds, so schedule it only after validating the shop's refund process. Blocked authorization failures are skipped. Recovery respects the saved request and retry delay.

## Disconnect and uninstall

Finish/reconcile pending refunds first. Disconnect each payment method in both test and live environments before uninstalling the package. Deleting a payment method unregisters its Teya connections; old orders still refer to that method ID and may lose their refund route. Preserve access to original paid transactions before removing methods.

## Support and distribution

Use this public repository's issue tracker. Report the plugin/Joomla/HikaShop/PHP versions, reproducible steps, HTTP status and safe response metadata. Never attach database exports, client secrets, access tokens, full order/session URLs or customer details. See `docs/TROUBLESHOOTING.md` and `docs/PRIVACY.md`.

License: GNU General Public License version 3; see LICENSE.txt. Teya and HikaShop names/logos identify their services. No certification or endorsement is claimed.
