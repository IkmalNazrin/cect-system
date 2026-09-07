<?php
session_start();
require_once '../config/db.php';
require_once '../lib/functions.php';

// Set header to return JSON
header('Content-Type: application/json');

// Initialize response array
$response = ['status' => 'error', 'message' => 'An unexpected error occurred.'];

// --- Enhanced Authorization Check ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized (not logged in)
    $response['message'] = 'Authentication required.';
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['user_id']; // Get user ID for checks
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
$is_approved_organizer = isset($_SESSION['organizer_status']) && $_SESSION['organizer_status'] === 'approved';

// User must be either an admin OR an approved organizer
if (!$is_admin && !$is_approved_organizer) {
     http_response_code(403); // Forbidden
     $response['message'] = 'Access denied. You must be an administrator or an approved organizer to manage events.';
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

// --- Determine Action ---
$action = $_POST['action'] ?? null;
$event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT) ?: null; // Get event_id early

// --- Database Operations ---
try {
    // Use a transaction for all database modifications
    $pdo->beginTransaction();

    // =========================================
    // == Handle CREATE or UPDATE Actions ==
    // =========================================
    if ($action === 'create' || $action === 'update') {

        // --- Input Sanitization and Validation ---
        // (Keep all the existing validation logic from your original code here)
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $description = trim($_POST['description'] ?? '');
        $event_date = $_POST['event_date'] ?? null;
        $event_time = $_POST['event_time'] ?? null;
        $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
        $venue = filter_input(INPUT_POST, 'venue', FILTER_SANITIZE_STRING);
        $audience = filter_input(INPUT_POST, 'audience', FILTER_SANITIZE_STRING);
        $is_online = isset($_POST['is_online']) ? (int)$_POST['is_online'] : 0;
        $price_str = $_POST['price'] ?? '0';
        $price = $price_str === '' ? 0.0 : (float)$price_str; // Treat empty string as 0
        $google_map_link = filter_input(INPUT_POST, 'google_map_link', FILTER_SANITIZE_URL);
        $city_id = filter_input(INPUT_POST, 'city_id', FILTER_VALIDATE_INT);
        $participant_limit_str = $_POST['participant_limit'] ?? null;
        $participant_limit = ($participant_limit_str === '' || $participant_limit_str === null) ? null : (int)$participant_limit_str;
        $tags = isset($_POST['tags']) && is_array($_POST['tags']) ? array_map('intval', $_POST['tags']) : [];
        $attention = trim($_POST['attention'] ?? '');
        $terms_and_conditions = trim($_POST['terms_and_conditions'] ?? '');

        // --- NEW: Store new data for potential comparison ---
        $newEventDataForComparison = [
            'title' => $title,
            'event_date' => $event_date,
            'event_time' => date('H:i:s', strtotime($event_time)), // Store consistent time format
            'is_online' => $is_online,
            'city_id' => $is_online ? null : $city_id, // Set city_id based on is_online
            'venue' => $venue,
            'price' => $price,
            'google_map_link' => $google_map_link,
            // Add other fields if you want to compare/notify about them
        ];

        // Basic Required Field Validation
        $errors = [];
        if (empty($title)) $errors[] = 'Event title is required.';
        if (empty($description)) $errors[] = 'Event description is required.';
        if (empty($event_date)) $errors[] = 'Event date is required.';
        if (empty($event_time)) $errors[] = 'Event time is required.';
        if (empty($category_id)) $errors[] = 'Category is required.';
        if (empty($venue)) $errors[] = 'Venue/Platform details are required.';
        if (empty($audience)) $errors[] = 'Target audience is required.';

        // Conditional Validation
        if ($is_online === 1) {
            if (empty($google_map_link)) $errors[] = 'Online event link is required.';
            if (!empty($google_map_link) && !filter_var($google_map_link, FILTER_VALIDATE_URL)) $errors[] = 'Invalid Online event link format.'; // Check only if not empty
            $city_id = null; // Ensure city_id is null for online events
        } else {
            if (empty($city_id)) $errors[] = 'Location (City) is required for in-person events.';
            if (empty($google_map_link)) $errors[] = 'Google Map link is required for in-person events.';
             if (!empty($google_map_link) && !filter_var($google_map_link, FILTER_VALIDATE_URL)) $errors[] = 'Invalid Google Map link format.'; // Check only if not empty
        }

        // Price Validation
        if ($price < 0) $errors[] = 'Price cannot be negative.';

        // Participant Limit Validation
        if ($participant_limit !== null && $participant_limit < 1) {
             $errors[] = 'Participant limit must be 1 or greater if specified.';
        }

        // Tag Validation
        if (count($tags) > 5) $errors[] = 'You can select a maximum of 5 tags.';

        // Image Upload Validation
        $image_url = null;
        $new_image_uploaded = false;
        if (isset($_FILES['event_image']) && $_FILES['event_image']['error'] === UPLOAD_ERR_OK) {
            // (Keep your existing image validation and upload logic here)
            $new_image_uploaded = true;
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            $maxSize = 2 * 1024 * 1024; // 2MB

            if (!in_array($_FILES['event_image']['type'], $allowedTypes)) {
                $errors[] = 'Invalid image file type. Only JPG, PNG, GIF allowed.';
            }
            if ($_FILES['event_image']['size'] > $maxSize) {
                $errors[] = 'Image file size exceeds the 2MB limit.';
            }

            if (empty($errors)) {
                $uploadDir = __DIR__ . '/../assets/images/';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0775, true)) {
                        $errors[] = 'Failed to create image upload directory.';
                        error_log("Failed to create directory: " . $uploadDir);
                    }
                }
                $fileInfo = pathinfo($_FILES['event_image']['name']);
                $safeBasename = preg_replace("/[^a-zA-Z0-9.\-_]/", "_", $fileInfo['filename']);
                $extension = isset($fileInfo['extension']) ? strtolower($fileInfo['extension']) : '';
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                    $errors[] = 'Invalid image file extension.';
                } else {
                    $filename = uniqid('event_', true) . '_' . $safeBasename . '.' . $extension;
                    $targetPath = $uploadDir . $filename;

                    if (!move_uploaded_file($_FILES['event_image']['tmp_name'], $targetPath)) {
                         $errors[] = 'Failed to upload the event image.';
                         error_log("File upload failed for: " . $_FILES['event_image']['name'] . " Error code: " . $_FILES['event_image']['error'] . " Target: " . $targetPath);
                    } else {
                        $image_url = $filename;
                    }
                }
            }
        } elseif (isset($_FILES['event_image']) && $_FILES['event_image']['error'] !== UPLOAD_ERR_NO_FILE) {
             $uploadErrors = [ /* Your upload error messages */ ];
             $errorCode = $_FILES['event_image']['error'];
             $errors[] = 'Image upload error: ' . ($uploadErrors[$errorCode] ?? 'Unknown error (Code: ' . $errorCode . ')');
        }
        // --- End Image Validation ---

        // If validation errors exist, return them
        if (!empty($errors)) {
            http_response_code(422); // Unprocessable Entity
            $response['message'] = implode(' ', $errors);
            $response['errors'] = $errors;
            $pdo->rollBack(); // Rollback before exiting
            echo json_encode($response);
            exit();
        }

        // --- Perform Create or Update DB Operation ---
        $oldEventDataForComparison = null; // Initialize variable to store old data

        if ($action === 'create') {
            $final_image_url = $image_url ?: 'default-event.jpg';
            $stmt = $pdo->prepare("
                INSERT INTO events
                (title, description, image_url, event_date, event_time, is_online,
                city_id, category_id, venue, price, participant_limit, audience, attention,
                terms_and_conditions, google_map_link, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $title, $description, $final_image_url, $event_date, $event_time, $is_online,
                $city_id, $category_id, $venue, $price, $participant_limit, $audience, $attention,
                $terms_and_conditions, $google_map_link, $user_id // Always use logged-in user as creator
            ]);
            $lastEventId = $pdo->lastInsertId();

            // Insert tags
            if (!empty($tags)) {
                $tagInsertStmt = $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)");
                foreach ($tags as $tagId) {
                    if ($tagId > 0) $tagInsertStmt->execute([$lastEventId, $tagId]);
                }
            }
            
            // --- Setup for New Event Notification ---
            if ($lastEventId) { // Ensure we have an ID
                $_SESSION['notify_new_event'] = [
                    'id' => $lastEventId,
                    'title' => $title,
                    'category_id' => $category_id,
                    'tag_ids' => $tags // Pass the array of tag IDs used
                ];
                error_log("[handle_event - create] Stored notification data for new event ID: {$lastEventId}"); // Log setup
           } else {
                error_log("[handle_event - create] ERROR: Event seemed to be created but lastEventId was not retrieved. Cannot queue notification.");
           }
           // --- New Event Notification Setup ---

            $response['status'] = 'success';
            $response['message'] = 'Event created successfully!';

        } elseif ($action === 'update' && $event_id) {

            // --- *** NEW: Fetch OLD Event Data BEFORE Update *** ---
            $owner_check_needed = !$is_admin;
            $sql_fetch_old = "
                SELECT
                    e.title, e.event_date, e.event_time, e.is_online, e.city_id, e.venue,
                    e.price, e.google_map_link, e.image_url, e.created_by,
                    l.city AS city_name, l.state AS state_name
                FROM events e
                LEFT JOIN locations l ON e.city_id = l.id
                WHERE e.id = ?";
            $fetchStmt = $pdo->prepare($sql_fetch_old);
            $fetchStmt->execute([$event_id]);
            $oldEventRawData = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldEventRawData) {
                 http_response_code(404);
                 throw new Exception("Event not found.");
            }
            // Ownership check (as before)
            if ($owner_check_needed && $oldEventRawData['created_by'] != $user_id) {
                 http_response_code(403);
                 throw new Exception("You do not have permission to update this event.");
            }

            // Prepare old data for comparison function
            $oldEventDataForComparison = [
                'title' => $oldEventRawData['title'],
                'event_date' => $oldEventRawData['event_date'],
                'event_time' => $oldEventRawData['event_time'], // Keep DB format
                'is_online' => $oldEventRawData['is_online'],
                'city_id' => $oldEventRawData['city_id'],
                'venue' => $oldEventRawData['venue'],
                'price' => $oldEventRawData['price'],
                'google_map_link' => $oldEventRawData['google_map_link'],
                'location_name' => $oldEventRawData['city_id'] ? trim($oldEventRawData['city_name'] . ', ' . $oldEventRawData['state_name']) : null, // Combined location name
                // Add other fields if needed for comparison
            ];
            $old_image_url = $oldEventRawData['image_url']; // Get old image for deletion logic
            // --- *** END Fetch OLD Event Data *** ---

            // --- Prepare and Execute UPDATE Query (as before) ---
            $sql = "UPDATE events SET
                       title = ?, description = ?, event_date = ?, event_time = ?, is_online = ?,
                       city_id = ?, category_id = ?, venue = ?, price = ?, participant_limit = ?,
                       audience = ?, attention = ?, terms_and_conditions = ?, google_map_link = ?";
            $params = [
                $title, $description, $event_date, $event_time, $is_online,
                $newEventDataForComparison['city_id'], // Use potentially adjusted city_id
                $category_id, $venue, $price, $participant_limit,
                $audience, $attention, $terms_and_conditions, $google_map_link
            ];
            $image_to_delete_on_commit = null;
            if ($new_image_uploaded && $image_url) {
                 $sql .= ", image_url = ?";
                 $params[] = $image_url;
                 if ($old_image_url && $old_image_url !== 'default-event.jpg') {
                    $image_to_delete_on_commit = $old_image_url;
                 }
            }
            $sql .= " WHERE id = ?";
            $params[] = $event_id;
            if ($owner_check_needed) {
                $sql .= " AND created_by = ?";
                $params[] = $user_id;
            }
            $stmt = $pdo->prepare($sql);
            $updateSuccess = $stmt->execute($params); // Check if execute was successful

            if (!$updateSuccess) {
                 throw new Exception("Failed to execute the event update query.");
            }

            // Update tags (delete existing, insert new)
            $pdo->prepare("DELETE FROM event_tags WHERE event_id = ?")->execute([$event_id]);
            if (!empty($tags)) {
                $tagInsertStmt = $pdo->prepare("INSERT INTO event_tags (event_id, tag_id) VALUES (?, ?)");
                foreach ($tags as $tagId) {
                     if ($tagId > 0) $tagInsertStmt->execute([$event_id, $tagId]);
                }
            }

            $response['status'] = 'success';
            $response['message'] = 'Event updated successfully!';

            // Handle image deletion AFTER successful commit (below)
            if ($image_to_delete_on_commit) {
                // Store path for deletion after commit
                $_SESSION['image_to_delete_after_commit'] = '/assets/images/' . $image_to_delete_on_commit;
            }

            // --- *** NEW: Prepare NEW Data and Trigger Notifications *** ---
            // Fetch new location name if city_id was set and potentially changed
            if (!$newEventDataForComparison['is_online'] && $newEventDataForComparison['city_id']) {
                $locStmt = $pdo->prepare("SELECT city, state FROM locations WHERE id = ?");
                $locStmt->execute([$newEventDataForComparison['city_id']]);
                $newLocData = $locStmt->fetch(PDO::FETCH_ASSOC);
                $newEventDataForComparison['location_name'] = $newLocData ? trim($newLocData['city'] . ', ' . $newLocData['state']) : 'Unknown Location';
            } else {
                 $newEventDataForComparison['location_name'] = null; // Online or no city selected
            }

            // We will call the notification function *after* the commit
            // Store data needed for notification in session or pass differently if preferred
            // For simplicity here, we'll call it just before commit, but *after* commit is technically safer.
             $_SESSION['notify_event_update'] = [
                 'event_id' => $event_id,
                 'old_data' => $oldEventDataForComparison,
                 'new_data' => $newEventDataForComparison
             ];
            // --- *** END Trigger Notifications Setup *** ---
        }

    // ==================================
    // == Handle DELETE Action ==
    // ==================================
    } elseif ($action === 'delete') {

        if (empty($event_id)) {
             http_response_code(400); // Bad Request
            throw new Exception("Event ID is required for deletion.");
        }

        // --- Check if Event Exists and Get Image URL (Ownership checked conditionally) ---
        $owner_check_needed = !$is_admin; // Admins skip owner check
        $sql_check = "SELECT image_url, price" . ($owner_check_needed ? ", created_by" : "") . " FROM events WHERE id = ?"; // Added e.price
        $checkStmt = $pdo->prepare($sql_check);
        $checkStmt->execute([$event_id]);
        $event_data = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$event_data) {
            http_response_code(404); // Not Found
            throw new Exception("Event not found.");
        }
        // If owner check is needed (not admin), verify ownership
        if ($owner_check_needed && $event_data['created_by'] != $user_id) {
            http_response_code(403); // Forbidden
            throw new Exception("You do not have permission to delete this event.");
        }
        $image_to_delete_on_commit = ($event_data['image_url'] && $event_data['image_url'] !== 'default-event.jpg') ? $event_data['image_url'] : null;

        // --- *** NEW: Check for Paid Registrations BEFORE Deletion *** ---
        $event_price = (float)($event_data['price'] ?? 0);
        if ($event_price > 0) {
            $stmtCheckPaidRegs = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND payment_status = 'paid'");
            $stmtCheckPaidRegs->execute([$event_id]);
            $paidRegistrationsCount = $stmtCheckPaidRegs->fetchColumn();

            if ($paidRegistrationsCount > 0) {
                http_response_code(403); // Forbidden (or 409 Conflict)
                throw new Exception("Cannot delete this event because it has confirmed paid registrations. Please contact support if you need to cancel or archive this event.");
            }
        }
        // --- *** END NEW Check *** ---

        // --- Perform Deletion (Order Matters: dependents first) ---

        // 1. Delete Registrations (Now only non-paid or if the above check allows)
        $pdo->prepare("DELETE FROM registrations WHERE event_id = ?")->execute([$event_id]);

        // 2. Delete Event Tags (No ownership check needed, linked by event_id)
        $pdo->prepare("DELETE FROM event_tags WHERE event_id = ?")->execute([$event_id]);

        // 3. Delete the Event itself (Conditional ownership check)
        $sql_delete = "DELETE FROM events WHERE id = ?";
        $params_delete = [$event_id];
        if ($owner_check_needed) { // Add created_by check only if NOT admin
            $sql_delete .= " AND created_by = ?";
            $params_delete[] = $user_id;
        }
        $deleteStmt = $pdo->prepare($sql_delete);
        $deleteStmt->execute($params_delete);

        if ($deleteStmt->rowCount() > 0) {
            $response['status'] = 'success';
            $response['message'] = 'Event deleted successfully!';

            // Handle image deletion AFTER successful commit (below)
            if ($image_to_delete_on_commit) {
                 $_SESSION['image_to_delete_after_commit'] = '/assets/images/' . $image_to_delete_on_commit;
            }
        } else {
             // This might happen if the event was deleted between the check and the delete,
             // or if the ownership check failed (though that should throw an exception earlier)
             throw new Exception("Failed to delete the event or event already deleted.");
        }

    } else {
        http_response_code(400); // Bad Request
        $response['message'] = 'Invalid action specified.';
        $pdo->rollBack(); // Rollback if action is invalid
        echo json_encode($response);
        exit();
    }

    // If we reach here without errors, commit the transaction
    $pdo->commit();

    // --- *** NEW: Perform Notifications AFTER Commit (if update occurred) *** ---
    if ($action === 'update' && isset($_SESSION['notify_event_update'])) {
        $notificationData = $_SESSION['notify_event_update'];
        // Call the notification function (ensure $pdo is still valid)
        try {
             notifyUsersOfEventUpdate(
                 $pdo,
                 $notificationData['event_id'],
                 $notificationData['old_data'],
                 $notificationData['new_data']
             );
        } catch (Exception $notifyEx) {
             error_log("Error during post-commit notification for event ID {$notificationData['event_id']}: " . $notifyEx->getMessage());
             // Don't fail the main response, just log the notification error
        }
        unset($_SESSION['notify_event_update']); // Clean up session variable
    }
    // --- *** END Perform Notifications *** ---

    // --- *** NEW: Perform Notifications AFTER Commit (if CREATE occurred) *** ---
    if ($action === 'create' && $lastEventId) { // Check if event was successfully created

        // 1. Notify users based on CATEGORY/TAG preferences (Original Logic)
        if (isset($_SESSION['notify_new_event'])) {
            $categoryTagNotifyData = $_SESSION['notify_new_event'];
            error_log("[handle_event] Attempting post-commit notification (Category/Tag) for NEW event ID: {$categoryTagNotifyData['id']}");
            try {
                 $catTagNotifyResults = notifyUsersOfNewEvent($pdo, $categoryTagNotifyData);
                 error_log("[handle_event] New event notification (Category/Tag) results for Event ID {$categoryTagNotifyData['id']}: " . print_r($catTagNotifyResults, true));
            } catch (Exception $notifyEx) {
                error_log("[handle_event] Error during post-commit NEW event notification (Category/Tag) for event ID {$categoryTagNotifyData['id']}: " . $notifyEx->getMessage());
            }
            unset($_SESSION['notify_new_event']); // Clean up session variable
        } else {
             error_log("[handle_event] No Category/Tag notification data found in session for event ID: {$lastEventId}.");
        }


        // 2. Notify users who FOLLOW THE ORGANIZER (New Logic)
        try {
            // Fetch organizer's name needed for the email
            $orgStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $orgStmt->execute([$user_id]); // $user_id is the creator in this context
            $organizer_name = $orgStmt->fetchColumn();

            if ($organizer_name) {
                 error_log("[handle_event] Attempting post-commit notification (Followers) for NEW event ID: {$lastEventId} by Organizer ID: {$user_id}");
                 $followerNotifyResults = notifyOrganizerFollowers(
                     $pdo,
                     $user_id,       // The organizer ID (creator)
                     $lastEventId,   // The new event ID
                     $title,         // The event title
                     $organizer_name // The organizer name
                 );
                 error_log("[handle_event] New event notification (Followers) results for Event ID {$lastEventId}: " . print_r($followerNotifyResults, true));

                 // Optional: Add notification results to the main response message if needed
                 // if (isset($response['status']) && $response['status'] === 'success') {
                 //     if (($followerNotifyResults['sent'] ?? 0) > 0) {
                 //         $response['message'] .= " Notified " . $followerNotifyResults['sent'] . " followers.";
                 //     }
                 // }

            } else {
                 error_log("[handle_event] Could not fetch organizer name for ID: {$user_id}. Skipping follower notifications.");
            }
        } catch (Exception $notifyEx) {
             error_log("[handle_event] Error during post-commit NEW event notification (Followers) for event ID {$lastEventId}: " . $notifyEx->getMessage());
        }

    } elseif ($action === 'create' && !$lastEventId) {
         error_log("[handle_event] Event creation action completed, but lastEventId is missing. Cannot trigger notifications.");
    }
    // --- *** END Perform New Event Notifications *** ---

    // --- Perform File Deletion AFTER Commit ---
    if (isset($_SESSION['image_to_delete_after_commit'])) {
        $deleteWebPath = $_SESSION['image_to_delete_after_commit'];
        // Convert web path to server path
        $deleteServerPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $deleteWebPath);
        error_log("Attempting to delete image at server path: " . $deleteServerPath); // Add logging
        if (file_exists($deleteServerPath)) {
            if (!@unlink($deleteServerPath)) { // Use @ to suppress warnings, rely on logging
                 error_log("Failed to delete image after commit: " . $deleteServerPath); // Log failure
            } else {
                 error_log("Successfully deleted image after commit: " . $deleteServerPath); // Log success
            }
        } else {
             error_log("Image file not found for deletion after commit: " . $deleteServerPath); // Log if file missing
        }
        unset($_SESSION['image_to_delete_after_commit']); // Clean up session variable
    }


} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log("Database Error: " . $e->getMessage());
    http_response_code(500);
    $response['message'] = 'A database error occurred processing the event.';
    // Clean up session vars on error
    if (isset($_SESSION['image_to_delete_after_commit'])) { unset($_SESSION['image_to_delete_after_commit']); }
    if (isset($_SESSION['notify_event_update'])) { unset($_SESSION['notify_event_update']); }

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log("General Error: " . $e->getMessage());
    if (http_response_code() < 400) { http_response_code(500); } // Use existing error code if set (e.g., 403, 404)
    $response['message'] = $e->getMessage();
    // Clean up session vars on error
    if (isset($_SESSION['image_to_delete_after_commit'])) { unset($_SESSION['image_to_delete_after_commit']); }
    if (isset($_SESSION['notify_event_update'])) { unset($_SESSION['notify_event_update']); }
}

// Output the JSON response
echo json_encode($response);
exit();