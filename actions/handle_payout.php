<?php
session_start();
require_once '../config/db.php'; // Adjust path if needed
require_once '../lib/functions.php'; // Adjust path if needed

header('Content-Type: application/json');

// --- Security Checks ---
// 1. Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    http_response_code(403); // Forbidden
    echo json_encode(['status' => 'error', 'message' => 'Access denied. Administrator privileges required.']);
    exit();
}

// 2. Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

// 3. CSRF Token Validation
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Invalid security token. Please refresh the page and try again.']);
    exit();
}

// --- Input Validation ---
$action = $_POST['action'] ?? null;
$event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);

if ($action !== 'mark_paid' || !$event_id) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid action or event ID.']);
    exit();
}

// --- Database Operation ---
try {
    // Check if the event exists and belongs to a non-admin organizer (optional but good practice)
    $check_stmt = $pdo->prepare("SELECT e.id FROM events e JOIN users u ON e.created_by = u.id WHERE e.id = :event_id AND u.is_admin = 0");
    $check_stmt->bindParam(':event_id', $event_id, PDO::PARAM_INT);
    $check_stmt->execute();
    $event_exists = $check_stmt->fetch();

    if (!$event_exists) {
        http_response_code(404); // Not Found
        echo json_encode(['status' => 'error', 'message' => 'Event not found or cannot be managed.']);
        exit();
    }

    // Update the payout status
    $update_stmt = $pdo->prepare("UPDATE events SET payout_status = 'paid' WHERE id = :event_id AND payout_status = 'pending'");
    $update_stmt->bindParam(':event_id', $event_id, PDO::PARAM_INT);
    $success = $update_stmt->execute();

    if ($success && $update_stmt->rowCount() > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Event payout status marked as paid.']);
    } elseif ($success) {
         // Row count is 0, likely already marked as paid
         echo json_encode(['status' => 'success', 'message' => 'Event payout status was already marked as paid.']);
    } else {
        // Execution failed
        throw new PDOException("Failed to update event payout status.");
    }

} catch (PDOException $e) {
    error_log("Handle Payout Error: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Database error processing payout status. Please try again later.']);
} catch (Exception $e) {
    error_log("Handle Payout General Error: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred.']);
}

exit();
?>