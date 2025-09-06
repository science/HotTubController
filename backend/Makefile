.PHONY: help install test test-unit test-integration coverage analyze cs-check cs-fix serve clean dev-setup

# Colors for output
YELLOW := \033[33m
GREEN := \033[32m
RED := \033[31m
RESET := \033[0m

# Default target
help: ## Show this help message
	@echo "$(YELLOW)Hot Tub Controller PHP Proxy - Available Commands:$(RESET)"
	@echo
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-20s$(RESET) %s\n", $$1, $$2}'

install: ## Install composer dependencies
	@echo "$(YELLOW)Installing dependencies...$(RESET)"
	composer install
	@if [ ! -f .env ]; then \
		echo "$(YELLOW)Creating .env file from .env.example...$(RESET)"; \
		cp .env.example .env; \
	fi

test: ## Run all tests
	@echo "$(YELLOW)Running all tests...$(RESET)"
	vendor/bin/phpunit

test-unit: ## Run only unit tests
	@echo "$(YELLOW)Running unit tests...$(RESET)"
	vendor/bin/phpunit --testsuite=unit

test-integration: ## Run only integration tests
	@echo "$(YELLOW)Running integration tests...$(RESET)"
	vendor/bin/phpunit --testsuite=integration

coverage: ## Generate code coverage report
	@echo "$(YELLOW)Generating coverage report...$(RESET)"
	vendor/bin/phpunit --coverage-html coverage
	@echo "$(GREEN)Coverage report generated in 'coverage' directory$(RESET)"

analyze: ## Run static analysis with PHPStan
	@echo "$(YELLOW)Running static analysis...$(RESET)"
	vendor/bin/phpstan analyse

cs-check: ## Check code style
	@echo "$(YELLOW)Checking code style...$(RESET)"
	vendor/bin/phpcs src tests --standard=PSR12

cs-fix: ## Fix code style issues
	@echo "$(YELLOW)Fixing code style...$(RESET)"
	vendor/bin/phpcbf src tests --standard=PSR12

serve: ## Start development server
	@echo "$(YELLOW)Starting development server at http://localhost:8080$(RESET)"
	@echo "$(GREEN)Press Ctrl+C to stop$(RESET)"
	php -S localhost:8080 -t public

serve-test: ## Start test server
	@echo "$(YELLOW)Starting test server at http://localhost:8081$(RESET)"
	APP_ENV=testing php -S localhost:8081 -t public

watch: ## Run tests on file changes (requires entr)
	@echo "$(YELLOW)Watching files for changes...$(RESET)"
	@echo "$(GREEN)Press Ctrl+C to stop$(RESET)"
	find src tests -name "*.php" | entr -c make test

dev-setup: install ## Set up development environment
	@echo "$(YELLOW)Setting up development environment...$(RESET)"
	@mkdir -p storage/logs coverage tests/results
	@touch storage/logs/app.log storage/logs/test.log
	@echo '{"tokens": []}' > storage/tokens.json
	@echo '{"tokens": []}' > storage/test_tokens.json
	@echo "$(GREEN)Development environment ready!$(RESET)"
	@echo "$(YELLOW)Run 'make serve' to start the development server$(RESET)"

clean: ## Clean up generated files
	@echo "$(YELLOW)Cleaning up...$(RESET)"
	rm -rf coverage/ tests/results/ .phpunit.cache/
	rm -f storage/logs/*.log storage/test_tokens.json
	@echo "$(GREEN)Cleanup complete$(RESET)"

quality: cs-check analyze test ## Run all quality checks

ci: install quality ## Run CI pipeline locally

# Development shortcuts
dev: serve ## Alias for serve
t: test ## Alias for test
tu: test-unit ## Alias for test-unit
ti: test-integration ## Alias for test-integration