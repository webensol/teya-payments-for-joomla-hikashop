# Teya Payments for HikaShop 1.0.0

Release date: 2026-10-29.

Initial merchant-distribution package. Includes hosted checkout, test/live onboarding, signed payment callbacks, encrypted credential storage, administrator partial/full refunds, durable retries and optional CLI refund recovery. Customer payment emails default to disabled.

Release review fixed a checkout authorization-error path that incorrectly touched refund state, restricted legacy stored-data decoding, sanitized administrator error output, and added connected/pending-refund uninstall protection. Package prerequisites now check supported runtime/dependency versions and OpenSSL/cURL support. GPLv3 terms and merchant documentation are included.

Native automated test baseline: Joomla 6.1.0, HikaShop Starter 6.6.0, PHP 8.4, MariaDB 11.4. These tests use a fake Teya API on an isolated Docker network; no merchant transactions are sent. They verify native checkout/callback/refund status/history, settings preservation, package enablement, defaults and uninstall protection.

The actual installation ZIP also passed a clean native Joomla package installation and two repeated upgrades, preserving encrypted credentials, order/refund state and payment methods without duplicate extensions or methods.

On 2026-10-29 the merchant reported that the real Teya sandbox acceptance checklist passed, including checkout, connection persistence and partial/full refunds. This records merchant acceptance; the automated tests remain separate simulated-provider tests and no independent live-money validation is claimed.

Release cleanup adds missing GPL notices and corrects a copied CLI comment without changing payment behavior. The source checkout is kept outside generated distribution output, and preparation refuses to replace directories containing Git metadata. The ZIP, checksum and update-feed hash are rebuilt together.

Install `pkg_teya_hikashop-1.0.0.zip` from the versioned GitHub release. The release includes a SHA-256 checksum file, and the Joomla update feed identifies the same versioned ZIP and checksum. Joomla 5.4 remains a compatibility target and must not be advertised as tested without native validation.
