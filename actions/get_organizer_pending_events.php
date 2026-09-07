<?php
session_start();
require_once '../config/db.php'; // Adjust path if needed
require_once '../lib/functions.php'; // Adjust path if needed

header('Content-Type: application/json');

// --- Security Checks ---
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit();
}

// --- Input Validation ---
$organizer_id = filter_input(INPUT_GET, 'organizer_id', FILTER_VALIDATE_INT);

if (!$organizer_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid organizer ID.']);
    exit();
}

// --- Database Operation ---
try {
   $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.title,
            e.event_date,
            e.price,
            e.payout_status,
            -- Calculate earnings: Multiply event price by the count of paid registrations
            (SELECT COALESCE(COUNT(*), 0) * e.price
             FROM registrations r
             WHERE r.event_id = e.id AND r.payment_status = 'paid'
            ) AS event_earnings
        FROM events e
        WHERE e.created_by = :organizer_id
          AND e.price > 0              -- Only consider paid events
          AND e.event_date < CURDATE() -- <<< MODIFIED: Only fetch PAST events
        ORDER BY
            -- <<< MODIFIED: Show pending events first, then by date descending
            CASE e.payout_status WHEN 'pending' THEN 0 ELSE 1 END ASC,
            e.event_date DESC
    ");
    $stmt->bindParam(':organizer_id', $organizer_id, PDO::PARAM_INT);
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'events' => $events]);

} catch (PDOException $e) {
    error_log("Get Organizer Pending Events Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error fetching events.']);
} catch (Exception $e) {
    error_log("Get Organizer Pending Events General Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred.']);
}

exit();
?>