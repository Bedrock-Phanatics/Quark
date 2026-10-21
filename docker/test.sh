#!/bin/bash
set -euo pipefail

IMAGE=${1:?Usage: docker/test.sh IMAGE}
TEST_ROOT=$(mktemp -d)
CONTAINER="quark-smoke-$$"
trap 'docker rm -f "$CONTAINER" >/dev/null 2>&1 || true; sudo rm -rf "$TEST_ROOT"' EXIT
mkdir "$TEST_ROOT/data"
sudo chown 1000:1000 "$TEST_ROOT/data"

run_server() {
	printf 'stop\n' | timeout 120 docker run --rm -i --name "$CONTAINER" \
		-e QUARK_ARGS="--settings.enable-dev-builds=1 --disable-ansi" \
		"$@" "$IMAGE" 2>&1 | tee "$TEST_ROOT/server.log"
	if grep -Eq 'CRITICAL|EMERGENCY|ERROR|axolotl-pm.org/docs|PocketMine-MP' "$TEST_ROOT/server.log"; then
		return 1
	fi
	grep -F 'This server is running Quark version 6.' "$TEST_ROOT/server.log"
	grep -F 'Done (' "$TEST_ROOT/server.log"
}

run_server
run_server -v "$TEST_ROOT/data:/data" -v "$PWD/docker/testsuite/create-data:/plugins:ro"
grep -F 'Test passed: create-data was written' "$TEST_ROOT/server.log"
test "$(cat "$TEST_ROOT/data/data/plugins/data-test/create-data")" = successful
run_server -v "$TEST_ROOT/data:/data" -v "$PWD/docker/testsuite/verify-data:/plugins:ro"
grep -F 'Test passed: create-data is successful' "$TEST_ROOT/server.log"
