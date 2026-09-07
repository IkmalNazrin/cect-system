<?php
session_start();
require_once '../config/db.php'; // Adjust path as needed
require_once '../lib/functions.php'; // Adjust path as needed

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['errors'] = ['Please log in to change your email.'];
    header('Location: /pages/login.php'); // Adjust redirect location if needed
    exit;
}

$errors = [];
$success = null;
$user_id = $_SESSION['user_id'];
$current_email = $_SESSION['user_email'] ?? ''; // Get current email from session

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_email = filter_input(INPUT_POST, 'new_email', FILTER_SANITIZE_EMAIL);
    $new_email = filter_var($new_email, FILTER_VALIDATE_EMAIL);
    $current_password = $_POST['current_password'] ?? ''; // No trimming here

    // --- Validations ---
    if (!$new_email) {
        $errors[] = 'Please enter a valid new email address.';
    }
    if (empty($current_password)) {
        $errors[] = 'Please enter your current password.';
    }
    if ($new_email && strtolower($new_email) === strtolower($current_email)) {
        $errors[] = 'The new email address cannot be the same as your current one.';
    }

    // --- Proceed if basic validations pass ---
    if (empty($errors)) {
        try {
            // Fetch current user data (including password hash)
            $stmt = $pdo->prepare("SELECT password, name FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $errors[] = 'User not found.'; // Should not happen if logged in
            } elseif (!password_verify($current_password, $user['password'])) {
                $errors[] = 'Incorrect current password.';
            } else {
                // Check if the new email is already in use by another active user
                $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt_check->execute([$new_email, $user_id]);
                if ($stmt_check->fetch()) {
                    $errors[] = 'This email address is already registered to another account.';
                } else {
                    // Check if this email is pending verification for *another* user
                    $stmt_pending = $pdo->prepare("SELECT id FROM users WHERE pending_email = ? AND id != ?");
                    $stmt_pending->execute([$new_email, $user_id]);
                     if ($stmt_pending->fetch()) {
                        $errors[] = 'This email address is currently pending verification for another account.';
                    }
                }
            }

            // --- If password is correct and email is available ---
            if (empty($errors) && $user) {
                $token = generateEmailChangeToken();
                $expiryTime = (new DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');

                // Store pending email and token in the database
                $sql_update = "UPDATE users SET
                                pending_email = ?,
                                email_change_token = ?,
                                email_change_token_expiry = ?
                               WHERE id = ?";
                $stmt_update = $pdo->prepare($sql_update);
                if ($stmt_update->execute([$new_email, $token, $expiryTime, $user_id])) {
                    // Send verification email
                    if (sendEmailChangeVerificationEmail($new_email, $token, $user['name'])) {
                        $success = "Verification email sent to " . htmlspecialchars($new_email) . ". Please check your inbox (and spam folder) and click the link within 1 hour to confirm the change.";
                        // Do NOT update session email here yet
                    } else {
                        $errors[] = 'Could not send verification email. Please try again later or contact support.';
                        // Rollback token saving? Less critical, maybe just log the error.
                         error_log("Failed to send verification email after DB update for user ID: $user_id");
                    }
                } else {
                    $errors[] = 'Could not save email change request. Please try again.';
                     error_log("Failed to update DB with pending email/token for user ID: $user_id");
                }
            }

        } catch (PDOException $e) {
            error_log("Database error during email change request: " . $e->getMessage());
            $errors[] = 'An unexpected error occurred. Please try again later.';
        } catch (Exception $e) {
             error_log("General error during email change request: " . $e->getMessage());
             $errors[] = 'An unexpected error occurred. Please try again.';
        }
    }
} else {
    // Redirect if accessed directly without POST
    header('Location: /pages/change_email.php');
    exit;
}

// Store messages in session and redirect back
$_SESSION['success'] = $success;
$_SESSION['errors'] = $errors;
header('Location: /pages/change_email.php');
exit;

?>