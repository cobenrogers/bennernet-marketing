# Social Posts — Feature Spec

## Feature Overview

`social-posts.php` is a journal-style view of all Postiz social media posts
(−30 days to +30 days from today). Posts are grouped by date under datestamp
headers and sorted newest-first. An inline expand panel on each post row
enables editing content, rescheduling, publishing immediately, and deleting
drafts — without leaving the page.

`social-posts-api.php` is the write-operations backend called by the JavaScript
in `social-posts.php` via `fetch()`.

---

## API Endpoints Used

### Postiz Public API (read — social-posts.php)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/public/v1/posts?startDate=…&endDate=…&take=500` | Fetch all posts in window |
| GET | `/api/public/v1/integrations` | Fetch channel integrations for account name display |

Auth: `Authorization: Bearer MK_POSTIZ_TOKEN`

Both calls fall back gracefully to a `.mk-notice--warn` if the API is
unreachable.

### social-posts-api.php (write — called by JS)

| Action | HTTP | Description |
|--------|------|-------------|
| `edit_content` | POST JSON | Update post content in DB (DRAFT only) |
| `reschedule` | POST JSON | Set new `publishDate`, state → QUEUE |
| `delete` | POST JSON | Soft-delete (`deletedAt = NOW()`) DRAFT post |
| `publish_now` | POST JSON | Retire draft in DB, POST new `type=now` post via Postiz API |

Request body: `{ action, id, content?, publishDate? }`
Response: `{ ok: true }` or `{ ok: false, error: "…" }`

---

## DB Write Approach

### Why not direct PDO?

The `postiz-postgres` container does **not** expose port 5432 on `localhost`.
Docker inspect shows:

```
"5432/tcp": null
```

The postgres service only binds within the `postiz_postiz-network` bridge
(container IP `172.18.0.3`). PHP running on the host cannot reach it with
a standard PDO DSN.

### Solution: mission-control-bridge `/postiz-db` endpoint

All DB writes go through `POST /postiz-db` on the mission-control-bridge
(`~/.local/share/mission-control-bridge/bridge.py`). The bridge runs on
Pop OS where docker is available, and executes:

```
docker exec -i postiz-postgres psql -U postiz-user postiz-db-local -t -A
```

SQL is built server-side from a whitelisted set of actions — callers never
send raw SQL. `subprocess.run` is called with a list (not `shell=True`),
eliminating shell injection risk. Single-quotes are escaped by doubling
(`''`), which is standard PostgreSQL escaping.

`social-posts-api.php` calls the bridge endpoint for all DB ops, the same
way it already calls the Postiz public API. Works identically from both
Bluehost production and local.

### Network path: how Bluehost reaches the bridge

The bridge binds to `127.0.0.1:18800`. It is exposed publicly via
**Tailscale Funnel** — Tailscale forwards inbound HTTPS traffic to
`localhost:18800`. `MK_BRIDGE_URL` in `config.php` is the Tailscale Funnel
URL (e.g. `https://pop-os.tail3d9bfc.ts.net`). Bluehost calls that URL;
Tailscale terminates TLS and delivers the request to the bridge process.

**Deployment dependency:** Tailscale must be running on Pop OS and Funnel
must be active for Bluehost production to reach the bridge. If the bridge
is down, all write ops return a 503 with `"Bridge unreachable"`.

### Known caveat: `_fetch_field` truncates multiline content

The bridge's `_fetch_field` helper takes only the first line of psql output.
For the `content` field this would silently truncate posts with newlines in
the content. **This does not affect `publish_now` in practice** because
`publish_now` always uses `contentOverride` from the browser textarea — it
never falls back to the DB content field. Leaving as-is; document here so
it's visible if `content` is ever used as a DB-read fallback in the future.

---

## Component Structure

### social-posts.php

