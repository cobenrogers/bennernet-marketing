<?php
/**
 * Pure helper functions for the social-posts publish pipeline.
 *
 * Extracted from social-posts-api.php so the thread-chain / settings-building
 * logic can be unit tested without the HTTP request lifecycle (auth, CSRF,
 * bridge I/O). social-posts-api.php requires this file and calls these
 * functions with real bridge-backed callables; tests pass fixture callables.
 *
 * Issue: cobenrogers/bennernet-marketing#37
 */

declare(strict_types=1);

/**
 * Decode a Post.image DB column value into a Postiz value[].image[] array.
 * Handles: JSON array of {id,url,path[,alt]}, a bare URL string, or empty/null.
 */
function mkDecodeImageField(?string $imageRaw): array
{
    if (!$imageRaw) {
        return [];
    }
    $decoded = json_decode($imageRaw, true);
    if (is_array($decoded) && !empty($decoded)) {
        return $decoded;
    }
    if (is_string($imageRaw) && str_starts_with($imageRaw, 'http')) {
        return [['id' => $imageRaw, 'url' => $imageRaw, 'path' => $imageRaw]];
    }
    return [];
}

/**
 * Walk a post's parentPostId chain backward to find the thread root.
 *
 * @param string   $startId     the post id publish_now was invoked on
 * @param array    $startFields fields already fetched for $startId: ['content','image','parentPostId']
 * @param callable $fetchFields function(string $id): ?array{content:?string,image:?string,parentPostId:?string}
 *                               Returns null if the row can't be read.
 * @param int      $maxDepth    safety cap — abort rather than loop forever on bad data
 * @return array{id:string, content:string, image:array}  the root post's id + content + decoded image
 */
function mkFindThreadRoot(string $startId, array $startFields, callable $fetchFields, int $maxDepth = 20): array
{
    $currentId     = $startId;
    $currentFields = $startFields;

    $depth = 0;
    while (!empty($currentFields['parentPostId']) && $depth < $maxDepth) {
        $parentId     = $currentFields['parentPostId'];
        $parentFields = $fetchFields($parentId);
        if ($parentFields === null) {
            // Parent row unreadable (deleted/missing) — treat current as root.
            break;
        }
        $currentId     = $parentId;
        $currentFields = $parentFields;
        $depth++;
    }

    return [
        'id'      => $currentId,
        'content' => $currentFields['content'] ?? '',
        'image'   => mkDecodeImageField($currentFields['image'] ?? null),
    ];
}

/**
 * Walk forward from a thread root via repeated child lookups, building the
 * ordered Postiz value[] array for the whole chain (root + every reply).
 *
 * @param string   $rootId      thread root post id
 * @param string   $rootContent root post content
 * @param array    $rootImage   root post decoded image array
 * @param callable $fetchChild  function(string $parentId): ?array{id:string,content:string,image:?string}
 *                               Returns null when no DRAFT child exists for that parent.
 * @param int      $maxDepth    safety cap on chain length (prevents infinite loop on cyclic data)
 * @return array{entries: array, ids: array}
 *   entries — value[] array for the Postiz payload, root first then each reply in order
 *   ids     — every post id in the chain (root + replies), for soft-delete/rollback
 */
function mkBuildThreadChain(string $rootId, string $rootContent, array $rootImage, callable $fetchChild, int $maxDepth = 20): array
{
    $entries = [['content' => $rootContent, 'image' => $rootImage]];
    $ids     = [$rootId];

    $currentId = $rootId;
    $depth     = 0;

    while ($depth < $maxDepth) {
        $child = $fetchChild($currentId);
        if ($child === null) {
            break;
        }
        $entries[] = [
            'content' => $child['content'] ?? '',
            'image'   => mkDecodeImageField($child['image'] ?? null),
        ];
        $ids[]     = $child['id'];
        $currentId = $child['id'];
        $depth++;
    }

    return ['entries' => $entries, 'ids' => $ids];
}

/**
 * Build the Postiz `settings` object for a post, including Mastodon per-image
 * alt text passthrough (Mastodon CW/spoiler_text is NOT supported by Postiz's
 * Mastodon provider — confirmed against gitroomhq/postiz-app mastodon.provider.ts,
 * which never sends spoiler_text to the Mastodon API. Do not add a CW field here;
 * it would be silently dropped).
 *
 * @param string $provider lowercased Postiz providerIdentifier (e.g. 'x', 'mastodon')
 */
function mkBuildPostSettings(string $provider): array
{
    $isX = ($provider === 'x' || $provider === 'twitter');

    $settings = ['__type' => $provider ?: 'social'];
    if ($isX) {
        $settings['who_can_reply_post'] = 'everyone';
    } else {
        $settings['post_type'] = 'post';
    }

    return $settings;
}
