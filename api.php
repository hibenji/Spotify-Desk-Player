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

function getTrackLikedStatus($trackId, $accessToken) {
    if (!$trackId) return false;

    // Check liked songs
    $chLiked = curl_init('https://api.spotify.com/v1/me/tracks/contains?ids=' . urlencode($trackId));
    curl_setopt($chLiked, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chLiked, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    $likedResp = curl_exec($chLiked);
    curl_close($chLiked);
    $likedData = json_decode($likedResp, true);
    $inLiked = is_array($likedData) && isset($likedData[0]) && $likedData[0];

    // If not in liked songs already, no need to check playlist
    if (!$inLiked) return false;

    // If no playlist configured, liked songs alone is enough
    $playlistId = LIKE_PLAYLIST_ID;
    if (!$playlistId) return true;

    // Check if track is in the configured playlist
    $inPlaylist = false;
    $offset = 0;
    $limit = 100;
    do {
        $chPl = curl_init('https://api.spotify.com/v1/playlists/' . $playlistId . '/tracks?fields=items(track(id)),next&limit=' . $limit . '&offset=' . $offset);
        curl_setopt($chPl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chPl, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
        $plResp = curl_exec($chPl);
        curl_close($chPl);
        $plData = json_decode($plResp, true);
        if (!empty($plData['items'])) {
            foreach ($plData['items'] as $plItem) {
                if (isset($plItem['track']['id']) && $plItem['track']['id'] === $trackId) {
                    $inPlaylist = true;
                    break 2;
                }
            }
        }
        $offset += $limit;
        $hasMore = !empty($plData['next']);
    } while ($hasMore);

    return $inPlaylist;
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

    // Check if already in liked songs before adding
    $alreadyLiked = getTrackLikedStatus($trackId, $accessToken);

    if (!$alreadyLiked) {
        $ch = curl_init('https://api.spotify.com/v1/me/tracks');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['ids' => [$trackId]]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201 && $httpCode !== 204) {
            http_response_code($httpCode);
            echo json_encode(['error' => 'Failed to like song', 'code' => $httpCode]);
            exit;
        }
    }

    // Also add to the configured playlist, but only if not already in it
    $playlistId = LIKE_PLAYLIST_ID;
    if ($playlistId) {
        // Check if track is already in the playlist
        $alreadyInPlaylist = false;
        $offset = 0;
        $limit = 100;
        do {
            $chCheck = curl_init('https://api.spotify.com/v1/playlists/' . $playlistId . '/tracks?fields=items(track(id)),next&limit=' . $limit . '&offset=' . $offset);
            curl_setopt($chCheck, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chCheck, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
            $checkResp = curl_exec($chCheck);
            curl_close($chCheck);
            $checkData = json_decode($checkResp, true);
            if (!empty($checkData['items'])) {
                foreach ($checkData['items'] as $plItem) {
                    if (isset($plItem['track']['id']) && $plItem['track']['id'] === $trackId) {
                        $alreadyInPlaylist = true;
                        break 2;
                    }
                }
            }
            $offset += $limit;
            $hasMore = !empty($checkData['next']);
        } while ($hasMore);

        if (!$alreadyInPlaylist) {
            $chPl = curl_init('https://api.spotify.com/v1/playlists/' . $playlistId . '/tracks');
            curl_setopt($chPl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chPl, CURLOPT_POST, true);
            curl_setopt($chPl, CURLOPT_POSTFIELDS, json_encode(['uris' => ['spotify:track:' . $trackId]]));
            curl_setopt($chPl, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]);
            curl_exec($chPl);
            curl_close($chPl);
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
        $is_liked = getTrackLikedStatus($lastPlayed['track']['id'], $accessToken);
        echo json_encode([
            'is_playing' => false,
            'recent_item' => $lastPlayed['track'],
            'played_at' => $lastPlayed['played_at'],
            'config' => $configArray,
            'is_liked' => $is_liked
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
        $responseData['is_liked'] = getTrackLikedStatus($responseData['item']['id'], $accessToken);
    } else {
        $responseData['is_liked'] = false;
    }
    echo json_encode($responseData);
} else {
    echo $response;
}
