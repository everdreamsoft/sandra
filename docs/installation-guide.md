# Sandra Installation Guide

Set up Sandra as your AI memory system with Claude Code.

## Prerequisites

- PHP 8.0+
- MySQL 5.7+ (or MariaDB 10.3+)
- Composer
- Claude Code

## Part 1: Sandra MCP Server

### 1. Clone and install

```bash
git clone https://github.com/everdreamsoft/sandra.git
cd sandra
composer install
```

### 2. Create a MySQL database

```sql
CREATE DATABASE sandra CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sandra'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON sandra.* TO 'sandra'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Configure environment

Create `bin/.env`:

```bash
SANDRA_ENV=myenv
SANDRA_DB_HOST=127.0.0.1
SANDRA_DB=sandra
SANDRA_DB_USER=sandra
SANDRA_DB_PASS=your_password
SANDRA_INSTALL=1
```

`SANDRA_ENV` is a prefix for your tables. Use different values for different environments (e.g. `personal`, `work`, `test`).

`SANDRA_INSTALL=1` auto-creates tables on first boot. Set to `0` after initial setup.

### 4. (Optional) Enable semantic search

Add your OpenAI API key to `bin/.env`:

```bash
OPENAI_API_KEY=sk-your-key-here
```

This enables:
- `sandra_semantic_search` — natural language search across all entities
- `sandra_embed_all` — backfill embeddings on existing data
- Auto-embedding on entity create/update

Cost: ~$0.02 per 1M tokens. Effectively free for personal use. Everything works without it — you just won't have semantic search.

### 5. Start the server

```bash
php bin/mcp-http-server.php
```

You should see:

```
Sandra MCP HTTP server starting on http://127.0.0.1:8090/mcp
```

The server runs in the foreground. Use `tmux`, `screen`, or a systemd service to keep it running in the background.

### 6. Connect to Claude Code

Add Sandra to your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "sandra": {
      "type": "http",
      "url": "http://127.0.0.1:8090/mcp"
    }
  }
}
```

Or add it globally for all projects. In Claude Code:

```bash
claude mcp add sandra --transport http --url http://127.0.0.1:8090/mcp
```

### 7. Verify

Start Claude Code and try:

```
sandra_list_factories()
```

If it returns a list (even empty), Sandra is connected.

### 8. (Optional) Backfill embeddings

If you enabled semantic search and already have data in Sandra:

```
sandra_embed_all()
```

This indexes all existing entities for semantic search. Only needed once.

---

## Part 2: Sandra Memory Agent Skill

The Sandra MCP server gives Claude the **tools**. The skill tells Claude **how to behave** — auto-recall, auto-save, concept reuse.

### 1. Create the skill directory

```bash
mkdir -p ~/.claude/skills/sandra
```

### 2. Create the skill file

Create `~/.claude/skills/sandra/SKILL.md` with this content:

