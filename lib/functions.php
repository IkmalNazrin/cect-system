<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer files
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/../config/load_env.php';

function clean_input($data) {
    return htmlspecialchars(trim($data));
}

function handle_file_upload($file, $type = 'general') {
    $valid_types = [
        'profile' => ['image/jpeg', 'image/png', 'image/gif'],
        'general' => ['image/jpeg', 'image/png', 'application/pdf']
    ];

    $max_size = 2 * 1024 * 1024; // 2MB
    $upload_dir = __DIR__ . '/../assets/images/profiles/'; // Path relative to functions.php

    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error'];
    }

    if (!in_array($file['type'], $valid_types[$type])) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }

    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File too large (max 2MB)'];
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('profile_', true) . '.' . $ext;
    $target_path = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        return ['success' => false, 'message' => 'Failed to save file'];
    }

    return ['success' => true, 'file_path' => $target_path];
}

function send_json_response($message, $status = 200, $additional = []) {
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $status >= 200 && $status < 300,
        'message' => $message
    ], $additional));
    exit;
}

/**
 * Sends an email using PHPMailer via Brevo SMTP.
 * Sends both HTML and plain text versions.
 *
 * @param string $to Recipient email address.
 * @param string $subject Email subject.
 * @param string $htmlBody The HTML version of the email body.
 * @param string $plainBody The plain text version of the email body.
 * @param string $fromEmail Sender email address.
 * @param string $fromName Sender name.
 * @param ?string $toName Recipient's name (optional, for personalization).
 * @return bool True if email sent successfully, false otherwise.
 */
function sendEmail(string $to, string $subject, string $htmlBody, string $plainBody, ?string $fromEmail = null, ?string $fromName = null, ?string $toName = null): bool
{
    if (empty($to) || empty($subject)) {
        error_log("sendEmail Error: 'To' address or 'Subject' cannot be empty.");
        return false;
    }

    $fromEmail = $fromEmail ?: cect_env('MAIL_FROM_ADDRESS', 'no-reply@cect.localhost');
    $fromName = $fromName ?: cect_env('MAIL_FROM_NAME', 'CECT System');

    $mail = new PHPMailer(true); // true enables exceptions

    try {
        //Server settings
        $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Enable verbose debug output
        $mail->Debugoutput = 'error_log'; // Output to error log
        $mail->isSMTP();
        $mail->Host       = cect_env('SMTP_HOST', 'smtp-relay.brevo.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = cect_env('SMTP_USERNAME', '');
        $mail->Password   = cect_env('SMTP_PASSWORD', '');
        $smtpEncryption = strtolower((string) cect_env('SMTP_ENCRYPTION', 'tls'));
        $mail->SMTPSecure = ($smtpEncryption === 'ssl')
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) cect_env('SMTP_PORT', '587');

        //Recipients
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to, $toName);     // Add a recipient
        
        // Set high priority for notification emails
        $mail->Priority = 1; // Highest priority
        
        // Add additional headers to improve deliverability
        $mail->addCustomHeader('X-CECT-Notification', 'true');

        // Content
        $mail->isHTML(true);                                  // Set email format to HTML
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody; //Text body for non-HTML mail clients

        $mail->send();
        error_log("sendEmail Success: Email sent to: {$to}, subject: {$subject} via Brevo/PHPMailer");
        return true;

    } catch (Exception $e) {
        error_log("sendEmail Error: PHPMailer Error -  Recipient: {$to}, Subject: {$subject}. Brevo/PHPMailer Error Info: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Checks for upcoming event registrations and sends email reminders.
 * Intended to be called by a scheduled task (cron job).
 *
 * @param PDO $pdo The database connection object.
 * @param int $hoursBeforeStart Minimum hours before event to send reminder (e.g., 24).
 * @param int $windowHours Duration of the check window in hours (e.g., 1 hour).
 * @return array Associative array with counts of 'processed', 'sent', 'failed'.
 */
function checkAndSendEventReminders(PDO $pdo, int $hoursBeforeStart = 24, int $windowHours = 1): array {
    error_log("Starting checkAndSendEventReminders function...");
    $results = ['processed' => 0, 'sent' => 0, 'failed' => 0];

    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
        $reminderWindowStart = $now->modify("+" . $hoursBeforeStart . " hours");
        $reminderWindowEnd = $reminderWindowStart->modify("+" . $windowHours . " hours");
        $windowStartStr = $reminderWindowStart->format('Y-m-d H:i:s');
        $windowEndStr = $reminderWindowEnd->format('Y-m-d H:i:s');

        error_log("Reminder check window: $windowStartStr to $windowEndStr");

        // --- MODIFIED SQL: Added r.send_reminder and u.allow_event_reminders ---
        $sql = "
            SELECT
                r.id AS registration_id,
                r.user_id,
                r.send_reminder, -- Get the per-registration flag
                u.email AS user_email,
                u.name AS user_name,
                u.allow_event_reminders, -- Get the global user preference
                e.id AS event_id,
                e.title AS event_title,
                e.event_date,
                e.event_time,
                DATE_FORMAT(e.event_date, '%W, %M %e, %Y') AS formatted_event_date,
                TIME_FORMAT(e.event_time, '%l:%i %p') AS formatted_event_time,
                COALESCE(l.city, 'Online') AS event_location
            FROM registrations r
            JOIN users u ON r.user_id = u.id
            JOIN events e ON r.event_id = e.id
            LEFT JOIN locations l ON e.city_id = l.id
            WHERE
                CONCAT(e.event_date, ' ', COALESCE(e.event_time, '00:00:00')) >= :window_start
                AND CONCAT(e.event_date, ' ', COALESCE(e.event_time, '00:00:00')) < :window_end
                AND r.reminder_sent_at IS NULL
                AND u.email IS NOT NULL AND u.email != ''
                AND u.allow_event_reminders = TRUE -- Check global user preference
                AND r.send_reminder = TRUE      -- Check per-registration preference
            ORDER BY e.event_date, e.event_time, r.user_id";
        // --- END MODIFIED SQL ---

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':window_start' => $windowStartStr,
            ':window_end' => $windowEndStr
        ]);

        $registrationsToSend = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Adjust processed count based on who actually wants reminders
        $results['processed'] = count($registrationsToSend);
        error_log("Found {$results['processed']} registrations needing reminders (global & specific preference checked).");

        if ($results['processed'] === 0) {
            error_log("No reminders to send in this window (or users opted out).");
            return $results;
        }

        // --- The rest of the sending logic remains the same ---
        $updateStmt = $pdo->prepare("UPDATE registrations SET reminder_sent_at = NOW() WHERE id = :registration_id");

        foreach ($registrationsToSend as $reg) {
            // Email sending and DB update logic... (no changes needed here)
             $userName = htmlspecialchars($reg['user_name'] ?? 'there');
            $eventTitle = htmlspecialchars($reg['event_title']);
            $eventDate = htmlspecialchars($reg['formatted_event_date']);
            $eventTime = htmlspecialchars($reg['formatted_event_time'] ?? 'All Day');
            $eventLocation = htmlspecialchars($reg['event_location']);
            $userEmail = $reg['user_email'];
            $registrationId = $reg['registration_id'];
            $eventId = $reg['event_id'];

            $subject = "Reminder: Upcoming Event - {$eventTitle}";
            $message = "
                <html><head><title>{$subject}</title></head>
                <body style='font-family: sans-serif; line-height: 1.6;'>
                <p>Hi {$userName},</p>
                <p>This is a friendly reminder about the upcoming event you registered for:</p>
                <h2 style='color: #6366F1;'>{$eventTitle}</h2>
                <p><strong>Date:</strong> {$eventDate}</p>
                <p><strong>Time:</strong> {$eventTime}</p>
                <p><strong>Location:</strong> {$eventLocation}</p>
                <p>We look forward to seeing you there!</p>
                <p>---<br>CECT System</p>
                <p><small>Event ID: {$eventId}</small></p>
                </body></html>";

            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: CECT System <no-reply@yourdomain.com>' . "\r\n";

            $plainBody = "Hi {$userName},\nReminder: {$eventTitle} on {$eventDate} at {$eventTime}. Location: {$eventLocation}.\nSee you there!\n--\nCECT System";

            // Call the sendEmail function with a validated sender
            if (sendEmail($userEmail, $subject, $message, $plainBody, 'ikmalnazrin256@gmail.com', 'CECT Reminders', $userName)) {
                error_log("Reminder email sent successfully to {$userEmail} for event ID {$eventId} (Reg ID: {$registrationId}) using sendEmail");
                $results['sent']++;
                try {
                    $updateStmt->execute([':registration_id' => $registrationId]);
                    if ($updateStmt->rowCount() === 0) {
                        error_log("Warning: Failed to mark reminder as sent for Reg ID {$registrationId}, but email was sent.");
                    }
                } catch (PDOException $updateEx) {
                    error_log("DB Update Error after sending email for Reg ID {$registrationId}: " . $updateEx->getMessage());
                }
            } else {
                $results['failed']++;
                error_log("Failed to send reminder email to {$userEmail} for event ID {$eventId} (Reg ID: {$registrationId}) using sendEmail.");
            }
            usleep(200000);
        } // End foreach

    } catch (PDOException $e) {
        error_log("Database error in checkAndSendEventReminders: " . $e->getMessage());
    } catch (Exception $e) {
        error_log("General error in checkAndSendEventReminders: " . $e->getMessage());
    }

    error_log("Finished checkAndSendEventReminders. Sent: {$results['sent']}, Failed: {$results['failed']}");
    return $results;
}

