<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Use logs in production
ini_set('log_errors', 1);

session_start();
require_once '../config/db.php'; // Adjust path if needed

header('Content-Type: application/json');

// --- Security & Input Checks ---

// 1. Check Request Method (Allow GET)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// 2. Check if Organizer is Logged In
if (!isset($_SESSION['user_id']) || !isset($_SESSION['organizer_status']) || $_SESSION['organizer_status'] !== 'approved') {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'Access denied. Organizer login required.']);
    exit;
}

$organizer_id = (int)$_SESSION['user_id'];

// 3. Get and Validate Event ID
$event_id = isset($_GET['event_id']) ? filter_var($_GET['event_id'], FILTER_VALIDATE_INT) : null;

if (!$event_id) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Invalid or missing event ID.']);
    exit;
}

try {
    // --- Ownership Check ---
    // Verify that the logged-in organizer actually owns this event
    $stmtOwner = $pdo->prepare("SELECT COUNT(*) FROM events WHERE id = ? AND created_by = ?");
    $stmtOwner->execute([$event_id, $organizer_id]);
    $isOwner = $stmtOwner->fetchColumn() > 0;

    if (!$isOwner) {
        http_response_code(403); // Forbidden
        error_log("Forbidden feedback access attempt: Organizer ID {$organizer_id} tried to access feedback for event ID {$event_id} which they do not own.");
        echo json_encode(['success' => false, 'message' => 'You do not have permission to view feedback for this event.']);
        exit;
    }

    // --- Fetch Feedback Data ---
    $stmtFeedback = $pdo->prepare("
        SELECT
            f.comment,
            f.submitted_at,
            u.name AS user_name,
            COALESCE(u.profile_image, 'default-avatar.jpg') AS user_profile_image
        FROM feedback f
        JOIN users u ON f.user_id = u.id
        WHERE f.event_id = ?
        ORDER BY f.submitted_at DESC
    ");
    $stmtFeedback->execute([$event_id]);
    $feedback_list = $stmtFeedback->fetchAll(PDO::FETCH_ASSOC);

    // --- Format Data for Frontend ---
    $formatted_feedback = [];
    $profile_base = '/assets/images/profiles/'; // Base path for profile images
    foreach ($feedback_list as $item) {
        // Format timestamp
        try {
            $submitted_dt = new DateTime($item['submitted_at']);
            // Example Format: "Jan 25, 2024, 10:30 AM"
            $formatted_date = $submitted_dt->format('M j, Y, g:i A');
        } catch (Exception $e) {
            $formatted_date = 'Invalid Date'; // Fallback
        }

        // Construct profile image path
        $db_image = $item['user_profile_image'];
            // $profile_base is defined outside the loop now, which is fine
            $default_avatar_filename = 'default-avatar.jpg'; // Just the filename

            if (empty($db_image) || $db_image === $default_avatar_filename) {
                // If DB value is empty or exactly 'default-avatar.jpg', use the default path
                $profile_image_path = $profile_base . $default_avatar_filename;
            } elseif (strpos($db_image, $profile_base) === 0) {
                // If the DB value ALREADY starts with the full base path, use it directly
                $profile_image_path = $db_image;
             } elseif (strpos($db_image, '/') !== false && strpos($db_image, 'profiles/') === false) {
                 // If it contains a slash but not 'profiles/', assume it's an older path structure?
                 // This might need adjustment based on how your image paths ARE actually stored.
                 // Safest bet might be to just prepend base if unsure.
                 // Let's revert to the simpler logic assuming filename or full path starting with base.
                  $profile_image_path = $profile_base . $db_image; // Fallback to prepending if not starting with base
            } elseif (strpos($db_image, '/') === false) {
                 // Assume it's just the filename and prepend the base path
                 $profile_image_path = $profile_base . $db_image;
            } else {
                 // If it contains '/' and doesn't start with the base, it might be an unexpected format.
                 // Log this or handle as needed. For now, use the value directly assuming it might be correct.
                 error_log("Unexpected profile image path format in get_feedback: " . $db_image);
                 $profile_image_path = $db_image; // Use as-is, might be wrong.
             }
            // --- End intelligent path construction ---

            // *** ONLY ADD THE ITEM ONCE ***
            $formatted_feedback[] = [
                'user_name' => htmlspecialchars($item['user_name'] ?? 'Anonymous'),
                'comment' => nl2br(htmlspecialchars($item['comment'] ?? '')), // Convert newlines and escape
                'submitted_at_formatted' => $formatted_date,
                // Use the correctly constructed path here
                'user_profile_image' => htmlspecialchars($profile_image_path)
            ];
            // *** REMOVED THE DUPLICATE ADDITION ***
    }

    echo json_encode(['success' => true, 'feedback' => $formatted_feedback]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error fetching feedback for event {$event_id}: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred while fetching feedback.']);
} catch (Exception $e) {
    http_response_code(500);
    error_log("General error fetching feedback for event {$event_id}: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
}

exit;
?>