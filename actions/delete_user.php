<?php
session_start();
require_once '../config/db.php';
require_once '../lib/functions.php';

if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    send_json_response('Access denied', 403);
}

try {
    if (!isset($_GET['user_id'])) {
        send_json_response('User ID required', 400);
    }

    $user_id = (int)$_GET['user_id'];

    if ($user_id === $_SESSION['user_id']) {
        send_json_response('You cannot delete your own account', 400);
    }

    $pdo->beginTransaction();

    // Delete user-related data from existing tables
    $tables = ['registrations']; // Only existing table
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("DELETE FROM $table WHERE user_id = ?");
        $stmt->execute([$user_id]);
    }

    // Handle events created by the user
    $stmt = $pdo->prepare("SELECT id FROM events WHERE created_by = ?");
    $stmt->execute([$user_id]);
    $eventIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($eventIds)) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        
        // Delete event registrations
        $stmt = $pdo->prepare("DELETE FROM registrations WHERE event_id IN ($placeholders)");
        $stmt->execute($eventIds);
        
        // Delete event tags
        $stmt = $pdo->prepare("DELETE FROM event_tags WHERE event_id IN ($placeholders)");
        $stmt->execute($eventIds);
        
        // Delete events
        $stmt = $pdo->prepare("DELETE FROM events WHERE created_by = ?");
        $stmt->execute([$user_id]);
    }

    // Finally delete the user
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    
    if ($stmt->rowCount() === 0) {
        send_json_response('User not found', 404);
    }

    $pdo->commit();
    send_json_response('User deleted successfully', 200);

} catch(PDOException $e) {
    $pdo->rollBack();
    error_log("Delete User Error: " . $e->getMessage());
    send_json_response('Database error: ' . $e->getMessage(), 500);
} catch(Exception $e) {
    send_json_response($e->getMessage(), 400);
}