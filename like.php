<?php
require_once 'config.php';
header('Content-Type: application/json');

function getValidAccessToken() {
    if (!file_exists(TOKEN_FILE)) return null;

    $tokenData = json_decode(file_get_contents(TOKEN_FILE), true);
    if (!isset($tokenData['refresh_token'])) return null;

    if (!isset($tokenData['expires_at']) || time() >= ($tokenData['expires_at'] - 60)) {
        $ch = curl_init('https://accounts.spotify.com/api/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokenData['refresh_token'],
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $newData = json_decode($response, true);

        if (isset($newData['access_token'])) {
            $tokenData['access_token'] = $newData['access_token'];
            $tokenData['expires_in'] = $newData['expires_in'];
            $tokenData['expires_at'] = time() + $newData['expires_in'];
            if (isset($newData['refresh_token'])) {
                $tokenData['refresh_token'] = $newData['refresh_token'];
            }
            file_put_contents(TOKEN_FILE, json_encode($tokenData));
        } else {
            return null;
        }
    }
    return $tokenData['access_token'];
}

$accessToken = getValidAccessToken();
$isQueueModeEnabled = (defined('FEATURE_QUEUE_AND_SKIP') && FEATURE_QUEUE_AND_SKIP === 'true');
$configArray = ['queue_and_skip' => $isQueueModeEnabled];

if (!$accessToken) {
    http_response_code(500);
    echo json_encode(['error' => 'Not authenticated with Spotify']);
    exit;
}

function spotifyApiRequest($url, $accessToken, $method = 'GET', $body = null, $maxRetries = 2) {
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $headers = ['Authorization: Bearer ' . $accessToken];
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
            $headers[] = 'Content-Type: application/json';
        } elseif ($method === 'POST') {
            $headers[] = 'Content-Length: 0';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($httpCode === 429) {
            $responseHeaders = substr($response, 0, $headerSize);
            $retryAfter = 1;
            if (preg_match('/Retry-After:\s*(\d+)/i', $responseHeaders, $m)) {
                $retryAfter = (int)$m[1];
            }
            sleep(min($retryAfter, 5));
            continue;
        }

        $responseBody = substr($response, $headerSize);
        return ['code' => $httpCode, 'body' => $responseBody];
    }
    return ['code' => 429, 'body' => 'Rate limited after retries'];
}

function getPlaylistTrackIds($playlistId, $accessToken) {
    $cacheFile = __DIR__ . '/playlist_cache.json';
    $cacheTTL = 30; // seconds

    // Check cache
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if (
            is_array($cache) &&
            isset($cache['playlist_id'], $cache['timestamp'], $cache['track_ids']) &&
            $cache['playlist_id'] === $playlistId &&
            (time() - $cache['timestamp']) < $cacheTTL
        ) {
            return $cache['track_ids'];
        }
    }

    // Fetch all track IDs from playlist
    $trackIds = [];
    $offset = 0;
    $limit = 100;
    do {
        $respData = spotifyApiRequest('https://api.spotify.com/v1/playlists/' . $playlistId . '/items?fields=items(track(id)),next&limit=' . $limit . '&offset=' . $offset, $accessToken, 'GET');
        $httpCode = $respData['code'];
        $respBody = $respData['body'];

        if ($httpCode !== 200) {
            if (file_exists($cacheFile)) {
                $stale = json_decode(file_get_contents($cacheFile), true);
                return $stale['track_ids'] ?? [];
            }
            return [];
        }

        $data = json_decode($respBody, true);
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                if (isset($item['track']['id'])) {
                    $trackIds[] = $item['track']['id'];
                }
            }
        }
        $offset += $limit;
        $hasMore = !empty($data['next']);
    } while ($hasMore);

    // Save cache
    file_put_contents($cacheFile, json_encode([
        'playlist_id' => $playlistId,
        'timestamp' => time(),
        'track_ids' => $trackIds
    ]));

    return $trackIds;
}

function invalidatePlaylistCache() {
    $cacheFile = __DIR__ . '/playlist_cache.json';
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
    }
}

function invalidateLikedCache() {
    $cacheFile = __DIR__ . '/liked_cache.json';
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$trackId = $_POST['track_id'] ?? '';
if (!$trackId) {
    http_response_code(400);
    echo json_encode(['error' => 'No track_id provided']);
    exit;
}

// Always add to liked songs (with retry on rate limit)
$likeResult = spotifyApiRequest(
    'https://api.spotify.com/v1/me/library?uris=spotify:track:' . $trackId,
    $accessToken,
    'PUT'
);

if ($likeResult['code'] !== 200 && $likeResult['code'] !== 201 && $likeResult['code'] !== 204) {
    http_response_code($likeResult['code']);
    echo json_encode(['error' => 'Failed to like song', 'code' => $likeResult['code']]);
    exit;
}

invalidateLikedCache();

// Always add to the configured playlist (with retry on rate limit)
$playlistId = LIKE_PLAYLIST_ID;
if ($playlistId) {
    $playlistTrackIds = getPlaylistTrackIds($playlistId, $accessToken);
    if (!in_array($trackId, $playlistTrackIds, true)) {
        $plResult = spotifyApiRequest(
            'https://api.spotify.com/v1/playlists/' . $playlistId . '/items',
            $accessToken,
            'POST',
            ['uris' => ['spotify:track:' . $trackId]]
        );
        
        // Invalidate cache so next poll sees the new track
        invalidatePlaylistCache();
    }
}

echo json_encode(['success' => true]);
