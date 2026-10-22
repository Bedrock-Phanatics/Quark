#!/bin/bash
set -euo pipefail

PM_WORKERS="auto"

while getopts "t:" OPTION 2> /dev/null; do
	case ${OPTION} in
		t)
			PM_WORKERS="$OPTARG"
			;;
		\?)
			break
			;;
	esac
done

#Run-the-server tests
DATA_DIR="$(pwd)/test_data"
PLUGINS_DIR="$DATA_DIR/plugins"

rm -rf "$DATA_DIR"
rm -f Quark.phar
mkdir "$DATA_DIR"
mkdir "$PLUGINS_DIR"

php -d phar.readonly=0 tests/build-plugin-phar.php tests/plugins/TesterPlugin "$PLUGINS_DIR/TesterPlugin.phar"
composer make-server

if [ -f Quark.phar ]; then
	echo Server phar created successfully.
else
	echo Server phar was not created!
	exit 1
fi

cp tests/plugins/TestScriptPlugin.php "$PLUGINS_DIR"

echo -e "stop\n" | php Quark.phar \
  --no-wizard \
  --disable-ansi \
  --disable-readline \
  --debug.level=2 \
  --data="$DATA_DIR" \
  --plugins="$PLUGINS_DIR" \
  --settings.async-workers="$PM_WORKERS" \
  --settings.enable-dev-builds=1 \
  --auto-report.enabled=0

LOG_FILE="$DATA_DIR/logs/server.log"
output=$(grep '\[TesterPlugin\]' "$LOG_FILE" || true)
if [ "$output" == "" ]; then
	echo TesterPlugin failed to run tests, check the logs
	exit 1
fi
if ! grep -Fq 'All tests finished, stopping the server' <<< "$output"; then
	echo TesterPlugin did not finish its tests
	exit 1
fi

result=$(echo "$output" | grep 'Finished' | grep -v 'PASS' || true)
if [ "$result" != "" ]; then
	echo "$result"
	echo Some tests did not complete successfully, changing build status to failed
	exit 1
elif [ "$(grep -c "ERROR\|CRITICAL\|EMERGENCY" "$LOG_FILE")" -ne 0 ]; then
	echo Server log contains error messages, changing build status to failed
	exit 1
else
	echo All tests passed
fi
