# Teya Payments for HikaShop 1.0.1 — marketplace review

Prepared 2026-10-30. **Unpublished review build, pending HikaShop retesting and real Teya sandbox acceptance for this version.** The published v1.0.0 release and stable Joomla update feed remain unchanged.

## Fixes following HikaShop review

- **PHP 8.5:** removed both deprecated `curl_close()` calls, made nullable callbacks explicit, and updated stream response-header access. These plugin deprecations no longer corrupt administrator JSON responses.
- **Itemised checkout:** read native checkout products from `$order->cart->products`, with a fallback to `$order->products`. Product quantities and tax-inclusive prices are included, and shipping, fees, discounts and rounding reconcile with the order total. The single-line option remains available.
- **Translations:** added language keys and translations for the reported payment settings, customer checkout errors, connection feedback, safe API errors and the administrator refund panel, including JavaScript controls. English, Hungarian, Czech, Slovak and German are covered. JSON/parser failures show localized messages without displaying raw response bodies.
- **Code cleanup:** removed obsolete private callback, credential, disconnect and cleanup implementations from both plugin entry points. Financial operations continue through the gateway/storage services.
- **Optional shop-owner email:** added a native HikaShop payment-notification option, disabled by default and separate from Teya's merchant email setting. It uses HikaShop's configured recipients and template after successful payment confirmation; duplicate callbacks do not send it again.

## Verification completed

- 105 gateway flow checks, plus credential encryption and environment separation, itemisation, signature/amount validation, administrator ACL/CSRF, durable refund retry and duplicate-notification regressions.
- Local HTTP OAuth/refund transport checks through cURL and PHP streams, including strict PHP 8.5 error handling, safe error/log output and redirect rejection.
- Language-key and placeholder checks for all five languages, plus connection/refund browser-script regressions.
- Actual ZIP installation and two repeated native upgrades, preserving credentials, orders and refund state without duplicate payment methods or extensions.
- Native checkout persistence, signed callbacks, partial/full refund state transitions, translated panels and optional merchant-notification invocation on these baselines, both with MariaDB 11.4:
  - Joomla 6.1.0 / HikaShop Starter 6.6.0 / PHP 8.5.9.
  - Joomla 5.4.8 / HikaShop Starter 6.6.0 / PHP 8.3.35.

The automated payment/refund tests use controlled provider responses. They do not establish successful transactions against the real Teya sandbox or actual email delivery.

## Requested HikaShop retest before publication

1. Install v1.0.1 over v1.0.0 on Joomla 6.2.0 / HikaShop Business 6.7.1 / PHP 8.5 with PHP notices displayed. Confirm settings, connection and refund AJAX responses remain valid JSON, and existing settings/credentials survive the upgrade.
2. With “Send order as one line item” disabled, verify a multi-product checkout contains separate product lines and the correct total, including tax, shipping and discounts. Check the enabled single-line option as well.
3. Check the translated settings, connection feedback, refund controls and customer-facing/API error messages. Confirm failures do not display tokens, credentials, customer data or raw HTML responses.
4. Repeat invalid-signature, incorrect-amount and duplicate-notification checks. Verify a valid notification confirms the order once and retains the expected customer-email behavior.
5. Check the native HikaShop shop-owner email option: disabled means no additional native merchant notification; enabled means one native notification for a successful payment and none for a duplicate callback. Verify actual delivery using HikaShop's configured recipients/mail transport.
6. Once sandbox access is available, complete real onboarding, reload the settings to verify persistence, make a test payment, then perform a partial refund and refund the remaining balance. Verify the resulting transactions in Teya and the order/history/remaining balance in HikaShop. Check cancellation/failure handling and disconnect/reconnect in the test environment.

The HikaShop team's Joomla 6.2.0 / Business 6.7.1 / PHP 8.5 report applies to v1.0.0; the same combination still needs retesting with v1.0.1. This package is for HikaShop on Joomla only; HikaShop on WordPress is not implemented or tested.

## Review package

- Installer: `pkg_teya_hikashop-1.0.1.zip`.
- SHA-256: `927fe340a94b7e063829f60cf1d1efe7fa67941a854f59f19cdb1a11c0487078`.
- A matching `.sha256` file is attached to this draft.

Draft assets are not publicly downloadable. Send the ZIP and checksum directly to reviewers until the release is published.
