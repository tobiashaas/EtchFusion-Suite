#!/bin/bash
# Test CORS Enforcement
# Tests that all REST endpoints properly enforce CORS origin validation
# Created: 2025-10-24 07:56

echo "=== Testing CORS Enforcement ==="
echo ""

# Configuration
BRICKS_URL="http://localhost:8888"
ETCH_URL="http://localhost:8889"
DISALLOWED_ORIGIN="http://evil-site.com"

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counter
TESTS_PASSED=0
TESTS_FAILED=0

# Function to test endpoint with disallowed origin
test_cors_rejection() {
    local url=$1
    local method=$2
    local endpoint=$3
    local description=$4
    
    echo "Testing: $description"
    echo "  URL: $url$endpoint"
    echo "  Method: $method"
    echo "  Origin: $DISALLOWED_ORIGIN"
    
    if [ "$method" = "GET" ]; then
        response=$(curl -s -w "\n%{http_code}" -H "Origin: $DISALLOWED_ORIGIN" "$url$endpoint")
    else
        response=$(curl -s -w "\n%{http_code}" -X POST -H "Origin: $DISALLOWED_ORIGIN" -H "Content-Type: application/json" -d '{}' "$url$endpoint")
    fi
    
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | head -n-1)
    
    if [ "$http_code" = "403" ]; then
        echo -e "  ${GREEN}✓ PASS${NC} - Correctly rejected with 403"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        echo -e "  ${RED}✗ FAIL${NC} - Expected 403, got $http_code"
        echo "  Response: $body"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
    echo ""
}

# Function to test endpoint with allowed origin
test_cors_allowed() {
    local url=$1
    local method=$2
    local endpoint=$3
    local description=$4
    
    echo "Testing: $description (allowed origin)"
    echo "  URL: $url$endpoint"
    echo "  Method: $method"
    echo "  Origin: $url"
    
    if [ "$method" = "GET" ]; then
        response=$(curl -s -w "\n%{http_code}" -H "Origin: $url" "$url$endpoint")
    else
        response=$(curl -s -w "\n%{http_code}" -X POST -H "Origin: $url" -H "Content-Type: application/json" -d '{}' "$url$endpoint")
    fi
    
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | head -n-1)
    
    # Check for CORS headers in response
    cors_header=$(curl -s -I -H "Origin: $url" "$url$endpoint" | grep -i "Access-Control-Allow-Origin")
    
    if [ "$http_code" != "403" ] && [ ! -z "$cors_header" ]; then
        echo -e "  ${GREEN}✓ PASS${NC} - Allowed origin accepted (HTTP $http_code) with CORS headers"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        echo -e "  ${YELLOW}⚠ WARNING${NC} - HTTP $http_code, CORS header: $cors_header"
        echo "  Response: $body"
    fi
    echo ""
}

echo "=== Testing Public Endpoints with Disallowed Origin ==="
echo ""

# Test handle_key_migration endpoint
test_cors_rejection "$ETCH_URL" "GET" "/wp-json/b2e/v1/migrate?domain=test&token=test&expires=9999999999" "Key Migration Endpoint"

# Test validate_migration_token endpoint (legacy b2e/v1 namespace)
test_cors_rejection "$ETCH_URL" "POST" "/wp-json/b2e/v1/validate" "Token Validation Endpoint (b2e/v1)"

# Test test_auth endpoint
test_cors_rejection "$ETCH_URL" "GET" "/wp-json/b2e/v1/auth/test" "Auth Test Endpoint"

echo "=== Testing efs/v1/validate Endpoint (Current Namespace) ==="
echo ""

# Disallowed browser origin must be rejected (CORS guard via allow_public_request).
test_cors_rejection "$ETCH_URL" "POST" "/wp-json/efs/v1/validate" "Token Validation Endpoint (efs/v1, disallowed origin)"

# Server-to-server call without Origin header: must NOT return 403 (admin login is not required).
echo "Testing: Token Validation Endpoint (efs/v1, no Origin header — server-to-server)"
echo "  URL: $ETCH_URL/wp-json/efs/v1/validate"
echo "  Method: POST"
echo "  Origin: (none)"
no_origin_response=$(curl -s -w "\n%{http_code}" -X POST \
    -H "Content-Type: application/json" \
    -d '{"migration_key":"invalid-key-for-reachability-test"}' \
    "$ETCH_URL/wp-json/efs/v1/validate")
no_origin_code=$(echo "$no_origin_response" | tail -n1)
no_origin_body=$(echo "$no_origin_response" | head -n-1)
if [ "$no_origin_code" != "403" ]; then
    echo -e "  ${GREEN}✓ PASS${NC} - Endpoint reachable without WP login (HTTP $no_origin_code, not 403)"
    TESTS_PASSED=$((TESTS_PASSED + 1))
else
    echo -e "  ${RED}✗ FAIL${NC} - Got 403; endpoint must be accessible to non-logged-in server callers"
    echo "  Response: $no_origin_body"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi
echo ""

echo "=== Testing Public Endpoints with Allowed Origin ==="
echo ""

# Test with allowed origin
test_cors_allowed "$ETCH_URL" "GET" "/wp-json/b2e/v1/auth/test" "Auth Test Endpoint"

echo "=== Testing Authenticated Endpoints (should also enforce CORS) ==="
echo ""

# Test export endpoint (requires API key, but CORS should still block)
test_cors_rejection "$BRICKS_URL" "GET" "/wp-json/b2e/v1/export/posts" "Export Posts Endpoint"

# Test import endpoint
test_cors_rejection "$ETCH_URL" "POST" "/wp-json/b2e/v1/import/post" "Import Post Endpoint"

echo "=== Test Summary ==="
echo ""
echo -e "Tests Passed: ${GREEN}$TESTS_PASSED${NC}"
echo -e "Tests Failed: ${RED}$TESTS_FAILED${NC}"
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}✓ All CORS enforcement tests passed!${NC}"
    exit 0
else
    echo -e "${RED}✗ Some CORS enforcement tests failed!${NC}"
    exit 1
fi
