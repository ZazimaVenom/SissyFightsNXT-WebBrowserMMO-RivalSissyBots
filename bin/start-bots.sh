#!/usr/bin/env sh
set -eu
SFNXT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
exec "${SFNXT_PHP:-php}" "$SFNXT_DIR/bin/worker.php" --run
