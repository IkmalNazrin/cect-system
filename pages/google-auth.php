<?php
// pages/google-auth.php
ob_start(); // Start output buffering
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include the Google config which ALSO includes the Composer autoloader
require_once '../config/google_config.php';

// Store the intended redirect URL (if provided from login/register page) in the session
if (isset($_GET['redirect'])) {
    $potential_redirect = filter_var($_GET['redirect'], FILTER_SANITIZE_URL);
     // Basic validation: Must start with / and not //
    if ($potential_redirect && strpos($potential_redirect, '/') === 0 && strpos($potential_redirect, '//') !== 0) {
        $_SESSION['google_auth_redirect_url'] = $potential_redirect;
    } else {
        unset($_SESSION['google_auth_redirect_url']); // Clear if invalid
    }
} else {
     unset($_SESSION['google_auth_redirect_url']); // Clear if not provided
}

// Create Google Client
$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URI);
$client->addScope("email");
$client->addScope("profile");

// Generate the authentication URL
$authUrl = $client->createAuthUrl();

// Clear any buffered output before redirecting
ob_end_clean();

// Redirect the user to Google's authentication page
header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
exit();
?>