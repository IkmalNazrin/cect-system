<?php
// --- actions/handle_follow.php ---

session_start();
require_once '../config/db.php'; // Adjust path as needed
require_once '../lib/functions.php'; // Adjust path as needed

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An error occurred.'];

// 1. Authentication Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    $response['message'] = 'Please log in to follow organizers.';
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
$organizer_id = filter_input(INPUT_POST, 'organizer_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? null; // 'follow' or 'unfollow'

if (!$organizer_id || $organizer_id <= 0) {
    http_response_code(400);
    $response['message'] = 'Invalid organizer specified.';
    echo json_encode($response);
    exit;
}

if ($action !== 'follow' && $action !== 'unfollow') {
    http_response_code(400);
    $response['message'] = 'Invalid action specified.';
    echo json_encode($response);
    exit;
}

// 4. Prevent Self-Follow
if ($user_id === $organizer_id) {
    http_response_code(400);
    $response['message'] = 'You cannot follow yourself.';
    echo json_encode($response);
    exit;
}

// 5. Database Operation
try {
    $pdo->beginTransaction();

    if ($action === 'follow') {
        // Check if already following (optional but good practice)
        $stmtCheck = $pdo->prepare("SELECT 1 FROM user_follows WHERE user_id = ? AND organizer_id = ?");
        $stmtCheck->execute([$user_id, $organizer_id]);
        if ($stmtCheck->fetch()) {
            $response['success'] = true; // Already following, treat as success
            $response['message'] = 'Already following.';
            $response['newState'] = 'following'; // Reflect current state
        } else {
            // Insert new follow relationship
            $stmtInsert = $pdo->prepare("INSERT INTO user_follows (user_id, organizer_id) VALUES (?, ?)");
            if ($stmtInsert->execute([$user_id, $organizer_id])) {
                $response['success'] = true;
                $response['message'] = 'Successfully followed!';
                $response['newState'] = 'following';
            } else {
                throw new Exception('Failed to follow organizer.');
            }
        }

    } elseif ($action === 'unfollow') {
        // Delete the follow relationship
        $stmtDelete = $pdo->prepare("DELETE FROM user_follows WHERE user_id = ? AND organizer_id = ?");
        $stmtDelete->execute([$user_id, $organizer_id]);

        // Check if a row was actually deleted
        if ($stmtDelete->rowCount() > 0) {
             $response['success'] = true;
             $response['message'] = 'Successfully unfollowed.';
             $response['newState'] = 'not_following';
        } else {
             // If no rows deleted, they might not have been following
             $response['success'] = true; // Treat as success (result is they are not following)
             $response['message'] = 'Not currently following.';
             $response['newState'] = 'not_following'; // Reflect current state
        }
    }

    $pdo->commit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Database error in handle_follow: " . $e->getMessage());
    http_response_code(500);
    $response['message'] = 'Database error processing follow request.';

} catch (Exception $e) {
     if ($pdo->inTransaction()) {
         $pdo->rollBack();
     }
     error_log("Error in handle_follow: " . $e->getMessage());
     http_response_code(500);
     $response['message'] = $e->getMessage(); // Use the specific error message
}

echo json_encode($response);
exit;

?>