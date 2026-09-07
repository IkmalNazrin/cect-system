<?php
require_once '../config/db.php';
header('Content-Type: application/json');

$category = $_GET['category'] ?? '';

if (empty($category)) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM tags WHERE category_id = ? ORDER BY name");
    $stmt->execute([$category]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch tags']);
}
?>