#!/bin/bash
# Debug wrapper: logs ALL stdin/stdout/stderr to files
LOG_DIR="/tmp/sandra-mcp-debug"
mkdir -p "$LOG_DIR"
TS=$(date +%s)

# Tee stdout and stderr to log files while still passing them through
exec 2> >(tee "$LOG_DIR/stderr-$TS.log" >&2)
/opt/homebrew/bin/php /Users/shabanshaame/htdocs/sandra/bin/mcp-server.php \
  | tee "$LOG_DIR/stdout-$TS.log"
