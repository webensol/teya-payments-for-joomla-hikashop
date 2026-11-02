# Teya Payments for HikaShop 1.0.2 — development build

Prepared 2026-11-02. This candidate is not published; the GitHub release and Joomla update feed remain on v1.0.1.

## Changes

- Debug logging now records recognized Teya API error codes and safe explanations, including invalid email addresses, without recording raw response bodies, echoed customer data, tokens or credentials. Unknown explanations are omitted. Session identifiers are masked in lookup request logs.
- When an authorized customer returns to HikaShop's checkout completion page, the plugin fetches the order's stored session from Teya and confirms only a COMPLETED/SUCCESS SALE matching the session, merchant reference (when stored), amount, currency and transaction.
- Return confirmation and signed webhooks share the same method/order locks, payment validation and duplicate guard. A late webhook or repeated return does not duplicate confirmation/history/emails or undo refunds.
- The optional lookup requests the checkout/sessions/id/get OAuth scope as needed, replacing older cached tokens safely. A missing permission, unavailable API or inconclusive payment leaves the order unchanged; webhooks remain enabled. Pending/error return lookups are throttled per order.
- The PHP stream fallback now reads legacy response headers correctly on PHP 8.2/8.3 without introducing PHP 8.5 deprecations.

## Verification

The existing payment/security/administrator/connection/localization/browser regressions and new return-flow tests cover return-before-webhook, webhook-before-return, duplicate delivery, refunds after return confirmation, guest/owner authorization, scope migration, 401 refresh, missing permissions, timeouts and mismatched payment data. Real local HTTP tests verify safe errors, debug-off silence, GET headers, scopes and both cURL/stream transports on PHP 8.3 and 8.5.

The candidate still requires a real Teya sandbox test of session lookup and the new scope with plugin-issued credentials. Automated provider responses are controlled fixtures, not real-money transactions.

Native installation of the actual 1.0.2 ZIP, two repeated upgrades, the real frontend return event, guest authorization, signed late callbacks and partial/full refunds pass on Joomla 6.1.0 / HikaShop Starter 6.6.0 / PHP 8.5.9 and Joomla 5.4.8 / HikaShop Starter 6.6.0 / PHP 8.3.35, with MariaDB 11.4. The automated suite includes 105 existing flow checks and 147 new return checks.

## Earlier sandbox evidence

For v1.0.1, the HikaShop team reported a real checkout/payment and two successful refunds (partial and remainder) on Joomla 6.2 / HikaShop Business 6.7.1 / PHP 8.5. They manually provisioned credentials and submitted a notification signed with their own key using the real payment identifiers. This does not validate actual Teya onboarding or Teya-delivered webhooks.
