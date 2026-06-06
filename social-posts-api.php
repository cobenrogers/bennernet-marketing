<?php
/**
 * Marketing module — Social Posts API endpoint
 *
 * Thin write-operations API for social-posts.php.
 * Handles: publish_now, reschedule, delete, edit_content.
 *
 * Write operations that require DB access use `docker exec` to reach
 * the postiz-postgres container (postgres port is not exposed on
 * localhost — it only binds within the Docker bridge network).
 *
 * POST body: JSON { action, id, content?, publishDate? }
 * Response:  JSON { ok: true } or { ok: false, error: "..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once PORT_ROOT . '/shared/shell.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
try {
    $user = requireModuleAccess('marketing', 'editor');
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// ── Parse request ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── CSRF validation ───────────────────────────────────────────────────────────
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
try {
    validateCsrfToken($csrfToken);
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$raw    = file_get_contents('php://input');
$body   = $raw ? json_decode($raw, true) : null;

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$action = $body['action'] ?? '';
$postId = trim($body['id'] ?? '');

if (!$postId || !preg_match('/^[a-zA-Z0-9_-]+$/', $postId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid post id']);
    exit;
}

// ── Postiz configuration ──────────────────────────────────────────────────────
$postizBaseUrl    = null;
$postizAuthHeader = null;

if (defined('MK_POSTIZ_URL') && MK_POSTIZ_URL !== '' &&
    defined('MK_POSTIZ_TOKEN') && MK_POSTIZ_TOKEN !== '') {
    $postizBaseUrl    = rtrim(MK_POSTIZ_URL, '/');
    $postizAuthHeader = 'Bearer ' . MK_POSTIZ_TOKEN;
} elseif (defined('MK_BRIDGE_URL') && MK_BRIDGE_URL !== '' &&
          defined('MK_BRIDGE_TOKEN') && MK_BRIDGE_TOKEN !== '') {
    $postizBaseUrl    = rtrim(MK_BRIDGE_URL, '/') . '/postiz';
    $postizAuthHeader = 'Bearer ' . MK_BRIDGE_TOKEN;
}

if (!$postizBaseUrl) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Postiz not configured']);
    exit;
}

// ── DB helpers via docker exec ────────────────────────────────────────────────
// The postgres container (postiz-postgres) does NOT expose port 5432 on the host.
// All DB writes go through: docker exec postiz-postgres psql ... -c "SQL"
// NOTE: On Bluehost (production), docker is not available — DB writes will fail
// gracefully. In that case, only publish_now (API-based) will work on production.
// See docs/social-posts-spec.md for details.

/**
 * Execute a SQL command in the postiz-postgres container via docker exec.
 * Returns [ok => bool, output => string, error => string].
 */
function mkPostizExecSql(string $sql): array {
    // Escape double-quotes in SQL for shell safety — use single-quoted heredoc approach
    // We pass the SQL via stdin to avoid shell injection on the SQL itself.
    // docker exec -i allows stdin passthrough.
    $safeContainer = 'postiz-postgres';
    $cmd = 'docker exec -i ' . escapeshellarg($safeContainer)
         . ' psql -U postiz-user postiz-db-local -t -A 2>&1';

    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'output' => '', 'error' => 'Failed to launch docker exec'];
    }
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        return ['ok' => false, 'output' => $stdout, 'error' => trim($stderr ?: $stdout)];
    }
    return ['ok' => true, 'output' => trim($stdout), 'error' => ''];
}

/**
 * Fetch a single column value from the Post table for a given post ID.
 * Queries one column at a time to avoid pipe/newline parsing issues.
 * Returns the trimmed string value, or null if not found or on error.
 */
function mkPostizFetchSingleField(string $id, string $column): ?string {
    $safeId = str_replace("'", "''", $id);
    $sql    = "SELECT " . $column . " FROM \"Post\" "
            . "WHERE id = '" . $safeId . "' AND \"deletedAt\" IS NULL LIMIT 1;";
    $result = mkPostizExecSql($sql);
    if (!$result['ok'] || $result['output'] === '') {
        return null;
    }
    // psql -t -A outputs exactly one line for a single-column query.
    // Take only the first line to guard against any trailing whitespace.
    $line = explode("\n", $result['output'])[0];
    return trim($line) !== '' ? trim($line) : null;
}

// ── Postiz API helper (POST) ──────────────────────────────────────────────────

/**
 * POST to the Postiz public API.
 * Returns decoded response array or null on error.
 */
function mkPostizApiPost(string $url, array $payload, string $authHeader): ?array {
    $jsonBody = json_encode($payload);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: {$authHeader}\r\n"
                         . "Content-Type: application/json\r\n"
                         . "User-Agent: bennernet-marketing/1.0\r\n"
                         . 'Content-Length: ' . strlen($jsonBody),
        'content'       => $jsonBody,
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    return json_decode($body, true) ?: null;
}

// ── Route actions ─────────────────────────────────────────────────────────────

