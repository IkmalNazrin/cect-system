<?php
// FILE: /actions/update_notification_preferences.php

// Ensure errors are logged but not displayed directly to users in production
// error_reporting(E_ALL);
// ini_set('display_errors', 0);
// ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/your/php-error.log'); // Set a writable path on your server

require_once __DIR__ . '/../config/db.php'; // Adjust path as needed
require_once __DIR__ . '/../lib/functions.php'; // Adjust path as needed

header('Content-Type: application/json');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Security Checks ---
// 1. Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}
$userId = $_SESSION['user_id'];

// 2. Check Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// 3. Check Content Type (Optional but good practice for APIs expecting JSON)
// if (strpos($_SERVER["CONTENT_TYPE"], 'application/json') === false) {
//      http_response_code(415); // Unsupported Media Type
//      echo json_encode(['success' => false, 'message' => 'Invalid content type. Expected application/json.']);
//      exit;
// }

// 4. Decode JSON Input
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400); // Bad Request
    error_log("Failed to decode JSON input: " . json_last_error_msg()); // Log the specific JSON error
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input.']);
    exit;
}

// 5. CSRF Token Validation
$token = $input['csrf_token'] ?? null;
if (!$token || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403); // Forbidden
    error_log("CSRF token mismatch for user ID {$userId}. Session Token: " . ($_SESSION['csrf_token'] ?? 'Not Set') . ", Input Token: " . ($token ?? 'Not Set'));
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

// --- Input Processing and Validation ---
$categoryIds = $input['category_ids'] ?? [];
$tagIds = $input['tag_ids'] ?? [];

// Basic validation: Ensure they are arrays
if (!is_array($categoryIds) || !is_array($tagIds)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Invalid input data format (expected arrays).']);
    exit;
}

// Sanitize: Ensure all IDs are positive integers
$sanitizedCategoryIds = array_filter(array_map('intval', $categoryIds), function($id) { return $id > 0; });
$sanitizedTagIds = array_filter(array_map('intval', $tagIds), function($id) { return $id > 0; });

// Optional: Further validation - Check if IDs actually exist in the database
// This prevents inserting invalid IDs if the frontend somehow sends them.
// Add this if needed for extra robustness, but requires extra queries.
/*
if (!empty($sanitizedCategoryIds)) {
    $placeholders = implode(',', array_fill(0, count($sanitizedCategoryIds), '?'));
    $stmtCheckCat = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE id IN ($placeholders)");
    $stmtCheckCat->execute($sanitizedCategoryIds);
    if ($stmtCheckCat->fetchColumn() != count($sanitizedCategoryIds)) {
         http_response_code(400);
         echo json_encode(['success' => false, 'message' => 'One or more category IDs are invalid.']);
         exit;
    }
}
// Similar check for tags...
*/

// --- Database Update ---
try {
    $pdo->beginTransaction();

    // --- Categories ---
    // Get current preferences
    $stmtGetOldCat = $pdo->prepare("SELECT category_id FROM user_category_preferences WHERE user_id = ?");
    $stmtGetOldCat->execute([$userId]);
    $oldCatPrefs = $stmtGetOldCat->fetchAll(PDO::FETCH_COLUMN, 0);

    // Determine which to add and which to remove
    $catsToAdd = array_diff($sanitizedCategoryIds, $oldCatPrefs);
    $catsToRemove = array_diff($oldCatPrefs, $sanitizedCategoryIds);

    // Remove preferences no longer selected
    if (!empty($catsToRemove)) {
        $placeholdersRemove = implode(',', array_fill(0, count($catsToRemove), '?'));
        $stmtRemoveCat = $pdo->prepare("DELETE FROM user_category_preferences WHERE user_id = ? AND category_id IN ($placeholdersRemove)");
        $paramsRemove = array_merge([$userId], $catsToRemove);
        $stmtRemoveCat->execute($paramsRemove);
    }

    // Add new preferences
    if (!empty($catsToAdd)) {
        $sqlCat = "INSERT INTO user_category_preferences (user_id, category_id) VALUES (?, ?)";
        $stmtCat = $pdo->prepare($sqlCat);
        foreach ($catsToAdd as $catId) {
            $stmtCat->execute([$userId, $catId]);
        }
    }

    // --- Tags ---
    // Get current preferences
    $stmtGetOldTag = $pdo->prepare("SELECT tag_id FROM user_tag_preferences WHERE user_id = ?");
    $stmtGetOldTag->execute([$userId]);
    $oldTagPrefs = $stmtGetOldTag->fetchAll(PDO::FETCH_COLUMN, 0);

    // Determine which to add and which to remove
    $tagsToAdd = array_diff($sanitizedTagIds, $oldTagPrefs);
    $tagsToRemove = array_diff($oldTagPrefs, $sanitizedTagIds);

    // Remove preferences no longer selected
    if (!empty($tagsToRemove)) {
        $placeholdersRemove = implode(',', array_fill(0, count($tagsToRemove), '?'));
        $stmtRemoveTag = $pdo->prepare("DELETE FROM user_tag_preferences WHERE user_id = ? AND tag_id IN ($placeholdersRemove)");
        $paramsRemove = array_merge([$userId], $tagsToRemove);
        $stmtRemoveTag->execute($paramsRemove);
    }

    // Add new preferences
    if (!empty($tagsToAdd)) {
        $sqlTag = "INSERT INTO user_tag_preferences (user_id, tag_id) VALUES (?, ?)";
        $stmtTag = $pdo->prepare($sqlTag);
        foreach ($tagsToAdd as $tagId) {
            $stmtTag->execute([$userId, $tagId]);
        }
    }

    $pdo->commit();

    http_response_code(200); // OK
    echo json_encode(['success' => true, 'message' => 'Notification preferences updated successfully.']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500); // Internal Server Error
    error_log("Database error updating notification preferences for user ID {$userId}: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error saving preferences. Please try again.']);
} catch (Exception $e) {
     if ($pdo->inTransaction()) {
        $pdo->rollBack(); // Rollback on general errors too
     }
     http_response_code(500); // Internal Server Error
     error_log("General error updating notification preferences for user ID {$userId}: " . $e->getMessage());
     echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
}

?>