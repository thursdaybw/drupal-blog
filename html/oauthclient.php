<?php
session_start();

// Configuration
$base_url = "https://www.bevansbench.com"; // Updated to use HTTPS
$client_id = "dev_consumer";
$client_secret = "secret";
$redirect_uri = "http://bevansbench.com.ddev.site/oauthclient.php"; // Ensure this matches exactly
$scope = "content_editor";
$auth_url = "$base_url/oauth/authorize";
$token_url = "$base_url/oauth/token";
$todos_url = "$base_url/api/todo/search"; // Updated URL
$create_todo_url = "$base_url/node"; // Endpoint for creating a node
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
    $token_url = 'https://www.bevansbench.com/oauth/token';

    // Prepare the data for the POST request
    $postData = http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $auth_code,
        'redirect_uri' => $redirect_uri,
        'client_id' => $client_id,
        'client_secret' => $client_secret
    ]);

    // Initialize cURL
    $ch = curl_init();

    // Set the options for the cURL request
    curl_setopt($ch, CURLOPT_URL, $token_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Content-Length: ' . strlen($postData)
    ]);

    // Execute the POST request
    $response = curl_exec($ch);

    // Check for errors
    if ($response === false) {
        echo 'Curl error: ' . curl_error($ch);
    } else {
        echo 'Response from OAuth server: ' . $response; // Debugging line
        $token_data = json_decode($response, true);
        if (isset($token_data['access_token'])) {
            $_SESSION['access_token'] = $token_data['access_token'];
        } else {
            // Handle the case where the access token is not found
            echo 'Error: Access token not found in the response.';
        }
    }

    // Close the cURL session
    curl_close($ch);
}


// Step 5: Fetch the list of todo items.
if (isset($_SESSION['access_token'])) {
    $access_token = $_SESSION['access_token'];
    $todos_response = file_get_contents($todos_url, false, stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Authorization: Bearer ' . $access_token
        ]
    ]));
    $todos = json_decode($todos_response, true);
}

// Step 6: Handle creating a test todo item
if (isset($_POST['create_test_todo'])) {
    $new_todo = [
        'type' => [['target_id' => 'to_do_list']], // Assuming 'to_do_list' is the content type
        'title' => [['value' => 'Test Todo Item']],
        'field_to_do_list_description' => [['value' => 'This is a test todo item created via the API.']],
        'field_to_do_list_due_date' => [['value' => '2024-06-01T00:00:00Z']], // Corrected date format (RFC 3339)
        'field_to_do_list_priority' => [['value' => 'high']], // Correct option from your configuration
        'field_to_do_list_status' => [['value' => 'pending']],  // Correct option from your configuration
        'field_to_do_list_tags' => [['target_id' => '42']] // Assuming '42' is a valid taxonomy term ID in the Tags vocabulary
    ];

    // Ensure the URL includes the _format parameter
    $create_todo_url = 'https://www.bevansbench.com/node?_format=json';

    // Initialize cURL
    $ch = curl_init($create_todo_url);

    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($new_todo));
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    // Execute the cURL request
    $create_response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo 'Curl error: ' . curl_error($ch) . "\n";
    }

    // Get the response status code
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Close cURL session
    curl_close($ch);

    // Log the payload, response, and status code
    echo 'Payload: ' . json_encode($new_todo) . "\n";
    echo 'HTTP Status Code: ' . $http_status . "\n";
    echo 'Response: ' . $create_response . "\n";

    // Decode the response to check for errors or success
    $response_data = json_decode($create_response, true);
    print_r($response_data);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Todo List</title>
</head>
<body>
    <h1>Todo List</h1>
    <ul>
        <?php if (isset($todos) && !empty($todos)): ?>
            <?php foreach ($todos['tasks'] as $todo): ?>
                <li><?php echo htmlspecialchars($todo['title']); ?></li>
            <?php endforeach; ?>
        <?php else: ?>
            <li>No todo items found.</li>
        <?php endif; ?>
    </ul>

    <form method="post">
        <button type="submit" name="create_test_todo">Create Test Todo Item</button>
    </form>
</body>
</html>

