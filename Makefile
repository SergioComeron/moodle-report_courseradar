.PHONY: setup test

## Activate git hooks for this repo (run once per clone).
setup:
	git config core.hooksPath .githooks
	chmod +x .githooks/pre-push
	@echo "Git hooks activated."

## Run PHPUnit tests locally.
test:
	@MOODLE_ROOT="$$(cd ../.. && pwd)"; \
	if [ ! -f "$$MOODLE_ROOT/vendor/bin/phpunit" ]; then \
		echo "PHPUnit not initialised. Run: cd $$MOODLE_ROOT && php admin/tool/phpunit/cli/init.php"; \
		exit 1; \
	fi; \
	cd "$$MOODLE_ROOT" && vendor/bin/phpunit --testsuite report_courseradar_testsuite
