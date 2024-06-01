<?php
session_start();

// Configuration
$base_url = "https://bevansbench.com.ddev.site"; // Updated to use HTTPS
$client_id = "default_consumer";
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

// Step 5: Create a to-do item if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SESSION['access_token'])) {
        $access_token = $_SESSION['access_token'];

        // Data for creating a new to-do item
        $new_todo_data = json_encode([
            "type" => "to_do",
            "title" => "Test To-Do Item",
            "body" => [
                "value" => "This is a test to-do item created to verify the functionality of creating tasks via the API.",
                "format" => "plain_text"
            ],
            "field_to_do_list_description" => [
                "value" => "Verify the creation and retrieval of a test to-do item."
            ],
            "field_to_do_list_due_date" => "2024-06-01",
            "field_to_do_list_priority" => "medium",
            "field_to_do_list_status" => "in_progress"
        ]);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $create_todo_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $new_todo_data);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Allow redirects

        $create_response = curl_exec($ch);

        if ($create_response === false) {
            echo "cURL Error: " . curl_error($ch);
        } else {
            $created_todo = json_decode($create_response, true);
            echo "<h1>Created Todo Item</h1><pre>";
            print_r($created_todo);
            echo "</pre>";
        }

        curl_close($ch);
    }
}

// Step 6: Make an authenticated API request using cURL to list todo items
if (isset($_SESSION['access_token'])) {

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
        'Authorization: Bearer ' . $_SESSION['access_token'],
    ]);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Allow redirects

    $todos_response = curl_exec($ch);

    if ($todos_response === false) {
        echo "cURL Error: " . curl_error($ch);
    } else {
        $todos = json_decode($todos_response, true);
	if (isset($todos['tasks'])) {
          $todos = $todos['tasks'];
	}
    }

    curl_close($ch);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>To-Do List</title>
</head>
<body>
    <h1>To-Do List</h1>
<?php
if (isset($todos)) {
    // First check if $todos is an array
    if (is_array($todos) && count($todos) > 0) {
        echo "<ul>";
        foreach ($todos as $todo) {
            // Check if each $todo is an array and has a 'title' key before accessing it
            if (is_array($todo) && array_key_exists('title', $todo)) {
                echo "<li>" . htmlspecialchars($todo['title']) . "</li>";
            } else {
                // Log or handle individual todo item format issues
                echo "<li>Error: Todo item is not in the expected format.</li>";
            }
        }
        echo "</ul>";
    } else {
        // Handle cases where $todos is not structured as expected or is empty
        echo "<p>Error: Todos data is not in the expected format or is empty.</p>";
    }
}
?>
    <form method="post">
        <button type="submit">Create Test To-Do</button>
    </form>
</body>
</html>

