# Teya Payments for HikaShop 1.0.1 — marketplace review

Prepared 2026-10-30. This review build leaves the published 1.0.0 release and stable update feed unchanged.

- Remove deprecated curl_close calls, make nullable callbacks explicit, and use modern stream response-header access on PHP 8.5. Administrator JSON is no longer prefixed by these plugin deprecations.
- Itemise the native checkout products from order.cart.products, retain support for order.products, include tax-inclusive prices and reconcile shipping/fees/discounts with the order total. The one-line option remains available.
- Translate payment settings, checkout errors, connection feedback, API errors and the entire administrator refund panel/browser controls in English, Hungarian, Czech, Slovak and German. Browser JSON/parser failures show safe localized errors instead of raw HTML fragments.
- Remove obsolete private checkout, callback, credential, disconnect and duplicate-cleanup implementations from both entry-point plugins. Gateway/storage services remain the single financial-operation implementation.
- Add an optional native HikaShop merchant payment-notification email, disabled by default. It uses HikaShop recipients/templates after payment confirmation and is not repeated for duplicate callbacks.

Validation: 105 gateway flow checks; active-path credential, itemisation and security regressions; administrator ACL/CSRF checks; connection/encryption persistence; real local HTTP OAuth/refund requests through both cURL and stream transports under strict PHP 8.5 error handling; five-language key/placeholder checks and browser refund/connection regressions. Native package installation, repeated upgrades, callbacks, partial/full refunds, settings preservation, translated panels and optional merchant notifications pass on Joomla 6.1.0 / HikaShop Starter 6.6.0 / PHP 8.5.9 and Joomla 5.4.8 / HikaShop Starter 6.6.0 / PHP 8.3.35, with MariaDB 11.4.

The HikaShop team reported testing 1.0.0 on Joomla 6.2.0 / HikaShop Business 6.7.1 / PHP 8.5. This 1.0.1 build awaits their retest on that combination and real Teya sandbox onboarding/payments/refunds. The package remains Joomla-only; HikaShop on WordPress is not implemented or tested.
