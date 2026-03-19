<?php
// Simple .env parser since we don't have composer/vlucas/phpdotenv installed by default.
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
    return true;
}

loadEnv(__DIR__ . '/.env');

define('SPOTIFY_CLIENT_ID', $_ENV['SPOTIFY_CLIENT_ID'] ?? '');
define('SPOTIFY_CLIENT_SECRET', $_ENV['SPOTIFY_CLIENT_SECRET'] ?? '');
define('SPOTIFY_REDIRECT_URI', $_ENV['SPOTIFY_REDIRECT_URI'] ?? '');
define('FEATURE_QUEUE_AND_SKIP', $_ENV['FEATURE_QUEUE_AND_SKIP'] ?? 'false');
define('LIKE_PLAYLIST_ID', $_ENV['LIKE_PLAYLIST_ID'] ?? '');

define('TOKEN_FILE', __DIR__ . '/token.json');
