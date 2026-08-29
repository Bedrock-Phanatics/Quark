#!/bin/bash

set -e

# Detect unowned files (this is a common docker issue)
BAD_COUNT=$(find /data /plugins ! -user quark | wc -l)
if [ "$BAD_COUNT" -gt 0 ]; then
	echo "=== WARNING ==="
	echo "Detected $BAD_COUNT files in /data or /plugins not owned by the user \"quark\"!"
	echo "For example:"
	find /data /plugins ! -user quark | head
	echo "This may cause problems when running the server."
	echo "If you mount /data and /plugins from a local directory, consider running \`chown -R 1000:1000 \$MOUNT\` (replace \$MOUNT with your local directory) (you may need sudo to run this)"
	echo "==============="
	# Continue running the server since this is not necessarily fatal
fi

# Run the server
cd /opt/quark
read -ra pm_args <<<"$QUARK_ARGS"
exec php Quark.phar --no-wizard --enable-ansi --data=/data --plugins=/plugins "${pm_args[@]}"
