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

$ch = curl_init('https://api.spotify.com/v1/me/player/currently-playing');

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 204 || empty($response) || $httpCode === 200 && json_decode($response, true)['is_playing'] === false) {
    echo json_encode(['is_playing' => false, 'config' => $configArray]);
    exit;
}

// Intercept success response to inject config
$responseData = json_decode($response, true);
if (is_array($responseData)) {
    $responseData['config'] = $configArray;
    echo json_encode($responseData);
} else {
    echo $response;
}
