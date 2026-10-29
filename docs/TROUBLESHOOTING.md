# Troubleshooting

## Connected state

Check the selected environment and save it. Test/live connections are separate. Polling checks the specific method and onboarding attempt; wait for it to confirm completion. Do not delete/recreate methods to fix an old order's refund: check its original payment-method ID.

## Checkout validation

HUF totals must be whole forints. Unknown currencies are rejected instead of substituted. The final charged total and line-item totals must agree. Browser redirects are informational; a signed payment callback establishes the order outcome.

## Refund pending or refused

A pending record means the outcome has not been confirmed. It does not prove Teya accepted a refund. Retries preserve the original amount, transaction, environment and idempotency key. A 401 refreshes the bearer token once; persistent 401/403 pauses automatic recovery. A 403 with a small HTML response can be a gateway/firewall rejection, so do not assume it is a merchant-scope problem.

Enable Debug on the payment method used by the affected order. Debug output records status, body format/size and allowlisted request IDs; HTTP request/response bodies are omitted. For administrator operations the PHP error log prefix is currently `[Teya HikaShop Delete Sync]`. In the standard Apache-based Joomla Docker image, inspect `docker logs <container>` when PHP error_log is /dev/stderr.

Do not log/export bearer headers or add raw-body dumps to a production shop. Remove temporary diagnostics before distribution. A manually successful external refund still needs reconciliation with the local ledger.

## Backups and credentials

Secrets/tokens are encrypted with a key derived from Joomla's site secret. A database backup alone is insufficient to recover them after losing that secret. Restore the matching site configuration with the database, or reconnect after resolving outstanding transactions. Legacy plaintext rows are encrypted on supported subsequent saves; the installer does not silently bulk-migrate merchant credentials.

## Updates

Install the complete package over the existing version. Keep both bundled plugins enabled. Public update notifications become available only when the release repository's `updates/hikashop.xml` and versioned ZIP have been published. Draft packages without configured public hosting do not yet advertise an update feed.
