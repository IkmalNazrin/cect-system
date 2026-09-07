<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Turn off for production, use logs
ini_set('log_errors', 1); // Log errors instead

session_start();
require_once '../config/db.php'; // Adjust path if needed

header('Content-Type: application/json'); // We will respond with JSON

// --- Security Checks ---
// 1. Check Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// 2. Check CSRF Token
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403); // Forbidden
    error_log("CSRF token mismatch. Session: " . ($_SESSION['csrf_token'] ?? 'Not set') . " | Posted: " . ($_POST['csrf_token'] ?? 'Not set'));
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

// 3. Check if User is Logged In
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'You must be logged in to submit feedback.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// --- Input Validation ---
$event_id = isset($_POST['event_id']) ? filter_var($_POST['event_id'], FILTER_VALIDATE_INT) : null;
$comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

if (!$event_id) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Invalid event specified.']);
    exit;
}

if (empty($comment)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Feedback comment cannot be empty.']);
    exit;
}

// Optional: Add length limit check
if (mb_strlen($comment) > 1000) { // Example limit: 1000 characters
     http_response_code(400);
     echo json_encode(['success' => false, 'message' => 'Feedback comment is too long (max 1000 characters).']);
     exit;
}

try {
    // --- Event Validation ---
    $stmt = $pdo->prepare("SELECT event_date, event_time FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404); // Not Found
        echo json_encode(['success' => false, 'message' => 'Event not found.']);
        exit;
    }

    // --- Check if Event Has Passed ---
    $isPastEvent = false;
    if (isset($event['event_date']) && isset($event['event_time'])) {
        try {
            $eventDateTimeStr = $event['event_date'] . ' ' . $event['event_time'];
            $eventDateTime = new DateTime($eventDateTimeStr);
            $now = new DateTime();
            $isPastEvent = ($eventDateTime < $now);
        } catch (Exception $e) {
            error_log("DateTime Error in submit_feedback for event {$event_id}: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error processing event date.']);
            exit;
        }
    } else {
        http_response_code(500); // Event data incomplete
         echo json_encode(['success' => false, 'message' => 'Event date/time information missing.']);
         exit;
    }

    if (!$isPastEvent) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'You can only submit feedback for events that have finished.']);
        exit;
    }

    // --- Check if User was Registered (Optional but Recommended) ---
    $stmtReg = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ? AND event_id = ?");
    $stmtReg->execute([$user_id, $event_id]);
    $wasRegistered = $stmtReg->fetchColumn() > 0;

    if (!$wasRegistered) {
         http_response_code(403); // Forbidden (or 400 Bad Request)
         echo json_encode(['success' => false, 'message' => 'You can only submit feedback for events you were registered for.']);
         exit;
    }

    // --- Check if Feedback Already Submitted (using the UNIQUE constraint is efficient) ---
    // We'll handle the potential duplicate entry error below.

    // --- Insert Feedback ---
    $insertStmt = $pdo->prepare("INSERT INTO feedback (user_id, event_id, comment) VALUES (?, ?, ?)");

    try {
        if ($insertStmt->execute([$user_id, $event_id, $comment])) {
            http_response_code(201); // Created
            echo json_encode(['success' => true, 'message' => 'Thank you! Your feedback has been submitted.']);
        } else {
            // This might not be reached if PDO throws an exception, but good as fallback
            http_response_code(500);
            error_log("Feedback insertion failed without PDO exception for user {$user_id}, event {$event_id}");
            echo json_encode(['success' => false, 'message' => 'Failed to submit feedback due to a server error.']);
        }
    } catch (PDOException $e) {
        // Check for duplicate entry error (MySQL error code 1062)
        if ($e->errorInfo[1] == 1062) {
             http_response_code(409); // Conflict
             echo json_encode(['success' => false, 'message' => 'You have already submitted feedback for this event.']);
        } else {
            // Other database error
            http_response_code(500);
            error_log("Database error during feedback insertion: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error occurred while submitting feedback.']);
        }
    }

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in submit_feedback: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
} catch (Exception $e) {
    http_response_code(500);
    error_log("General error in submit_feedback: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
}

exit; // End script execution
?>