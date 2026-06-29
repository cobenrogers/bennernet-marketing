<?php
// Proxy for Postiz-stored images. Postiz runs at localhost:5000 which is
// unreachable from the browser, but PHP can reach it server-side.
// Usage: /marketing/postiz-img.php?p=2026/06/29/file.webp

$path = $_GET['p'] ?? '';

// Allow only safe path characters: letters, digits, /, ., -, _
if (!preg_match('#^[a-zA-Z0-9/_.\-]+$#', $path) || str_contains($path, '..')) {
    http_response_code(400);
    exit;
}

$upstream = 'http://localhost:5000/uploads/' . $path;

$ch = curl_init($upstream);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_FAILONERROR    => false,
]);
$body   = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($body === false || $status !== 200) {
    http_response_code(404);
    exit;
}

// Pass through content-type; default to webp if absent
$ctype = $ctype ?: 'image/webp';
// Strip charset suffix if present (e.g. "image/jpeg; charset=...")
$ctype = preg_replace('/\s*;.*/', '', $ctype);

header('Content-Type: ' . $ctype);
header('Cache-Control: public, max-age=31536000, immutable');
echo $body;
