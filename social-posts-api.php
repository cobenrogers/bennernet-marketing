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
require_once __DIR__ . '/social-posts-lib.php';
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

// ── Postiz + bridge configuration ────────────────────────────────────────────
// $postizBaseUrl   — Postiz public API base (for POST /api/public/v1/posts)
// $bridgeBaseUrl   — bridge base URL (for /postiz-db DB writes; always the bridge)
$postizBaseUrl    = null;
$postizAuthHeader = null;
$bridgeBaseUrl    = null;
$bridgeAuthHeader = null;

if (defined('MK_BRIDGE_URL') && MK_BRIDGE_URL !== '' &&
    defined('MK_BRIDGE_TOKEN') && MK_BRIDGE_TOKEN !== '') {
    $bridgeBaseUrl    = rtrim(MK_BRIDGE_URL, '/');
    $bridgeAuthHeader = 'Bearer ' . MK_BRIDGE_TOKEN;
    // Postiz public API goes through the bridge proxy
    $postizBaseUrl    = $bridgeBaseUrl . '/postiz';
    $postizAuthHeader = $bridgeAuthHeader;
} elseif (defined('MK_POSTIZ_URL') && MK_POSTIZ_URL !== '' &&
          defined('MK_POSTIZ_TOKEN') && MK_POSTIZ_TOKEN !== '') {
    // Direct local access (dev without bridge)
    $postizBaseUrl    = rtrim(MK_POSTIZ_URL, '/');
    $postizAuthHeader = 'Bearer ' . MK_POSTIZ_TOKEN;
}

if (!$postizBaseUrl) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Postiz not configured']);
    exit;
}

if (!$bridgeBaseUrl) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Bridge not configured — DB write ops require MK_BRIDGE_URL']);
    exit;
}

// ── DB helpers via bridge ─────────────────────────────────────────────────────
// All DB writes go through the mission-control-bridge /postiz-db endpoint,
// which runs docker exec psql on the Pop OS server (where docker is available).
// This works from both Bluehost production and local — same as Postiz API calls.

/**
 * POST to the bridge /postiz-db endpoint.
 * Returns decoded response array.
 */
