#!/usr/bin/env bash
# =============================================================================
# API Brute Force & Security Test Script
# Tests rate limiting, enumeration, XSS, and SQLi against your own API.
#
# Usage:
#   chmod +x tests/scripts/brute_force_test.sh
#   ./tests/scripts/brute_force_test.sh http://localhost:8000/api
# =============================================================================

set -euo pipefail

BASE_URL="${1:-http://localhost:8000/api}"
PASS=0
FAIL=0
WARN=0

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log_pass() { ((PASS++)); echo -e "${GREEN}[PASS]${NC} $1"; }
log_fail() { ((FAIL++)); echo -e "${RED}[FAIL]${NC} $1"; }
log_warn() { ((WARN++)); echo -e "${YELLOW}[WARN]${NC} $1"; }
log_info() { echo -e "${CYAN}[INFO]${NC} $1"; }
separator() { echo "──────────────────────────────────────────────────"; }

# Fire N requests, count how many get 429
burst_test() {
    local url="$1"
    local method="${2:-GET}"
    local body="${3:-}"
    local count="${4:-20}"
    local label="$5"

    local got_429=0
    for i in $(seq 1 "$count"); do
        if [ "$method" = "POST" ]; then
            status=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$url" \
                -H "Content-Type: application/json" -d "$body" 2>/dev/null)
        else
            status=$(curl -s -o /dev/null -w "%{http_code}" "$url" 2>/dev/null)
        fi
        [ "$status" = "429" ] && ((got_429++)) || true
    done

    if [ "$got_429" -ge 1 ]; then
        log_pass "$label — rate limited ($got_429/$count blocked)"
    else
        log_fail "$label — $count requests sent, none were rate limited"
    fi
}

echo ""
echo "=============================================="
echo " API BRUTE FORCE & SECURITY TEST"
echo " Target: $BASE_URL"
echo "=============================================="
echo ""

# ─────────────────────────────────────────────────
# 1. ORDER ID ENUMERATION
# ─────────────────────────────────────────────────
separator
log_info "TEST 1: Order ID Enumeration (unauthenticated)"

exposed=0
for id in 1 2 3 5 10 50; do
    status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/v1/orders/$id" 2>/dev/null)
    [ "$status" = "200" ] && ((exposed++)) || true
done

if [ "$exposed" -gt 0 ]; then
    log_fail "Order enumeration: $exposed orders accessible without auth"
else
    log_pass "Orders not accessible without auth"
fi

# ─────────────────────────────────────────────────
# 2. PAYMENT ENDPOINT PROBING
# ─────────────────────────────────────────────────
separator
log_info "TEST 2: Payment Endpoint Enumeration (unauthenticated)"

payment_exposed=0
for id in 1 2 3 4 5; do
    status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/v1/payments/$id" 2>/dev/null)
    [ "$status" = "200" ] && ((payment_exposed++)) || true
done

if [ "$payment_exposed" -gt 0 ]; then
    log_fail "Payment enumeration: $payment_exposed payments accessible without auth"
else
    log_pass "Payment endpoint not publicly enumerable"
fi

# ─────────────────────────────────────────────────
# 3. DISCOUNT CODE LEAK
# ─────────────────────────────────────────────────
separator
log_info "TEST 3: Discount Code Exposure"

discount_response=$(curl -s "$BASE_URL/v1/discounts" 2>/dev/null)
discount_count=$(echo "$discount_response" | jq '.data | length' 2>/dev/null || echo "0")

if [ "$discount_count" -gt 0 ]; then
    log_fail "Public /discounts leaks $discount_count discount codes"
else
    log_pass "Discount codes not publicly exposed"
fi

# ─────────────────────────────────────────────────
# 4. DISCOUNT VALIDATE BRUTE FORCE
# ─────────────────────────────────────────────────
separator
log_info "TEST 4: Discount Validation Brute Force (20 requests)"

burst_test \
    "$BASE_URL/v1/discounts/validate" \
    "POST" \
    '{"code":"BRUTE_TEST","order_amount":100}' \
    20 \
    "Discount validate rate limit"

# ─────────────────────────────────────────────────
# 5. LOGIN BRUTE FORCE
# ─────────────────────────────────────────────────
separator
log_info "TEST 5: Login Brute Force (15 rapid attempts)"

burst_test \
    "$BASE_URL/v1/login" \
    "POST" \
    '{"email":"attacker@test.com","password":"wrongpassword"}' \
    15 \
    "Login rate limit"

# ─────────────────────────────────────────────────
# 6. ORDER CREATION SPAM
# ─────────────────────────────────────────────────
separator
log_info "TEST 6: Order Creation Spam (15 rapid requests)"

burst_test \
    "$BASE_URL/v1/orders" \
    "POST" \
    '{"customer_name":"Bot","customer_email":"bot@test.com","delivery_method":"pickup","shipping_address":null,"items":[{"product_id":1,"quantity":1}]}' \
    15 \
    "Order creation rate limit"

# ─────────────────────────────────────────────────
# 7. BACKORDER TOKEN PROBING
# ─────────────────────────────────────────────────
separator
log_info "TEST 7: Backorder Token Brute Force (20 random tokens)"

