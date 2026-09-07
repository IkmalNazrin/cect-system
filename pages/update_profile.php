<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $city = trim($_POST['city']);
    $country = trim($_POST['country']);
    $pincode = trim($_POST['pincode']);
    $existing_image = $_POST['existing_profile_image'];
    $is_default_avatar = str_contains($existing_image, 'default-avatar.jpg');

    // Validation
    if (empty($first_name) || empty($last_name) || empty($email)) {
        $errors[] = "First name, last name, and email are required.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    // Check email uniqueness
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $user_id]);
    if ($stmt->fetch()) {
        $errors[] = "Email already in use.";
    }

    // Handle file upload
    $profile_image = $existing_image;
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_photo'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 2 * 1024 * 1024; // 2MB

        if (!in_array($file['type'], $allowed)) {
            $errors[] = "Only JPG, PNG, and GIF files are allowed.";
        } elseif ($file['size'] > $max_size) {
            $errors[] = "File size must be less than 2MB.";
        } else {
            $upload_dir = '/uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = "profile_{$user_id}_" . time() . ".$ext";
            $target = $upload_dir . $filename;

            if (move_uploaded_file($file['tmp_name'], $target)) {
                $profile_image = $target;
                // Delete old image if exists
                if ($existing_image && file_exists($existing_image) && !$is_default_avatar) {
                    unlink($existing_image);
                }
            } else {
                $errors[] = "Failed to upload profile image.";
            }
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET 
                first_name = ?, 
                last_name = ?, 
                phone = ?, 
                address = ?, 
                city = ?, 
                country = ?, 
                pincode = ?, 
                profile_image = ?
                WHERE id = ?");

            $stmt->execute([
                $first_name,
                $last_name,
                $phone,
                $address,
                $city,
                $country,
                $pincode,
                $profile_image,
                $user_id
            ]);

            $_SESSION['success'] = "Profile updated successfully!";
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
    }

    header('Location: account_info.php');
    exit();
}