/**
 * Notifies registered users about updates to an event.
 *
 * @param PDO $pdo Database connection.
 * @param int $eventId The ID of the updated event.
 * @param array $oldEventData Associative array of event data BEFORE the update.
 * @param array $newEventData Associative array of event data AFTER the update (from POST).
 * @return array Counts of emails sent/failed.
 */
function notifyUsersOfEventUpdate(PDO $pdo, int $eventId, array $oldEventData, array $newEventData): array {
    error_log("Attempting to notify users about update for event ID: {$eventId}");
    $results = ['sent' => 0, 'failed' => 0, 'skipped_preference' => 0, 'no_changes' => 0];
    $changes = [];

    // --- Compare Key Fields ---
    // Helper function for comparison output
    $formatChange = function ($label, $old, $new) {
        return "<li><strong>{$label}:</strong> <del style='color: #999;'>{$old}</del> → <strong style='color: #D946EF;'>{$new}</strong></li>"; // Use purple for new
    };
    $formatSimpleChange = function ($label, $new) {
         return "<li><strong>{$label}:</strong> <strong style='color: #D946EF;'>{$new}</strong></li>";
    };

    // Title
    if ($oldEventData['title'] !== $newEventData['title']) {
        $changes[] = $formatChange('Title', htmlspecialchars($oldEventData['title']), htmlspecialchars($newEventData['title']));
    }
    // Date
    if ($oldEventData['event_date'] !== $newEventData['event_date']) {
        $oldDateFormatted = date('D, M j, Y', strtotime($oldEventData['event_date']));
        $newDateFormatted = date('D, M j, Y', strtotime($newEventData['event_date']));
        $changes[] = $formatChange('Date', $oldDateFormatted, $newDateFormatted);
    }
    // Time
    if ($oldEventData['event_time'] !== $newEventData['event_time']) {
        $oldTimeFormatted = date('g:i A', strtotime($oldEventData['event_time']));
        $newTimeFormatted = date('g:i A', strtotime($newEventData['event_time']));
        $changes[] = $formatChange('Time', $oldTimeFormatted, $newTimeFormatted);
    }
    // Online Status & Location/Link
    $oldIsOnline = (int) $oldEventData['is_online'];
    $newIsOnline = (int) $newEventData['is_online'];

    if ($oldIsOnline !== $newIsOnline) {
        if ($newIsOnline) {
            $changes[] = $formatSimpleChange('Event Type', 'Changed to Online');
            // Check if the primary link changed
            if ($oldEventData['google_map_link'] !== $newEventData['google_map_link']) {
                 $changes[] = $formatChange('Event Link', htmlspecialchars($oldEventData['google_map_link']), htmlspecialchars($newEventData['google_map_link']));
            }
        } else {
            $changes[] = $formatSimpleChange('Event Type', 'Changed to In-Person');
            // Compare Location (City/State)
            $oldLoc = $oldEventData['location_name'] ?: 'N/A';
            $newLoc = $newEventData['location_name'] ?: 'N/A';
            if ($oldLoc !== $newLoc) {
                 $changes[] = $formatChange('Location', $oldLoc, $newLoc);
            }
             // Check if the primary link changed (now map link)
            if ($oldEventData['google_map_link'] !== $newEventData['google_map_link']) {
                 $changes[] = $formatChange('Map Link', htmlspecialchars($oldEventData['google_map_link']), htmlspecialchars($newEventData['google_map_link']));
            }
        }
    } else {
        // Type didn't change, compare relevant fields
        if ($newIsOnline) {
            if ($oldEventData['google_map_link'] !== $newEventData['google_map_link']) {
                $changes[] = $formatChange('Event Link', htmlspecialchars($oldEventData['google_map_link']), htmlspecialchars($newEventData['google_map_link']));
            }
        } else { // In-Person
            $oldLoc = $oldEventData['location_name'] ?: 'N/A';
            $newLoc = $newEventData['location_name'] ?: 'N/A';
             if ($oldLoc !== $newLoc) {
                 $changes[] = $formatChange('Location', $oldLoc, $newLoc);
             }
             if ($oldEventData['google_map_link'] !== $newEventData['google_map_link']) {
                 $changes[] = $formatChange('Map Link', htmlspecialchars($oldEventData['google_map_link']), htmlspecialchars($newEventData['google_map_link']));
             }
        }
    }

    // Venue
    if ($oldEventData['venue'] !== $newEventData['venue']) {
        $changes[] = $formatChange('Venue/Platform', htmlspecialchars($oldEventData['venue']), htmlspecialchars($newEventData['venue']));
    }
    // Price
    $oldPrice = (float) $oldEventData['price'];
    $newPrice = (float) $newEventData['price'];
    if ($oldPrice !== $newPrice) {
         $oldPriceFormatted = $oldPrice == 0 ? 'Free' : 'RM' . number_format($oldPrice, 2);
         $newPriceFormatted = $newPrice == 0 ? 'Free' : 'RM' . number_format($newPrice, 2);
         $changes[] = $formatChange('Price', $oldPriceFormatted, $newPriceFormatted);
    }

    // --- Check if any changes were detected ---
    if (empty($changes)) {
        error_log("No significant changes detected for event ID {$eventId}. Skipping notifications.");
        $results['no_changes'] = 1; // Indicate no changes were made
        return $results;
    }

    $changesHtml = "<ul>" . implode('', $changes) . "</ul>";
    $changesPlain = "Changes:\n" . strip_tags(str_replace(['<li>', '</li>', '<del>', '</del>', '<strong>', '</strong>', '→'], ["- ", "\n", "", "", "", "", "->"], $changesHtml));

    // --- Fetch Registered Users Who Allow Reminders ---
    try {
        $sql = "SELECT u.email, u.name, u.allow_event_reminders
                FROM registrations r
                JOIN users u ON r.user_id = u.id
                WHERE r.event_id = :event_id
                  AND u.email IS NOT NULL AND u.email != ''"; // Fetch all initially
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':event_id' => $eventId]);
        $usersToNotify = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($usersToNotify)) {
             error_log("No registered users found for event ID {$eventId} to notify.");
             return $results;
        }

        error_log("Found " . count($usersToNotify) . " registered users for event ID {$eventId}. Checking preferences...");

        // --- Loop and Send Emails ---
        foreach ($usersToNotify as $user) {
            if (!$user['allow_event_reminders']) {
                $results['skipped_preference']++;
                error_log("Skipping notification for user {$user['email']} due to preference.");
                continue; // Skip this user
            }

            $userName = htmlspecialchars($user['name'] ?? 'Participant');
            $userEmail = $user['email'];
            $eventTitle = htmlspecialchars($newEventData['title']); // Use the new title

            $subject = "Update: Event '{$eventTitle}' Details Changed";

            // Format New Details for Email
            $newDetailsHtml = "<ul>";
            $newDetailsPlain = "Current Details:\n";

            $newDetailsHtml .= "<li><strong>Date:</strong> " . date('D, M j, Y', strtotime($newEventData['event_date'])) . "</li>";
            $newDetailsPlain .= "- Date: " . date('D, M j, Y', strtotime($newEventData['event_date'])) . "\n";
            $newDetailsHtml .= "<li><strong>Time:</strong> " . date('g:i A', strtotime($newEventData['event_time'])) . "</li>";
            $newDetailsPlain .= "- Time: " . date('D, M j, Y', strtotime($newEventData['event_time'])) . "\n";

            if ($newIsOnline) {
                 $newDetailsHtml .= "<li><strong>Type:</strong> Online</li>";
                 $newDetailsPlain .= "- Type: Online\n";
                 $newDetailsHtml .= "<li><strong>Event Link:</strong> <a href='" . htmlspecialchars($newEventData['google_map_link']) . "'>" . htmlspecialchars($newEventData['google_map_link']) . "</a></li>";
                 $newDetailsPlain .= "- Event Link: " . htmlspecialchars($newEventData['google_map_link']) . "\n";
            } else {
                 $newDetailsHtml .= "<li><strong>Type:</strong> In-Person</li>";
                 $newDetailsPlain .= "- Type: In-Person\n";
                 $newDetailsHtml .= "<li><strong>Location:</strong> " . htmlspecialchars($newEventData['location_name'] ?: 'N/A') . "</li>";
                 $newDetailsPlain .= "- Location: " . htmlspecialchars($newEventData['location_name'] ?: 'N/A') . "\n";
                 $newDetailsHtml .= "<li><strong>Map Link:</strong> <a href='" . htmlspecialchars($newEventData['google_map_link']) . "'>" . htmlspecialchars($newEventData['google_map_link']) . "</a></li>";
                 $newDetailsPlain .= "- Map Link: " . htmlspecialchars($newEventData['google_map_link']) . "\n";
            }
            $newDetailsHtml .= "<li><strong>Venue/Platform:</strong> " . htmlspecialchars($newEventData['venue']) . "</li>";
            $newDetailsPlain .= "- Venue/Platform: " . htmlspecialchars($newEventData['venue']) . "\n";
            $newPriceFormatted = $newPrice == 0 ? 'Free' : 'RM' . number_format($newPrice, 2);
            $newDetailsHtml .= "<li><strong>Price:</strong> {$newPriceFormatted}</li>";
            $newDetailsPlain .= "- Price: {$newPriceFormatted}\n";
            $newDetailsHtml .= "</ul>";


            // HTML Email Body
            $htmlBody = "
            <html><head><title>{$subject}</title></head>
            <body style='font-family: sans-serif; line-height: 1.6; color: #333;'>
            <p>Hi {$userName},</p>
            <p>Please note that some details for the event <strong>'{$eventTitle}'</strong> (ID: {$eventId}) that you registered for have been updated by the organizer.</p>
            <h3 style='color: #4F46E5; border-bottom: 1px solid #ddd; padding-bottom: 5px;'>Summary of Changes:</h3>
            {$changesHtml}
            <h3 style='color: #4F46E5; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 20px;'>Current Event Details:</h3>
            {$newDetailsHtml}
            <p>Please review these changes. If you have any questions, please contact the event organizer.</p>
            <p>Thank you,<br>The CECT System Team</p>
            <hr style='border: none; border-top: 1px solid #eee; margin-top: 20px;'>
            <p style='font-size: 0.8em; color: #777;'>You are receiving this email because you registered for this event and have allowed event notifications. You can manage your notification preferences in your profile settings.</p>
            </body></html>";

            // Plain Text Email Body
            $plainBody = "
            Hi {$userName},\n\n
            Please note that some details for the event '{$eventTitle}' (ID: {$eventId}) that you registered for have been updated by the organizer.\n\n
            {$changesPlain}\n\n
            {$newDetailsPlain}\n
            Please review these changes. If you have any questions, please contact the event organizer.\n\n
            Thank you,\nThe CECT System Team\n\n
            ---\n
            You are receiving this email because you registered for this event and have allowed event notifications. You can manage your notification preferences in your profile settings.";

            // Use the existing sendEmail function
            if (sendEmail($userEmail, $subject, $htmlBody, $plainBody, 'ikmalnazrin256@gmail.com', 'CECT Event Updates', $userName)) {
                $results['sent']++;
                error_log("Event update notification sent successfully to {$userEmail} for event ID {$eventId}.");
            } else {
                $results['failed']++;
                error_log("Failed to send event update notification to {$userEmail} for event ID {$eventId}.");
            }
            usleep(150000); // Small delay between emails
        }

    } catch (PDOException $e) {
        error_log("Database error while fetching users for event update notification (Event ID: {$eventId}): " . $e->getMessage());
        // Don't stop the whole process, just log the error
    } catch (Exception $e) {
         error_log("General error during event update notification process (Event ID: {$eventId}): " . $e->getMessage());
    }

    error_log("Finished notification process for event ID {$eventId}. Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped_preference']}");
    return $results;
}

