# Sandra Memory Agent Setup

Turn Claude Code into a personal assistant with persistent memory powered by Sandra.

## What you get

- **Auto-recall**: the agent searches Sandra for relevant context before responding
- **Auto-save**: new information is memorized automatically during conversations
- **Concept reuse**: the agent follows strict rules to avoid vocabulary pollution
- **Slash commands**: `/recall`, `/save`, `/memory` for manual control

## Prerequisites

1. Sandra MCP server running and connected to Claude Code
2. (Optional) `OPENAI_API_KEY` in `bin/.env` for semantic search

## Installation

### 1. Copy CLAUDE.md to your project

```bash
# Project-level (shared with team)
cp examples/agent/CLAUDE.md /path/to/your/project/CLAUDE.md

# Or user-level (all your projects)
cp examples/agent/CLAUDE.md ~/.claude/CLAUDE.md
```

### 2. Install hooks

```bash
# Create hooks directory in your project
mkdir -p /path/to/your/project/.claude/hooks

# Copy the session hook
cp examples/agent/hooks/session-recall.sh /path/to/your/project/.claude/hooks/
chmod +x /path/to/your/project/.claude/hooks/session-recall.sh
```

Then merge the hooks config into your project's `.claude/settings.json`:

```json
{
  "hooks": {
    "SessionStart": [
      {
        "hooks": [
          {
            "type": "command",
            "command": ".claude/hooks/session-recall.sh"
          }
        ]
      }
    ]
  }
}
```

### 3. Install skills

```bash
# Create skills directory
mkdir -p /path/to/your/project/.claude/skills

# Copy skills
cp examples/agent/skills/*.md /path/to/your/project/.claude/skills/
```

### 4. Backfill embeddings (if using semantic search)

In a Claude Code conversation:

```
sandra_embed_all()
```

This indexes all existing entities for semantic search. Only needed once.

## Usage

### Automatic behavior

Once installed, the agent will:
- Search Sandra for context when you mention names, topics, or past events
- Save new information (people, preferences, facts) without you asking
- Reuse existing concepts instead of creating duplicates

### Manual commands

| Command | What it does |
|---------|-------------|
| `/recall marketing strategy` | Search memory for a specific topic |
| `/save` | Force-save any new info from the current conversation |
| `/memory` | Show everything the agent knows about you |

## Customization

### Changing the persona

Edit `CLAUDE.md` to change the agent's personality, language, or behavior rules.

### Adding more hooks

See Claude Code documentation for available hook events:
- `SessionStart` — beginning of conversation
- `UserPromptSubmit` — each user message
- `PreToolUse` — before any tool call
- `PostToolUse` — after any tool call

### Creating custom skills

Add `.md` files to `.claude/skills/` with YAML frontmatter:

```yaml
---
name: my-skill
description: What this skill does
---

Instructions for the agent...
```

## Architecture

```
Your Project
├── CLAUDE.md                    # Agent behavior instructions
├── .claude/
│   ├── settings.json            # Hooks configuration
│   ├── hooks/
│   │   └── session-recall.sh    # Auto-recall at session start
│   └── skills/
│       ├── recall.md            # /recall command
│       ├── save.md              # /save command
│       └── memory.md            # /memory command
```

Sandra MCP server runs separately. The agent config above tells Claude HOW to use Sandra as memory. Sandra itself remains a neutral, general-purpose graph database.
