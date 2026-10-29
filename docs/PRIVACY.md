# Data handled by the integration

The plugin sends the order amount/currency, line items, merchant/order references, checkout return URLs and available customer/billing details to the selected Teya test/live API to create hosted checkout sessions. The optional customer-confirmation email field is omitted unless the merchant enables its setting; the default is disabled. An optional merchant email is sent only when configured.

Card entry happens on Teya's hosted checkout. The plugin does not collect card numbers or CVV. Payment callbacks can include card brand/last-four and customer/order metadata; callback/payment data and refund references are stored with the HikaShop order. HikaShop can separately send native order-status emails.

OAuth client secrets and access tokens are stored encrypted using AES-256-GCM and a key derived from the Joomla site secret. Test/live fields are separate. Database and application-host access still require protection. Supported saves encrypt legacy plaintext values; unchanged legacy rows are not automatically migrated during installation.

Debug logging omits HTTP request/response bodies, bearer headers, cookies and raw HTML errors. Safe diagnostic metadata can include HTTP status, response byte length, content type and request/correlation IDs. Previously generated logs and temporary debug files are not retroactively removed by updates.

The merchant controls the Joomla database, logs, backups and retention. There is no developer telemetry endpoint in the plugin. Public update checks use the configured distribution repository and disclose ordinary HTTP connection information to that host. Teya processes data under the merchant's own Teya service agreement.
