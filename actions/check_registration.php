<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

require_once __DIR__ . '/../config/db.php';
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Authentication required', 401);
    }

    $event_id = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
    if (!$event_id || $event_id < 1) {
        throw new Exception('Invalid event ID', 400);
    }

    if (!$pdo) {
        throw new Exception('Database connection failed', 500);
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS registered 
        FROM registrations 
        WHERE user_id = :user_id 
        AND event_id = :event_id
    ");
    
    if ($stmt === false) {
        throw new Exception('Failed to prepare SQL statement', 500);
    }
    
    $stmt->execute([
        ':user_id' => (int)$_SESSION['user_id'],
        ':event_id' => $event_id
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result === false) {
        throw new Exception('Failed to fetch registration status', 500);
    }
    
    echo json_encode([
        'registered' => (bool)$result['registered'],
        'error' => null
    ]);

} catch (Exception $e) {
    $status_code = $e->getCode() ?: 500;
    http_response_code($status_code);
    
    error_log("[" . date('Y-m-d H:i:s') . "] Registration Check Error: " . $e->getMessage());
    
    die(json_encode([
        'error' => $e->getMessage(),
        'registered' => false
    ]));
}