# Restate Laravel integration — developer tasks. Mirrors the SDK's strict gate.

.PHONY: install test test-unit lint stan cs cs-fix sast infection check

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
