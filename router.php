<?php

declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Cross-Origin-Resource-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; worker-src 'self'; manifest-src 'self'");

$path = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
$normalized = '/' . ltrim(str_replace('\\', '/', $path), '/');
$lowerPath = strtolower($normalized);


if ($lowerPath === '/favicon.ico') {
    header('Location: /assets/images/ucclogo.jpg', true, 302);
    exit;
}

$blockedPrefixes = ['/config/', '/database/', '/hardware/', '/includes/', '/pages/', '/employee_pages/', '/tests/'];
$blockedExtensions = [
    '.sql',
    '.ini',
    '.log',
    '.md',
    '.ino',
    '.h',
    '.zip',
    '.bak',
    '.db',
    '.ps1',
    '.psm1',
    '.cmd',
    '.bat',
    '.sh',
];
$blocked = str_contains($lowerPath, '/.')
    || $lowerPath === '/readme.md'
    // Reject an accidentally literal Windows environment-variable directory.
    // A malformed maintenance command once created this project-local path;
    // none of its cache/database files are web application assets.
    || str_starts_with($lowerPath, '/%systemdrive%/');

foreach ($blockedPrefixes as $prefix) {
    if (str_starts_with($lowerPath, $prefix)) {
        $blocked = true;
        break;
    }
}
foreach ($blockedExtensions as $extension) {
    if (str_ends_with($lowerPath, $extension)) {
        $blocked = true;
        break;
    }
}

if ($blocked) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}


// Explicit MIME types are needed for installation in the development server.
if ($lowerPath === '/manifest.webmanifest' || $lowerPath === '/service-worker.js') {
    $isManifest = $lowerPath === '/manifest.webmanifest';
    header('Content-Type: ' . ($isManifest ? 'application/manifest+json' : 'application/javascript') . '; charset=utf-8');
    header('Cache-Control: ' . ($isManifest ? 'public, max-age=3600' : 'no-cache, no-store, must-revalidate'));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile(__DIR__ . ($isManifest ? '/manifest.webmanifest' : '/service-worker.js'));
    }
    exit;
}

return false;
