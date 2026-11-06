# Troubleshooting

## Connected state

Check the selected environment and save it. Test/live connections are separate. Polling checks the specific method and onboarding attempt; wait for it to confirm completion. Do not delete/recreate methods to fix an old order's refund: check its original payment-method ID.

## Checkout validation

HUF totals must be whole forints. Unknown currencies are rejected instead of substituted. The final charged total and line-item totals must agree. Browser URLs are not payment evidence. Since version 1.0.2, either a signed payment callback or an authenticated session lookup on an authorized checkout return establishes success after matching the order details.

## Refund pending or refused

A pending record means the outcome has not been confirmed. It does not prove Teya accepted a refund. Retries preserve the original amount, transaction, environment and idempotency key. A 401 refreshes the bearer token once; persistent 401/403 pauses automatic recovery. A 403 with a small HTML response can be a gateway/firewall rejection, so do not assume it is a merchant-scope problem.

Enable Debug on the payment method used by the affected order. Debug output records status, body format/size and allowlisted request IDs; HTTP request/response bodies are omitted. Version 1.0.2 also records recognized API error codes and safe explanations such as Invalid Email Address; unknown descriptions and echoed values are omitted. Session lookup identifiers are masked. For administrator operations the PHP error log prefix is currently `[Teya HikaShop Delete Sync]`. In the standard Apache-based Joomla Docker image, inspect `docker logs <container>` when PHP error_log is /dev/stderr.

Do not log/export bearer headers or add raw-body dumps to a production shop. Remove temporary diagnostics before distribution. A manually successful external refund still needs reconciliation with the local ledger.

## Backups and credentials

Secrets/tokens are encrypted with a key derived from Joomla's site secret. A database backup alone is insufficient to recover them after losing that secret. Restore the matching site configuration with the database, or reconnect after resolving outstanding transactions. Legacy plaintext rows are encrypted on supported subsequent saves; the installer does not silently bulk-migrate merchant credentials.

## Updates

Install the complete package over the existing version. Keep both bundled plugins enabled. The package declares the public repository's `updates/hikashop.xml` feed. Published installation ZIPs and SHA-256 checksum files are available on the versioned GitHub release pages. If Joomla cannot download an update, check that the shop can access both the raw GitHub update feed and GitHub release downloads over HTTPS.

## PHP 8.5 and version 1.0.1

Version 1.0.1 removes plugin cURL-close, implicit-nullable and stream-header deprecations that can corrupt administrator JSON when notices are displayed. Reinstall the full package over the previous version; keep the same payment methods and credentials. Browser failures show a localized generic message. Server logs retain safe status/request-ID metadata; never publish raw tokens or provider bodies.

## Native merchant notification email

Enable Send HikaShop payment notification to merchant in the Teya payment method and configure HikaShop's payment-notification recipient address. It uses HikaShop's mail template and transport. The setting defaults off and is separate from Teya's own email options. A mail-delivery problem does not roll back or repeat a confirmed payment; check the shop's mail configuration.

## Return verification

The optional session lookup requires `checkout/sessions/id/get`. Existing cached tokens are renewed with that scope when needed. A refusal leaves the original order status unchanged and does not disable normal checkout, refunds or signed callbacks. Check the method's debug log for safe HTTP/error details and resolve the API credential permissions. A return that is not authenticated as the order owner or by the HikaShop order token does not perform a lookup.

A pending/failed lookup can be retried by reloading the completion page after the 15-second throttle. A successful lookup records session_lookup as its confirmation source and enables the same administrator refund flow. Repeated returns and late webhooks preserve refunded orders.