bo_429=0
for i in $(seq 1 20); do
    token=$(head -c 48 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 64)
    status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/v1/backorders/pay/$token" 2>/dev/null)
    [ "$status" = "429" ] && ((bo_429++)) || true
done

if [ "$bo_429" -ge 1 ]; then
    log_pass "Backorder token endpoint rate limited ($bo_429/20 blocked)"
else
    log_fail "Backorder token endpoint NOT rate limited — 20 probes, 0 blocks"
fi

# ─────────────────────────────────────────────────
# 8. TRACKING NUMBER ENUMERATION
# ─────────────────────────────────────────────────
separator
log_info "TEST 8: Tracking Number Enumeration"

tracking_exposed=0
for suffix in "000001" "000002" "ABC123" "TEST01"; do
    status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/v1/tracking/SSU-20260319-$suffix" 2>/dev/null)
    [ "$status" = "200" ] && ((tracking_exposed++)) || true
done

if [ "$tracking_exposed" -gt 0 ]; then
    log_warn "Tracking: $tracking_exposed shipments found via guessed numbers"
else
    log_pass "Tracking number guessing returned no results"
fi

# ─────────────────────────────────────────────────
# 9. XSS PAYLOAD INJECTION
# ─────────────────────────────────────────────────
separator
log_info "TEST 9: XSS Payload in Order Fields"

xss_response=$(curl -s -X POST "$BASE_URL/v1/orders" \
    -H "Content-Type: application/json" \
    -d '{
        "customer_name": "<script>alert(1)</script>",
        "customer_email": "xss@test.com",
        "customer_phone": "<img onerror=alert(1) src=x>",
        "delivery_method": "pickup",
        "shipping_address": null,
        "items": [{"product_id": 1, "quantity": 1}]
    }' 2>/dev/null)

if echo "$xss_response" | grep -qi "<script>\|onerror=\|alert(1)"; then
    log_fail "XSS payload reflected in response — sanitization not working"
else
    log_pass "XSS payloads stripped or not reflected"
fi

# ─────────────────────────────────────────────────
# 10. SQL INJECTION PROBING
# ─────────────────────────────────────────────────
separator
log_info "TEST 10: SQL Injection Probing"

sqli_fail=0

for payload in "' OR 1=1 --" "'; DROP TABLE orders;--" "\" OR \"\"=\""; do
    encoded=$(python3 -c "import urllib.parse; print(urllib.parse.quote('''$payload'''))" 2>/dev/null || echo "$payload")
    status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/v1/products?search=$encoded" 2>/dev/null)
    [ "$status" = "500" ] && ((sqli_fail++)) || true
done

# Sort injection on discounts
sort_status=$(curl -s -o /dev/null -w "%{http_code}" \
    "$BASE_URL/v1/discounts?sort=created_at;DROP+TABLE+discounts" 2>/dev/null)
[ "$sort_status" = "500" ] && ((sqli_fail++)) || true

if [ "$sqli_fail" -eq 0 ]; then
    log_pass "SQL injection probes handled without server errors"
else
    log_fail "SQL injection probes caused $sqli_fail server errors"
fi

# ─────────────────────────────────────────────────
# 11. 2FA BRUTE FORCE
# ─────────────────────────────────────────────────
separator
log_info "TEST 11: 2FA Code Brute Force (10 rapid attempts)"

burst_test \
    "$BASE_URL/v1/two-factor/verify" \
    "POST" \
    '{"code":"123456","email":"brute@test.com"}' \
    10 \
    "2FA verification rate limit"

# ─────────────────────────────────────────────────
# 12. GLOBAL RATE LIMIT
# ─────────────────────────────────────────────────
separator
log_info "TEST 12: Global API Rate Limit (burst 130 requests)"

global_429=0
for i in $(seq 1 130); do
    status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/v1/products" 2>/dev/null)
    [ "$status" = "429" ] && ((global_429++)) || true
done

if [ "$global_429" -ge 1 ]; then
    log_pass "Global rate limit triggered ($global_429/130 blocked)"
else
    log_warn "Global rate limit did not trigger in 130 requests"
fi

# ─────────────────────────────────────────────────
# 13. PASSWORD RESET BRUTE FORCE
# ─────────────────────────────────────────────────
separator
log_info "TEST 13: Password Reset Brute Force (10 rapid attempts)"

burst_test \
    "$BASE_URL/forgot-password" \
    "POST" \
    '{"email":"victim@test.com"}' \
    10 \
    "Password reset rate limit"

# ─────────────────────────────────────────────────
# SUMMARY
# ─────────────────────────────────────────────────
echo ""
echo "=============================================="
echo " RESULTS"
echo "=============================================="
echo -e " ${GREEN}PASSED:  $PASS${NC}"
echo -e " ${RED}FAILED:  $FAIL${NC}"
echo -e " ${YELLOW}WARNINGS: $WARN${NC}"
echo "=============================================="

if [ "$FAIL" -gt 0 ]; then
    echo -e "${RED}Security issues found. Fix before deploying.${NC}"
    exit 1
else
    echo -e "${GREEN}All critical checks passed.${NC}"
    exit 0
fi
