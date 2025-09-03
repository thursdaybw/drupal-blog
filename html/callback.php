<?php
// callback.php
if (isset($_GET['code'])) {
    echo "Authorization code: " . htmlspecialchars($_GET['code']);
} else {
    echo "No code received.";
}

