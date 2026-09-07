<?php
// --- actions/handle_save.php ---

session_start();
require_once '../config/db.php'; // Adjust path as needed
require_once '../lib/functions.php'; // Adjust path as needed

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An error occurred.'];

// 1. Authentication Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    $response['message'] = 'Please log in to save events.';
    echo json_encode($response);
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// 2. CSRF Check
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(400);
    $response['message'] = 'Invalid request. Please try again.';
    echo json_encode($response);
    exit;
}

// 3. Input Validation
$event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? null; // 'save' or 'unsave'

if (!$event_id || $event_id <= 0) {
    http_response_code(400);
    $response['message'] = 'Invalid event specified.';
    echo json_encode($response);
    exit;
}

if ($action !== 'save' && $action !== 'unsave') {
    http_response_code(400);
    $response['message'] = 'Invalid action specified.';
    echo json_encode($response);
    exit;
}

// 4. Database Operation
try {
    $pdo->beginTransaction();

    if ($action === 'save') {
        // Attempt to insert, ignore if already exists (due to PRIMARY KEY constraint)
        $stmtInsert = $pdo->prepare("INSERT IGNORE INTO user_saved_events (user_id, event_id) VALUES (?, ?)");
        if ($stmtInsert->execute([$user_id, $event_id])) {
            $response['success'] = true;
            $response['message'] = 'Event saved successfully!';
            $response['newState'] = 'saved'; // 'saved' or 'unsaved'
        } else {
            // This might happen if the insert itself fails for reasons other than duplicate key
            throw new Exception('Failed to save event due to a database error.');
        }
        // Check if a row was actually inserted (rowCount > 0) or if it was ignored (rowCount == 0)
        if ($stmtInsert->rowCount() > 0) {
            error_log("User $user_id saved event $event_id");
        } else {
            // Row was ignored, meaning it was already saved. Treat as success.
            $response['success'] = true;
            $response['message'] = 'Event already saved.';
            $response['newState'] = 'saved';
             error_log("User $user_id tried to save event $event_id, but it was already saved.");
        }

    } elseif ($action === 'unsave') {
        // Delete the save relationship
        $stmtDelete = $pdo->prepare("DELETE FROM user_saved_events WHERE user_id = ? AND event_id = ?");
        $stmtDelete->execute([$user_id, $event_id]);

        // Check if a row was actually deleted
        if ($stmtDelete->rowCount() > 0) {
             $response['success'] = true;
             $response['message'] = 'Event unsaved successfully.';
             $response['newState'] = 'unsaved';
             error_log("User $user_id unsaved event $event_id");
        } else {
             // If no rows deleted, they might not have saved it in the first place
             $response['success'] = true; // Treat as success (result is it's unsaved)
             $response['message'] = 'Event was not saved.';
             $response['newState'] = 'unsaved'; // Reflect current state
             error_log("User $user_id tried to unsave event $event_id, but it was not found in saves.");
        }
    }

    $pdo->commit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log("Database error in handle_save: " . $e->getMessage());
    http_response_code(500);
    $response['message'] = 'Database error processing save request.';

} catch (Exception $e) {
     if ($pdo->inTransaction()) { $pdo->rollBack(); }
     error_log("Error in handle_save: " . $e->getMessage());
     http_response_code(500);
     $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>