#!/usr/bin/env bash
set -euo pipefail
php src/Quark.php --version
if output=$(php -n src/Quark.php --version 2>&1); then
	echo "Startup accepted a runtime without native extensions" >&2
	exit 1
fi
grep -F "(bedwire) extension." <<< "$output"
grep -F 'Selected PHP binary does not satisfy some requirements.' <<< "$output"