/**
 * Generates a unique password reset token.
 *
 * @return string
 */
function generatePasswordResetToken(): string {
    return bin2hex(random_bytes(32)); // 64-character hex string
}

/**
 * Sends a password reset link to the user's email.
 *
 * @param PDO $pdo Database connection.
 * @param string $email User's email address.
 * @param string $resetToken Password reset token.
 * @return bool True if email sent successfully, false otherwise.
 */
function sendPasswordResetEmail(PDO $pdo, string $email, string $resetToken): bool {
    error_log("Preparing to send password reset email to: " . $email);
    $resetLink = "http://" . $_SERVER['HTTP_HOST'] . "/pages/reset_password.php?token=" . urlencode($resetToken); // Adjust URL if needed

    $subject = "CECT System - Password Reset Request";
    $htmlBody = "
        <html><body style='font-family: sans-serif;'>
        <p>Dear User,</p>
        <p>You have requested to reset your password for your CECT account.</p>
        <p>Please click on the following link to reset your password. This link is valid for 1 hour:</p>
        <p><a href='{$resetLink}'>Reset Your Password</a></p>
        <p>If you did not request a password reset, please ignore this email. Your password will remain unchanged.</p>
        <p>If you are still having trouble, please contact our support team.</p>
        <p>Best regards,<br>The CECT System Team</p>
        </body></html>
    ";
    $plainBody = "
        Dear User,\n\n
        You have requested to reset your password for your CECT account.\n\n
        Please visit the following link to reset your password. This link is valid for 1 hour:\n
        {$resetLink}\n\n
        If you did not request a password reset, please ignore this email. Your password will remain unchanged.\n\n
        If you are still having trouble, please contact our support team.\n\n
        Best regards,\nThe CECT System Team
    ";

    if (sendEmail($email, $subject, $htmlBody, $plainBody, 'ikmalnazrin256@gmail.com', 'CECT Password Reset')) {
        error_log("Password reset email sent successfully to: " . $email);
        return true;
    } else {
        error_log("Failed to send password reset email to: " . $email);
        return false;
    }
}

