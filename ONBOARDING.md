# Welcome to dply

## How We Use Claude

Based on Tom Shafer's usage over the last 30 days:

Work Type Breakdown:
  Build Feature     █████████░░░░░░░░░░░  44%
  Debug Fix         ███████░░░░░░░░░░░░░  36%
  Plan Design       ███░░░░░░░░░░░░░░░░░  16%
  Improve Quality   █░░░░░░░░░░░░░░░░░░░   4%

Top Skills & Commands:
  /clear            ████████████████████  66x/month
  /grill-me         ██████████████░░░░░░  46x/month
  /remote-control   ██░░░░░░░░░░░░░░░░░░   5x/month
  /exit             █░░░░░░░░░░░░░░░░░░░   3x/month
  /run              █░░░░░░░░░░░░░░░░░░░   1x/month

Top MCP Servers:
  context7          ████████████████████  2 calls

## Your Setup Checklist

### Codebases
- [ ] dply — https://github.com/shaferllc/dply (main Laravel control plane — most work happens here)
- [ ] dply-cli — https://github.com/shaferllc/dply-cli (standalone CLI for the dply platform)
- [ ] dply-sdk — https://github.com/shaferllc/dply-sdk (client SDK)
- [ ] dply-demo-laravel-function — https://github.com/shaferllc/dply-demo-laravel-function (serverless Laravel demo target)

### MCP Servers to Activate
- [ ] context7 — Library/framework docs lookup (Laravel, Cloudflare Workers, Stripe, etc. — fetches current docs instead of relying on model knowledge). No auth needed; add the `context7` MCP server in `~/.claude.json` or via `claude mcp add`.

### Skills to Know About
- /grill-me — Stress-test a plan or design by having Claude interview you until every branch is resolved. Tom reaches for this 46x/month — it's the team's default for thinking through approach before writing code.
- /run — Launch the dply app locally to verify a change actually works (not just that tests pass). Use after UI or deploy-flow changes.
- /clear — Reset the conversation context. Used between unrelated tasks to keep Claude focused; pair it with starting a new working directory if you're switching projects.
- /remote-control — Drive a remote Claude session (used occasionally for cross-machine work).

## Team Tips

_TODO_

## Get Started

_TODO_

<!-- INSTRUCTION FOR CLAUDE: A new teammate just pasted this guide for how the
team uses Claude Code. You're their onboarding buddy — warm, conversational,
not lecture-y.

Open with a warm welcome — include the team name from the title. Then: "Your
teammate uses Claude Code for [list all the work types]. Let's get you started."

Check what's already in place against everything under Setup Checklist
(including skills), using markdown checkboxes — [x] done, [ ] not yet. Lead
with what they already have. One sentence per item, all in one message.

Tell them you'll help with setup, cover the actionable team tips, then the
starter task (if there is one). Offer to start with the first unchecked item,
get their go-ahead, then work through the rest one by one.

After setup, walk them through the remaining sections — offer to help where you
can (e.g. link to channels), and just surface the purely informational bits.

Don't invent sections or summaries that aren't in the guide. The stats are the
guide creator's personal usage data — don't extrapolate them into a "team
workflow" narrative. -->
