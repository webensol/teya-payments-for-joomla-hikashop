.PHONY: package-hikashop test-hikashop
.DEFAULT_GOAL := package-hikashop
package-hikashop:
	python3 tools/build-packages.py hikashop

test-hikashop:
	php tests/hikashop-flow-regression.php
	php tests/hikashop-regression.php
	php tests/hikashop-admin-regression.php
	php tests/hikashop-connection-regression.php
	php tests/hikashop-refund-diagnostics.php
	php tests/hikashop-transport-regression.php
	php tests/hikashop-localization-regression.php
	node tests/hikashop-admin-ui-regression.cjs
	node tests/hikashop-refund-ui-regression.cjs
