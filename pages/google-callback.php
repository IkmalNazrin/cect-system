<?php
// pages/google-callback.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start(); // Start output buffering
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php'; // Database connection
// Include the Google config which ALSO includes the Composer autoloader
require_once '../config/google_config.php';

$logFile = __DIR__ . '/google_callback_debug.log';
function writeGoogleLog($message) {
    global $logFile;
    // Ensure the directory is writable by the web server on InfinityFree
    @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

writeGoogleLog("=== Google Callback Handler Started ===");
writeGoogleLog("GET data: " . print_r($_GET, true));
writeGoogleLog("SESSION data: " . print_r($_SESSION, true));


$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URI);
// No need to add scope here again, just need it configured for token exchange

if (!isset($_GET['code'])) {
    writeGoogleLog("Error: No authorization code received from Google.");
    $_SESSION['login_error'] = "Google authentication failed (no code).";
    header('Location: login.php');
    exit();
}

try {
    writeGoogleLog("Attempting to fetch access token with code: " . $_GET['code']);
    // Exchange authorization code for an access token.
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (isset($token['error'])) {
        writeGoogleLog("Error fetching access token: " . print_r($token, true));
        throw new Exception('Failed to retrieve access token: ' . ($token['error_description'] ?? $token['error']));
    }

    writeGoogleLog("Access token fetched successfully.");
    $client->setAccessToken($token);

    // Get profile info
    $google_oauth = new Google_Service_Oauth2($client);
    $google_account_info = $google_oauth->userinfo->get();

    $google_id = $google_account_info->getId();
    $email = $google_account_info->getEmail();
    $name = $google_account_info->getName();
    $first_name = $google_account_info->getGivenName();
    $profile_pic_url = $google_account_info->getPicture(); // Get Google profile picture URL

    writeGoogleLog("Google User Info: ID=$google_id, Email=$email, Name=$name, FirstName=$first_name");

    // --- Database Interaction ---
    $user = null;
    $pdo->beginTransaction(); // Start transaction

    // 1. Check if user exists with this google_id
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ?");
    $stmt->execute([$google_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        writeGoogleLog("User found by google_id: " . $user['id']);
        // User exists, log them in
        // Update last login and potentially name/picture if they changed in Google
        $updateStmt = $pdo->prepare("UPDATE users SET name = ?, first_name = ?, profile_image = ?, last_login = NOW() WHERE id = ?");
        // Use Google's picture URL directly here
        $updateStmt->execute([$name, $first_name ?: strtok($name, ' '), $profile_pic_url, $user['id']]);
        writeGoogleLog("Updated details for existing Google user ID: " . $user['id']);

    } else {
        // 2. Check if user exists with this email but NO google_id yet
        writeGoogleLog("User not found by google_id. Checking by email: " . $email);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            writeGoogleLog("User found by email: " . $user['id'] . ". Linking google_id.");
            // User exists via email, link the google_id and update details
            $updateStmt = $pdo->prepare("UPDATE users SET google_id = ?, name = ?, first_name = ?, profile_image = ?, last_login = NOW() WHERE id = ?");
            $updateStmt->execute([$google_id, $name, $first_name ?: strtok($name, ' '), $profile_pic_url, $user['id']]);
            writeGoogleLog("Linked google_id $google_id and updated details for user ID: " . $user['id']);
            // Re-fetch user data to ensure we have the latest (including the new google_id)
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

        } else {
            writeGoogleLog("User not found by email. Creating new user.");
            // 3. User does not exist, create a new account
            // We don't have a local password, so set it to NULL or an unusable hash if the column doesn't allow NULLs.
            // Assuming 'password' column can be NULL for Google-only users for now.
            // Set profile_image directly to the Google picture URL.
            // Use Google's first_name if available.
            $sql = "INSERT INTO users (name, first_name, email, google_id, profile_image, is_admin, organizer_status, created_at, last_login, password)
                    VALUES (?, ?, ?, ?, ?, 0, NULL, NOW(), NOW(), NULL)"; // Set organizer_status to NULL or 'pending' as needed. Password is NULL.
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([$name, $first_name ?: strtok($name, ' '), $email, $google_id, $profile_pic_url]);

            if ($result) {
                $newUserId = $pdo->lastInsertId();
                writeGoogleLog("New user created with ID: " . $newUserId);
                // Fetch the newly created user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$newUserId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                 $errorInfo = $stmt->errorInfo();
                 writeGoogleLog("Failed to insert new user. Error: " . print_r($errorInfo, true));
                 $pdo->rollBack(); // Rollback transaction on failure
                throw new Exception("Failed to create user account: " . ($errorInfo[2] ?? 'Unknown DB error'));
            }
        }
    }

    $pdo->commit(); // Commit transaction if all DB operations were successful

    // --- Session Setup ---
    if ($user) {
        writeGoogleLog("Setting up session for user ID: " . $user['id']);
        session_regenerate_id(true); // Security measure

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['first_name'] = $user['first_name'] ?? strtok($user['name'] ?? '', ' ') ?: 'User';
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['is_admin'] = $user['is_admin'] ?? 0; // Default to 0 if NULL
        $_SESSION['organizer_status'] = $user['organizer_status']; // Might be NULL for new users
        // Use the profile image directly from Google/DB (already updated)
        $_SESSION['profile_image'] = $user['profile_image'];

        writeGoogleLog("Session variables set: " . print_r($_SESSION, true));


        // --- Redirection ---
        $redirect_to = '/index.php'; // Default redirect
        if (isset($_SESSION['google_auth_redirect_url'])) {
            $potential_redirect = $_SESSION['google_auth_redirect_url'];
             // Validate again just in case
             if ($potential_redirect && strpos($potential_redirect, '/') === 0 && strpos($potential_redirect, '//') !== 0) {
                $redirect_to = $potential_redirect;
                writeGoogleLog("Using redirect URL from session: " . $redirect_to);
            } else {
                 writeGoogleLog("Invalid redirect URL in session ignored: " . $potential_redirect);
            }
            unset($_SESSION['google_auth_redirect_url']); // Clear it after use
        } else {
            writeGoogleLog("No redirect URL in session, using default: " . $redirect_to);
        }

        // Clear any existing output
         while (ob_get_level()) {
            ob_end_clean();
         }

        writeGoogleLog("Google login successful. Redirecting to: " . $redirect_to);
        header('Location: ' . $redirect_to);
        exit();

    } else {
        // Should not happen if user creation/finding worked, but as a fallback
        writeGoogleLog("Error: User data is null after database operations.");
        throw new Exception("Could not retrieve user data after login/registration.");
    }

} catch (Exception $e) {
    // Rollback transaction if it's still active and an exception occurred
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
        writeGoogleLog("Transaction rolled back due to exception.");
    }
    writeGoogleLog("Error during Google callback processing: " . $e->getMessage());
    writeGoogleLog("Trace: " . $e->getTraceAsString());
    $_SESSION['login_error'] = "Google authentication failed: " . htmlspecialchars($e->getMessage());
     // Clear any existing output before error redirect
     while (ob_get_level()) {
         ob_end_clean();
     }
    header('Location: login.php');
    exit();
}

// Ensure buffer is cleaned if exit wasn't reached
ob_end_flush();
?>