# Directory submission copy — review before submission

## Common listing copy

Name: **Teya Payments for HikaShop**
Developer: Webensol
Version: 1.0.0 (merchant sandbox acceptance reported on 2026-10-29)
Type: Joomla installation package containing HikaShop payment and administration plugins.
License: GNU GPL version 3. A Teya merchant account is required; payment-service costs are separate from the plugin distribution terms.

Short description:
Accept Teya Hosted Checkout payments in HikaShop with separate test/live merchant connections, signed payment callbacks, and administrator full/partial refunds.

Features:
- Merchant connection and method-specific status polling.
- Separate encrypted test/live secrets and tokens.
- Signed callbacks and idempotent payment/refund recovery.
- HikaShop administrator partial/full refund panel and optional CLI recovery.
- HUF whole-forint validation and reconciled checkout totals.
- Customer payment-confirmation email disabled by default.
- One upload installs both required plugins.

Do not claim recurring payments, separate authorization/capture, customer-executed refunds, Teya certification, verified live-money behavior, or broader compatibility than the test record supports.

## Download channel

Public repository URL: https://github.com/webensol/teya-payments-for-joomla-hikashop
Versioned installation ZIP: https://github.com/webensol/teya-payments-for-joomla-hikashop/releases/download/v1.0.0/pkg_teya_hikashop-1.0.0.zip
Documentation: https://github.com/webensol/teya-payments-for-joomla-hikashop#readme
Support: https://github.com/webensol/teya-payments-for-joomla-hikashop/issues
Update feed: https://raw.githubusercontent.com/webensol/teya-payments-for-joomla-hikashop/main/updates/hikashop.xml

Publish the versioned ZIP and its SHA-256 file as release assets, then publish the update feed with that ZIP's checksum. Never make the private development repository public to distribute this plugin.

## HikaShop Marketplace

Prepare the common copy, package ZIP, documentation/download/support links, compatibility evidence and screenshots of test data only. Ask the HikaShop team about the current third-party marketplace submission process through https://www.hikashop.com/support/contact-us.html . This file is a draft, not a message already sent or a marketplace approval.

## Joomla Extensions Directory

Use https://extensions.joomla.org/support/knowledgebase/for-jed-developers/submitting-an-extension/ to submit through the publisher account after the public URLs work. New listings require Joomla update-system integration: https://extensions.joomla.org/support/knowledgebase/submission-requirements/joomla-update-system-requirement/ . GPL distribution and a valid download/product page are required. Choose distribution/payment classification according to the current JED SaaS rules rather than claiming the payment service is free.

Only select compatibility versions actually verified. Confirm extension naming, pricing, developer profile, support links and any requested screenshots in the submission account. Submission is subject to the directory's own review; preparation does not mean the listing is published.
