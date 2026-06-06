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

### Solution: docker exec via proc_open

All DB writes use:

```
docker exec -i postiz-postgres psql -U postiz-user postiz-db-local -t -A
```

SQL is passed via stdin (pipe), which avoids shell-quoting issues and
prevents command injection. Single-quotes in SQL values are escaped by
doubling (`''`), which is standard PostgreSQL.

### Production limitation (Bluehost)

On Bluehost shared hosting, `docker` is not available. This means:

- `edit_content`, `reschedule`, `delete` will fail with a DB error
- `publish_now` will also fail (it needs the integration ID from DB)

These operations are only functional when running on the Pop OS dev server
(local or Tailscale-proxied). On production, the social-posts.php list view
(read-only) will work fine via the Postiz public API.

**Future fix:** Expose the postgres port in the Postiz Docker Compose
(`5432:5432`) or add a thin REST endpoint to the Postiz app itself.

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
Parse JSON body
Route on action:
  edit_content  → mkPostizExecSql(UPDATE "Post" SET content …)
  reschedule    → mkPostizExecSql(UPDATE "Post" SET publishDate, state=QUEUE …)
  delete        → mkPostizExecSql(UPDATE "Post" SET deletedAt=NOW() …)
  publish_now   → mkPostizFetchPost() [DB]
                → mkPostizExecSql(soft-delete old draft)
                → mkPostizApiPost(POST /api/public/v1/posts, type=now)
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

1. **Write ops unavailable on Bluehost** — `docker exec` not available on
   shared hosting. The page is read-only in production. See DB write section.
2. **No image upload** — the inline panel shows the image from the API response
   (`image`/`media` field). Uploading a new image is not implemented.
3. **No pagination** — fetches up to 500 posts in the 60-day window. If the
   volume grows beyond that, add `&page=` pagination or shrink the date window.
4. **publish_now creates a new post** — the old draft is soft-deleted and a
   fresh post is POSTed via the Postiz API. The post ID will change.
5. **Thread posts** — multi-message threads in Postiz are returned as a single
   post object; the textarea shows only the first message. Thread editing is
   not supported.

---

## Config.php — New Constants Required

Add these to `config.php` on the server if not already present:

```php
define('MK_POSTIZ_URL',   'http://localhost:5000');  // or Tailscale URL
define('MK_POSTIZ_TOKEN', 'YOUR_POSTIZ_PUBLIC_API_TOKEN');
```

**No new constants are needed beyond the existing `MK_POSTIZ_URL` and
`MK_POSTIZ_TOKEN`.** The `MK_POSTIZ_DB_PASSWORD` constant is NOT used —
DB access goes through `docker exec psql` (no password needed for
`postiz-user` within the container's trusted local connection).

If `MK_POSTIZ_URL` / `MK_POSTIZ_TOKEN` are not yet in your production
`config.php`, add them. The social-posts.php page will show a warning
notice until they are set.
