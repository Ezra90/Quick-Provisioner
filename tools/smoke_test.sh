#!/usr/bin/env bash
# Quick-Provisioner smoke test — run on the FreePBX host (or via ssh).
set -euo pipefail

BASE="${QP_BASE:-http://127.0.0.1/admin/modules/quickprovisioner}"
TEST_MAC="${QP_TEST_MAC:-AABBCCDDEEFF}"
TEST_EXT="${QP_TEST_EXT:-101}"
TEST_MODEL="${QP_TEST_MODEL:-T54W}"

pass=0
fail=0

check() {
  local name="$1" url="$2" expect="${3:-200}"
  local code
  code=$(curl -sS -o /tmp/qp_smoke_body -w '%{http_code}' "$url" || echo "000")
  if [ "$code" = "$expect" ]; then
    echo "PASS $name ($code)"
    pass=$((pass + 1))
  else
    echo "FAIL $name expected=$expect got=$code url=$url"
    head -3 /tmp/qp_smoke_body 2>/dev/null || true
    fail=$((fail + 1))
  fi
}

echo "=== Quick-Provisioner smoke test ==="
echo "BASE=$BASE MAC=$TEST_MAC"

# Ensure test device exists (idempotent)
sudo mysql asterisk -e "
INSERT INTO quickprovisioner_devices (mac, model, extension, keys_json, contacts_json, custom_options_json, prov_username, prov_password)
VALUES ('$TEST_MAC', '$TEST_MODEL', '$TEST_EXT', '[]', '[]', '{}', 'qp${TEST_EXT}', 'smoketest')
ON DUPLICATE KEY UPDATE model='$TEST_MODEL', extension='$TEST_EXT';
" 2>/dev/null || true

check "bootstrap unknown mac" "$BASE/bootstrap.php?mac=000000000000" "404"
check "provision query mac" "$BASE/provision.php?mac=$TEST_MAC" "200"
check "provision path cfg" "$BASE/provision.php/${TEST_MAC}.cfg" "200"
check "provision path xml" "$BASE/provision.php/${TEST_MAC}.xml" "200"

if grep -q "static.auto_provision.server.url" /tmp/qp_smoke_body 2>/dev/null; then
  echo "PASS cfg contains auto_provision.server.url"
  pass=$((pass + 1))
else
  echo "FAIL cfg missing auto_provision.server.url"
  fail=$((fail + 1))
fi

if grep -q "linekey.1.type" /tmp/qp_smoke_body 2>/dev/null || grep -q "account.1" /tmp/qp_smoke_body 2>/dev/null; then
  echo "PASS cfg has account/linekey markers"
  pass=$((pass + 1))
else
  echo "WARN cfg may be empty template (no keys programmed)"
fi

echo "=== Results: $pass passed, $fail failed ==="
[ "$fail" -eq 0 ]
