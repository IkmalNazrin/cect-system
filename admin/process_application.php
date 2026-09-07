<?php
session_start();
require_once '../config/db.php';
require_once '../lib/functions.php'; 

// --- Security & Admin Check ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit('Invalid request method.');
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    $_SESSION['error_message'] = "Access denied.";
    header('Location: /pages/login.php');
    exit();
}

// --- CSRF Check ---
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['error_message'] = 'Invalid security token. Please try again.';
    header('Location: /pages/admin_dashboard.php?tab=review#review-section'); // Redirect back to dashboard review tab
    exit();
}


// --- Input Validation ---
$user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
$action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING, ['flags' => FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH]);
// Allow HTML in notes if needed, otherwise keep FILTER_SANITIZE_STRING or use a library like HTMLPurifier
$approval_notes = trim($_POST['approval_notes'] ?? ''); // Use $_POST directly if FILTER_SANITIZE_STRING removes too much

$errors = [];

if (!$user_id) {
    $errors['user_id'] = "Invalid user specified.";
}
if ($action !== 'approve' && $action !== 'reject') {
    $errors['action'] = "Invalid action specified.";
}
// Require notes only for rejection
if ($action === 'reject' && empty($approval_notes)) {
    $errors['approval_notes'] = "Rejection reason is required.";
}

// If validation fails for rejection, redirect back with errors
if ($action === 'reject' && !empty($errors)) {
     $_SESSION['form_errors'] = $errors;
     $_SESSION['form_errors']['user_id'] = $user_id; // Keep track of which user modal to open
     $_SESSION['form_errors']['approval_notes_old'] = $_POST['approval_notes'] ?? ''; // Repopulate notes from original POST
     header('Location: /pages/admin_dashboard.php?tab=review#review-section'); // Redirect back to dashboard review tab
     exit();
} elseif (!empty($errors)) { // Handle general errors (e.g., invalid action/user)
     $_SESSION['error_message'] = implode(' ', array_values($errors));
     header('Location: /pages/admin_dashboard.php?tab=review#review-section'); // Redirect back to dashboard review tab
     exit();
}


// --- Database Update ---
$new_status = ($action === 'approve') ? 'approved' : 'rejected';
$notes_to_save = ($action === 'approve') ? null : $approval_notes;