/**
 * Stores the password reset token in the database, linked to the user.
 *
 * @param PDO $pdo Database connection.
 * @param int $userId User ID.
 * @param string $token Password reset token.
 * @return bool True on success, false on failure.
 */
function storePasswordResetToken(PDO $pdo, int $userId, string $token): bool {
    error_log("Storing password reset token for user ID: " . $userId);
    try {
        $expiryTime = (new DateTime())->modify('+1 hour')->format('Y-m-d H:i:s'); // Token expires in 1 hour
        $sql = "INSERT INTO password_reset_tokens (user_id, token, expiry_at) VALUES (?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$userId, $token, $expiryTime]);

        if ($result) {
            error_log("Password reset token stored successfully for user ID: " . $userId);
            return true;
        } else {
            error_log("Failed to store password reset token for user ID: " . $userId . ". DB Error: " . print_r($stmt->errorInfo(), true));
            return false;
        }
    } catch (PDOException $e) {
        error_log("Database error storing password reset token for user ID: " . $userId . ": " . $e->getMessage());
        return false;
    }
}

/**
 * Verifies if a password reset token is valid and not expired.
 *
 * @param PDO $pdo Database connection.
 * @param string $token Password reset token to verify.
 * @return array|false Returns user data (id, email) on valid token, false otherwise.
 */
function verifyPasswordResetToken(PDO $pdo, string $token) {
    error_log("Verifying password reset token: " . $token);
    try {
        $now = new DateTime();
        $sql = "SELECT prt.user_id, u.email
                FROM password_reset_tokens prt
                JOIN users u ON prt.user_id = u.id
                WHERE prt.token = ? AND prt.expiry_at > ? AND prt.used_at IS NULL"; // Check if not used
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$token, $now->format('Y-m-d H:i:s')]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            error_log("Password reset token is valid for user ID: " . $result['user_id']);
            return $result; // Return user data
        } else {
            error_log("Password reset token is invalid or expired: " . $token);
            return false;
        }
    } catch (PDOException $e) {
        error_log("Database error verifying password reset token: " . $e->getMessage());
        return false;
    }
}

/**
 * Marks a password reset token as used.
 *
 * @param PDO $pdo Database connection.
 * @param string $token Password reset token.
 * @return bool True on success, false on failure.
 */
function markPasswordResetTokenAsUsed(PDO $pdo, string $token): bool {
    error_log("Marking password reset token as used: " . $token);
    try {
        $sql = "UPDATE password_reset_tokens SET used_at = NOW() WHERE token = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$token]);

        if ($result && $stmt->rowCount() > 0) {
            error_log("Password reset token marked as used successfully: " . $token);
            return true;
        } else {
            error_log("Failed to mark password reset token as used: " . $token . ". Token not found or already used.");
            return false; // Token not found or already used
        }
    } catch (PDOException $e) {
        error_log("Database error marking password reset token as used: " . $e->getMessage());
        return false;
    }
}


/**
 * Updates the user's password in the database.
 *
 * @param PDO $pdo Database connection.
 * @param int $userId User ID.
 * @param string $newPasswordHashed New hashed password.
 * @return bool True on success, false on failure.
 */
function updatePassword(PDO $pdo, int $userId, string $newPasswordHashed): bool {
    error_log("Updating password for user ID: " . $userId);
    try {
        $sql = "UPDATE users SET password = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$newPasswordHashed, $userId]);

        if ($result && $stmt->rowCount() > 0) {
            error_log("Password updated successfully for user ID: " . $userId);
            return true;
        } else {
            error_log("Failed to update password for user ID: " . $userId . ". User not found or password not updated.");
            return false; // User not found or password not updated
        }
    } catch (PDOException $e) {
        error_log("Database error updating password for user ID: " . $e->getMessage());
        return false;
    }
}