```
PHP (data layer)
  ├── requireModuleAccess('marketing', 'viewer')
  ├── Fetch posts via Postiz API (startDate -30d, endDate +30d, take=500)
  ├── Fetch integrations for account name resolution
  ├── Filter by ?filter= tab (all/draft/scheduled/published/error)
  ├── Group posts by date key (Y-m-d), sort newest-first
  └── Render

HTML structure
  ├── .mk-page-header — title + back link
  ├── .mk-filter-tabs — All / Drafts / Scheduled / Published / Errors
  └── .mk-date-group (per date)
       └── .mk-post-list
            └── <li .mk-post-card> (per post)
                 ├── <button .mk-post-card__summary> — toggle row (platform badge, account, preview, state, time)
                 └── .mk-post-panel — collapsible panel
                      ├── image (or placeholder)
                      ├── releaseURL link (published only)
                      ├── <textarea> — editable content
                      ├── .mk-post-panel__feedback — JS status area
                      └── .mk-post-panel__actions (non-published only)
                           ├── "Save Edits" → edit_content
                           ├── "Post Now" → publish_now
                           ├── datetime-local + "Schedule" → reschedule
                           └── "Delete Draft" (DRAFT state only) → delete
```

### social-posts-api.php

```
requireModuleAccess('marketing', 'editor')  ← editor role required
validateCsrfToken(X-CSRF-Token header)      ← CSRF protection
Parse JSON body
Route on action:
  edit_content  → mkBridgeDbCall(bridge, edit_content)
  reschedule    → validate future date
                → mkBridgeDbCall(bridge, reschedule, isoDate)
  delete        → mkBridgeDbCall(bridge, delete)
  publish_now   → mkBridgeDbCall(bridge, fetch_fields) [state, integrationId, image, content]
                → mkBridgeDbCall(bridge, soft_delete)
                → mkPostizApiPost(POST /api/public/v1/posts, type=now, with image)
                → on API failure: mkBridgeDbCall(bridge, restore) [rollback]
```

---

## CSS / Style Notes

- All scoped styles live in an inline `<style>` block in `social-posts.php`,
  following the pattern used by `index.php`.
- All colors use CSS token variables — no hardcoded hex values.
- Platform badges reuse `.mk-badge--{bluesky,mastodon,linkedin,reddit,twitter,instagram}`.
- State badges use new `.mk-state-badge--{draft,queue,published,error}` classes.
- Panel expand/collapse uses the `max-height` CSS transition trick (`0 → 1200px`)
  with `hidden` attribute toggled by JS. Works without JS for the list view.

---

## Known Limitations

1. **Bridge dependency for write ops** — write ops require the
   mission-control-bridge to be reachable via Tailscale Funnel. If the bridge
   is down, all write actions return 503. Read-only list view always works.
2. **No image upload** — the inline panel shows the image from the API response.
   Uploading a new image is not implemented.
3. **No pagination** — fetches up to 500 posts in the 60-day window. If volume
   grows beyond that, add `&page=` pagination or shrink the date window.
4. **publish_now creates a new post** — the old draft is soft-deleted and a
   fresh post is POSTed via the Postiz API. The post ID will change.
5. **Thread posts** — multi-message threads are returned as a single post
   object; the textarea shows only the first message. Thread editing is not
   supported.
6. **_fetch_field content truncation** — the bridge's `_fetch_field` takes only
   the first line of psql output; multiline post content would be truncated.
   This is benign because `publish_now` always uses the textarea value, never
   falls back to the DB content read. See DB Write Approach section.

---

## Config.php — Required Constants

`social-posts.php` (read) and `social-posts-api.php` (write) both require
`MK_BRIDGE_URL` and `MK_BRIDGE_TOKEN` to be set in `config.php`:

```php
define('MK_BRIDGE_URL',   'https://pop-os.tail3d9bfc.ts.net');  // Tailscale Funnel URL
define('MK_BRIDGE_TOKEN', 'YOUR_BRIDGE_TOKEN');                   // from sops secrets
```

`MK_POSTIZ_URL` / `MK_POSTIZ_TOKEN` are used as a fallback for direct local
access (dev without bridge). On production, the bridge constants take
precedence and the Postiz API is called through the bridge proxy.
