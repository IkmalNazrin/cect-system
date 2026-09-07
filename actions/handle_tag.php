<?php
session_start();
require_once '../config/db.php'; // Adjust path as necessary
require_once '../lib/functions.php'; // Adjust path as necessary

// Set header to JSON
header('Content-Type: application/json');

// Response template
$response = ['status' => 'error', 'message' => 'An unexpected error occurred.'];

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    http_response_code(403); // Forbidden
    $response['message'] = 'Access Denied: Administrator privileges required.';
    echo json_encode($response);
    exit();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    $response['message'] = 'Invalid request method.';
    echo json_encode($response);
    exit();
}

// --- CSRF Token Validation ---
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(400); // Bad Request
    $response['message'] = 'Invalid CSRF token.';
    error_log("CSRF token mismatch for user ID: " . $_SESSION['user_id']);
    // Optionally unset the token? Only if you regenerate per request, which isn't the case here.
    // unset($_SESSION['csrf_token']);
    echo json_encode($response);
    exit;
}

// Get action type
$action = $_POST['action'] ?? null;

if (!$action) {
    http_response_code(400);
    $response['message'] = 'Action not specified.';
    echo json_encode($response);
    exit();
}

try {
    switch ($action) {
        // --- CREATE TAG ---
        case 'create':
            $name = trim($_POST['name'] ?? '');
            $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);

            if (empty($name)) {
                $response['message'] = 'Tag name cannot be empty.';
                http_response_code(400);
            } elseif ($category_id === false || $category_id === null) {
                 $response['message'] = 'Invalid or missing category.';
                 http_response_code(400);
            } else {
                 // Check if category exists
                 $stmt_check_cat = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
                 $stmt_check_cat->execute([$category_id]);
                 if ($stmt_check_cat->fetch() === false) {
                     $response['message'] = 'Selected category does not exist.';
                     http_response_code(400);
                 } else {
                     // Check for duplicate tag name within the same category
                     $stmt_check = $pdo->prepare("SELECT id FROM tags WHERE LOWER(name) = LOWER(?) AND category_id = ?");
                     $stmt_check->execute([$name, $category_id]);
                     if ($stmt_check->fetch()) {
                         $response['message'] = 'A tag with this name already exists in this category.';
                         http_response_code(409); // Conflict
                     } else {
                         $stmt = $pdo->prepare("INSERT INTO tags (name, category_id) VALUES (?, ?)");
                         if ($stmt->execute([$name, $category_id])) {
                             $response['status'] = 'success';
                             $response['message'] = 'Tag created successfully.';
                             http_response_code(201); // Created
                         } else {
                             $response['message'] = 'Failed to create tag. Database error.';
                             error_log("PDO Error creating tag: " . implode(":", $stmt->errorInfo()));
                         }
                     }
                 }
            }
            break;

        // --- UPDATE TAG ---
        case 'update':
            $tag_id = filter_input(INPUT_POST, 'tag_id', FILTER_VALIDATE_INT);
            $name = trim($_POST['name'] ?? '');
            $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);

            if ($tag_id === false || $tag_id === null) {
                $response['message'] = 'Invalid or missing tag ID.';
                http_response_code(400);
            } elseif (empty($name)) {
                $response['message'] = 'Tag name cannot be empty.';
                http_response_code(400);
            } elseif ($category_id === false || $category_id === null) {
                 $response['message'] = 'Invalid or missing category.';
                 http_response_code(400);
            } else {
                 // Check if category exists
                 $stmt_check_cat = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
                 $stmt_check_cat->execute([$category_id]);
                 if ($stmt_check_cat->fetch() === false) {
                     $response['message'] = 'Selected category does not exist.';
                     http_response_code(400);
                 } else {
                    // Check for duplicate tag name within the same category, excluding the current tag
                    $stmt_check = $pdo->prepare("SELECT id FROM tags WHERE LOWER(name) = LOWER(?) AND category_id = ? AND id != ?");
                    $stmt_check->execute([$name, $category_id, $tag_id]);
                    if ($stmt_check->fetch()) {
                        $response['message'] = 'Another tag with this name already exists in this category.';
                        http_response_code(409); // Conflict
                    } else {
                        $stmt = $pdo->prepare("UPDATE tags SET name = ?, category_id = ? WHERE id = ?");
                        if ($stmt->execute([$name, $category_id, $tag_id])) {
                            if ($stmt->rowCount() > 0) {
                                $response['status'] = 'success';
                                $response['message'] = 'Tag updated successfully.';
                            } else {
                                // Could be no change or tag not found
                                $response['status'] = 'success'; // Or 'info' maybe?
                                $response['message'] = 'No changes detected or tag not found.';
                            }
                        } else {
                            $response['message'] = 'Failed to update tag. Database error.';
                            error_log("PDO Error updating tag ID $tag_id: " . implode(":", $stmt->errorInfo()));
                        }
                    }
                 }
            }
            break;

        // --- DELETE TAG ---
        case 'delete':
            $tag_id = filter_input(INPUT_POST, 'tag_id', FILTER_VALIDATE_INT);

            if ($tag_id === false || $tag_id === null) {
                $response['message'] = 'Invalid or missing tag ID.';
                http_response_code(400);
            } else {
                // Optional: Check usage in event_tags before deleting the tag itself
                // The FK constraint should handle this, but explicit check is fine too.

                // First, delete references in event_tags (or rely on ON DELETE CASCADE if set)
                $stmt_event_tags = $pdo->prepare("DELETE FROM event_tags WHERE tag_id = ?");
                $stmt_event_tags->execute([$tag_id]);
                // Don't check rowCount here, as there might be 0 usages.

                // Now delete the tag itself
                $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ?");
                if ($stmt->execute([$tag_id])) {
                    if ($stmt->rowCount() > 0) {
                        $response['status'] = 'success';
                        $response['message'] = 'Tag deleted successfully.';
                    } else {
                        $response['message'] = 'Tag not found or already deleted.';
                        http_response_code(404); // Not Found
                    }
                } else {
                    $response['message'] = 'Failed to delete tag. Database error.';
                    error_log("PDO Error deleting tag ID $tag_id: " . implode(":", $stmt->errorInfo()));
                    // Check for FK constraint errors specifically if needed
                    if ($stmt->errorInfo()[1] == 1451) { // MySQL Foreign Key constraint error code
                       $response['message'] = 'Cannot delete tag. It might still be referenced unexpectedly.';
                       http_response_code(409); // Conflict
                    }
                }
            }
            break;

        default:
            $response['message'] = 'Invalid action specified.';
            http_response_code(400);
            break;
    }

} catch (PDOException $e) {
    error_log("Database Error in handle_tag.php: " . $e->getMessage());
    $response['message'] = 'Database error: ' . $e->getMessage(); // Provide more details for debugging if needed
    http_response_code(500); // Internal Server Error
} catch (Exception $e) {
    error_log("General Error in handle_tag.php: " . $e->getMessage());
    $response['message'] = 'An unexpected error occurred: ' . $e->getMessage();
    http_response_code(500);
}

// Ensure CSRF token is still set for subsequent requests if needed
// if (empty($_SESSION['csrf_token'])) {
//     $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
// }

echo json_encode($response);
exit();