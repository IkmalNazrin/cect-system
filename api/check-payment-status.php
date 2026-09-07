<?php
require_once '../../config/db.php';
require_once '../../lib/functions.php';

header('Content-Type: application/json');

if (!isset($_GET['event_id']) || !isset($_GET['reg_id'])) {
    http_response_code(400);    
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    exit;
}

$event_id = (int)$_GET['event_id'];
$reg_id = (int)$_GET['reg_id'];

try {
    $stmt = $pdo->prepare("SELECT payment_status FROM registrations 
                          WHERE id = ? AND event_id = ?");
    $stmt->execute([$reg_id, $event_id]);
    $status = $stmt->fetchColumn();
    
    echo json_encode(['status' => $status ?: 'not_found']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}