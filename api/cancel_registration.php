<?php
session_start();
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $event_id = filter_var($input['event_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$event_id) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'Invalid event ID']));
    }

    // Delete registration
    $stmt = $pdo->prepare("
        DELETE FROM registrations 
        WHERE user_id = :user_id AND event_id = :event_id
    ");
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':event_id' => $event_id
    ]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Registration not found']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}