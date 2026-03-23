<?php
require_once 'config.php';
header('Content-Type: application/json');

function getValidAccessToken() {
    if (!file_exists(TOKEN_FILE)) {
        return null;
    }

    $tokenData = json_decode(file_get_contents(TOKEN_FILE), true);
    if (!isset($tokenData['refresh_token'])) {
        return null;
    }

    // Refresh if expiring within 1 minute
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
        $ch = curl_init('https://api.spotify.com/v1/playlists/' . $playlistId . '/tracks?fields=items(track(id)),next&limit=' . $limit . '&offset=' . $offset);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 429) {
            // Rate limited — return stale cache if available, otherwise empty
            if (file_exists($cacheFile)) {
                $stale = json_decode(file_get_contents($cacheFile), true);
                return $stale['track_ids'] ?? [];
            }
            return [];
        }

        $data = json_decode($resp, true);
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

function getTrackLikedStatus($trackId, $accessToken) {
    $result = ['is_liked' => false, 'is_in_playlist' => false];
    if (!$trackId) return $result;

    // Check liked songs
    $chLiked = curl_init('https://api.spotify.com/v1/me/tracks/contains?ids=' . urlencode($trackId));
    curl_setopt($chLiked, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chLiked, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    $likedResp = curl_exec($chLiked);
    curl_close($chLiked);
    $likedData = json_decode($likedResp, true);
    $result['is_liked'] = is_array($likedData) && isset($likedData[0]) && $likedData[0];

    // Check the configured playlist using cache
    $playlistId = LIKE_PLAYLIST_ID;
    if ($playlistId) {
        $playlistTrackIds = getPlaylistTrackIds($playlistId, $accessToken);
        $result['is_in_playlist'] = in_array($trackId, $playlistTrackIds, true);
    }

    return $result;
}

$accessToken = getValidAccessToken();

$isQueueModeEnabled = (defined('FEATURE_QUEUE_AND_SKIP') && FEATURE_QUEUE_AND_SKIP === 'true');
$configArray = ['queue_and_skip' => $isQueueModeEnabled];

if (!$accessToken) {
    http_response_code(500);
    echo json_encode(['error' => 'Not authenticated with Spotify']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'like') {
    $trackId = $_POST['track_id'] ?? '';
    if (!$trackId) {
        http_response_code(400);
        echo json_encode(['error' => 'No track_id provided']);
        exit;
    }

    // Always add to liked songs (with retry on rate limit)
    $likeResult = spotifyApiRequest(
        'https://api.spotify.com/v1/me/tracks',
        $accessToken,
        'PUT',
        ['ids' => [$trackId]]
    );

    if ($likeResult['code'] !== 200 && $likeResult['code'] !== 201 && $likeResult['code'] !== 204) {
        http_response_code($likeResult['code']);
        echo json_encode(['error' => 'Failed to like song', 'code' => $likeResult['code']]);
        exit;
    }

    // Always add to the configured playlist (with retry on rate limit)
    $playlistId = LIKE_PLAYLIST_ID;
    if ($playlistId) {
        $playlistTrackIds = getPlaylistTrackIds($playlistId, $accessToken);
        if (!in_array($trackId, $playlistTrackIds, true)) {
            $plResult = spotifyApiRequest(
                'https://api.spotify.com/v1/playlists/' . $playlistId . '/tracks',
                $accessToken,
                'POST',
                ['uris' => ['spotify:track:' . $trackId]]
            );
            echo json_encode($plResult);

            // Invalidate cache so next poll sees the new track
            invalidatePlaylistCache();
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'next') {
    $ch = curl_init('https://api.spotify.com/v1/me/player/next');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Length: 0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 204 || $httpCode === 200) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code($httpCode);
        echo json_encode(['error' => 'Failed to skip song']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'queue_and_skip') {
    if (!$isQueueModeEnabled) {
        http_response_code(403);
        echo json_encode(['error' => 'Feature disabled']);
        exit;
    }
    
    $uri = $_POST['uri'] ?? '';
    if (!$uri) {
        http_response_code(400);
        echo json_encode(['error' => 'No URI provided']);
        exit;
    }

    // Add to queue
    $ch = curl_init('https://api.spotify.com/v1/me/player/queue?uri=' . urlencode($uri));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Length: 0'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 204 || $httpCode === 200) {
        // Skip
        $ch2 = curl_init('https://api.spotify.com/v1/me/player/next');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Length: 0'
        ]);
        curl_exec($ch2);
        curl_close($ch2);
        echo json_encode(['success' => true]);
    } else {
        http_response_code($httpCode);
        echo json_encode(['error' => 'Failed to queue song']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'search') {
    if (!$isQueueModeEnabled) {
        http_response_code(403);
        echo json_encode(['error' => 'Feature disabled']);
        exit;
    }
    
    $query = $_GET['q'] ?? '';
    if (empty($query)) {
        echo json_encode([]);
        exit;
    }

    $ch = curl_init('https://api.spotify.com/v1/search?type=track&limit=5&q=' . urlencode($query));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $results = [];
        if (!empty($data['tracks']['items'])) {
            foreach ($data['tracks']['items'] as $item) {
                $results[] = [
                    'id' => $item['id'],
                    'uri' => $item['uri'],
                    'name' => $item['name'],
                    'artist' => isset($item['artists'][0]) ? $item['artists'][0]['name'] : '',
                    'image' => isset($item['album']['images'][2]) ? $item['album']['images'][2]['url'] : ''
                ];
            }
        }
        echo json_encode($results);
    } else {
        http_response_code($httpCode);
        echo json_encode(['error' => 'Search failed']);
    }
    exit;
}

$ch = curl_init('https://api.spotify.com/v1/me/player/currently-playing');

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 204 || empty($response) || $httpCode === 200 && json_decode($response, true)['is_playing'] === false) {
    $ch2 = curl_init('https://api.spotify.com/v1/me/player/recently-played?limit=1');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);
    
    $recentResp = curl_exec($ch2);
    curl_close($ch2);
    
    $recentData = json_decode($recentResp, true);
    if (!empty($recentData['items'])) {
        $lastPlayed = $recentData['items'][0];
        $likeStatus = getTrackLikedStatus($lastPlayed['track']['id'], $accessToken);
        echo json_encode([
            'is_playing' => false,
            'recent_item' => $lastPlayed['track'],
            'played_at' => $lastPlayed['played_at'],
            'config' => $configArray,
            'is_liked' => $likeStatus['is_liked'],
            'is_in_playlist' => $likeStatus['is_in_playlist']
        ]);
    } else {
        echo json_encode(['is_playing' => false, 'config' => $configArray]);
    }
    exit;
}

// Intercept success response to inject config
$responseData = json_decode($response, true);
if (is_array($responseData)) {
    $responseData['config'] = $configArray;
    if (isset($responseData['item']['id'])) {
        $likeStatus = getTrackLikedStatus($responseData['item']['id'], $accessToken);
        $responseData['is_liked'] = $likeStatus['is_liked'];
        $responseData['is_in_playlist'] = $likeStatus['is_in_playlist'];
    } else {
        $responseData['is_liked'] = false;
        $responseData['is_in_playlist'] = false;
    }
    echo json_encode($responseData);
} else {
    echo $response;
}
