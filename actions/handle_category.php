<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'An unexpected error occurred.'];

// --- Authorization: Admin Only ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403); // Forbidden
    $response['message'] = 'Access denied. Administrator privileges required.';
    echo json_encode($response);
    exit();
}

// --- CSRF Check ---
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
     http_response_code(400); // Bad Request
     $response['message'] = 'Invalid request (CSRF token mismatch). Please refresh and try again.';
     echo json_encode($response);
     exit();
}

$action = $_POST['action'] ?? null;
$category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
$name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
// Add description if implemented: $description = trim($_POST['description'] ?? '');

try {
    $pdo->beginTransaction();

    if ($action === 'create') {
        if (empty($name)) {
            throw new Exception('Category name is required.');
        }
        // Check if category name already exists (case-insensitive check recommended)
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE LOWER(name) = LOWER(?)");
        $stmtCheck->execute([$name]);
        if ($stmtCheck->fetchColumn() > 0) {
             throw new Exception("A category with this name already exists.");
        }

        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)"); // Add description if needed
        $stmt->execute([$name]); // Add $description if needed
        $response['status'] = 'success';
        $response['message'] = 'Category created successfully!';

    } elseif ($action === 'update') {
        if (empty($category_id) || empty($name)) {
            throw new Exception('Category ID and name are required for update.');
        }
        // Check if name exists for *another* category
         $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE LOWER(name) = LOWER(?) AND id != ?");
         $stmtCheck->execute([$name, $category_id]);
         if ($stmtCheck->fetchColumn() > 0) {
              throw new Exception("Another category with this name already exists.");
         }

        $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?"); // Add description = ? if needed
        $stmt->execute([$name, $category_id]); // Add $description if needed
        $response['status'] = 'success';
        $response['message'] = 'Category updated successfully!';

    } elseif ($action === 'delete') {
        if (empty($category_id)) {
            throw new Exception('Category ID is required for deletion.');
        }

        // --- Check for Usage Before Deletion ---
        // Check Tags
        $stmtTags = $pdo->prepare("SELECT COUNT(*) FROM tags WHERE category_id = ?");
        $stmtTags->execute([$category_id]);
        $tagCount = $stmtTags->fetchColumn();

        // Check Events
        $stmtEvents = $pdo->prepare("SELECT COUNT(*) FROM events WHERE category_id = ?");
        $stmtEvents->execute([$category_id]);
        $eventCount = $stmtEvents->fetchColumn();

        if ($tagCount > 0 || $eventCount > 0) {
            $usageMsg = "Cannot delete category. It is currently associated with";
            if ($tagCount > 0) $usageMsg .= " {$tagCount} tag(s)";
            if ($tagCount > 0 && $eventCount > 0) $usageMsg .= " and";
            if ($eventCount > 0) $usageMsg .= " {$eventCount} event(s)";
            $usageMsg .= ". Please reassign or remove these associations first.";
             http_response_code(409); // Conflict
            throw new Exception($usageMsg);
        }
        // --- End Usage Check ---


        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$category_id]);

        if ($stmt->rowCount() > 0) {
             $response['status'] = 'success';
             $response['message'] = 'Category deleted successfully!';
        } else {
             // Might happen if the category was already deleted
             http_response_code(404); // Not Found
             throw new Exception('Category not found or already deleted.');
        }


    } else {
        http_response_code(400);
        $response['message'] = 'Invalid action specified.';
        $pdo->rollBack();
        echo json_encode($response);
        exit();
    }

    $pdo->commit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log("Category DB Error: " . $e->getMessage());
    http_response_code(500);
    $response['message'] = 'Database error processing category request.';
    //$response['message'] = 'DB Error: ' . $e->getMessage(); // Debug only
} catch (Exception $e) {
     if ($pdo->inTransaction()) { $pdo->rollBack(); }
     error_log("Category General Error: " . $e->getMessage());
     if (http_response_code() < 400) { http_response_code(500); } // Use existing code if set (e.g., 400, 403, 409)
     $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit();