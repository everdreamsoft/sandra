#!/bin/bash
# Sandra Agent — Session Start Hook
#
# Runs at the beginning of each Claude Code session.
# Reads the user's first message and searches Sandra for relevant context.
# Injects found context so the agent starts with memory loaded.
#
# Install: copy to .claude/hooks/ and add to settings.json:
#   "SessionStart": [{"hooks": [{"type": "command", "command": ".claude/hooks/session-recall.sh"}]}]

# Read input from stdin (Claude provides session info as JSON)
INPUT=$(cat /dev/stdin 2>/dev/null || echo '{}')

# Extract the session prompt if available
PROMPT=$(echo "$INPUT" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    print(data.get('prompt', data.get('message', '')))
except:
    print('')
" 2>/dev/null)

# If no prompt, just provide a generic memory note
if [ -z "$PROMPT" ]; then
    cat <<'ENDJSON'
{
  "hookSpecificOutput": {
    "hookEventName": "SessionStart",
    "additionalContext": "Sandra memory agent active. Use sandra_semantic_search or sandra_search to recall context when the user mentions names, topics, or asks about past information."
  }
}
ENDJSON
    exit 0
fi

# If we have a prompt, suggest the agent search for context
cat <<ENDJSON
{
  "hookSpecificOutput": {
    "hookEventName": "SessionStart",
    "additionalContext": "Sandra memory agent active. The user's message may reference people, projects or topics you know about. Search Sandra for relevant context before responding."
  }
}
ENDJSON