try {
    // Fetch user email AND name for notification before updating
    $stmt_email = $pdo->prepare("SELECT email, name, contact_person FROM users WHERE id = ? AND organizer_status = 'pending'");
    $stmt_email->execute([$user_id]);
    $applicant = $stmt_email->fetch(PDO::FETCH_ASSOC);

    if (!$applicant || empty($applicant['email'])) { // Ensure email exists
        $_SESSION['error_message'] = "Application not found, already processed, or applicant email missing.";
        error_log("Attempted to process application for user ID {$user_id}, but user/email not found or status not pending.");
        header('Location: /pages/admin_dashboard.php?tab=review#review-section');
        exit();
    }
    $applicant_email = $applicant['email'];
    // Prefer contact_person if available, fallback to user's main name
    $applicant_name = !empty($applicant['contact_person']) ? $applicant['contact_person'] : $applicant['name'];


    $pdo->beginTransaction();

    $sql = "UPDATE users SET
                organizer_status = :status,
                approval_notes = :notes
            WHERE id = :user_id AND organizer_status = 'pending'";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':status', $new_status, PDO::PARAM_STR);
    $stmt->bindParam(':notes', $notes_to_save, PDO::PARAM_STR);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);

    if ($stmt->execute() && $stmt->rowCount() > 0) {
        $pdo->commit();
        $action_past_tense = ($action === 'approve' ? 'approved' : 'rejected');
        $_SESSION['success_message'] = "Application successfully {$action_past_tense}.";

        // --- *** NEW: Send Notification to User *** ---
        $applicant_name_display = htmlspecialchars($applicant_name ?: 'Applicant');
        $subject = "Your CECT Organizer Application has been {$action_past_tense}";
        $login_link = "http://{$_SERVER['HTTP_HOST']}/pages/login.php"; // Adjust URL if needed
        $account_link = "http://{$_SERVER['HTTP_HOST']}/pages/account_info.php"; // Adjust URL if needed

        // Prepare email content based on action
        $htmlBody = "<html><body style='font-family: sans-serif; line-height: 1.6; color: #333;'>";
        $htmlBody .= "<p>Dear {$applicant_name_display},</p>";

        $plainBody = "Dear {$applicant_name_display},\n\n";

        if ($action === 'approve') {
            $htmlBody .= "<p>Congratulations! Your application to become an event organizer on the Community Event Calendar & Tracker (CECT) has been <strong>approved</strong>.</p>";
            $htmlBody .= "<p>You can now log in to your account and start creating and managing events.</p>";
            $htmlBody .= "<p><a href='{$login_link}' style='padding: 10px 15px; background-color: #10B981; color: white; text-decoration: none; border-radius: 5px;'>Log In & Create Events</a></p>";
            $htmlBody .= "<p>We look forward to seeing the great events you host!</p>";

            $plainBody .= "Congratulations! Your application to become an event organizer on the Community Event Calendar & Tracker (CECT) has been APPROVED.\n\n";
            $plainBody .= "You can now log in to your account and start creating and managing events:\n";
            $plainBody .= "{$login_link}\n\n";
            $plainBody .= "We look forward to seeing the great events you host!\n";

        } else { // Action is 'reject'
            $htmlBody .= "<p>Thank you for your interest in becoming an organizer on CECT. After reviewing your application, we regret to inform you that it has been <strong>rejected</strong> at this time.</p>";
            if (!empty($notes_to_save)) {
                $htmlBody .= "<p><strong>Reason provided by the administrator:</strong></p>";
                $htmlBody .= "<blockquote style='border-left: 4px solid #ccc; padding-left: 15px; margin-left: 10px; color: #555;'>" . nl2br(htmlspecialchars($notes_to_save)) . "</blockquote>";
            } else {
                 $htmlBody .= "<p>No specific reason was provided.</p>";
            }
            $htmlBody .= "<p>Please review the reason provided (if any). You can view your application status and notes in your account settings. Depending on the reason, you may be able to re-apply after addressing the concerns noted.</p>";
             $htmlBody .= "<p><a href='{$account_link}' style='padding: 10px 15px; background-color: #F59E0B; color: white; text-decoration: none; border-radius: 5px;'>View Account Status</a></p>";


            $plainBody .= "Thank you for your interest in becoming an organizer on CECT. After reviewing your application, we regret to inform you that it has been REJECTED at this time.\n\n";
             if (!empty($notes_to_save)) {
                $plainBody .= "Reason provided by the administrator:\n";
                $plainBody .= "------------------------------------\n";
                $plainBody .= strip_tags($notes_to_save) . "\n"; // Use strip_tags for plain text
                $plainBody .= "------------------------------------\n\n";
             } else {
                 $plainBody .= "No specific reason was provided.\n\n";
             }
            $plainBody .= "Please review the reason provided (if any). You can view your application status and notes in your account settings. Depending on the reason, you may be able to re-apply after addressing the concerns noted.\n";
            $plainBody .= "View Account Status: {$account_link}\n";
        }

        $htmlBody .= "<br><p>Regards,</p><p>The CECT Admin Team</p>";
        $htmlBody .= "</body></html>";

        $plainBody .= "\nRegards,\nThe CECT Admin Team\n";

        // Send the email using the function from functions.php
        if (!sendEmail($applicant_email, $subject, $htmlBody, $plainBody, 'ikmalnazrin256@gmail.com', 'CECT Admin Team', $applicant_name)) {
             error_log("Failed to send {$action} notification email to {$applicant_email} for user ID: {$user_id}");
             // Potentially add a different session message for the admin?
             // $_SESSION['error_message'] = "Application processed, but failed to send notification email to applicant.";
        }
        // --- *** END Send Notification to User *** ---

    } else {
         $pdo->rollBack();
         // Check if the row count is 0 because it was already processed vs. a real error
         $stmt_check_again = $pdo->prepare("SELECT organizer_status FROM users WHERE id = ?");
         $stmt_check_again->execute([$user_id]);
         $current_status = $stmt_check_again->fetchColumn();
         if ($current_status !== 'pending') {
              $_SESSION['error_message'] = "Failed to update application status. It may have been processed already.";
         } else {
              $_SESSION['error_message'] = "Failed to update application status due to an unknown database error.";
         }
         error_log("Failed to update status or rowCount was 0 for user $user_id, action $action.");
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Database Error (process_application): " . $e->getMessage());
    $_SESSION['error_message'] = "A database error occurred while processing the application.";
}

// Redirect back to the review page
header('Location: /pages/admin_dashboard.php?tab=review#review-section'); // Redirect back to dashboard review tab
exit();
?>