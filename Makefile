# Restate Laravel integration — developer tasks. Mirrors the SDK's strict gate.

.PHONY: install test test-unit lint stan cs cs-fix sast infection check e2e

install:
	composer install

test: test-unit

test-unit:
	vendor/bin/phpunit --testsuite unit

# Full strict gate: coding standard (check only) + static analysis (PHPStan max + Larastan).
lint: cs stan

stan:
	vendor/bin/phpstan analyse --no-progress

# Verify coding standard without modifying files (fails on violations).
cs:
	vendor/bin/php-cs-fixer fix --dry-run --diff

# Apply the coding standard.
cs-fix:
	vendor/bin/php-cs-fixer fix

# Offline SAST: Psalm taint analysis (untrusted input -> dangerous sinks).
sast:
	vendor/bin/psalm --taint-analysis --no-progress

# Mutation testing (Infection): needs a coverage driver (pcov/xdebug).
infection:
	vendor/bin/infection --threads=max --no-progress

# The local pre-commit gate: lint + SAST + unit tests.
check: lint sast test-unit

# Live end-to-end test: a real Restate runtime + a minimal Laravel app hosting the package's
# services over bidi HTTP/2, driven through the ingress. Requires Docker (and a >= 1.7 runtime
# image, AVX2 host). Separate from the offline gate above. KEEP_UP=1 leaves the stack running.
e2e:
	tests/e2e/run.sh
