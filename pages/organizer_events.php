<?php
// --- pages/organizer_events.php ---

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// --- CSRF Token Generation ---
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) { die("Failed to generate security token."); }
}
// --- End CSRF Token Generation ---


require_once '../config/db.php'; // Includes APP_BASE_URL and PDO connection ($pdo)
require_once '../lib/functions.php'; // May contain helper functions

$organizer_id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;
$organizer = null;
$upcoming_events = [];
$past_events = [];
$pageTitle = "Organizer Events"; // Default title

if ($organizer_id <= 0) {
    header('Location: /404.php'); // Redirect if ID is invalid
    exit;
}

try {
    // --- 1. Fetch Organizer Details ---
    $stmtOrg = $pdo->prepare("SELECT id, name, profile_image FROM users WHERE id = :organizer_id LIMIT 1");
    $stmtOrg->bindParam(':organizer_id', $organizer_id, PDO::PARAM_INT);
    $stmtOrg->execute();
    $organizer = $stmtOrg->fetch(PDO::FETCH_ASSOC);

    // --- Check Follow Status (for logged-in users viewing someone else's profile) ---
    $current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $is_following_organizer = false;
    if ($current_user_id > 0 && $current_user_id != $organizer_id) {
        $stmtFollowCheck = $pdo->prepare("SELECT 1 FROM user_follows WHERE user_id = :user_id AND organizer_id = :organizer_id LIMIT 1");
        $stmtFollowCheck->bindParam(':user_id', $current_user_id, PDO::PARAM_INT);
        $stmtFollowCheck->bindParam(':organizer_id', $organizer_id, PDO::PARAM_INT);
        $stmtFollowCheck->execute();
        $is_following_organizer = $stmtFollowCheck->fetchColumn() !== false;
    }

    if (!$organizer) {
        // Organizer not found
        header('Location: /404.php');
        exit;
    }

    // Set page title using organizer's name
    $pageTitle = "Events by " . htmlspecialchars($organizer['name']);

    // Prepare organizer profile image URL
    $profile_img_base_url = '/assets/images/profiles/';
    $default_avatar_url = $profile_img_base_url . 'default-avatar.jpg';
    if (!empty($organizer['profile_image']) && $organizer['profile_image'] !== 'default-avatar.jpg') {
        $organizer['profile_image_path'] = $profile_img_base_url . htmlspecialchars($organizer['profile_image']);
    } else {
        $organizer['profile_image_path'] = $default_avatar_url;
    }


    // --- 2. Fetch Events by this Organizer ---
    $sqlEvents = "
        SELECT
            e.id, e.title, e.event_date, e.event_time, e.image_url, e.is_online, e.price,
            l.city, l.state,
            (SELECT COUNT(*) FROM registrations r_count
             WHERE r_count.event_id = e.id
             AND r_count.payment_status IN ('paid', 'free')) AS current_participant_count
        FROM events e
        LEFT JOIN locations l ON e.city_id = l.id AND e.is_online = 0
        WHERE e.created_by = :organizer_id
        ORDER BY e.event_date ASC, e.event_time ASC -- Fetch all, sorted chronologically
    ";

    $stmtEvents = $pdo->prepare($sqlEvents);
    $stmtEvents->bindParam(':organizer_id', $organizer_id, PDO::PARAM_INT);
    $stmtEvents->execute();
    $all_events = $stmtEvents->fetchAll(PDO::FETCH_ASSOC);

    // --- 3. Separate into Upcoming and Past Events ---
    $appTimeZone = new DateTimeZone('Asia/Kuala_Lumpur'); // SET YOUR TIMEZONE CONSISTENTLY
    $now = new DateTime('now', $appTimeZone);

    foreach ($all_events as $event) {
        try {
            $eventDateTimeStr = $event['event_date'] . ' ' . $event['event_time'];
            $eventDateTime = new DateTime($eventDateTimeStr, $appTimeZone);

            // Prepare image URL
            $event_image_base_url = '/assets/images/';
            if (!empty($event['image_url']) && $event['image_url'] !== 'default-event.jpg') {
                 $event['processed_image_url'] = $event_image_base_url . htmlspecialchars($event['image_url']);
            } else {
                 $event['processed_image_url'] = $event_image_base_url . 'default-event.jpg';
            }

             // Prepare location string
            if ($event['is_online']) {
                $event['location_string'] = 'Online Event';
            } elseif (!empty($event['city']) && !empty($event['state'])) {
                $event['location_string'] = htmlspecialchars($event['city']) . ', ' . htmlspecialchars($event['state']);
            } else {
                $event['location_string'] = 'Location TBD';
            }


            if ($eventDateTime >= $now) {
                $upcoming_events[] = $event;
            } else {
                $past_events[] = $event; // Add to past events
            }
        } catch (Exception $e) {
            error_log("Error processing date for event ID {$event['id']} on organizer page: " . $e->getMessage());
            // Optionally add to a 'could not process' list or just skip
        }
    }
    // Reverse past events so the most recently finished are first
    $past_events = array_reverse($past_events);


} catch (PDOException $e) {
    error_log("Database error on organizer events page (ID: {$organizer_id}): " . $e->getMessage());
    // Redirect to a generic error page or display a message
    die("Sorry, there was a problem loading the organizer's events. Please try again later."); // Simple error for now
} catch (Exception $e) {
    error_log("General error on organizer events page (ID: {$organizer_id}): " . $e->getMessage());
    die("Sorry, an unexpected error occurred."); // Simple error for now
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"> 
    <title><?= $pageTitle ?> - CECT</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        /* Add any specific styles if needed, otherwise rely on Tailwind */
        .event-card:hover .event-image { transform: scale(1.05); }
        .event-image { transition: transform 0.3s ease-out; }
    </style>
</head>
<body class="bg-gradient-to-b from-white via-purple-200 via-purple-400 to-purple-600 min-h-screen bg-fixed bg-no-repeat bg-cover">
<?php include '../includes/nav.php'; ?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">

    <!-- Organizer Header -->
    <div class="flex items-center gap-4 md:gap-6 mb-8 md:mb-12 p-6 bg-white rounded-xl shadow-md border border-gray-200">
        <img src="<?= htmlspecialchars($organizer['profile_image_path']) ?>"
             alt="<?= htmlspecialchars($organizer['name']) ?>'s profile picture"
             class="w-16 h-16 md:w-20 md:h-20 rounded-full object-cover border-2 border-purple-200 flex-shrink-0"
             onerror="this.onerror=null; this.src='<?= $default_avatar_url ?>';">
        <div>
            <p class="text-sm text-gray-500 mb-1">Events Hosted By</p>
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800">
            <?= htmlspecialchars($organizer['name']) ?>
        </h1>
         <!-- Optional: Add more organizer details here if available/needed -->

        <!-- Follow Button for Organizer Page Header -->
         <?php if ($current_user_id > 0 && $current_user_id != $organizer_id): ?>
            <div class="mt-3">
                 <button type="button"
                        class="follow-organizer-btn inline-flex items-center justify-center px-4 py-1.5 border text-sm font-medium rounded-md transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2 relative min-h-[32px] <?= $is_following_organizer ? 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100 focus:ring-red-500' : 'border-green-300 bg-green-50 text-green-700 hover:bg-green-100 focus:ring-green-500' ?>"
                        data-organizer-id="<?= $organizer_id ?>"
                        data-following="<?= $is_following_organizer ? 'true' : 'false' ?>"
                        onclick="toggleFollowOrganizer(this)">
                     <span class="button-text flex items-center gap-1.5">
                         <?php if ($is_following_organizer): ?>
                             <i class="fas fa-user-minus text-xs"></i> Unfollow
                         <?php else: ?>
                             <i class="fas fa-user-plus text-xs"></i> Follow
                         <?php endif; ?>
                     </span>
                     <span class="loading hidden absolute inset-0 flex items-center justify-center bg-opacity-50">
                         <svg class="animate-spin h-4 w-4 text-current" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                     </span>
                 </button>
                  <div id="follow-error-<?= $organizer_id ?>" class="text-red-600 text-xs mt-1 hidden"></div>
            </div>
         <?php elseif ($current_user_id > 0 && $current_user_id == $organizer_id): ?>
             <span class="block text-xs text-gray-500 mt-2">(This is you)</span>
         <?php endif; ?>
         <!-- End Follow Button -->

    </div> <!-- End text container -->
</div> <!-- End Organizer Header -->

    <!-- Upcoming Events Section -->
    <?php if (!empty($upcoming_events)): ?>
        <section class="mb-12">
            <h2 class="text-2xl font-semibold text-gray-800 mb-6 flex items-center">
                <i class="fas fa-calendar-alt text-purple-600 mr-2.5"></i>
                Upcoming Events
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($upcoming_events as $event): ?>
                    <?= render_event_card($event) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Past Events Section -->
    <?php if (!empty($past_events)): ?>
         <section class="mb-12">
            <h2 class="text-2xl font-semibold text-gray-700 mb-6 flex items-center">
                <i class="fas fa-calendar-check text-gray-500 mr-2.5"></i>
                Past Events
            </h2>
             <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 opacity-80">
                <?php foreach ($past_events as $event): ?>
                    <?= render_event_card($event, true) // Pass true for past event styling ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- No Events Message -->
    <?php if (empty($upcoming_events) && empty($past_events)): ?>
        <div class="text-center py-16 px-6 bg-white rounded-lg shadow border border-gray-200">
            <i class="fas fa-calendar-times text-4xl text-gray-400 mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">No Events Found</h3>
            <p class="text-gray-500">
                <?= htmlspecialchars($organizer['name']) ?> hasn't listed any events on the platform yet.
            </p>
        </div>
    <?php endif; ?>

</main>

<?php include '../includes/footer.php'; ?>
<script src="/assets/js/events.js"></script>
</body>
</html>
<?php

?>