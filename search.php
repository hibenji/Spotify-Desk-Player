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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

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
