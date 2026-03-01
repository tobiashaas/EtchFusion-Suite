#!/bin/bash
# setup-test-suite.sh
# Automated WordPress test suite setup in Docker
# Run this after: npx wp-env start
# Or include in: npm run dev post-hook

set -e

echo "📋 Setting up WordPress Test Suite in Docker CLI container..."

# Get MySQL port dynamically
MYSQL_PORT=${1:-3306}

# Install test suite in Docker
echo "🔧 Installing WordPress test suite in CLI container..."
npx wp-env run cli bash /var/www/html/wp-content/plugins/etch-fusion-suite/install-wp-tests.sh \
  wordpress_test root password 127.0.0.1:${MYSQL_PORT} latest true

echo "✅ WordPress test suite installed successfully!"
echo "   WP_TESTS_DIR: /wordpress-phpunit"
echo "   WP_CORE_DIR: /tmp/wordpress"
echo ""
echo "📝 To run unit tests:"
echo "   npx wp-env run cli bash -c \"export WP_TESTS_DIR=/wordpress-phpunit && /var/www/html/wp-content/plugins/etch-fusion-suite/vendor/bin/phpunit -c /var/www/html/wp-content/plugins/etch-fusion-suite/phpunit.xml.dist --testsuite unit\""
echo ""
echo "💡 Or use this simpler command from package.json:"
echo "   npm run test:unit"
