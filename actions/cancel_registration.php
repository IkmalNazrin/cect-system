<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/db.php';

header('Content-Type: application/json'); // Important for JS fetch

// Check login
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

// Check method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Get JSON data and CSRF token
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
    exit;
}


$event_id = isset($input['event_id']) ? filter_var($input['event_id'], FILTER_VALIDATE_INT) : null;
$user_id = (int)$_SESSION['user_id'];


if (!$event_id || $event_id <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Invalid Event ID provided.']);
    exit;
}


try {
    // Note: We don't strictly need a transaction here as it's a single DELETE,
    // but it doesn't hurt either. Let's keep it simple without one for now.

    // 1. Fetch Event Details (Date and Price) and Registration Status in one go (if possible)
    //    Or fetch event details first, then check registration.
    $stmtEvent = $pdo->prepare("SELECT event_date, price FROM events WHERE id = :event_id");
    $stmtEvent->execute(['event_id' => $event_id]);
    $event = $stmtEvent->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        // Event not found, implies registration doesn't exist.
        error_log("Attempting cancellation for non-existent event ID: {$event_id} by user {$user_id}");
        http_response_code(404); // Not Found
        echo json_encode(['success' => false, 'message' => 'Event not found.']);
        exit;
    }

    // 2. Check if the event is paid and block cancellation
    $eventPrice = isset($event['price']) ? (float)$event['price'] : 0.0;
    if ($eventPrice > 0) {
        http_response_code(403); // Forbidden
        echo json_encode(['success' => false, 'message' => 'Paid event registrations are non-refundable and cannot be cancelled.']);
        exit;
    }

    // 3. Check if the event has already passed (Only applies to FREE events now)
    $eventDate = new DateTime($event['event_date']);
    $currentDate = new DateTime();
    // Compare dates only, ignoring time for 'past event' check
    $eventDate->setTime(0, 0, 0);
    $currentDate->setTime(0, 0, 0);

    if ($eventDate < $currentDate) {
        http_response_code(403); // Forbidden
        echo json_encode(['success' => false, 'message' => 'Cannot cancel registration for past events.']);
        exit;
    }

    // 4. Check registration existence (for FREE, upcoming events)
    $stmtCheck = $pdo->prepare("SELECT id, payment_status FROM registrations WHERE user_id = :user_id AND event_id = :event_id");
    $stmtCheck->execute(['user_id' => $user_id, 'event_id' => $event_id]);
    $registration = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$registration) {
        http_response_code(404); // Not Found
        echo json_encode(['success' => false, 'message' => 'Registration not found.']);
        exit;
    }

    // --- Log Cancellation (Optional but good practice) ---
    error_log("User {$user_id} cancelled a FREE registration (ID: {$registration['id']}) for event {$event_id}.");

    // 5. Proceed with cancellation (Only for FREE, upcoming events at this point)
    $stmtDelete = $pdo->prepare("DELETE FROM registrations WHERE user_id = :user_id AND event_id = :event_id");
    $success = $stmtDelete->execute(['user_id' => $user_id, 'event_id' => $event_id]);

    if ($success && $stmtDelete->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Registration cancelled successfully.']);
        exit;
    } else {
        // rowCount might be 0 if already deleted between check and delete (race condition)
        error_log("Failed to delete FREE registration for user $user_id, event $event_id. Row count: " . $stmtDelete->rowCount());
        http_response_code(500); // Or 404 if rowCount was 0 consistently
        echo json_encode(['success' => false, 'message' => 'Cancellation failed. Please try again or contact support.']);
        exit;
    }

} catch (PDOException $e) {
    error_log("Database error during cancellation (Event: {$event_id}, User: {$user_id}): " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
    exit;
} catch (Exception $e) {
     // Catch potential DateTime errors
     error_log("General error during cancellation (Event: {$event_id}, User: {$user_id}): " . $e->getMessage());
     http_response_code(500);
     echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
     exit;
}
?>