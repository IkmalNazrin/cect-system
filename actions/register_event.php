<?php
// This script is ONLY for registering for FREE events.
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once '../config/db.php';
require_once '../lib/functions.php'; 

header('Content-Type: application/json');

// Check login
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required. Please log in.']);
    exit;
}

// --- CSRF Check ---
// Expecting JSON input from JavaScript
$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
    exit;
}
// --- End CSRF Check ---

$user_id = (int)$_SESSION['user_id'];

// ***** START PROFILE COMPLETION CHECK *****
if (!isUserProfileComplete($pdo, $user_id)) {
    // *** THIS LINE IS CRUCIAL for the redirect back ***
    $_SESSION['redirect_after_profile_update'] = $_SERVER['HTTP_REFERER'] ?? '/pages/events.php';
    // Set error message for the account page
    $_SESSION['profile_error'] = 'Please complete your profile (First Name, Last Name, Phone Number) before registering for events.';
    http_response_code(403); // Forbidden
    echo json_encode([
       'success' => false,
       'message' => $_SESSION['profile_error'], // Send message back to JS
       'redirect' => '/pages/account_info.php?reason=incomplete_profile' // Tell JS where to go
    ]);
    exit;
}
// ***** END PROFILE COMPLETION CHECK *****

$event_id = isset($data['event_id']) ? filter_var($data['event_id'], FILTER_VALIDATE_INT) : null;

if (!$event_id || $event_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid event specified.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Fetch Event Details (Check if FREE and participant limit)
    // Lock the row to prevent race conditions on participant count check
    $stmtEvent = $pdo->prepare("SELECT price, participant_limit FROM events WHERE id = ? FOR UPDATE");
    $stmtEvent->execute([$event_id]);
    $event = $stmtEvent->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Event not found.']);
        exit;
    }

    // *** CRUCIAL: Ensure this is ONLY for FREE events ***
    if ((float)$event['price'] > 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This is a paid event. Registration process is different.']);
        exit;
    }

    // 2. Check Participant Limit (if applicable)
    if ($event['participant_limit'] !== null && $event['participant_limit'] > 0) { // Check > 0 too
        // Count only 'paid' or 'free' registrations against the limit
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND payment_status IN ('paid', 'free')");
        $stmtCount->execute([$event_id]);
        $currentParticipants = $stmtCount->fetchColumn();

        if ($currentParticipants >= $event['participant_limit']) {
            $pdo->rollBack();
             http_response_code(409); // Conflict - event full
            echo json_encode(['success' => false, 'message' => 'Sorry, this event has reached its participant limit.']);
            exit;
        }
    }

    // 3. Check if already registered (with status 'paid' or 'free')
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ? AND event_id = ? AND payment_status IN ('paid', 'free')");
    $stmtCheck->execute([$user_id, $event_id]);
    if ($stmtCheck->fetchColumn() > 0) {
        $pdo->rollBack();
         http_response_code(409); // Conflict
        echo json_encode(['success' => false, 'message' => 'You are already registered for this event.']);
        exit;
    }

    // 4. Insert registration with 'free' status
    // Make sure the combination of user_id and event_id is unique if not handled by PK/Unique index
    // Using INSERT IGNORE or ON DUPLICATE KEY UPDATE might be safer depending on DB schema
    // Simple INSERT assumes no previous pending/failed record exists for this user/event for a FREE event.
    $stmtInsert = $pdo->prepare("
        INSERT INTO registrations (user_id, event_id, registration_date, payment_status, toyyibpay_bill_code)
        VALUES (?, ?, NOW(), 'free', NULL)
        ON DUPLICATE KEY UPDATE payment_status = 'free' -- Example: Update if key exists (Requires UNIQUE index on user_id, event_id)
                                                        -- Or handle potential duplicates differently
    ");
    // Note: The ON DUPLICATE KEY UPDATE requires a UNIQUE constraint on (user_id, event_id) in your `registrations` table.
    // If you don't have that constraint, remove the `ON DUPLICATE KEY UPDATE...` part, but be aware that
    // rare race conditions could lead to duplicate entries if checks fail.

    if ($stmtInsert->execute([$user_id, $event_id])) {
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Successfully registered for the free event!']);
    } else {
        $pdo->rollBack();
        error_log("Failed to insert free registration: " . print_r($stmtInsert->errorInfo(), true));
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log("Database Error during free registration: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal database error occurred.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log("General Error during free registration: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
}

?>