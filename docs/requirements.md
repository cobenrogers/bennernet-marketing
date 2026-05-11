---
freshness: active
---

# Marketing Module — Requirements

_Owner: Pop-Mark. Last updated: 2026-05-11._

This is the full requirements doc for the **Marketing Port module** at `bennernet.com/port/marketing/`. It is filed per the [cross-cutting spec (§7)](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/projects/mission-control/requirements.md#7-per-module-requirements-handoff) and covers what the module surfaces, where the data comes from, what actions exist, and how permissions, schema, and the Mission Control tile are defined.

Cross-cutting decisions (build order, connectivity, cache conventions, authentication, design system) are inherited from [`projects/mission-control/requirements.md`](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/projects/mission-control/requirements.md) and are not duplicated here.

---

## 1. Module Identity

| Field | Value |
|-------|-------|
| **Slug** | `marketing` |
| **Name** | Marketing |
| **URL** | `bennernet.com/port/marketing/` |
| **Lucide icon** | `megaphone` |
| **Owner** | Pop-Mark |
| **Build order priority** | v0 module #3 — ships after Operations |
| **Repo** | `cobenrogers/bennernet-marketing` (this repo) |

### module.json

```json
{
  "slug": "marketing",
  "name": "Marketing",
  "icon": "megaphone",
  "url": "/port/marketing/",
  "owner": "pop-mark",
  "version": "0.1.0",
  "tileEndpoint": "/port/marketing/api/tile",
  "permissions": ["viewer", "editor", "admin"]
}
```

---

## 2. Views

The module has one dashboard tile (consumed by Mission Control) and five sub-views accessible within the module itself.

### 2.1 Dashboard Tile

The tile is the Marketing module's face on Mission Control's tile grid. See [§7 Tile Contract](#7-tile-contract) for the full `GET /tile` spec.

### 2.2 Drafts Queue

**What it shows:** All draft content files currently in `glyc/docs/marketing/workspace/queue/`, organized by platform sub-directory (`bluesky/`, `reddit/`, `linkedin/`, etc.). Each row shows: filename, platform badge, created date (inferred from filename or git log), and a link to render the markdown content in a side panel.

**Data source:** `cobenrogers/glyc` repo — GitHub Contents API, directory listing of `docs/marketing/workspace/queue/` and its sub-directories.

**Cache TTL:** 2 minutes. Stale display: show cached list with "last refreshed HH:MM" label; no blank state.

**Interactions (v0):** Read-only. Click a row to open the draft markdown in a rendered side panel.

**v0.5+ write path:** "Push to Postiz as DRAFT" button per row — calls Postiz `POST /api/public/v1/posts` with `state=DRAFT` for the appropriate integration ID. Requires `editor` permission. See [§4 Write Actions](#4-write-actions).

### 2.3 Published Archive

**What it shows:** All files in `glyc/docs/marketing/workspace/published/`, organized by platform sub-directory. Each row: filename, platform badge, published date, link to render. The published archive is the voice reference pool — posts here have been green-lit and posted externally.

**Data source:** `cobenrogers/glyc` — GitHub Contents API on `docs/marketing/workspace/published/`.

**Cache TTL:** 5 minutes. These files change infrequently.

**Interactions (v0):** Read-only. Click to render.

### 2.4 Engagement Timeline

**What it shows:** A reverse-chronological log of published posts and their engagement metrics, pulled from `glyc/docs/marketing/workspace/tracking/engagement-log.md`. Each entry: platform, post slug or title, publish date, engagement score (upvotes / reactions / impressions / clicks — whatever the engagement-log row contains). Rows that have UTM data also show a "GA sessions" column (pulled from the engagement-log; Pop-Mark writes these in after checking analytics).

**Data source:** `cobenrogers/glyc` — GitHub Contents API on `docs/marketing/workspace/tracking/engagement-log.md`, parsed as markdown table.

**Cache TTL:** 5 minutes.

**Interactions (v0):** Read-only. Filter by platform. Click a row to open the corresponding published file in a side panel (best-effort match by filename slug).

**Note on real-time analytics:** Postiz `/analytics/{integrationId}` and `/analytics/post/{postId}` return empty for Bluesky and Mastodon (platform API limitation — see [ops/postiz.md quirk #12](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/ops/postiz.md#quirks-worth-remembering)). The engagement-log.md markdown table remains the source of truth for engagement data until platform-native API paths are validated per-channel. The timeline view renders from that file; it does not call Postiz analytics in v0.

### 2.5 GA / Search Console Summary

**What it shows:** A summary card per property (getglyc.com, ibdmovement.com) with: date range selector (last 7 / 30 / 90 days), total clicks, total impressions, average CTR, average position, and a top-10 queries table. A second panel shows sitemap status and any coverage errors flagged by Search Console.

**Data source:** Google Search Console API, via the `gsc` skill (`python3 ~/.openclaw/skills/gsc/scripts/gsc.py performance <site-url>`) invoked through the OpenClaw gateway bridge. Site URLs: `sc-domain:getglyc.com`, `sc-domain:ibdmovement.com`.

**Cache TTL:** 30 minutes. GSC data is not real-time; staleness up to 30 minutes is acceptable. Show "last refreshed HH:MM" label.

**Stale/offline behavior:** Show last cached response with degraded-mode banner. Never show blank. GSC credentials live on the Linux box; when the bridge is unreachable, Marketing renders all views from cache per the cross-cutting cache policy.

**Interactions (v0):** Read-only. Date range selector triggers a fresh fetch (bypasses TTL, rate-limited to max 1 request per 60 seconds per property).

### 2.6 SEO Scratchpad

**What it shows:** A rendered view of `glyc/docs/marketing/workspace/research/` files, filterable by keyword (filename search). This is the working research area — weekly scan files (`YYYY-MM-DD-weekly-scan.md`), daily surface files (`YYYY-MM-DD-daily.md`), and any ad-hoc keyword/competitor research files Pop-Mark writes there.

**Data source:** `cobenrogers/glyc` — GitHub Contents API on `docs/marketing/workspace/research/`.

**Cache TTL:** 5 minutes.

**Interactions (v0):** Read-only. Search/filter by filename. Click to render markdown in side panel.

### 2.7 Social Campaign Status

**What it shows:** A unified view of posts in all non-PUBLISHED states across Postiz-connected integrations. Columns: platform (icon + name), post preview (first 80 chars), state badge (`DRAFT` / `QUEUE` / `ERROR`), scheduled date (if QUEUE), last updated. Grouped by state: DRAFT at top, QUEUE next, ERROR highlighted.

**Data source:** Postiz REST API — `GET /api/public/v1/posts?startDate=<30-days-ago>&endDate=<30-days-forward>`, filtered client-side to exclude PUBLISHED. Auth: `Authorization: <postiz_api_key>` (no Bearer prefix — see [ops/postiz.md quirk #2](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/ops/postiz.md#quirks-worth-remembering)).

**Cache TTL:** 1 minute. This is the most operationally live view — Ben checks it before deciding what to publish.

**Connected integrations (as of 2026-05-11):**

| Integration ID | Platform | Handle |
|---|---|---|
| `cmouj99190001pi8h1f0upfga` | Bluesky | @bennernet.bsky.social |
| `cmouqqkw70001o08gts5rpnyb` | Mastodon | @glyc@mastodon.social |
| `cmouqudgd0003o08gq5w1q3jj` | Mastodon | @theibdmovement@mastodon.social |

LinkedIn and Reddit are not Postiz-connected in v0; their drafts show in the Drafts Queue (§2.2) from the glyc repo but not in this view.

**Interactions (v0):** Read-only. Filter by platform, filter by state.

**v0.5+ write path:** "Mark as Published" action per QUEUE/DRAFT post — calls Postiz `PUT /api/public/v1/posts/:id/status`. Requires `editor` permission.

---

## 3. Data Sources + Cache TTLs

| Source | Data | Endpoint / Path | Cache TTL | Stale behavior |
|--------|------|-----------------|-----------|----------------|
| `cobenrogers/glyc` (GitHub) | Drafts queue directory listing | GitHub Contents API: `docs/marketing/workspace/queue/` | 2 min | Show cached list + "last refreshed" label |
| `cobenrogers/glyc` (GitHub) | Draft file content | GitHub Contents API: per-file fetch | 5 min | Show cached render |
| `cobenrogers/glyc` (GitHub) | Published archive listing + content | GitHub Contents API: `docs/marketing/workspace/published/` | 5 min | Show cached |
| `cobenrogers/glyc` (GitHub) | Engagement log | GitHub Contents API: `docs/marketing/workspace/tracking/engagement-log.md` | 5 min | Show cached |
| `cobenrogers/glyc` (GitHub) | UTM registry | GitHub Contents API: `docs/marketing/workspace/tracking/utm-registry.md` | 10 min | Show cached |
| `cobenrogers/glyc` (GitHub) | SEO scratchpad / research files | GitHub Contents API: `docs/marketing/workspace/research/` | 5 min | Show cached |
| Postiz API (localhost:4007) | Campaign status — all non-PUBLISHED posts | `GET /api/public/v1/posts?startDate=...&endDate=...` | 1 min | Show cached with "last refreshed" label |
| Postiz API | Per-post analytics | `GET /api/public/v1/analytics/post/:postId` | 15 min | Show cached; note: returns `[]` for Bluesky + Mastodon |
| Postiz API | Integration list | `GET /api/public/v1/integrations` | 10 min | Show cached |
| Google Search Console (`gsc` skill) | Performance data per property | `gsc.py performance sc-domain:getglyc.com` + `sc-domain:ibdmovement.com` | 30 min | Show cached with degraded banner |
| Google Search Console (`gsc` skill) | Sitemap + coverage errors | `gsc.py` sitemap/inspection endpoints | 30 min | Show cached |
| `cobenrogers/mission-control-wiki` (GitHub) | Issues filtered to `marketing` label | GitHub REST API: `GET /repos/cobenrogers/mission-control-wiki/issues?labels=marketing` | 2 min | Show cached |

**Postiz access path from Bluehost:** Postiz runs on the Linux box at `localhost:4007`. Bluehost cannot call it directly. Marketing module Postiz calls go through the OpenClaw gateway bridge (same Tailscale Funnel path as all other Linux box data), via a bridge endpoint such as `GET /marketing/postiz-proxy?path=...`. The bridge handles the actual `localhost:4007/api/public/v1/...` call server-side and returns JSON to Bluehost. The API key lives only on the Linux box (sops `postiz_api_key`); it is never sent to or stored on Bluehost.

**`cobenrogers/glyc` access:** GitHub API calls for the glyc repo go from Bluehost directly to `api.github.com` using the `gh` token, same as other modules' GitHub calls. No Linux box mediation required.

**v0.2 addition:** IBD Movement workspace (`ibdmovement-wp` or equivalent) — drafts and research for the ibdmovement.com surface once the v0.2 runtime split ships and the IBD Movement brand voice doc exists. Data source and path TBD at v0.2 time; placeholder cache TTL: same as glyc workspace equivalents.

---

## 4. Write Actions

### v0 — Read-Only Mirror

The Marketing module is **read-only in v0.** It surfaces what already exists in `glyc/docs/marketing/workspace/` and Postiz — no mutations.

All display-only. No form submission, no state changes, no commits.

### v0.5+ Write Actions (planned, not in scope for v0)

| Action | Trigger | Mechanism | Permission required |
|--------|---------|-----------|---------------------|
| Push draft to Postiz | "Send to Postiz" button on a Drafts Queue row | `POST /api/public/v1/posts` with `type: "draft"`, appropriate integration ID, content from the glyc file | `editor` |
| Mark post as published in Postiz | "Mark Published" on Campaign Status row | `PUT /api/public/v1/posts/:id/status` | `editor` |
| Move glyc file from `queue/` to `published/` | Companion to "Mark Published" | GitHub Contents API: create file in `published/`, delete from `queue/` — requires glyc repo write token | `editor` |
| Refresh GSC data (bypass TTL) | "Refresh" button on GA/GSC view | Invoke bridge endpoint; rate-limited to 1 req/60 sec/property | `viewer` |

**Trust posture note:** The `QUEUE` state (auto-publish at scheduled time) is **out of scope for v0.5**. Only `DRAFT` state creation is permitted. Promotion to `QUEUE` is a v0.3 trust-graduation decision tracked in [mission-control-wiki #19](https://github.com/cobenrogers/mission-control-wiki/issues/19).

---

## 5. Permission Model

Permissions inherited from Port's `port_permissions` table (`viewer` | `editor` | `admin` per module slug).

| Permission | What it grants |
|------------|---------------|
| `viewer` | Read access to all five sub-views. Can trigger GSC data refresh (rate-limited). No mutations. |
| `editor` | All viewer rights. In v0.5+: can push drafts to Postiz, mark posts published, move glyc files queue → published. Not yet relevant in v0 (no write actions exist). |
| `admin` | All editor rights. In future: can configure which glyc workspace sub-directories are surfaced, can add/remove Postiz integration IDs displayed, can manage Marketing module settings. Reserved; no admin-only actions exist in v0 or v0.5. |

Ben is `is_admin = 1` in Port and bypasses all checks.

Future readers (e.g., a contractor reviewing campaign performance) get `marketing:viewer` only — they see the read-only surface with no mutation capability.

---

## 6. Schema

The Marketing module owns all tables under the `marketing_*` prefix in the Port shared database. The first migration creates the `marketing_schema_migrations` tracking table per [Port module-authoring §7](https://github.com/cobenrogers/bennernet-port/blob/main/docs/module-authoring.md#7-migrations).

### 6.1 v0 Schema (cache only)

In v0 the module stores no persistent domain data — the glyc repo and Postiz are the sources of truth. The only module-owned DB state is the cache file manifest (stored as flat JSON files outside `public_html`, not in the database, following the cross-cutting cache convention from the overarching requirements §4).

The schema migration infrastructure is still initialized at install time so future migrations have a clean track:

```sql
-- Migration 0001: initialize tracking table
CREATE TABLE IF NOT EXISTS marketing_schema_migrations (
    version     VARCHAR(14) NOT NULL PRIMARY KEY,
    applied_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO marketing_schema_migrations (version) VALUES ('0001');
```

### 6.2 v0.5+ Tables (planned)

| Table | Purpose |
|-------|---------|
| `marketing_postiz_drafts` | Local index of posts pushed to Postiz from the module UI — postiz post ID, glyc source path, integration ID, push timestamp, last known state |
| `marketing_engagement_cache` | Normalized engagement rows parsed from engagement-log.md, updated on each cache refresh — enables sorting/filtering without re-parsing markdown |
| `marketing_gsc_cache` | Last-fetched GSC performance response per site URL + date range, keyed by `(site, start_date, end_date)` |

These tables are not created in v0. They're noted here so v0.5 migration planning has a clear starting point.

---

## 7. Tile Contract

The Marketing tile is the module's face on Mission Control's dashboard tile grid. Mission Control calls `GET /port/marketing/api/tile` on each dashboard load (TTL: 1 minute, same as Campaign Status).

### 7.1 Status Pill Logic

| Condition | Pill |
|-----------|------|
| Postiz reachable AND at least one draft in queue | `healthy` (green) — "N drafts ready" |
| Postiz reachable AND queue empty | `idle` (neutral) — "Queue empty" |
| Postiz unreachable OR bridge down | `degraded` (amber) — "Postiz offline" |
| GSC unreachable (but Postiz OK) | `degraded` (amber) — "GSC offline" |
| Both Postiz and GSC unreachable | `offline` (red) — "Marketing offline" |

### 7.2 Tile Response Shape

```json
{
  "slug": "marketing",
  "status": "healthy | degraded | offline | idle",
  "primaryMetric": {
    "label": "Drafts queued",
    "value": 3
  },
  "secondaryMetric": {
    "label": "Posts this week",
    "value": 2
  },
  "lastUpdated": "2026-05-11T14:30:00Z",
  "linkTarget": "/port/marketing/"
}
```

`primaryMetric` is the count of files in `glyc/docs/marketing/workspace/queue/` (all sub-directories). `secondaryMetric` is the count of PUBLISHED posts in Postiz in the last 7 days (or from engagement-log if Postiz analytics are unavailable).

### 7.3 Tile Visual

One-line status: status pill + primary metric + secondary metric. No sparkline in v0. On Mission Control's tile grid, Marketing uses the `megaphone` Lucide icon from the shared sprite at `/port/shared/assets/icons/lucide.svg`.

---

## 8. Open Questions / Future Scope

### Near-term (v0.2)

- **IBD Movement surface** — v0.2 ships the Pop-Mark runtime split and adds ibdmovement.com to the drafting surface. The Marketing module's Drafts Queue and Published Archive will need to surface a second workspace root alongside `glyc/docs/marketing/workspace/`. IBD Movement brand voice doc must exist before v0.2 can ship. Tracked in the agent's v0.2 scope notes ([pop-mark.md §Status & Scope](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/agents/pop-mark.md#status--scope)).

- **Reddit re-application** — Reddit developer access was denied 2026-05-06 (non-commercial registration + health subreddit extra scrutiny). Manual posting is preserved. The Campaign Status view shows Reddit drafts from the glyc repo but cannot push to Postiz for Reddit. Revisit after Glyc has its first paying-customer cohort or when Reddit's commercial-tier conversation is viable. The module should make it easy to add Reddit as a Postiz integration once access is granted — no code changes needed, just an integration ID.

- **Posting-authority graduation** — v0.3 decision: when does Pop-Mark earn `QUEUE` state (auto-publish at scheduled time) instead of `DRAFT`? Per-platform criteria TBD. Tracked in [mission-control-wiki #19](https://github.com/cobenrogers/mission-control-wiki/issues/19). The module surface is graduation-ready — state display already differentiates DRAFT vs QUEUE; write actions for QUEUE state just need to be un-gated.

### Medium-term

- **Platform-native engagement APIs** — Postiz analytics return empty for Bluesky and Mastodon ([ops/postiz.md quirk #12](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/ops/postiz.md#quirks-worth-remembering)). The Engagement Timeline falls back to engagement-log.md today. Once platform-native paths are proven (Bluesky AT Protocol `/xrpc/app.bsky.feed.getPostThread`, Mastodon `/api/v1/statuses/:id`), the timeline view can pull live data per post. Medium-priority — the markdown engagement-log is a working fallback.

- **Postiz `/notifications` for reply/comment monitoring** — verified to return empty even with connected platforms ([ops/postiz.md §Public API surface](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/ops/postiz.md#public-api-surface-verified-from-running-instance)). Direct DB read on Postiz `Comments`/`Mentions` tables is the documented fallback. Surface this in the module once the bridge exposes a Postiz DB proxy endpoint.

- **Pop-Intel integration** — once Pop-Intel ships, its research outputs (competitor signals, trend data, keyword opportunities) may feed the SEO Scratchpad view. The SEO Scratchpad is designed as a file-browser over the glyc research directory today; if Pop-Intel writes to a different workspace, the view needs to union both. Architecture decision deferred to when Pop-Intel exists.

- **LinkedIn connect** — LinkedIn is on Tier-1 hold pending OAuth app review. When the hold lifts, register the OAuth app, set `LINKEDIN_CLIENT_ID`/`LINKEDIN_CLIENT_SECRET` in Postiz's `docker-compose.override.yaml`, and add the integration ID to the Campaign Status view's integration list. No Marketing module code changes required.

- **Cross-channel coordination with Pop-Op** — when SNAM publishes content to ibdmovement.com (Pop-Op surface), Pop-Mark may want to amplify. Boundary: Pop-Op operates SNAM as infra; Pop-Mark consumes SNAM output as marketing data. A future "SNAM recent publications" row in the Drafts Queue or SEO Scratchpad view could surface this.

### Architecture questions

- **Postiz DB proxy endpoint in bridge** — Campaign Status and future write actions need the bridge to proxy Postiz API calls. The v0 bridge spec is read-only and limited to gateway + heartbeat endpoints. Marketing needs a narrow Postiz proxy surface added to the bridge: `GET /marketing/postiz?path=/api/public/v1/posts...` → proxied to localhost:4007 with API key injected server-side. This is a bridge expansion, not a Marketing module change. Track in the bridge ticket.

- **glyc repo write token on Bluehost** — v0.5+ write actions (move files queue → published) require a GitHub token with write access to `cobenrogers/glyc`. This token must not be the same as the read-only token used for display fetches. Separate fine-grained PAT, stored in sops on the Linux box and surfaced to Bluehost via the bridge on authenticated write requests only. Decide before v0.5 ships.

---

## 9. References

- [Mission Control cross-cutting requirements](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/projects/mission-control/requirements.md) — Tier-1 overarching spec
- [Port module-authoring](https://github.com/cobenrogers/bennernet-port/blob/main/docs/module-authoring.md)
- [Port architecture](https://github.com/cobenrogers/bennernet-port/blob/main/docs/architecture.md)
- [pop-mark.md](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/agents/pop-mark.md) — Pop-Mark agent spec (identity, autonomy matrix, workflows, surface area)
- [ops/postiz.md](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/ops/postiz.md) — Postiz API quirks, integration IDs, self-host superpower
- [glyc/docs/marketing/workspace/](https://github.com/cobenrogers/glyc/tree/main/docs/marketing/workspace) — source-of-truth for drafts, published archive, tracking, research
- [mission-control-wiki issue #27](https://github.com/cobenrogers/mission-control-wiki/issues/27) — this requirements doc's originating issue
- [mission-control-wiki issue #19](https://github.com/cobenrogers/mission-control-wiki/issues/19) — posting-authority graduation decision (Bluesky autonomous publishing)

---

_Initial draft: 2026-05-11. Written by Pop-Mark per Tier-2 autonomous authorization._