/**
 * Checks if the user's essential profile details are complete.
 *
 * @param PDO $pdo Database connection.
 * @param int $userId The ID of the user to check.
 * @return bool True if the profile is complete, false otherwise.
 */
function isUserProfileComplete(PDO $pdo, int $userId): bool {
    try {
        // Fetch the specific fields needed for the completeness check
        $stmt = $pdo->prepare("SELECT first_name, last_name, phone FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            error_log("isUserProfileComplete: User ID {$userId} not found.");
            return false; // User doesn't exist
        }

        // Trim values before checking if they are empty.
        // Allows spaces but not *only* spaces.
        $firstNameComplete = !empty(trim($user['first_name'] ?? ''));
        $lastNameComplete = !empty(trim($user['last_name'] ?? ''));
        $phoneComplete = !empty(trim($user['phone'] ?? ''));

        // Return true ONLY if all required fields have non-empty, non-whitespace values
        $isComplete = $firstNameComplete && $lastNameComplete && $phoneComplete;

        // Optional: Log the status for debugging
        // error_log("isUserProfileComplete check for User ID {$userId}: First={$firstNameComplete}, Last={$lastNameComplete}, Phone={$phoneComplete} -> Complete={$isComplete}");

        return $isComplete;

    } catch (PDOException $e) {
        error_log("Database Error in isUserProfileComplete for User ID {$userId}: " . $e->getMessage());
        return false; // Treat DB errors as incomplete for safety
    }
}

/**
 * Generates a unique email change verification token.
 *
 * @return string
 */
function generateEmailChangeToken(): string {
    return bin2hex(random_bytes(32)); // 64-character hex string
}

/**
 * Sends an email verification link to the new email address.
 *
 * @param string $toEmail The new email address to verify.
 * @param string $token The verification token.
 * @param string|null $userName Optional user's name for personalization.
 * @return bool True if email sent successfully, false otherwise.
 */
function sendEmailChangeVerificationEmail(string $toEmail, string $token, ?string $userName = null): bool {
    error_log("Preparing to send email change verification to: " . $toEmail);
    $verificationLink = APP_BASE_URL . "/pages/verify_email_change.php?token=" . urlencode($token);
    $recipientName = $userName ? htmlspecialchars($userName) : 'User';

    $subject = "CECT System - Verify Your New Email Address";
    $htmlBody = "
        <html><body style='font-family: sans-serif;'>
        <p>Dear {$recipientName},</p>
        <p>You recently requested to change your email address associated with your CECT account to this one ({$toEmail}).</p>
        <p>To complete the process, please click the link below to verify this email address. This link is valid for 1 hour:</p>
        <p><a href='{$verificationLink}' style='padding: 10px 15px; background-color: #6366F1; color: white; text-decoration: none; border-radius: 5px;'>Verify New Email Address</a></p>
        <p>If you did not request this change, please ignore this email. Your current email address will remain unchanged.</p>
        <p>Link not working? Copy and paste this URL into your browser: {$verificationLink}</p>
        <p>Best regards,<br>The CECT System Team</p>
        </body></html>
    ";
    $plainBody = "
        Dear {$recipientName},\n\n
        You recently requested to change your email address associated with your CECT account to this one ({$toEmail}).\n\n
        To complete the process, please visit the following link to verify this email address. This link is valid for 1 hour:\n
        {$verificationLink}\n\n
        If you did not request this change, please ignore this email. Your current email address will remain unchanged.\n\n
        Best regards,\nThe CECT System Team
    ";

    // Use your existing sendEmail function
    if (sendEmail($toEmail, $subject, $htmlBody, $plainBody, 'ikmalnazrin256@gmail.com', 'CECT Email Verification', $userName)) {
        error_log("Email change verification sent successfully to: " . $toEmail);
        return true;
    } else {
        error_log("Failed to send email change verification to: " . $toEmail);
        return false;
    }
}

/**
 * Verifies an email change token and retrieves user ID and pending email.
 *
 * @param PDO $pdo Database connection.
 * @param string $token Email change token.
 * @return array|false User ID and pending email on success, false otherwise.
 */
function verifyEmailChangeToken(PDO $pdo, string $token) {
    error_log("Verifying email change token: " . $token);
    try {
        $now = (new DateTime())->format('Y-m-d H:i:s');
        // Ensure the token exists, is not expired, and there's a pending email
        $sql = "SELECT id AS user_id, pending_email
                FROM users
                WHERE email_change_token = ?
                  AND email_change_token_expiry > ?
                  AND pending_email IS NOT NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$token, $now]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && !empty($result['pending_email'])) {
            error_log("Email change token is valid for user ID: " . $result['user_id'] . " for email: " . $result['pending_email']);
            return $result; // Return user_id and pending_email
        } else {
            error_log("Email change token is invalid, expired, or no pending email found: " . $token);
            return false;
        }
    } catch (PDOException $e) {
        error_log("Database error verifying email change token: " . $e->getMessage());
        return false;
    }
}

/**
 * Finalizes the email change in the database.
 *
 * @param PDO $pdo Database connection.
 * @param int $userId User ID.
 * @param string $newEmail The verified new email address.
 * @return bool True on success, false on failure.
 */
function finalizeEmailChange(PDO $pdo, int $userId, string $newEmail): bool {
     error_log("Finalizing email change for user ID: {$userId} to email: {$newEmail}");
    try {
        $sql = "UPDATE users SET
                    email = ?,
                    pending_email = NULL,
                    email_change_token = NULL,
                    email_change_token_expiry = NULL
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$newEmail, $userId]);

        if ($result && $stmt->rowCount() > 0) {
            error_log("Email successfully updated for user ID: " . $userId);
            return true;
        } else {
            error_log("Failed to finalize email update for user ID: " . $userId . ". Maybe already updated or user not found.");
            return false;
        }
    } catch (PDOException $e) {
        error_log("Database error finalizing email change for user ID: " . $userId . ": " . $e->getMessage());
        return false;
    }
}

/**
 * Notifies users about a newly created event based on their category/tag preferences.
 *
 * This function should be called AFTER a new event and its tags have been successfully
 * saved to the database.
 *
 * @param PDO $pdo Database connection object.
 * @param array $eventData An associative array containing the new event's details.
 *                         MUST include: 'id', 'title', 'category_id'
 *                         SHOULD include: 'tag_ids' (an array of tag IDs associated with the event)
 * @return array Counts of notifications attempted, sent, failed, skipped.
 */
