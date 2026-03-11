#!/bin/bash
# Wrapper that launches the MCP server with xdebug disabled
# This prevents PHP startup warnings from polluting stderr
exec /opt/homebrew/bin/php -d "xdebug.mode=off" -n \
  -d "extension_dir=/opt/homebrew/lib/php/pecl/20240924" \
  -d "pdo_mysql.default_socket=/tmp/mysql.sock" \
  "$(dirname "$0")/mcp-server.php"
