<?php
require_once '../config/db.php';
header('Content-Type: application/json');

$state = $_GET['state'] ?? '';

if (empty($state)) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT DISTINCT city FROM locations WHERE state = ? ORDER BY city");
    $stmt->execute([$state]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch cities']);
}
?>