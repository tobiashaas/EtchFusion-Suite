#!/bin/bash
# Run WordPress PHPUnit tests

cd /var/www/html/wp-content/plugins/etch-fusion-suite

export WP_TESTS_DIR=/tmp/wordpress-tests-lib
export WP_CORE_DIR=/tmp/wordpress

echo "Running PHPUnit tests..."
echo "WP_TESTS_DIR: $WP_TESTS_DIR"
echo "WP_CORE_DIR: $WP_CORE_DIR"
echo ""

php vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit
