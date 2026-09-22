# AgentTag

AgentTag is a self-hosted Symfony bot that delegates Mattermost threads to Codex. Each thread gets an isolated persistent workspace and one durable task whose status card evolves from acknowledgement through completion.

## What it does

- Accepts `@Codex` requests from a Mattermost outgoing webhook.
- Immediately creates a Mattermost task card acknowledging receipt and showing that model selection is in progress.
- Uses one ephemeral GPT-5.6 Luna call with low reasoning and a JSON output schema to select an appropriate Luna, Terra, or Sol profile.
- Creates one Mattermost task card and updates it instead of streaming commands or harness events.
- Renders the entire evolving task card as one blockquote so the separately posted answer is visually distinct.
- Shows one Stop button while work is active, keeps the completed step timeline, then posts the answer after it.
- Runs the task directly on the selected model and mirrors its meaningful progress messages into the task card.
- Treats new messages during a task as steering for the same Codex session.
- Persists Codex session UUIDs and resumes them after steering, scheduled wakeups, retries, or worker restarts.
- Supports waiting for CI, reviews, schedules, and other external state through durable Messenger wakeups.
- Downloads files attached to task messages into each run's read-only input directory for Codex to inspect.
- Uploads completed files from each run's reply outbox and attaches them to the final Mattermost post.
- Stores bounded retry policies, task deadlines, notification preferences, redacted logs, events, artifacts, and token usage.
- Runs different Mattermost threads concurrently while serializing work within one thread.

AgentTag is Mattermost-only. Slack, global memory, approvals, and the web admin panel have intentionally been removed.

## Requirements

- PHP 8.4, Composer, PostgreSQL 16 or compatible.
- Codex CLI installed and authenticated for the worker’s Unix user.
- A Mattermost bot token and outgoing webhook token.
- One Symfony web process, one high-priority model-selection worker, and one or more task workers.
- A workspace template containing your `AGENTS.md`, skills, plugins, and shared documentation.

## Configuration

Put production secrets in `.env.local`, never in committed files.

```dotenv
APP_ENV=prod
APP_SECRET=change-this
DEFAULT_URI=https://agentag.example.com
DATABASE_URL="postgresql://app:change-this@127.0.0.1:5432/agentag?serverVersion=16&charset=utf8"
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

AGENTAG_TAG=@Codex
AGENTAG_WORKSPACE_PATH=/srv/agentag/workspace
AGENTAG_CONTEXT_MAX_CHARS=12000
AGENTAG_RUN_TIMEOUT_SECONDS=1200
AGENTAG_REDACTION_PATTERNS=

AGENTAG_MODEL_SELECTION_MODEL=gpt-5.6-luna
AGENTAG_MODEL_SELECTION_TIMEOUT_SECONDS=20
AGENTAG_TASK_DEADLINE_SECONDS=86400
AGENTAG_MAX_RETRIES=2
AGENTAG_RETRY_DELAY_SECONDS=60
AGENTAG_NOTIFICATION_PREFERENCE=milestones

GITHUB_PAT_TOKEN=github_pat_change_this
MATTERMOST_WEBHOOK_TOKEN=change-this
MATTERMOST_BASE_URL=https://mattermost.example.com
MATTERMOST_BOT_TOKEN=change-this
MATTERMOST_RECENT_REPLY_LIMIT=20
```

`GITHUB_PAT_TOKEN` is passed to every Codex CLI process so GitHub MCP servers can authenticate. Define it in `.env.local`; non-interactive workers do not load shell startup files such as `.bashrc`.

### MCP startup timeout and alerts

Codex waits 10 seconds by default for each MCP server to initialize. Set a longer timeout in the root worker's `/root/.codex/config.toml` for every remote server that may be slow to establish OAuth or list its tools:

```toml
[mcp_servers.oa-ecologistes]
url = "https://administrer.lesecologistes.fr/mcp"
startup_timeout_sec = 60
```

AgentTag posts a warning in the Mattermost thread whenever Codex reports an MCP startup failure. The task continues without that server's tools, and the warning intentionally omits the raw error detail to avoid exposing credentials or endpoint internals.

`DEFAULT_URI` must be the public AgentTag origin. Mattermost uses it to call `/integrations/mattermost/action` when a user clicks a task-card button. If AgentTag is on a private address, allow that address in Mattermost’s `AllowedUntrustedInternalConnections` setting.