function notifyUsersOfNewEvent(PDO $pdo, array $eventData): array {
    $eventId = $eventData['id'] ?? null;
    $eventTitle = $eventData['title'] ?? 'A New Event';
    $categoryId = $eventData['category_id'] ?? null;
    // Ensure tag_ids is always an array, even if null/not set in $eventData
    $tagIds = isset($eventData['tag_ids']) && is_array($eventData['tag_ids']) ? $eventData['tag_ids'] : [];

    // *** START ENHANCED LOGGING ***
    error_log("[notifyUsersOfNewEvent] Starting for Event ID: {$eventId}, Category ID: {$categoryId}, Tag IDs: " . implode(', ', $tagIds));
    // *** END ENHANCED LOGGING ***

    $results = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped_preference' => 0, 'no_subscribers' => 0];

    // Validate essential data
    if (!$eventId || !$categoryId) {
        error_log("[notifyUsersOfNewEvent] Error: Missing event ID ({$eventId}) or category ID ({$categoryId}) for event '{$eventTitle}'. Cannot proceed.");
        return $results; // Cannot proceed
    }

    // Sanitize tag IDs just in case (remove non-positive integers)
    $sanitizedTagIds = array_filter(array_map('intval', $tagIds), function($id) { return $id > 0; });
     error_log("[notifyUsersOfNewEvent] Sanitized Tag IDs: " . implode(', ', $sanitizedTagIds));


    try {
        // --- Find Users Subscribed to the Category OR any of the Tags ---
        $sqlFindUsers = "
            SELECT DISTINCT u.id -- Get unique user IDs
            FROM users u
            WHERE
                -- Ensure user has a valid email
                u.email IS NOT NULL AND u.email != ''
                AND (
                    -- User subscribed to this specific category
                    EXISTS (SELECT 1 FROM user_category_preferences ucp WHERE ucp.user_id = u.id AND ucp.category_id = :category_id)
        ";

        // Use NAMED parameters for clarity
        $params = [':category_id' => $categoryId];

        // Add condition for tags only if tags are provided for the event
        if (!empty($sanitizedTagIds)) {
            // Create named placeholders for IN clause :tag_id_0, :tag_id_1, ...
            $tagPlaceholders = [];
            foreach ($sanitizedTagIds as $index => $tId) {
                $key = ":tag_id_" . $index;
                $tagPlaceholders[] = $key;
                $params[$key] = $tId; // Add to parameters array with named key
            }
            $tagInClause = implode(',', $tagPlaceholders);

            // Append the OR condition for matching tags
            $sqlFindUsers .= "
                    -- OR User subscribed to any of these specific tags
                    OR EXISTS (SELECT 1 FROM user_tag_preferences utp WHERE utp.user_id = u.id AND utp.tag_id IN ($tagInClause))
            ";
        }

        // Close the parenthesis for the OR group
        $sqlFindUsers .= "
                ) -- End OR group
        ";

        // *** START ENHANCED LOGGING *** (Keep this logging)
        error_log("[notifyUsersOfNewEvent] SQL to find users: " . $sqlFindUsers);
        error_log("[notifyUsersOfNewEvent] Parameters for SQL: " . print_r($params, true));
        // *** END ENHANCED LOGGING ***

        $stmtFindUsers = $pdo->prepare($sqlFindUsers);
        $stmtFindUsers->execute($params); // Execute with named parameters
        $interestedUserIds = $stmtFindUsers->fetchAll(PDO::FETCH_COLUMN, 0);

        // *** START ENHANCED LOGGING *** (Keep this logging)
        error_log("[notifyUsersOfNewEvent] Found potentially interested user IDs: " . implode(', ', $interestedUserIds));
        // *** END ENHANCED LOGGING ***

        $results['attempted'] = count($interestedUserIds);

        if (empty($interestedUserIds)) {
            error_log("[notifyUsersOfNewEvent] No users subscribed for category {$categoryId} or relevant tags for event ID {$eventId}.");
            $results['no_subscribers'] = 1;
            return $results;
        }

        error_log("[notifyUsersOfNewEvent] Found {$results['attempted']} potentially interested users for new event ID {$eventId}. Fetching details...");

        // --- Fetch Email and Name for Interested Users ---
        // Create NAMED placeholders for the IN clause for user IDs
        $userPlaceholders = [];
        $userParams = [];
         foreach ($interestedUserIds as $index => $uId) {
             $key = ":user_id_" . $index;
             $userPlaceholders[] = $key;
             $userParams[$key] = $uId;
         }
        $userInClause = implode(',', $userPlaceholders);

        $sqlGetUsers = "SELECT id, email, name FROM users WHERE id IN ($userInClause)";
        $stmtGetUsers = $pdo->prepare($sqlGetUsers);

        // *** START ENHANCED LOGGING ***
        error_log("[notifyUsersOfNewEvent] SQL to get user details: " . $sqlGetUsers);
        error_log("[notifyUsersOfNewEvent] Parameters for user details: " . print_r($userParams, true));
        // *** END ENHANCED LOGGING ***

        $stmtGetUsers->execute($userParams);
        $usersToSend = $stmtGetUsers->fetchAll(PDO::FETCH_ASSOC);

         error_log("[notifyUsersOfNewEvent] Fetched details for " . count($usersToSend) . " users.");

        // --- Loop and Send Emails ---
        // Ensure APP_BASE_URL is defined (e.g., in config.php) or construct it
        $appBaseUrl = defined('APP_BASE_URL') ? APP_BASE_URL : 'http://' . $_SERVER['HTTP_HOST']; // Basic fallback
        $eventLink = $appBaseUrl . '/pages/event.php?id=' . $eventId;

        foreach ($usersToSend as $user) {
            $userId = $user['id'];
            $userEmail = $user['email'];
            $userName = htmlspecialchars($user['name'] ?? 'Event Enthusiast'); // Use default if name is null
            $eventTitleSafe = htmlspecialchars($eventTitle);

            $subject = "New Event Posted: {$eventTitleSafe}";

            // Simple Email Body (Customize as needed)
            $htmlBody = "
            <html><body style='font-family: sans-serif; line-height: 1.6;'>
            <p>Hi {$userName},</p>
            <p>A new event matching your interests has been posted on CECT:</p>
            <h2 style='color: #6366F1;'>{$eventTitleSafe}</h2>
            <p>This event is in a category or has tags you're following.</p>
            <p>You can view the event details and register here:</p>
            <p style='margin: 20px 0;'><a href='{$eventLink}' style='display: inline-block; padding: 12px 20px; background-color: #4F46E5; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>View Event Details</a></p>
            <p>Thank you,<br>The CECT System Team</p>
            <hr style='border: none; border-top: 1px solid #eee; margin-top: 20px;'>
            <p style='font-size: 0.8em; color: #777;'>You are receiving this email because you subscribed to notifications for certain event categories or tags. You can manage your preferences in your account settings: <a href='" . $appBaseUrl . "/pages/account_info.php'>Manage Preferences</a></p>
            </body></html>";

            $plainBody = "
            Hi {$userName},\n\n
            A new event matching your interests has been posted on CECT: {$eventTitleSafe}\n\n
            This event is in a category or has tags you're following.\n\n
            You can view the event details and register here:\n{$eventLink}\n\n
            Thank you,\nThe CECT System Team\n\n
            ---\n
            You are receiving this email because you subscribed to notifications for certain event categories or tags. You can manage your preferences in your account settings: " . $appBaseUrl . "/pages/account_info.php";

            // --- TEST EMAIL SENDER ---
            // Temporarily use a known working sender for debugging
            $senderEmail = 'ikmalnazrin256@gmail.com'; // USE THIS FOR TESTING
            // $senderEmail = 'notifications@cect.wuaze.com'; // Original - likely spam issue
            $senderName = 'CECT Event Notifications'; // Changed from 'CECT New Events' for better deliverability
            // --- END TEST ---

            error_log("[notifyUsersOfNewEvent] Attempting to send notification to {$userEmail} (User ID: {$userId}) from {$senderEmail} for event {$eventId}.");

            if (sendEmail($userEmail, $subject, $htmlBody, $plainBody, $senderEmail, $senderName, $userName)) {
                $results['sent']++;
                error_log("[notifyUsersOfNewEvent] SUCCESS sending to User ID {$userId} ({$userEmail}) for event ID {$eventId}.");
            } else {
                $results['failed']++;
                // The sendEmail function already logs the detailed error
                error_log("[notifyUsersOfNewEvent] FAILED sending to User ID {$userId} ({$userEmail}) for event ID {$eventId}. Check sendEmail logs for details.");
            }
            usleep(200000); // Increase delay slightly (200ms)
        } // End foreach user

    } catch (PDOException $e) {
        error_log("[notifyUsersOfNewEvent] Database error during notification process (Event ID: {$eventId}): " . $e->getMessage());
        // Cannot reliably return count in this case
    } catch (Exception $e) {
         error_log("[notifyUsersOfNewEvent] General error during notification process (Event ID: {$eventId}): " . $e->getMessage());
         // Cannot reliably return count
    }

    error_log("[notifyUsersOfNewEvent] Finished for Event ID {$eventId}. Attempted: {$results['attempted']}, Sent: {$results['sent']}, Failed: {$results['failed']}, No Subs: {$results['no_subscribers']}");
    return $results;
}

