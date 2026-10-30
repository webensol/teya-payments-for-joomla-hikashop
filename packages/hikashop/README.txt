Teya Payments for HikaShop — 1.0.1

Install pkg_teya_hikashop-1.0.1.zip through Joomla System > Install > Extensions.
This package installs both required plugins. Do not install the inner ZIPs individually.
Requires PHP 8.2+, OpenSSL, cURL, Joomla 5.4/6 and HikaShop 6.6.0+.
Create and save a Teya payment method in HikaShop before connecting it. Test/live connections are separate.
Customer payment-confirmation emails default to disabled; HikaShop native status emails are separate.
Update over the existing installation. Do not delete/recreate payment methods to update them.
Disconnect both environments and resolve pending refunds before uninstalling.

Version 1.0.1 is distributed as a stable GitHub release. Sandbox acceptance recorded on 2026-10-29 applies to version 1.0.0; the 1.0.1 real sandbox and marketplace retest is pending. Test your shop's payment and refund flow before enabling live payments.
Source, merchant guide and release notes: https://github.com/webensol/teya-payments-for-joomla-hikashop
License: GNU General Public License version 3; full terms are in LICENSE.txt.

Native HikaShop merchant payment notifications are optional and disabled by default. Enable them in the payment method and configure payment-notification recipients in HikaShop.
