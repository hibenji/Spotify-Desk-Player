<?php
require_once 'config.php';

if (!isset($_GET['code'])) {
    die('Error: No code provided. <a href="auth.php">Try again</a>');
}

$code = $_GET['code'];

$ch = curl_init('https://accounts.spotify.com/api/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => SPOTIFY_REDIRECT_URI,
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
    'Content-Type: application/x-www-form-urlencoded'
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if (isset($data['access_token'])) {
    $data['expires_at'] = time() + $data['expires_in'];
    file_put_contents(TOKEN_FILE, json_encode($data));
    echo "<h1>Successfully authenticated!</h1><p>Tokens saved. The site will now work independently. <a href='index.php'>Return to the home page</a>.</p>";
} else {
    echo "<h1>Error authenticating:</h1>";
    echo "<pre>" . print_r($data, true) . "</pre>";
}    
