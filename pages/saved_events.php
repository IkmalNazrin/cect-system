<?php
// --- pages/saved_events.php ---

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Include dependencies
require_once '../config/db.php';
require_once '../lib/functions.php'; // Ensure render_event_card is here

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    $_SESSION['info_message'] = "Please log in to view your saved events.";
    header('Location: /pages/login.php?redirect=' . urlencode('/pages/saved_events.php'));
    exit();
}
$user_id = (int)$_SESSION['user_id'];

$saved_events = [];
$pageTitle = "My Saved Events";
$errorMessage = null;

try {
    // Fetch upcoming events saved by the user
    $sqlFetchSaved = "
        SELECT
            e.id, e.title, e.event_date, e.event_time, e.image_url, e.is_online, e.price,
            e.created_by,
            l.city, l.state,
            u.name as organizer_name,
            (SELECT COUNT(*) FROM registrations r_count
             WHERE r_count.event_id = e.id
             AND r_count.payment_status IN ('paid', 'free')) AS current_participant_count,
            1 AS is_saved -- Mark all fetched events as saved for the card renderer
        FROM events e
        JOIN user_saved_events usev ON e.id = usev.event_id
        LEFT JOIN locations l ON e.city_id = l.id AND e.is_online = 0
        LEFT JOIN users u ON e.created_by = u.id
        WHERE usev.user_id = :user_id
          AND e.event_date >= CURDATE() -- Only show upcoming saved events
        ORDER BY e.event_date ASC, e.event_time ASC
    ";

    $stmtFetchSaved = $pdo->prepare($sqlFetchSaved);
    $stmtFetchSaved->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmtFetchSaved->execute();
    $saved_events_raw = $stmtFetchSaved->fetchAll(PDO::FETCH_ASSOC);

    // Process data for rendering
    foreach ($saved_events_raw as $event) {
         // Prepare image URL
         $event_image_base_url = '/assets/images/';
         $event['processed_image_url'] = $event_image_base_url . (!empty($event['image_url']) && $event['image_url'] !== 'default-event.jpg' ? htmlspecialchars($event['image_url']) : 'default-event.jpg');
         // Prepare location string
         $event['location_string'] = $event['is_online'] ? 'Online Event' : (!empty($event['city']) ? htmlspecialchars($event['city'] . ', ' . $event['state']) : 'Location TBD');
         $saved_events[] = $event;
    }

} catch (PDOException $e) {
    error_log("Database error on saved_events.php for User ID {$user_id}: " . $e->getMessage());
    $errorMessage = "Sorry, there was a problem loading your saved events.";
} catch (Exception $e) {
    error_log("General error on saved_events.php for User ID {$user_id}: " . $e->getMessage());
    $errorMessage = "Sorry, an unexpected error occurred.";
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
        .event-card:hover .event-image { transform: scale(1.05); }
        .event-image { transition: transform 0.3s ease-out; }
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
                <i class="fas fa-bookmark text-red-500"></i> <?php // Changed icon ?>
                My Saved Events
            </h1>
            <p class="mt-1 text-sm text-gray-600">Upcoming events you've saved for later.</p>
        </div>

        <?php if ($errorMessage): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                <strong class="font-bold">Error:</strong>
                <span class="block sm:inline"><?= htmlspecialchars($errorMessage) ?></span>
            </div>
        <?php elseif (empty($saved_events)): ?>
            <!-- Empty State Message -->
            <div class="text-center py-16 px-6 bg-white rounded-lg shadow-sm border border-gray-200 fade-in">
                 <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                   <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.5 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
                 </svg>
                 <h3 class="mt-2 text-lg font-semibold text-gray-900">No Saved Events Yet</h3>
                 <p class="mt-1 text-sm text-gray-500">
                     Click the bookmark icon <i class="far fa-bookmark text-gray-500 mx-1"></i> on event cards or pages to save them here.
                 </p>
                 <div class="mt-6">
                     <a href="/index.php#upcomingEvents"
                        class="inline-flex items-center justify-center rounded-md border border-transparent bg-purple-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2">
                         <i class="fas fa-search mr-2 text-xs"></i>
                         Explore Events
                     </a>
                 </div>
             </div>
        <?php else: ?>
            <!-- Events Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($saved_events as $event): ?>
                    <?php echo render_event_card($event, false); // Pass false for $is_past explicitly ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>

    <?php include '../includes/footer.php'; ?>

    <?php // Need events.js for the toggleSaveEvent function to work on cards ?>
    <script src="/assets/js/events.js"></script>

</body>
</html>