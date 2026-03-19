<?php
require_once 'config.php';

$scope = 'user-read-currently-playing user-modify-playback-state user-read-recently-played user-library-read user-library-modify playlist-modify-public';
$authUrl = 'https://accounts.spotify.com/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id' => SPOTIFY_CLIENT_ID,
    'scope' => $scope,
    'redirect_uri' => SPOTIFY_REDIRECT_URI,
]);

header('Location: ' . $authUrl);
exit;