switch ($action) {

    // ── edit_content: UPDATE content for a DRAFT post ────────────────────────
    case 'edit_content': {
        $content = $body['content'] ?? null;
        if ($content === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing content']);
            exit;
        }
        $safeId      = str_replace("'", "''", $postId);
        $safeContent = str_replace("'", "''", $content);
        $sql = "UPDATE \"Post\" SET content = '" . $safeContent . "' "
             . "WHERE id = '" . $safeId . "' AND state = 'DRAFT' AND \"deletedAt\" IS NULL;";
        $result = mkPostizExecSql($sql);
        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'DB update failed: ' . $result['error']]);
            exit;
        }
        echo json_encode(['ok' => true]);
        break;
    }

    // ── reschedule: UPDATE publishDate for a DRAFT/QUEUE post ────────────────
    case 'reschedule': {
        $publishDate = trim($body['publishDate'] ?? '');
        if (!$publishDate) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing publishDate']);
            exit;
        }
        // Accept datetime-local format (YYYY-MM-DDTHH:MM) or ISO
        $ts = strtotime($publishDate);
        if (!$ts) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid publishDate']);
            exit;
        }
        if ($ts <= time()) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Schedule date must be in the future']);
            exit;
        }
        $isoDate    = date('c', $ts);
        $safeId     = str_replace("'", "''", $postId);
        $safeDt     = str_replace("'", "''", $isoDate);
        $sql = "UPDATE \"Post\" SET \"publishDate\" = '" . $safeDt . "', state = 'QUEUE' "
             . "WHERE id = '" . $safeId . "' AND state IN ('DRAFT','QUEUE') AND \"deletedAt\" IS NULL;";
        $result = mkPostizExecSql($sql);
        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'DB update failed: ' . $result['error']]);
            exit;
        }
        echo json_encode(['ok' => true, 'publishDate' => $isoDate]);
        break;
    }

    // ── delete: soft-delete a DRAFT post ─────────────────────────────────────
    case 'delete': {
        $safeId = str_replace("'", "''", $postId);
        $sql = "UPDATE \"Post\" SET \"deletedAt\" = NOW() "
             . "WHERE id = '" . $safeId . "' AND state = 'DRAFT' AND \"deletedAt\" IS NULL;";
        $result = mkPostizExecSql($sql);
        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'DB delete failed: ' . $result['error']]);
            exit;
        }
        echo json_encode(['ok' => true]);
        break;
    }

    // ── publish_now: post immediately via Postiz public API ──────────────────
    // Strategy:
    //   1. Read state, integrationId, image from DB (separate queries — safe against pipe chars)
    //   2. Soft-delete the draft
    //   3. POST new post via Postiz API with type=now
    //   On step 3 failure: restore the draft (rollback) so the user doesn't lose the post.
    case 'publish_now': {
        $contentOverride = $body['content'] ?? null;
        $safeId          = str_replace("'", "''", $postId);

        // Read required fields individually (C2: avoids pipe-split corruption)
        $state         = mkPostizFetchSingleField($postId, 'state');
        $integrationId = mkPostizFetchSingleField($postId, '"integrationId"');
        $dbContent     = mkPostizFetchSingleField($postId, 'content');
        $imageRaw      = mkPostizFetchSingleField($postId, 'image');

        if ($state === null) {
            http_response_code(503);
            echo json_encode([
                'ok'    => false,
                'error' => 'Could not read post from DB (docker exec may be unavailable on this host).',
            ]);
            exit;
        }

        if (!in_array($state, ['DRAFT', 'QUEUE'], true)) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Post is not in a publishable state: ' . $state]);
            exit;
        }

        if (!$integrationId) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Post has no integration ID']);
            exit;
        }

        $content = $contentOverride ?? $dbContent ?? '';

        // Step 1: soft-delete the draft
        $delSql = "UPDATE \"Post\" SET \"deletedAt\" = NOW() "
                . "WHERE id = '" . $safeId . "' AND \"deletedAt\" IS NULL;";
        $delResult = mkPostizExecSql($delSql);
        if (!$delResult['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to retire old draft: ' . $delResult['error']]);
            exit;
        }

        // Step 2: POST new post via Postiz API with type=now
        $apiUrl  = $postizBaseUrl . '/api/public/v1/posts';
        $payload = [
            'type'      => 'now',
            'shortLink' => false,
            'tags'      => [],
            'posts'     => [[
                'integration' => ['id' => $integrationId],
                'value'       => [['content' => $content]],
            ]],
        ];

        // Include image if the original post had one (H1: don't drop media)
        if ($imageRaw) {
            $imageArr = json_decode($imageRaw, true);
            if (is_array($imageArr) && !empty($imageArr)) {
                $payload['posts'][0]['value'][0]['image'] = $imageArr;
            } elseif (is_string($imageRaw) && str_starts_with($imageRaw, 'http')) {
                $payload['posts'][0]['value'][0]['image'] = [
                    ['id' => $imageRaw, 'url' => $imageRaw, 'path' => $imageRaw],
                ];
            }
        }

        $response = mkPostizApiPost($apiUrl, $payload, $postizAuthHeader);
        if (!$response) {
            // H2: Rollback — restore the draft so the user doesn't lose the post
            $restoreSql = "UPDATE \"Post\" SET \"deletedAt\" = NULL "
                        . "WHERE id = '" . $safeId . "';";
            mkPostizExecSql($restoreSql);
            http_response_code(502);
            echo json_encode([
                'ok'    => false,
                'error' => 'Postiz API call failed — draft has been restored. Check Postiz logs and retry.',
            ]);
            exit;
        }

        echo json_encode(['ok' => true, 'postiz_response' => $response]);
        break;
    }

    default: {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
        break;
    }
}