```markdown
---
name: sandra
description: Activate Sandra memory agent — persistent memory, recall, and auto-save
---

# Sandra Memory Agent — Activated

You are now a personal assistant with **persistent memory** via Sandra (MCP).

## LANGUAGE RULE — CRITICAL

All system concepts, verbs, and entity relationships in Sandra are stored in **English**.
The user may speak any language. You MUST:
- **Save** concepts and verbs in English: `likes`, `works_at`, `dislikes`
- **Search** in English: if user says "tomates", search for "%tomato%"
- **Always translate** the user's query to English before searching Sandra
- **Respond** in the user's language, but all Sandra operations happen in English
- When in doubt, search BOTH the original term AND the English translation

## RECALL — ALWAYS SEARCH FIRST

**NEVER say "I don't know" before searching Sandra.**

When the user asks about anything:
1. **FIRST**: search Sandra (translate to English if needed)
   - `sandra_semantic_search` with the English translation of the query
   - `sandra_search` with targeted English patterns ("%keyword%")
2. **THEN**: use `sandra_get_triplets` on found entities to discover relationships
3. **ONLY THEN**: respond — or say you don't know if Sandra returned nothing

Present what you find as things you "remember".

## Auto-Save

When the user shares new information (people, preferences, facts, tasks):
- Check if the info already exists in Sandra (search first)
- Create entities/triplets with **English** concepts and verbs
- Entity reference values (names, descriptions) stay in the user's language
- Do this naturally without asking permission for small facts

### What to memorize
- People: names, roles, relationships, contact info
- Preferences: likes, dislikes, habits
- Companies, projects, partnerships
- Tasks, reminders, deadlines
- Key facts from documents

### What NOT to memorize
- Ephemeral chat
- One-off technical debugging
- Info already in Sandra

## Concept Reuse — CRITICAL

- ALWAYS `sandra_list_concepts("%keyword%")` before creating new concepts
- If `sandra_semantic_search` is available, use it to check for synonyms
- General verbs: `likes` not `likes_chocolate`
- Specificity goes in the triplet target, not the concept name

## Persona

You are a high-end personal assistant. You know the user. Never mention
"Sandra", "graph", "triplets", or "MCP" — you simply "remember".

## First action

Start by loading the user's profile:
1. `sandra_search` for the main user entity (person factory)
2. `sandra_get_triplets` to load their relationships
3. Greet them by name and ask how you can help
```

### 3. Verify

Start a new Claude Code session and type:

```
/sandra
```

The agent should activate, load your profile from Sandra, and greet you by name.

---

## Part 3: Optional — Run Sandra as a background service

### macOS (launchd)

Create `~/Library/LaunchAgents/com.sandra.mcp.plist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.sandra.mcp</string>
    <key>ProgramArguments</key>
    <array>
        <string>/usr/local/bin/php</string>
        <string>/path/to/sandra/bin/mcp-http-server.php</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>/tmp/sandra-mcp-stdout.log</string>
    <key>StandardErrorPath</key>
    <string>/tmp/sandra-mcp-stderr.log</string>
</dict>
</plist>
```

Then:

```bash
launchctl load ~/Library/LaunchAgents/com.sandra.mcp.plist
```

### Linux (systemd)

Create `/etc/systemd/system/sandra-mcp.service`:

```ini
[Unit]
Description=Sandra MCP Server
After=mysql.service

[Service]
ExecStart=/usr/bin/php /path/to/sandra/bin/mcp-http-server.php
Restart=always
User=youruser

[Install]
WantedBy=multi-user.target
```

Then:

```bash
sudo systemctl enable sandra-mcp
sudo systemctl start sandra-mcp
```

---

## Quick Reference

| What | Command |
|------|---------|
| Start server | `php bin/mcp-http-server.php` |
| Activate agent | `/sandra` in Claude Code |
| Search memory | `sandra_search("%keyword%")` |
| Semantic search | `sandra_semantic_search("natural language query")` |
| Backfill embeddings | `sandra_embed_all()` |
| Check server logs | `tail -f /tmp/sandra-mcp-http.log` |

## Troubleshooting

**"Unknown skill: sandra"**
- Skill must be at `~/.claude/skills/sandra/SKILL.md` (not `~/.claude/skills/sandra.md`)
- The directory structure matters: `skills/sandra/SKILL.md`

**`sandra_semantic_search` not available**
- Check that `OPENAI_API_KEY` is in `bin/.env`
- Restart the Sandra MCP server after adding the key
- Start a new Claude Code session (tool list is cached per session)

**Slow entity creation (~2s instead of ~40ms)**
- This is normal with embeddings enabled — the API call takes ~2s
- The embedding is non-blocking: if it fails, the entity is still created

**"No such tool: mcp__sandra__*"**
- Check that the Sandra server is running: `curl http://127.0.0.1:8090/mcp`
- Check `.mcp.json` is in your project root or Sandra is added globally
- Restart Claude Code

**Tables not created**
- Set `SANDRA_INSTALL=1` in `bin/.env` and restart the server