`AGENTAG_NOTIFICATION_PREFERENCE` accepts `all`, `milestones`, or `completion`. Users can override it per task with phrases such as “notify me only when complete” or “notify me on every update.” A request can set a shorter deadline with “deadline in 3 hours” (minutes, hours, and days are supported).

The webhook posts the initial task card before any Codex call. The preparation worker then calls Codex with `--ephemeral`, `gpt-5.6-luna`, low reasoning, and `--output-schema`. The router minimizes quota usage by sending simple work to Luna and routine agentic or OpenAction MCP-only work to Terra. Coding tasks—including Linear issue implementations—and functional testing use Sol/high, even when their workflow also uses Linear, GitHub, or other OpenAction MCP tools. Sensitive, architectural, or exceptionally complex work uses Sol/xhigh. Explicit model requests are honored. If selection times out, fails, or returns an invalid route, AgentTag safely falls back to Sol/medium and still queues the task.

The selected model and reasoning effort are persisted on the run and passed directly to `codex exec` and `codex exec resume`; the task process does not delegate to another agent. The task card shows the selected profile and rationale before execution. The main task follows the workspace’s French-or-English response policy.

## Workspace layout

`AGENTAG_WORKSPACE_PATH` is a template. AgentTag copies it once per Mattermost thread and deliberately excludes `.git`.

```text
/srv/agentag/
  app/                         # this checkout
  workspace/                   # operator-managed template
    AGENTS.md
    skills/
    .codex-plugin/
    docs/
  runs/session-<hash>/         # persistent workspace per thread
  artifacts/run-<id>/          # internal Codex output
    input-files/               # Mattermost attachments supplied as task input
    reply-files/               # validated files attached to the final reply
```

Repository URLs, safety policy, and domain knowledge belong in the template’s `AGENTS.md`, not application code.

## Mattermost setup and usage

Configure an outgoing webhook that sends matching posts to:

```text
POST https://agentag.example.com/integrations/mattermost/webhook
```

The bot token must be able to read the thread, create and edit its own posts, upload files, show typing, and join public channels where it is invoked. Interactive actions are signed with `APP_SECRET`, kept in Mattermost’s server-side action context, and restricted to the original requester.

Examples:

```text
@Codex fix the billing tests and watch CI
@Codex focus on the backend, ignore the UI
@Codex use repository foo instead
@Codex stop
@Codex retry
@Codex retry from the test step
```

Files attached to the initial request or a later steering message are downloaded before the corresponding Codex turn and exposed through the absolute `input-files` path in the prompt. While a task is running or waiting, a new mentioned message becomes steering for that same task rather than a replacement run. Stop interrupts a running command or immediately stops a task queued for retry, and preserves the workspace for 24 hours. Retry and resume remain available as explicit chat commands. Raw command events never appear in the status card.

The first request in a Mattermost thread selects the model route for that session. Follow-up requests in the same thread reuse that route without running model selection again; a request in a new thread starts a new session and selects its own route.

Codex can keep a task alive by ending a stage with this private protocol (the comment is removed before display):

```html
<!-- agentag:{"action":"wait","seconds":300,"reason":"Waiting for CI"} -->
```

AgentTag schedules the same run, then invokes `codex exec resume <session-uuid>` at the wake time. Doctrine Messenger retains delayed messages and retries across process restarts. A dedicated `acknowledgements` transport keeps model selection ahead of long task executions; the queue name is retained for deployment compatibility.

## Development and verification

```bash
composer install
docker compose up -d database
composer check
```

Health endpoints:

```text
GET /health
GET /ready
```

Useful commands:

```bash
bin/console agentag:config:validate
bin/console agentag:workspace:cleanup --older-than-days=7
bin/console doctrine:migrations:migrate --no-interaction
```

Routine database inspection does not need custom maintenance commands:

```sql
SELECT id, status, title, attempt, wake_at, deadline_at, codex_thread_id
FROM agent_run ORDER BY id DESC LIMIT 50;

SELECT run_id, type, message, created_at
FROM run_event ORDER BY id DESC LIMIT 100;

DELETE FROM inbound_event WHERE received_at < NOW() - INTERVAL '30 days';
```

## Production

Host-based nginx, PHP-FPM, systemd worker, migration, and deployment instructions are in [prod/README.md](prod/README.md). Multiple `agentag-worker@N` instances can process independent threads concurrently.