function mkBridgeDbCall(string $baseUrl, string $authHeader, array $payload): array {
    $url      = rtrim($baseUrl, '/') . '/postiz-db';
    $jsonBody = json_encode($payload);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: {$authHeader}\r\n"
                         . "Content-Type: application/json\r\n"
                         . 'Content-Length: ' . strlen($jsonBody),
        'content'       => $jsonBody,
        'timeout'       => 12,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return ['ok' => false, 'error' => 'Bridge unreachable'];
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : ['ok' => false, 'error' => 'Invalid bridge response'];
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
        $result = mkBridgeDbCall($bridgeBaseUrl, $bridgeAuthHeader, [
            'action'  => 'edit_content',
            'id'      => $postId,
            'content' => $content,
        ]);
        if (!($result['ok'] ?? false)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'DB update failed: ' . ($result['error'] ?? 'unknown')]);
            exit;
        }
        echo json_encode(['ok' => true]);
        break;
    }

    // ── edit_image_alt: UPDATE image[0].alt for a DRAFT post's stored image ──
    case 'edit_image_alt': {
        $alt = $body['alt'] ?? null;
        if ($alt === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing alt']);
            exit;
        }
        $result = mkBridgeDbCall($bridgeBaseUrl, $bridgeAuthHeader, [
            'action' => 'edit_image_alt',
            'id'     => $postId,
            'alt'    => $alt,
        ]);
        if (!($result['ok'] ?? false)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'DB update failed: ' . ($result['error'] ?? 'unknown')]);
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
        $isoDate = date('c', $ts);
        $result  = mkBridgeDbCall($bridgeBaseUrl, $bridgeAuthHeader, [
            'action'      => 'reschedule',
            'id'          => $postId,
            'publishDate' => $isoDate,
        ]);
        if (!($result['ok'] ?? false)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'DB update failed: ' . ($result['error'] ?? 'unknown')]);
            exit;
        }
        echo json_encode(['ok' => true, 'publishDate' => $isoDate]);
        break;
    }

    // ── delete: soft-delete a DRAFT post ─────────────────────────────────────
    case 'delete': {
        $result = mkBridgeDbCall($bridgeBaseUrl, $bridgeAuthHeader, [
            'action' => 'delete',
            'id'     => $postId,
        ]);
        if (!($result['ok'] ?? false)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'DB delete failed: ' . ($result['error'] ?? 'unknown')]);
            exit;
        }
        echo json_encode(['ok' => true]);
        break;
    }

    // ── publish_now: post immediately via Postiz public API ──────────────────
    // Strategy:
    //   1. Fetch state, integrationId, content, image, parentPostId from DB via bridge
    //   2. For X: find the thread root (walk parentPostId backward), then walk
    //      forward via fetch_thread_child to build the full N-row value[] chain —
    //      publish_now can be invoked from any row in the thread.
    //   3. Soft-delete every post in the chain via bridge
    //   4. POST new post via Postiz API with type=now
    //   On step 4 failure: restore the soft-deleted rows so no data loss.
    case 'publish_now': {
        $contentOverride = $body['content'] ?? null;

        // Step 0: read post fields from DB via bridge
        $fields = mkBridgeDbCall($bridgeBaseUrl, $bridgeAuthHeader, [
            'action' => 'fetch_fields',
            'id'     => $postId,
        ]);

        if (!($fields['ok'] ?? false) || !isset($fields['state'])) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => 'Could not read post from DB via bridge: ' . ($fields['error'] ?? 'unknown')]);
            exit;
        }

        $state         = $fields['state'];
        $integrationId = $fields['integrationId'] ?? null;
        $provider      = strtolower($fields['provider'] ?? '');
        $dbContent     = $fields['content'] ?? null;
        $imageRaw      = $fields['image'] ?? null;
        $parentPostId  = $fields['parentPostId'] ?? null;

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
        $isX     = ($provider === 'x' || $provider === 'twitter');

        if ($isX) {
            // Bridge-backed callables for the pure chain-walking functions.
            $fetchFields = function (string $id) use ($bridgeBaseUrl, $bridgeAuthHeader): ?array {
                $r = mkBridgeDbCall($bridgeBaseUrl, $bridgeAuthHeader, ['action' => 'fetch_fields', 'id' => $id]);
                if (!($r['ok'] ?? false) || !isset($r['state'])) {
                    return null;
                }
                return ['content' => $r['content'] ?? null, 'image' => $r['image'] ?? null, 'parentPostId' => $r['parentPostId'] ?? null];
            };
            $fetchChild = function (string $parentId) use ($bridgeBaseUrl, $bridgeAuthHeader): ?array {
                $r = mkBridgeDbCall($bridgeBaseUrl, $bridgeAuthHeader, ['action' => 'fetch_thread_child', 'id' => $parentId]);
                if (!($r['ok'] ?? false) || !($r['found'] ?? false)) {
                    return null;
                }
                return ['id' => $r['id'], 'content' => $r['content'] ?? '', 'image' => $r['image'] ?? null];
            };

            // Use the override content for the invoked row, DB content otherwise —
            // mkFindThreadRoot/mkBuildThreadChain re-fetch every row in the chain
            // from the bridge, so apply the override after locating the root.
            $startFields = ['content' => $content, 'image' => $imageRaw, 'parentPostId' => $parentPostId];
            $root         = mkFindThreadRoot($postId, $startFields, $fetchFields);
            $chain        = mkBuildThreadChain($root['id'], $root['content'], $root['image'], $fetchChild);
            $valueEntries = $chain['entries'];
            $idsToDelete  = $chain['ids'];
        } else {
            $valueEntries = [['content' => $content, 'image' => mkDecodeImageField($imageRaw)]];
            $idsToDelete  = [$postId];
        }

        $settings = mkBuildPostSettings($provider);

        // Step 1: soft-delete every post in the chain
        foreach ($idsToDelete as $idToDelete) {
            $delResult = mkBridgeDbCall($bridgeBaseUrl, $bridgeAuthHeader, [
                'action' => 'soft_delete',
                'id'     => $idToDelete,
            ]);
            if (!($delResult['ok'] ?? false)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Failed to retire draft (id=' . $idToDelete . '): ' . ($delResult['error'] ?? 'unknown')]);
                exit;
            }
        }

        // Step 2: POST new post via Postiz API with type=now
        $apiUrl  = $postizBaseUrl . '/api/public/v1/posts';
        $payload = [
            'type'      => 'now',
            'date'      => date('c'),
            'shortLink' => false,
            'tags'      => [],
            'posts'     => [[
                'integration' => ['id' => $integrationId],
                'value'       => $valueEntries,
                'settings'    => $settings,
            ]],
        ];

        $response = mkPostizApiPost($apiUrl, $payload, $postizAuthHeader);
        $failure  = mkClassifyPublishFailure($response);

        if ($failure !== null) {
            // Rollback: restore all soft-deleted rows. 'timeout' is ambiguous —
            // Postiz may have actually published before we lost the response —
            // so those rows go to ERROR (requires human verification before
            // retry) rather than DRAFT, to prevent a silent duplicate publish.
            $restoreState = mkRollbackStateForFailureKind($failure['kind']);
            foreach ($idsToDelete as $idToRestore) {
                mkBridgeDbCall($bridgeBaseUrl, $bridgeAuthHeader, [
                    'action'  => 'restore',
                    'id'      => $idToRestore,
                    'asState' => $restoreState,
                ]);
            }
            http_response_code(502);
            $restoreNote = $restoreState === 'ERROR'
                ? ' — publish status is unknown; drafts marked ERROR, verify on the platform before retrying.'
                : ' — drafts have been restored.';
            echo json_encode([
                'ok'    => false,
                'error' => $failure['message'] . $restoreNote,
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
