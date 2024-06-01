<?php
session_start();

// Configuration
$base_url = "https://www.bevansbench.com"; // Updated to use HTTPS
$client_id = "default_consumer";
$client_secret = "secret";
$redirect_uri = "http://bevansbench.com.ddev.site/oauthclient.php"; // Ensure this matches exactly
$scope = "content_editor";
$auth_url = "$base_url/oauth/authorize";
$token_url = "$base_url/oauth/token";
$todos_url = "$base_url/api/todo/search"; // Updated URL
$state = bin2hex(random_bytes(16)); // Generate a random state

// Step 1: Check if the authorization code is already in the session
if (!isset($_GET['code']) && !isset($_SESSION['auth_code'])) {
    // Step 2: Redirect to the authorization endpoint
    $auth_url = sprintf("%s?client_id=%s&redirect_uri=%s&response_type=code&scope=%s&state=%s", 
        $auth_url, $client_id, urlencode($redirect_uri), $scope, $state);
    header('Location: ' . $auth_url);
    exit();
} elseif (isset($_GET['code'])) {
    // Step 3: Handle the redirect back with the authorization code
    $_SESSION['auth_code'] = $_GET['code'];
    $_SESSION['state'] = $_GET['state'];
}

// Step 4: Exchange authorization code for access token
if (isset($_SESSION['auth_code']) && !isset($_SESSION['access_token'])) {
    $auth_code = $_SESSION['auth_code'];
    $response = file_get_contents($token_url, false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $auth_code,
                'redirect_uri' => $redirect_uri,
                'client_id' => $client_id,
                'client_secret' => $client_secret
            ])
        ]
    ]));
    $token_data = json_decode($response, true);
    if (isset($token_data['access_token'])) {
        $_SESSION['access_token'] = $token_data['access_token'];
    } else {
        echo "Failed to obtain access token.";
        exit();
    }
}

// Step 5: Make an authenticated API request using cURL
if (isset($_SESSION['access_token'])) {
    $access_token = $_SESSION['access_token'];
    
    // Print the access token for debugging
    echo "Access Token: " . $access_token . "<br>";

    // Prepare query parameters
    $query_params = http_build_query([
        '_format' => 'json'
    ]);

    // Add query parameters to the URL
    $todos_url_with_params = $todos_url . '?' . $query_params;

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $todos_url_with_params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token
    ]);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Allow redirects

    $todos_response = curl_exec($ch);

    if ($todos_response === false) {
        echo "cURL Error: " . curl_error($ch);
    } else {
        $todos = json_decode($todos_response, true);
        echo "<h1>Todo List</h1><pre>";
        print_r($todos);
        echo "</pre>";
    }

    curl_close($ch);
}
?>