/**
 * Notifies followers when an organizer they follow creates a new event.
 *
 * IMPORTANT: Consider background processing for scalability if organizers
 *            can have many followers.
 *
 * @param PDO $pdo Database connection object.
 * @param int $organizerId The ID of the user who created the event.
 * @param int $eventId The ID of the newly created event.
 * @param string $eventTitle The title of the newly created event.
 * @param string $organizerName The name of the organizer.
 * @return array Counts of notifications attempted, sent, failed.
 */
function notifyOrganizerFollowers(PDO $pdo, int $organizerId, int $eventId, string $eventTitle, string $organizerName): array {
    error_log("[notifyOrganizerFollowers] Starting for Organizer ID: {$organizerId}, Event ID: {$eventId}");
    $results = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'no_followers' => 0];

    if ($organizerId <= 0 || $eventId <= 0) {
        error_log("[notifyOrganizerFollowers] Invalid Organizer ID ({$organizerId}) or Event ID ({$eventId}).");
        return $results;
    }

    try {
        // Find users who follow this organizer and have a valid email
        // FUTURE: Add check for user preference like 'u.allow_follower_notifications = TRUE'
        $sqlFindFollowers = "
            SELECT u.id, u.email, u.name
            FROM user_follows uf
            JOIN users u ON uf.user_id = u.id
            WHERE uf.organizer_id = :organizer_id
              AND u.email IS NOT NULL AND u.email != ''
        ";

        $stmtFindFollowers = $pdo->prepare($sqlFindFollowers);
        $stmtFindFollowers->bindParam(':organizer_id', $organizerId, PDO::PARAM_INT);
        $stmtFindFollowers->execute();
        $followers = $stmtFindFollowers->fetchAll(PDO::FETCH_ASSOC);

        $results['attempted'] = count($followers);

        if (empty($followers)) {
            error_log("[notifyOrganizerFollowers] No followers found (or none with email) for Organizer ID: {$organizerId}.");
            $results['no_followers'] = 1;
            return $results;
        }

        error_log("[notifyOrganizerFollowers] Found {$results['attempted']} followers for Organizer ID {$organizerId}. Preparing emails for event ID {$eventId}.");

        // Prepare common email elements
        $appBaseUrl = defined('APP_BASE_URL') ? APP_BASE_URL : 'http://' . $_SERVER['HTTP_HOST']; // Basic fallback
        $eventLink = $appBaseUrl . '/pages/event.php?id=' . $eventId;
        $eventTitleSafe = htmlspecialchars($eventTitle);
        $organizerNameSafe = htmlspecialchars($organizerName);
        $subject = "New Event from {$organizerNameSafe}: {$eventTitleSafe}";
        $senderEmail = 'ikmalnazrin256@gmail.com'; // Use a consistent sender
        $senderName = 'CECT Event Notifications';

        // Loop and Send Emails
        foreach ($followers as $follower) {
            $followerId = $follower['id'];
            $followerEmail = $follower['email'];
            // Use default if name is null/empty
            $followerName = !empty(trim($follower['name'])) ? htmlspecialchars(trim($follower['name'])) : 'Event Follower';

            // HTML Email Body
            $htmlBody = "
            <html><body style='font-family: sans-serif; line-height: 1.6;'>
            <p>Hi {$followerName},</p>
            <p>An organizer you follow, <strong>{$organizerNameSafe}</strong>, has just posted a new event:</p>
            <h2 style='color: #6366F1;'>{$eventTitleSafe}</h2>
            <p>You can view the details and register by clicking the link below:</p>
            <p style='margin: 20px 0;'><a href='{$eventLink}' style='display: inline-block; padding: 12px 20px; background-color: #4F46E5; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>View Event</a></p>
            <p>Thank you for using CECT!</p>
            <hr style='border: none; border-top: 1px solid #eee; margin-top: 20px;'>
            <p style='font-size: 0.8em; color: #777;'>You are receiving this email because you are following {$organizerNameSafe} on CECT. You can manage your followed organizers on your profile or contact support if you believe this is an error.</p>
            </body></html>"; // FUTURE: Add link to manage preferences/unfollow

            // Plain Text Email Body
            $plainBody = "
            Hi {$followerName},\n\n
            An organizer you follow, {$organizerNameSafe}, has just posted a new event: {$eventTitleSafe}\n\n
            You can view the details and register here:\n{$eventLink}\n\n
            Thank you for using CECT!\n\n
            ---\n
            You are receiving this email because you are following {$organizerNameSafe} on CECT. You can manage your followed organizers on your profile or contact support if you believe this is an error."; // FUTURE: Add link to manage preferences/unfollow

            error_log("[notifyOrganizerFollowers] Attempting to send notification to {$followerEmail} (Follower ID: {$followerId}) for event {$eventId}.");

            if (sendEmail($followerEmail, $subject, $htmlBody, $plainBody, $senderEmail, $senderName, $followerName)) {
                $results['sent']++;
            } else {
                $results['failed']++;
                error_log("[notifyOrganizerFollowers] FAILED sending follower notification to {$followerEmail} (Follower ID: {$followerId}) for event {$eventId}. Check sendEmail logs.");
            }
            // Add a small delay to avoid overwhelming the mail server
            usleep(250000); // 250 milliseconds delay between emails
        } // End foreach follower

    } catch (PDOException $e) {
        error_log("[notifyOrganizerFollowers] Database error during follower notification process (Organizer ID: {$organizerId}, Event ID: {$eventId}): " . $e->getMessage());
    } catch (Exception $e) {
         error_log("[notifyOrganizerFollowers] General error during follower notification process (Organizer ID: {$organizerId}, Event ID: {$eventId}): " . $e->getMessage());
    }

    error_log("[notifyOrganizerFollowers] Finished for Organizer ID {$organizerId}, Event ID {$eventId}. Attempted: {$results['attempted']}, Sent: {$results['sent']}, Failed: {$results['failed']}");
    return $results;
}

