<?php
// --- pages/my_feed.php ---

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// --- CSRF Token Generation (Needed for potential forms/actions later, good practice) ---
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) { die("Failed to generate security token."); }
}
// --- End CSRF Token Generation ---

// Include dependencies
require_once '../config/db.php'; // Includes APP_BASE_URL and PDO connection ($pdo)
require_once '../lib/functions.php'; // Includes helper functions like render_event_card if moved

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    // Redirect to login, asking to return here
    $_SESSION['info_message'] = "Please log in to view your personalized event feed.";
    header('Location: /pages/login.php?redirect=' . urlencode('/pages/my_feed.php'));
    exit();
}
$user_id = (int)$_SESSION['user_id'];

$feed_events = [];
$pageTitle = "My Event Feed";

try {
    // --- Fetch Relevant Upcoming Event IDs ---
    // Combine events from followed organizers, preferred categories, and preferred tags
    // Using UNION DISTINCT ensures each event appears only once even if it matches multiple criteria
    $sqlFetchEventIds = "
        (
            -- Events from followed organizers
            SELECT e.id
            FROM events e
            JOIN user_follows uf ON e.created_by = uf.organizer_id
            WHERE uf.user_id = :user_id_follow AND e.event_date >= CURDATE()
        )
        UNION DISTINCT
        (
            -- Events matching preferred categories
            SELECT e.id
            FROM events e
            JOIN user_category_preferences ucp ON e.category_id = ucp.category_id
            WHERE ucp.user_id = :user_id_cat AND e.event_date >= CURDATE()
        )
        UNION DISTINCT
        (
            -- Events matching preferred tags
            SELECT e.id
            FROM events e
            JOIN event_tags et ON e.id = et.event_id
            JOIN user_tag_preferences utp ON et.tag_id = utp.tag_id
            WHERE utp.user_id = :user_id_tag AND e.event_date >= CURDATE()
        )
    ";

    $stmtFetchIds = $pdo->prepare($sqlFetchEventIds);
    // Bind the same user ID to all placeholders
    $stmtFetchIds->bindParam(':user_id_follow', $user_id, PDO::PARAM_INT);
    $stmtFetchIds->bindParam(':user_id_cat', $user_id, PDO::PARAM_INT);
    $stmtFetchIds->bindParam(':user_id_tag', $user_id, PDO::PARAM_INT);
    $stmtFetchIds->execute();
    $relevantEventIds = $stmtFetchIds->fetchAll(PDO::FETCH_COLUMN, 0);

    // --- Fetch Full Details for Relevant Events ---
    if (!empty($relevantEventIds)) {
        // Create placeholders for the IN clause (e.g., ?, ?, ?)
        $placeholders = implode(',', array_fill(0, count($relevantEventIds), '?'));

        $sqlFetchEvents = "
            SELECT
                e.id, e.title, e.event_date, e.event_time, e.image_url, e.is_online, e.price,
                e.created_by, -- Needed if you want to show organizer info potentially
                l.city, l.state,
                u.name as organizer_name, -- Get organizer name for cards
                (SELECT COUNT(*) FROM registrations r_count
                 WHERE r_count.event_id = e.id
                 AND r_count.payment_status IN ('paid', 'free')) AS current_participant_count
            FROM events e
            LEFT JOIN locations l ON e.city_id = l.id AND e.is_online = 0
            LEFT JOIN users u ON e.created_by = u.id -- Join to get organizer name
            WHERE e.id IN ($placeholders)
              AND e.event_date >= CURDATE() -- Re-affirm upcoming, though already filtered
            ORDER BY e.event_date ASC, e.event_time ASC
        ";

        $stmtFetchEvents = $pdo->prepare($sqlFetchEvents);
        // Bind each event ID
        foreach ($relevantEventIds as $k => $id) {
            $stmtFetchEvents->bindValue(($k + 1), $id, PDO::PARAM_INT);
        }
        $stmtFetchEvents->execute();
        $feed_events_raw = $stmtFetchEvents->fetchAll(PDO::FETCH_ASSOC);

        // --- Process Event Data (Image URL, Location) ---
        foreach ($feed_events_raw as $event) {
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
             $feed_events[] = $event; // Add processed event to the final array
        }

    } // else: $feed_events remains empty

} catch (PDOException $e) {
    error_log("Database error on my_feed.php for User ID {$user_id}: " . $e->getMessage());
    // Simple error message for the user
    $errorMessage = "Sorry, there was a problem loading your event feed. Please try again later.";
} catch (Exception $e) {
    error_log("General error on my_feed.php for User ID {$user_id}: " . $e->getMessage());
    $errorMessage = "Sorry, an unexpected error occurred.";
}



?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"> <!-- Add CSRF token -->
    <title><?= $pageTitle ?> - CECT</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        .event-card:hover .event-image { transform: scale(1.05); }
        .event-image { transition: transform 0.3s ease-out; }
        /* Basic fade-in for empty state */
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .fade-in { animation: fadeIn 0.5s ease-out forwards; }
    </style>
</head>
<body class="bg-gray-50">
<?php include '../includes/nav.php'; ?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">

    <!-- Page Header -->
    <div class="mb-8 md:mb-10 pb-4 border-b border-gray-200">
        <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
            <i class="fas fa-stream text-purple-600"></i>
            My Event Feed
        </h1>
        <p class="mt-1 text-sm text-gray-600">Upcoming events from organizers you follow and based on your preferences.</p>
    </div>

    <?php if (isset($errorMessage)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
            <strong class="font-bold">Error:</strong>
            <span class="block sm:inline"><?= htmlspecialchars($errorMessage) ?></span>
        </div>
    <?php elseif (empty($feed_events)): ?>
        <!-- Empty State Message -->
        <div class="text-center py-16 px-6 bg-white rounded-lg shadow-sm border border-gray-200 fade-in">
             <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
             </svg>
             <h3 class="mt-2 text-lg font-semibold text-gray-900">Your Feed is Empty (For Now!)</h3>
             <p class="mt-1 text-sm text-gray-500">
                 Follow organizers or set your event preferences to see relevant upcoming events here.
             </p>
             <div class="mt-6 space-y-3 sm:space-y-0 sm:flex sm:justify-center sm:gap-4">
                 <a href="/pages/account_info.php#notificationPrefsForm" 
                     class="inline-flex items-center justify-center rounded-md border border-transparent bg-purple-100 px-4 py-2 text-sm font-medium text-purple-700 hover:bg-purple-200 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2">
                     <i class="fas fa-sliders-h mr-2 text-xs"></i>
                     Set Preferences
                 </a>
                 <a href="/index.php#upcomingEvents" 
                    class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                     <i class="fas fa-search mr-2 text-xs"></i>
                     Explore All Events
                 </a>
             </div>
         </div>
    <?php else: ?>
        <!-- Events Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($feed_events as $event): ?>
                <?php echo render_event_card($event); // Use the helper function ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<?php include '../includes/footer.php'; ?>

<!-- Include events.js if needed for any card interactions or future features on this page -->
<!-- <script src="/assets/js/events.js"></script> -->
</body>
</html>