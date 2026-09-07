<?php
session_start();
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../lib/functions.php';

if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    send_json_response('Access denied', 403);
}

try {
    $user_id = $_POST['user_id'];
    $name = clean_input($_POST['name']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $role = $_POST['role'];

    // Validate role
    if (!in_array($role, ['admin', 'organizer', 'user'])) {
        throw new Exception('Invalid role selection');
    }

    // Prevent self-modification
    if ($user_id == $_SESSION['user_id'] && $role !== 'admin') {
        throw new Exception('You cannot modify your own admin status');
    }

    // Handle profile image upload
    $profile_image = null;
    if (!empty($_FILES['profile_image']['name'])) {
        $upload = handle_file_upload($_FILES['profile_image'], 'profile');
        if (!$upload['success']) {
            throw new Exception($upload['message']);
        }
        $profile_image = basename($upload['file_path']);
    }

    // Update user data
    $pdo->beginTransaction();

    $sql = "UPDATE users SET 
            name = ?, 
            email = ?, 
            is_admin = ?, 
            organizer_status = ?,
            profile_image = COALESCE(?, profile_image)
            WHERE id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $name,
        $email,
        $role === 'admin' ? 1 : 0,
        $role === 'organizer' ? 'approved' : null,
        $profile_image,
        $user_id
    ]);

    $pdo->commit();
    send_json_response('User updated successfully');
    
} catch (Exception $e) {
    $pdo->rollBack();
    send_json_response($e->getMessage(), 400);
}