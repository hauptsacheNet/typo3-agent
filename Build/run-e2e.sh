#!/usr/bin/env bash
set -euo pipefail

#
# Run the Playwright E2E test suite against a local TYPO3 instance.
#
# Boots a full TYPO3 backend (SQLite, PHP built-in server) with the agent
# extension configured against a deterministic fake LLM server, then runs
# Playwright. Modeled on Build/runTests.sh -s e2e --no-docker in
# hn/typo3-mcp-server.
#
# Usage:
#   Build/run-e2e.sh                    # full suite
#   Build/run-e2e.sh --ui               # Playwright UI mode
#   Build/run-e2e.sh -g "tool call"     # filter by test title
#
# Environment:
#   TYPO3_E2E_PORT      TYPO3 web server port (default 8080)
#   FAKE_LLM_PORT       fake LLM server port (default 8089)
#

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WEB_HOST="127.0.0.1"
WEB_PORT="${TYPO3_E2E_PORT:-8080}"
WEB_URL="http://${WEB_HOST}:${WEB_PORT}"
LLM_PORT="${FAKE_LLM_PORT:-8089}"
LLM_URL="http://${WEB_HOST}:${LLM_PORT}"

command -v php >/dev/null 2>&1 || { echo "Error: php is required." >&2; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "Error: composer is required." >&2; exit 1; }
command -v npx >/dev/null 2>&1 || { echo "Error: npx (Node.js) is required." >&2; exit 1; }
php -r 'exit(extension_loaded("pdo_sqlite")?0:1);' \
    || { echo "Error: PHP extension pdo_sqlite is required." >&2; exit 1; }

WEB_PID=""
LLM_PID=""
cleanup() {
    set +e
    [ -n "${WEB_PID}" ] && kill "${WEB_PID}" >/dev/null 2>&1
    [ -n "${LLM_PID}" ] && kill "${LLM_PID}" >/dev/null 2>&1
    set -e
}
trap cleanup EXIT

cd "${ROOT_DIR}"
rm -rf var/cache var/log config/system/settings.php config/system/additional.php var/*.db 2>/dev/null || true

if [ ! -d vendor ]; then
    echo "Installing composer dependencies..."
    composer install --no-interaction --prefer-dist -q
fi

echo "Setting up TYPO3 (SQLite)..."
vendor/bin/typo3 setup \
    --driver=sqlite \
    --dbname="${ROOT_DIR}/var/sqlite.db" \
    --admin-username=admin \
    --admin-user-password=Admin123! \
    --admin-email=admin@example.com \
    --project-name=typo3-agent-e2e \
    --create-site="${WEB_URL}/" \
    --server-type=other \
    --no-interaction \
    --force >/dev/null

# Relax trusted hosts for the built-in server and point the agent extension
# at the fake LLM server.
LLM_URL="${LLM_URL}" php -r '
    $s = include "config/system/settings.php";
    $s["SYS"]["trustedHostsPattern"] = ".*";
    $s["SYS"]["devIPmask"] = "*";
    $s["EXTENSIONS"]["agent"] = [
        "apiUrl" => getenv("LLM_URL") . "/v1/",
        "apiKey" => "e2e-test-key",
        "model" => "fake-model",
        "systemPrompt" => "You are a helpful TYPO3 assistant used in an E2E test.",
        "maxIterations" => 10,
        "reasoningEffort" => "off",
    ];
    file_put_contents("config/system/settings.php", "<?php\nreturn " . var_export($s, true) . ";\n");
'
rm -rf var/cache

echo "Seeding E2E fixtures (workspace)..."
# TYPO3 setup derives its own sqlite filename — resolve it from settings.php.
DB_PATH="$(php -r '$s = include "config/system/settings.php"; echo $s["DB"]["Connections"]["Default"]["path"] ?? "";')"
php Build/tests/e2e-seed.php "${DB_PATH}"

echo "Starting fake LLM server at ${LLM_URL}..."
mkdir -p var/log
php -S "${WEB_HOST}:${LLM_PORT}" Build/tests/fake-llm-server.php \
    >"${ROOT_DIR}/var/log/fake-llm.log" 2>&1 &
LLM_PID=$!

echo "Starting TYPO3 web server at ${WEB_URL}..."
# Multiple workers: the SSE stream holds one worker busy while the browser
# fetches assets and the cancel endpoint.
PHP_CLI_SERVER_WORKERS=4 php -S "${WEB_HOST}:${WEB_PORT}" -t public/ \
    >"${ROOT_DIR}/var/log/typo3-e2e-web.log" 2>&1 &
WEB_PID=$!

echo "Waiting for TYPO3..."
for i in $(seq 1 60); do
    if ! kill -0 "${WEB_PID}" 2>/dev/null; then
        echo "Web server exited unexpectedly. Logs:" >&2
        tail -30 "${ROOT_DIR}/var/log/typo3-e2e-web.log" >&2
        exit 1
    fi
    if curl -sf "${WEB_URL}/typo3/" -o /dev/null 2>&1; then
        echo "TYPO3 is ready."
        break
    fi
    if [ "$i" -eq 60 ]; then
        echo "TYPO3 web server timeout. Logs:" >&2
        tail -30 "${ROOT_DIR}/var/log/typo3-e2e-web.log" >&2
        exit 1
    fi
    sleep 1
done

echo "Running Playwright tests..."
cd "${ROOT_DIR}/Build"
if [ ! -d node_modules/@playwright ]; then
    npm ci
fi
# Idempotent: a no-op once the matching browser is cached.
npx playwright install chromium >/dev/null 2>&1 || true
TYPO3_BASE_URL="${WEB_URL}" CI="${CI:-}" npx playwright test "$@"
