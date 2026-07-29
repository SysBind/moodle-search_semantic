#!/bin/bash
set -e

# Navigate to the script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

CONTAINER="moodle"

echo "=========================================="
echo "      Moodle AI Unit Test Runner          "
echo "=========================================="

# Check if the moodle container is running
if ! docker compose ps --services --filter "status=running" | grep -q "^${CONTAINER}$"; then
    echo "Error: '${CONTAINER}' service is not running."
    echo "Start the environment first using: docker compose up -d"
    exit 1
fi

# Ensure PHPUnit binary exists (run composer install if missing)
echo "--> Verifying PHPUnit installation..."
docker compose exec "$CONTAINER" bash -c "
if [ ! -f /var/www/html/vendor/bin/phpunit ]; then
    echo 'PHPUnit not found. Installing composer dependencies...'
    composer install --working-dir=/var/www/html
fi
"

# Ensure PHPUnit database environment is initialized
echo "--> Verifying PHPUnit environment initialization..."
docker compose exec "$CONTAINER" bash -c "
if [ ! -d /var/www/phpunit_moodledata/phpunit ]; then
    echo 'Initializing PHPUnit database and dataroot...'
    php /var/www/html/public/admin/tool/phpunit/cli/util.php --init
fi
"

# Determine tests to execute
if [ $# -gt 0 ]; then
    TEST_ARGS=("$@")
else
    echo "No specific test path provided. Running RAG/AI subsystem test suite..."
    TEST_ARGS=("public/ai/tests")
fi

echo "--> Executing PHPUnit: ${TEST_ARGS[*]}"
echo "=========================================="
docker compose exec "$CONTAINER" /var/www/html/vendor/bin/phpunit "${TEST_ARGS[@]}"
