# Teya Payments for HikaShop 1.0.0 — release draft

Initial merchant-distribution package. Includes hosted checkout, test/live onboarding, signed payment callbacks, encrypted credential storage, administrator partial/full refunds, durable retries and optional CLI refund recovery. Customer payment emails default to disabled.

Release review fixed a checkout authorization-error path that incorrectly touched refund state, restricted legacy stored-data decoding, sanitized administrator error output, and added connected/pending-refund uninstall protection. Package prerequisites now check supported runtime/dependency versions and OpenSSL/cURL support. GPLv3 terms and merchant documentation are included.

Native automated test baseline: Joomla 6.1.0, HikaShop Starter 6.6.0, PHP 8.4, MariaDB 11.4. These tests use a fake Teya API on an isolated Docker network; no merchant transactions are sent. They verify native checkout/callback/refund status/history, settings preservation, package enablement, defaults and uninstall protection.

Before stable publication: finish real Teya sandbox acceptance (including the previously observed refund 403), publish the public repository/download/update feed, verify the final archive installation in the target shop and review listing content/screenshots. Joomla 5.4 is an allowed compatibility target but must not be advertised as tested without native validation.
