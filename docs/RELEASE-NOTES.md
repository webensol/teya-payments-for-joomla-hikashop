# Teya Payments for HikaShop 1.0.1

Release date: 2026-10-30. Stable release for HikaShop on Joomla.

## Changes

- **PHP 8.5 compatibility:** removed deprecated `curl_close()` calls, made nullable callbacks explicit and updated stream response-header access. These plugin deprecations no longer corrupt administrator JSON responses.
- **Itemised checkout:** use native `$order->cart->products`, retaining support for `$order->products`. Product quantities and tax-inclusive prices are itemised, with shipping, fees, discounts and rounding reconciled to the order total. The single-line option remains available.
- **Translations:** added language keys for the reported settings, connection feedback, customer checkout errors, safe API errors and the administrator refund panel, including browser controls. English, Hungarian, Czech, Slovak and German are covered. Parser failures do not display raw HTML responses.
- **Code cleanup:** removed obsolete private implementations from both plugin entry points. Payment, connection and refund operations continue through the gateway/storage services.
- **Optional shop-owner email:** added native HikaShop payment-notification emails, disabled by default and separate from Teya's merchant email setting. They use HikaShop's recipients/template after successful payment confirmation; duplicate callbacks do not send another notification.

## Validation and compatibility

The regression suite passes 105 gateway flow checks plus encryption/environment separation, itemisation, signature and amount validation, administrator ACL/CSRF, refund retry, safe diagnostics and duplicate-notification checks. Local HTTP transport tests cover cURL and PHP streams, including strict PHP 8.5 error handling. Browser-script and language checks cover all five languages.

The actual release ZIP passes native installation, two repeated upgrades, checkout persistence, signed callbacks, partial/full refund state transitions, settings preservation and translated refund-panel checks on:

- Joomla 6.1.0 / HikaShop Starter 6.6.0 / PHP 8.5.9 / MariaDB 11.4.
- Joomla 5.4.8 / HikaShop Starter 6.6.0 / PHP 8.3.35 / MariaDB 11.4.

Automated payment/refund tests use controlled provider responses; they do not establish real Teya sandbox transactions or actual email delivery. Merchant-reported sandbox acceptance on 2026-10-29 applies to v1.0.0. The v1.0.1 real sandbox retest and the HikaShop team's retest on Joomla 6.2.0 / HikaShop Business 6.7.1 / PHP 8.5 remain pending. GitHub publication does not imply HikaShop Marketplace approval.

This package supports HikaShop on Joomla only. HikaShop on WordPress is not implemented or tested. Requirements: Joomla 5.4/6, HikaShop 6.6.0+, PHP 8.2+, OpenSSL and cURL.

## Install or upgrade

Download `pkg_teya_hikashop-1.0.1.zip` and verify its SHA-256 against the attached `.sha256` file:

```text
e4cd5da1ea376c13579fc9028925d055636517a52e99ebabec4ac6294bd72d09
```

Back up the site and upload the full package through Joomla's extension installer, or use the Joomla updater once it offers v1.0.1. Keep both bundled plugins enabled. Upgrade over the existing installation; do not uninstall or recreate payment methods. Existing saved email preferences are preserved.

Test onboarding, connection persistence, successful/cancelled checkout and partial/full refunds on your shop before enabling live payments. Native HikaShop merchant emails are opt-in; existing customer order-status email behavior remains controlled by HikaShop.

Developed by Webensol. GNU GPL v3.