/**
 * Renders an HTML event card.
 * Assumes $event array contains processed_image_url and location_string.
 * Also expects organizer_name if available.
 *
 * @param array $event Associative array of event data.
 * @param bool $is_past Whether the event is in the past (for styling).
 * @return string HTML string for the event card.
 */
function render_event_card($event, $is_past = false): string {
    $card_link = "/pages/event.php?id=" . ($event['id'] ?? 0); // Use absolute path
    $event_title = htmlspecialchars($event['title'] ?? 'Untitled Event');
    $image_url = $event['processed_image_url'] ?? '/assets/images/default-event.jpg'; // Use processed URL or default
    $default_image_url = '/assets/images/default-event.jpg';
    $location = $event['location_string'] ?? 'Location TBD'; // Use processed location
    $is_online_event = ($event['is_online'] == 1);
    $event_date_formatted = isset($event['event_date']) ? date('D, M j, Y', strtotime($event['event_date'])) : 'Date TBD';
    $event_time_formatted = isset($event['event_time']) ? date('g:i A', strtotime($event['event_time'])) : 'Time TBD';
    $price = isset($event['price']) ? (float)$event['price'] : 0.0;
    $price_formatted = ($price > 0)
        ? 'RM ' . number_format($price, 2)
        : 'Free';
    $participant_count = $event['current_participant_count'] ?? 0;
    // Ensure organizer_name is checked and handled gracefully
    $organizer_name = isset($event['organizer_name']) ? htmlspecialchars($event['organizer_name']) : null;

    $card_classes = "event-card group bg-white rounded-xl shadow hover:shadow-lg transition-all duration-300 overflow-hidden flex flex-col border border-gray-100 h-full"; // Added h-full for consistent height in grids
    if ($is_past) {
        $card_classes .= " opacity-75 hover:opacity-90";
    }

    ob_start();
?>
    <a href="<?= $card_link ?>" class="<?= $card_classes ?>">
        <div class="relative h-48 bg-gray-200 overflow-hidden">
            <img src="<?= $image_url ?>"
                 alt="<?= $event_title ?>"
                 class="w-full h-full object-cover event-image transition-transform duration-300 group-hover:scale-105"
                 loading="lazy" <!-- Added lazy loading -->
                 onerror="this.onerror=null; this.src='<?= $default_image_url ?>';">

            <div class="absolute bottom-2 left-2 px-2.5 py-1 rounded-md text-sm font-semibold shadow-sm <?= ($price > 0) ? 'bg-purple-100 text-purple-800' : 'bg-green-100 text-green-800' ?>">
                <?= $price_formatted ?>
            </div>
             <?php if ($is_online_event): ?>
            <div class="absolute top-2 left-2 bg-blue-600 text-white px-2 py-0.5 rounded-full text-xs font-medium flex items-center shadow">
                <i class="fas fa-video mr-1 text-xs opacity-90"></i> Online
            </div>
            <?php endif; ?>
             <?php if ($is_past): ?>
             <div class="absolute inset-0 bg-black/20 flex items-center justify-center backdrop-blur-[1px]">
                 <span class="text-white text-sm font-semibold bg-gray-900/60 px-3 py-1 rounded">Finished</span>
             </div>
             <?php endif; ?>
        </div>
        <div class="p-5 flex flex-col flex-grow">
            <h3 class="font-semibold text-lg mb-1 text-gray-800 group-hover:text-purple-700 transition-colors line-clamp-2" title="<?= $event_title ?>"> <!-- Reduced margin bottom -->
                <?= $event_title ?>
            </h3>
            <!-- Display Organizer Name if available -->
            <?php if ($organizer_name): ?>
                <p class="text-xs text-gray-500 mb-3">By: <span class="font-medium"><?= $organizer_name ?></span></p>
            <?php endif; ?>

            <div class="text-sm text-gray-600 mb-4 space-y-1.5 flex-grow"> <!-- Added flex-grow here -->
                <div class="flex items-center">
                    <i class="fas fa-calendar-day w-4 mr-2 text-center text-gray-400 flex-shrink-0"></i>
                    <span><?= $event_date_formatted ?></span>
                </div>
                <div class="flex items-center">
                    <i class="fas fa-clock w-4 mr-2 text-center text-gray-400 flex-shrink-0"></i>
                    <span><?= $event_time_formatted ?></span>
                </div>
                <div class="flex items-center">
                    <?php if ($is_online_event): ?>
                        <i class="fas fa-video w-4 mr-2 text-center text-blue-500 flex-shrink-0"></i>
                        <span class="text-blue-700 font-medium"><?= $location ?></span>
                    <?php else: ?>
                        <i class="fas fa-map-marker-alt w-4 mr-2 text-center text-gray-400 flex-shrink-0"></i>
                        <span class="truncate" title="<?= $location ?>"><?= $location ?></span>
                    <?php endif; ?>
                </div>
                 <div class="flex items-center pt-1">
                    <i class="fas fa-users w-4 mr-2 text-center text-purple-500 flex-shrink-0"></i>
                    <span class="font-medium text-purple-700"><?= $participant_count ?></span>
                    <span class="ml-1 text-gray-500 text-xs">participant<?= $participant_count !== 1 ? 's' : '' ?> registered</span> <!-- Smaller text -->
                 </div>
            </div>
            <div class="mt-auto pt-3 border-t border-gray-100 text-right"> <!-- Removed flex-grow from parent -->
                <span class="inline-flex items-center text-sm font-medium text-purple-600 group-hover:text-purple-800 group-hover:underline">
                    View Details
                    <i class="fas fa-arrow-right ml-1.5 text-xs transition-transform duration-200 group-hover:translate-x-1"></i>
                </span>
            </div>
        </div>
    </a>
<?php
    return ob_get_clean();
}
?>