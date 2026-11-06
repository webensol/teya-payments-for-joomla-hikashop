Teya Payments for HikaShop — 1.0.2

Install pkg_teya_hikashop-1.0.2.zip through Joomla System > Install > Extensions.
This package installs both required plugins. Do not install the inner ZIPs individually.
Requires PHP 8.2+, OpenSSL, cURL, Joomla 5.4/6 and HikaShop 6.6.0+.
Create and save a Teya payment method in HikaShop before connecting it. Test/live connections are separate.
Customer payment-confirmation emails default to disabled; HikaShop native status emails are separate.
Update over the existing installation. Do not delete/recreate payment methods to update them.
Disconnect both environments and resolve pending refunds before uninstalling.

Validate session lookup and the session-read permission with your Teya test connection before using checkout return verification in production.
Source, merchant guide and release notes: https://github.com/webensol/teya-payments-for-joomla-hikashop
License: GNU General Public License version 3; full terms are in LICENSE.txt.

Native HikaShop merchant payment notifications are optional and disabled by default. Enable them in the payment method and configure payment-notification recipients in HikaShop.

Authorized checkout returns verify the stored Teya session before confirming payment. Webhooks remain required for customers who do not return. Debug mode records recognized, sanitized API explanations; raw bodies and sensitive data are omitted.
