<?php
session_start();
require_once '../config/db.php';

// Array to hold errors
$errors = [];
$success = null;

// 1. Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// --- Define Paths and Defaults ---
$default_avatar_web_path = '/assets/images/profiles/default-avatar.jpg';
$shortDefaultName = 'default-avatar.jpg'; // Potential short name in DB/session
// Physical server path to the directory where images ARE STORED
$profile_image_server_dir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/assets/images/profiles/';
// Web accessible base URL path for these images
$profile_image_web_base_path = '/assets/images/profiles/';


// 2. Check if the form was submitted via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 3. Retrieve and Sanitize Input Data
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');

    // Get the current image web path from hidden input OR session OR default
    $current_profile_image_web_path = trim($_POST['existing_profile_image'] ?? $_SESSION['profile_image'] ?? $default_avatar_web_path);

    // --- Normalization of Current Path ---
    // If the retrieved path is the short default name, normalize it to the full path
    if ($current_profile_image_web_path === $shortDefaultName) {
        $current_profile_image_web_path = $default_avatar_web_path;
    }
    // Ensure even the retrieved path doesn't somehow become empty, fall back to default full path
    elseif (empty($current_profile_image_web_path)) {
        $current_profile_image_web_path = $default_avatar_web_path;
    } // *** Added the missing closing brace here ***


    // 4. Validate Required Fields
    if (empty($first_name)) {
        $errors[] = "First name is required.";
    }
    if (empty($last_name)) {
        $errors[] = "Last name is required.";
    }
    if (empty($phone)) {
        $errors[] = "Phone number is required.";
    }

    // 5. Handle Profile Photo Upload (if a file was submitted)
    $new_profile_image_web_path = $current_profile_image_web_path; // Assume current image unless successfully updated
    $file_uploaded = false;

    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_photo'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
        $max_size = 5 * 1024 * 1024; // 5 MB

        // **Corrected MIME type check to be case-insensitive**
        $file_mime_type = strtolower(mime_content_type($file['tmp_name']));
        if (!in_array($file_mime_type, $allowed_types)) {
             $errors[] = "Invalid file type ({$file_mime_type}). Only JPG, PNG, and GIF are allowed.";
        } elseif ($file['size'] > $max_size) {
            $errors[] = "File is too large. Maximum size is 5 MB.";
        } else {
            // Ensure the target directory exists and is writable
            if (!is_dir($profile_image_server_dir)) {
                if (!mkdir($profile_image_server_dir, 0775, true)) { // Use 0775 for security
                     $errors[] = "Failed to create profile image directory. Check permissions for 'assets/images/profiles'.";
                     error_log("Error: Failed to create directory: " . $profile_image_server_dir);
                }
            } elseif (!is_writable($profile_image_server_dir)) {
                 $errors[] = "Profile image directory is not writable. Check permissions for 'assets/images/profiles'.";
                 error_log("Error: Directory not writable: " . $profile_image_server_dir);
            }

            if (empty($errors)) { // Proceed only if directory is okay
                $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                // Use user ID and timestamp for unique filename
                $new_filename = 'profile_' . $user_id . '_' . time() . '.' . strtolower($file_extension);
                $destination_server_path = $profile_image_server_dir . $new_filename;

                if (move_uploaded_file($file['tmp_name'], $destination_server_path)) {
                    // File moved successfully!
                    $file_uploaded = true;
                    // Set the NEW web path for DB saving and session update
                    $new_profile_image_web_path = $profile_image_web_base_path . $new_filename;

                    // --- Delete the OLD image ---
                    // Check if the current path exists, is not the default full path, and different from the new path
                    if ($current_profile_image_web_path &&
                        $current_profile_image_web_path !== $default_avatar_web_path &&
                        $current_profile_image_web_path !== $new_profile_image_web_path) {

                        // Construct the physical server path to the old file from its web path
                        $old_file_server_path = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $current_profile_image_web_path;

                        // Double-check it exists before trying to delete
                        if (file_exists($old_file_server_path)) {
                           if (!@unlink($old_file_server_path)) {
                                // Log error if deletion fails, but don't block user update
                                error_log("Warning: Could not delete old profile image: " . $old_file_server_path);
                           }
                        } else {
                             // Log if the expected old file doesn't exist (might indicate path issue or prior manual deletion)
                             error_log("Warning: Old profile image not found for deletion: " . $old_file_server_path);
                        }
                    }
                } else {
                    $errors[] = "Failed to move uploaded file. Check server permissions and logs.";
                    error_log("Error: move_uploaded_file failed for user ID: " . $user_id . " to " . $destination_server_path);
                }
            }
        }
    }
    // Handle other file upload errors
    elseif (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => "The uploaded file exceeds the server's maximum upload size.", // Simpler message
            UPLOAD_ERR_FORM_SIZE  => "The uploaded file exceeds the form's maximum size limit.", // Simpler message
            UPLOAD_ERR_PARTIAL    => "The file was only partially uploaded. Please try again.",
            UPLOAD_ERR_NO_TMP_DIR => "Server configuration error: Missing temporary folder.",
            UPLOAD_ERR_CANT_WRITE => "Server configuration error: Cannot write file to disk.",
            UPLOAD_ERR_EXTENSION  => "Server configuration error: A PHP extension stopped the file upload.",
        ];
        $error_code = $_FILES['profile_photo']['error'];
        $errors[] = $upload_errors[$error_code] ?? "An unknown error occurred during file upload. Code: " . $error_code;
        error_log("File Upload Error Code: " . $error_code . " for user ID: " . $user_id);
    }

    // 6. Proceed with Database Update if NO errors occurred
    if (empty($errors)) {
        try {
            // *** Ensure $new_profile_image_web_path has a valid value (fallback to default) ***
            if (empty($new_profile_image_web_path)) {
                $new_profile_image_web_path = $default_avatar_web_path; // Use the defined full default path
            }
            // Ensure first_name and last_name are not saved as empty strings if required not to be
            // (Add validation earlier if they must never be empty)

            $sql = "UPDATE users SET
                        first_name = :first_name,
                        last_name = :last_name,
                        phone = :phone,
                        address = :address,
                        city = :city,
                        country = :country,
                        pincode = :pincode,
                        profile_image = :profile_image,
                        name = CONCAT(:first_name, ' ', :last_name) -- Also update the 'name' field
                    WHERE id = :user_id";

            $stmt = $pdo->prepare($sql);

            // Bind parameters
            $stmt->bindParam(':first_name', $first_name, PDO::PARAM_STR);
            $stmt->bindParam(':last_name', $last_name, PDO::PARAM_STR);
            // Use ternary for potentially nullable fields
            $phone_param = $phone ?: null;
            $stmt->bindParam(':phone', $phone_param, PDO::PARAM_STR|PDO::PARAM_NULL);
            $address_param = $address ?: null;
            $stmt->bindParam(':address', $address_param, PDO::PARAM_STR|PDO::PARAM_NULL);
            $city_param = $city ?: null;
            $stmt->bindParam(':city', $city_param, PDO::PARAM_STR|PDO::PARAM_NULL);
            $country_param = $country ?: null;
            $stmt->bindParam(':country', $country_param, PDO::PARAM_STR|PDO::PARAM_NULL);
            $pincode_param = $pincode ?: null;
            $stmt->bindParam(':pincode', $pincode_param, PDO::PARAM_STR|PDO::PARAM_NULL);

            // Save the guaranteed full web path
            $stmt->bindParam(':profile_image', $new_profile_image_web_path, PDO::PARAM_STR);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $success = "Profile updated successfully!";

                // ***** CRITICAL: Update Session Variables *****
                $_SESSION['first_name'] = $first_name; // Update first name in session
                $_SESSION['user_name'] = $first_name . ' ' . $last_name; // Update combined name too if used
                $_SESSION['profile_image'] = $new_profile_image_web_path; // Update profile image path in session

                // Check if we were redirected here due to an incomplete profile
                if (isset($_SESSION['redirect_after_profile_update']) && !empty($_SESSION['redirect_after_profile_update'])) {
                    $redirectUrl = $_SESSION['redirect_after_profile_update'];
                    unset($_SESSION['redirect_after_profile_update']); // Clear the instruction
                    error_log("Profile updated successfully. Redirecting back to: " . $redirectUrl);
                } else {
                    // Default redirect if no specific instruction exists
                    $redirectUrl = '/pages/account_info.php';
                    error_log("Profile updated successfully. Redirecting to default: " . $redirectUrl);
                }

                // Store success message BEFORE redirecting
                $_SESSION['success_message'] = $success;

                // Redirect using the determined URL
                header('Location: ' . $redirectUrl);
                exit();

            } else {
                 $errors[] = "Failed to update profile in the database.";
                 error_log("Database Update Failed (User ID: {$user_id}): " . implode(", ", $stmt->errorInfo()));
            }

        } catch (PDOException $e) {
            error_log("Database Error - Profile Update (User ID: {$user_id}): " . $e->getMessage());
            $errors[] = "A database error occurred. Please try again later.";
        }
    }

    // 7. Store messages in session and redirect back
    $_SESSION['errors'] = $errors; // Store errors even if empty, to clear previous ones
    if ($success) {
        $_SESSION['success_message'] = $success;
    }

    header('Location: /pages/account_info.php');
    exit();

} else {
    // If not a POST request, redirect back
    $_SESSION['errors'] = ['Invalid request method.'];
    header('Location: /pages/account_info.php');
    exit();
}
?>