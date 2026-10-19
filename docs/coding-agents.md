# AI coding agents

Start the demo stack ({doc}`demo`), open your agent in the repository,
and describe what you want built. `AGENTS.md` is the contract it works
to: it initializes Orbitron before anything else, so it works from this
project's installed Kinetis versions and verified layout rather than
from recalled framework knowledge.

## Getting a session ready

The agent can only initialize on a turn, never between turns, so it
checks on every turn until the `orbitron` tools and resources are
listed. Until then it answers questions about setup and these
instructions, and nothing else.

1. **Complete the stack's initial setup**, `docker compose up --build -d`,
   so the `app` image exists and its vendor volume is populated. The MCP
   server runs in a disposable container built from that image. The
   launcher can do this part itself on a fresh clone (below), but its
   first launch then takes as long as the build and the install.
2. **Reload, restart or reconnect the client** when the MCP configuration
   arrived or changed after the session started, or when an earlier
   launch failed before step 1 completed.
3. **Approve the project-local `orbitron` server** under your client's
   own policy ([Trust and approval](#trust-and-approval)).
4. The agent reads `kinetis://orbitron/context` and
   `kinetis://docs/agent-workflow`, calls `orbitron_inspect`, checks that
   the `checkoutRoot` it reports is this checkout's `pwd -P`, and calls
   `orbitron_verify`.
5. It reports readiness in one line, then gets on with your request:

   ```text
   Orbitron ready — kinetis/framework <installed-version>, layout pass (App\, App\Tests\).
   ```

An agent with a shell does step 1 itself. Steps 2 and 3 are yours: it
cannot reconnect the server it runs inside, or approve itself. When a
step fails it stops, names the step, quotes the error and points you to
[Diagnostics](#diagnostics). A refusal to start is the contract working.

## What is checked in

- `AGENTS.md`, the agent contract and the only place it is written.
  `CLAUDE.md` and `GEMINI.md` import it.
- `bin/orbitron-mcp`, and one configuration per client: `.mcp.json`,
  `.codex/config.toml` and `.gemini/settings.json`. Each registers the
  stdio server `orbitron`, launched as `./bin/orbitron-mcp`, plus the
  Nuxt, Vuetify and Vue documentation servers for frontend work.

`bin/orbitron-mcp` runs `vendor/bin/kinetis-orbitron-mcp` through
`docker compose --progress quiet run --rm -T --no-deps --entrypoint sh`
on the `app`
service: a one-off container from `app`'s image, sharing its project and
vendor mounts but not its lifetime. `--entrypoint sh` skips the
entrypoint's `composer install` on every launch, and `--no-deps` starts
no other service. On a fresh clone the launcher sets itself up: it
copies a missing `backend/.env` from `backend/.env.example` (set
`JWT_SECRET` in it before the app itself boots), and when the vendor
volume has never been populated it runs `composer install` under the
entrypoint's own `vendor/.install.lock`, with its output on stderr, so
it never writes the tree while `app` does. Restarting, recreating or rebuilding
`app` leaves a session connected. The container sees every checkout as
`/workspace/demo/backend`, so the launcher passes the host path in
`KINETIS_ORBITRON_CHECKOUT_ROOT`, which `orbitron_inspect` reports as
`checkoutRoot`. The host needs no PHP and no Composer.

That one server is the whole registration. The Kinetis documentation
arrives on the same connection, as `kinetis://docs/*` pages. Those pages
describe Kinetis `main`, so `orbitron_inspect` and the installed-source
tools stay the authority for anything version-sensitive. The tools read
any installed package, `kinetis/vector` included; `vendor/` is a Docker
volume, so they are also the way to reach it from the host. See
[Orbitron](https://kinetis.dev/docs/orbitron.html) for every tool.

## Trust and approval

The checked-in files register a server and configure nothing else: no
credential, no trust override, no preapproved tool. Your client's own
trust and approval policy applies, and it differs by client. Claude Code
prompts about a project-scoped `.mcp.json` in an interactive session.
Codex reads `.codex/config.toml` only for a trusted project. Gemini CLI
may ignore workspace settings in an untrusted workspace, where a server
without `trust` keeps its default, `false`.

Reload or restart the client when this configuration was added or
changed after the current session started.

## Client differences

| Client | Reads instructions from | Project MCP configuration |
|---|---|---|
| Claude Code | `CLAUDE.md` (imports `AGENTS.md`) | `.mcp.json`, after its project-server prompt |
| Codex | `AGENTS.md` | `.codex/config.toml`, for a trusted project |
| Gemini CLI | `GEMINI.md` (imports `AGENTS.md`) | `.gemini/settings.json`, subject to workspace trust |
| Any other MCP client | `AGENTS.md` | Register `./bin/orbitron-mcp` as a stdio server named `orbitron`. |

## Without MCP

The four Orbitron documents are commands too, for an agent that only has
a shell, or for you:

```console
docker compose exec app vendor/bin/kinetis orbitron:context
docker compose exec app vendor/bin/kinetis orbitron:inspect
docker compose exec app vendor/bin/kinetis orbitron:verify
docker compose exec app vendor/bin/kinetis orbitron:scaffold
```

`orbitron:scaffold --apply` is the only one that writes, and it writes
two fixed files. Inside the container, `orbitron:inspect` reports
`/workspace/demo/backend` as both roots: the container's view, not the
host checkout. See
[Appendix: Orbitron](https://kinetis.dev/docs/appendix-orbitron.html).

## Diagnostics

**The server fails to start on a fresh clone.** Its first launch
builds the `app` image when it is absent and installs the dependencies
into an empty vendor volume, and a client whose server start-up timeout
is shorter reports it as failed. Bring the stack up, then restart the
client:

```console
docker compose up --build -d
docker compose ps
```

**An `app` restart, recreation or rebuild.** It does not disconnect
Orbitron, which runs in its own container. Nothing to do.

**The server was there and is gone.** Docker stopping, or the client
ending its own process, removes the one-off container. Launch or connect
the client again; the stack itself can still be healthy.

**`docker compose down` fails with a network still in use.** A live
session keeps its container on the project network, so `down` removes
`app` and then fails:

```text
Network <project>_default Resource is still in use
```

End the client session, run `down` again, and relaunch the client once
the stack is back up.

**The server shows as disconnected.** Run the launcher yourself. A
working server answers a handshake on stdin:

```console
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"manual","version":"0"}}}' \
    | ./bin/orbitron-mcp
```

A result naming `kinetis-orbitron-mcp` means the launcher and the server
work, and what remains is the client: its trust policy, or a tool
catalogue that has not picked the server up yet.

**A dependency change.** It needs no restart. Orbitron reads the
installed-package inventory on every call, so after a `composer require`
the next `orbitron_inspect` already sees it.

**`orbitron_verify` reports an error.** The `code` of each failed check
names the outcome. This project keeps the layout Orbitron admits, one
`autoload.psr-4` prefix mapped to `src/` and one `autoload-dev.psr-4`
prefix mapped to `tests/`, so an error means `demo/backend/composer.json`
moved away from it. See
[Appendix: Orbitron](https://kinetis.dev/docs/appendix-orbitron.html#orbitron-verify)
for every code.

## See also

- {doc}`contributing` — the tests and checks a change must pass.
- {doc}`demo` — the stack the agent works in.
- [Vector for coding agents](https://kinetis.dev/docs/vector.html) —
  what an agent working on an application that installs Vector reads,
  as `kinetis://docs/vector` from the Kinetis documentation.
