<?php
$ENDPOINT = 'https://www.bevansbench.com/challenge.php'; // exact URL
$TOKEN    = '27a15f9b9d6d98a59bea4b32c77464eaf002327d';                // your token

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $code = $_GET['challenge_code'] ?? '';
    $h = hash_init('sha256');
    hash_update($h, $code);
    hash_update($h, $TOKEN);
    hash_update($h, $ENDPOINT);
    header('Content-Type: application/json');
    echo json_encode(['challengeResponse' => hash_final($h)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // DEV MODE: just log to container output so you see something
    $payload = file_get_contents('php://input') ?: '';
    error_log("eBay MAD POST: ".$payload);
    http_response_code(200);
    exit;
}

http_response_code(405);

