<?php
session_start();
require_once '../config/db.php'; // Adjust path as needed
require_once '../lib/functions.php'; // Adjust path as needed

$token = $_GET['token'] ?? null;
$errors = [];
$success = null;

if (empty($token)) {
    $errors[] = 'Verification token is missing.';
} else {
    try {
        // Verify the token and get user ID and pending email
        $verificationData = verifyEmailChangeToken($pdo, $token);

        if ($verificationData) {
            $user_id = $verificationData['user_id'];
            $new_email = $verificationData['pending_email'];

            // Check if this new email is ACTUALLY already in use by someone else (last minute check)
            $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt_check->execute([$new_email, $user_id]);
            if ($stmt_check->fetch()) {
                 $errors[] = 'This email address has been registered by another user since your request. Please try changing to a different email.';
                 // Optionally clear the token for the original user to prevent reuse with the now-invalid email
                 $pdo->prepare("UPDATE users SET pending_email = NULL, email_change_token = NULL, email_change_token_expiry = NULL WHERE id = ?")->execute([$user_id]);
            } else {
                // Finalize the email change    
                if (finalizeEmailChange($pdo, $user_id, $new_email)) {
                    $success = 'Your email address has been successfully updated to ' . htmlspecialchars($new_email) . '.';

                    // IMPORTANT: If the user verifying is the currently logged-in user, update their session
                    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
                        $_SESSION['user_email'] = $new_email;
                        $_SESSION['fresh_update'] = true; // Flag to show "updated just now" message
                        error_log("Session email updated for user ID: $user_id to $new_email");
                    } else {
                         error_log("Email updated for user ID: $user_id via token, but session user is different or not logged in.");
                    }

                } else {
                    $errors[] = 'Failed to update your email address. The link might have already been used or an error occurred.';
                }
            }
        } else {
            $errors[] = 'Invalid, expired, or already used verification link.';
        }

    } catch (PDOException $e) {
        error_log("Database error during email verification: " . $e->getMessage());
        $errors[] = 'An unexpected database error occurred.';
    } catch (Exception $e) {
         error_log("General error during email verification: " . $e->getMessage());
         $errors[] = 'An unexpected error occurred.';
    }
}

// Store messages in session and redirect
$_SESSION['success'] = $success;
$_SESSION['errors'] = $errors;

// Redirect to the change email page to show the message
// Or redirect to account info or login page depending on desired flow
header('Location: /pages/change_email.php');
exit;

?>