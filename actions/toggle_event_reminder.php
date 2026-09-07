<?php
// FILE: D:\xampp\htdocs\cect_system\actions\toggle_event_reminder.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/functions.php'; // For send_json_response

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check Authentication
if (!isset($_SESSION['user_id'])) {
    send_json_response('Authentication required.', 401);
}
$userId = $_SESSION['user_id'];

// Check Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     send_json_response('Method Not Allowed.', 405);
}

// Get Input Data (expecting JSON)
$input = json_decode(file_get_contents('php://input'), true);

$registrationId = filter_var($input['registration_id'] ?? null, FILTER_VALIDATE_INT);
$sendReminderState = filter_var($input['send_reminder'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

// Validate Input
if (!$registrationId) {
    send_json_response('Invalid or missing registration ID.', 400);
}
if ($sendReminderState === null) {
    send_json_response('Invalid or missing reminder state.', 400);
}

try {
    // Prepare and execute the update
    // IMPORTANT: Include user_id in WHERE clause for security! Ensures user only updates their own registrations.
    $stmt = $pdo->prepare("
        UPDATE registrations
        SET send_reminder = :send_reminder
        WHERE id = :registration_id AND user_id = :user_id
    ");

    $stmt->bindParam(':send_reminder', $sendReminderState, PDO::PARAM_BOOL);
    $stmt->bindParam(':registration_id', $registrationId, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);

    $stmt->execute();

    // Check if the update actually happened (user owns this registration)
    if ($stmt->rowCount() > 0) {
        send_json_response('Reminder preference updated successfully.', 200);
    } else {
        // RowCount is 0 if registration ID doesn't exist OR doesn't belong to the user OR value was already set
        // We should check if the registration exists for the user to give a better error
        $checkStmt = $pdo->prepare("SELECT id FROM registrations WHERE id = :registration_id AND user_id = :user_id");
        $checkStmt->execute([':registration_id' => $registrationId, ':user_id' => $userId]);
        if ($checkStmt->fetch()) {
            // Registration exists, so the value was likely already set to the desired state
            send_json_response('Reminder preference already set to this value.', 200);
        } else {
            // Registration not found or doesn't belong to user
            send_json_response('Registration not found or access denied.', 404);
        }
    }

} catch (PDOException $e) {
    error_log("Error toggling reminder for Reg ID {$registrationId}, User ID {$userId}: " . $e->getMessage());
    send_json_response('Database error while updating preference.', 500);
}
?>