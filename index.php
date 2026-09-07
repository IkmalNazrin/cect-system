<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
error_log("Session data: " . print_r($_SESSION, true));
require_once 'config/db.php';
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

/**
 * Fetches events by category with pagination
 * 
 * @param PDO $pdo Database connection
 * @param string $category Event category
 * @param int $limit Number of events to fetch
 * @return array Array of events
 */
/**
 * Fetches events by category with pagination and save status
 *
 * @param PDO $pdo Database connection
 * @param string $category Event category
 * @param int $limit Number of events to fetch
 * @param int $user_id Current user ID (0 if guest)
 * @return array Array of events
 */
function fetchEventsByCategory($pdo, $category, $limit = 4, $user_id = 0) {
    try {
        $savedStatusSelect = ", 0 AS is_saved"; // Default for guests or if user_id is 0
        if ($user_id > 0) {
            $savedStatusSelect = ", EXISTS(SELECT 1 FROM user_saved_events usev WHERE usev.event_id = events.id AND usev.user_id = :user_id) AS is_saved";
        }

        $sql = "SELECT
                    events.*,
                    locations.city AS location,
                    -- Add a sorting column: 0 for upcoming/today, 1 for past
                    CASE
                        WHEN events.event_date >= CURDATE() THEN 0
                        ELSE 1
                    END AS is_past_sort
                    {$savedStatusSelect} -- Add the saved status select
                FROM events
                JOIN categories ON events.category_id = categories.id
                LEFT JOIN locations ON events.city_id = locations.id -- Changed to LEFT JOIN
                WHERE categories.name = :category
                -- Sort upcoming first, then by date ascending (soonest first)
                ORDER BY is_past_sort ASC, event_date ASC
                LIMIT " . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':category', $category, PDO::PARAM_STR);
        if ($user_id > 0) {
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC); // Fetch associative array
    } catch(PDOException $e) {
        error_log("Error fetching events by category '{$category}': " . $e->getMessage());
        return [];
    }
}

/**
 * Renders an event card with save button
 *
 * @param array $event Event data (must include 'is_saved' if user is logged in)
 * @param int $user_id Current user ID (0 if guest)
 */
function renderEventCard($event, $user_id = 0) {
    $is_past_event = isset($event['event_date']) && strtotime($event['event_date']) < strtotime(date('Y-m-d'));
    $is_currently_saved = ($user_id > 0 && isset($event['is_saved'])) ? (bool)$event['is_saved'] : false;
    ?>
    <div class="relative group bg-white rounded-lg hover:shadow-xl transition-all duration-300 ease-in-out transform hover:-translate-y-1 <?= $is_past_event ? 'opacity-70 hover:opacity-90' : '' ?>"> <?php // Main card container ?>
        <a href="/pages/event.php?id=<?php echo $event['id']; ?>" class="block"> <?php // Link wraps image and content ?>
            <div class="relative rounded-t-lg overflow-hidden h-48"> <?php // Image wrapper ?>
                <img src="<?php echo !empty($event['image_url']) ? 'assets/images/' . htmlspecialchars($event['image_url']) : 'assets/images/default-event.jpg'; ?>"
                     alt="<?php echo htmlspecialchars($event['title']); ?>"
                     class="w-full h-full object-cover transform transition-transform duration-300 group-hover:scale-105 <?= $is_past_event ? 'filter grayscale group-hover:grayscale-0' : '' ?>">
            </div>
            <div class="p-4"> <?php // Content padding ?>
                <?php if ($is_past_event): ?>
                <span class="inline-block mb-2 px-2.5 py-1 bg-gray-500 text-white text-xs font-medium rounded-full flex items-center w-fit shadow-sm">
                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Past
                </span>
                <?php endif; ?>
                <div class="flex items-center justify-between mb-3 text-sm">
                    <div class="flex items-center space-x-2">
                        <div class="bg-purple-50 rounded-lg px-2 py-1">
                            <span class="font-semibold uppercase text-purple-700"><?php echo date('M', strtotime($event['event_date'])); ?></span>
                            <span class="text-purple-600 ml-1"><?php echo date('d', strtotime($event['event_date'])); ?></span>
                        </div>
                    </div>
                    <?php if ($user_id > 0 && !$is_past_event): ?>
                    <button type="button"
                        class="save-event-btn ml-2 p-1.5 rounded-full bg-white/90 hover:bg-purple-50 transition-colors shadow-sm"
                        data-event-id="<?= $event['id'] ?>"
                        data-saved="<?= $is_currently_saved ? 'true' : 'false' ?>"
                        onclick="event.preventDefault(); event.stopPropagation(); toggleSaveEvent(this);"
                        title="<?= $is_currently_saved ? 'Unsave Event' : 'Save Event' ?>">
                        <svg class="w-5 h-5 <?= $is_currently_saved ? 'text-purple-600' : 'text-gray-400' ?>" 
                            fill="<?= $is_currently_saved ? 'currentColor' : 'none' ?>" 
                            stroke="currentColor" 
                            stroke-width="2"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" 
                                stroke-linejoin="round" 
                                d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                        </svg>
                    </button>
                    <?php endif; ?>
                    <span class="text-gray-600 text-xs flex items-center">
                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
                        <?php echo $event['is_online'] ? 'Online' : htmlspecialchars($event['location'] ?? 'TBD'); ?> <?php // Added null check for location ?>
                    </span>
                </div>
                <h3 class="font-medium text-gray-900 mb-1 line-clamp-2">
                    <?php echo htmlspecialchars($event['title']); ?>
                </h3>
                <div class="flex items-center text-gray-500 text-xs">
                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?php echo date('g:i A', strtotime($event['event_time'])); ?>
                </div>
            </div>
        </a> <?php // End of main content link ?>
            <?php // Price badge - repositioned if save button is present ?>
             
            <?php if ($event['price'] > 0): ?>
                <div class="absolute top-3 right-3 bg-purple-600 shadow-lg px-3 py-1.5 rounded-full text-xs font-semibold text-white">
                    RM<?php echo number_format($event['price'], 2); ?>
                </div>
            <?php endif; ?>

    </div> <?php // End of main card container ?>
    <?php
}

/**
 * Renders a section of events
 *
 * @param string $title Section title
 * @param array $events Array of events
 * @param string $category Category name (or 'Upcoming')
 * @param int $user_id Current user ID (0 if guest)
 */
function renderEventSection($title, $events, $category, $user_id = 0) {
    if (empty($events)) return;

    // Handle 'Upcoming' events differently for view all link
    if ($category === 'Upcoming') {
        $today = date('Y-m-d');
        $viewAllLink = "/pages/search_results.php?start_date=" . $today;
    } else {
        $categoryParam = urlencode($category);
        $viewAllLink = "/pages/search_results.php?category[]=" . $categoryParam;
    }
    ?>
    <section class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center mb-8">
                <h2 class="text-2xl font-bold text-gray-900"><?php echo $title; ?></h2>
                <a href="<?php echo $viewAllLink; ?>"
                   class="text-purple-600 hover:text-purple-700 text-sm flex items-center group">
                    View All
                    <svg class="w-4 h-4 ml-1 transform transition-transform group-hover:translate-x-1"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php foreach ($events as $event): ?>
                    <?php renderEventCard($event, $user_id); // Pass user_id here ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
}

// Fetch all event data
try {
    // Fetch upcoming events
    $savedStatusSelectUpcoming = ", 0 AS is_saved"; // Default for guests
    if ($user_id > 0) {
         $savedStatusSelectUpcoming = ", EXISTS(SELECT 1 FROM user_saved_events usev WHERE usev.event_id = events.id AND usev.user_id = :user_id) AS is_saved";
    }

    $sqlUpcoming = "SELECT
                        events.*,
                        locations.city AS location,
                        0 AS is_past_sort -- Always 0 for upcoming
                        {$savedStatusSelectUpcoming} -- Add saved status
                    FROM events
                    LEFT JOIN locations ON events.city_id = locations.id -- Use LEFT JOIN
                    WHERE event_date >= CURDATE()
                    ORDER BY event_date ASC
                    LIMIT 4";
    $stmtUpcoming = $pdo->prepare($sqlUpcoming);
    if ($user_id > 0) {
        $stmtUpcoming->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    }
    $stmtUpcoming->execute();
    $upcoming_events = $stmtUpcoming->fetchAll(PDO::FETCH_ASSOC); // Fetch associative

    // Fetch category events using the modified function
    $arts_events = fetchEventsByCategory($pdo, 'Arts & Culture', 4, $user_id);
    $community_events = fetchEventsByCategory($pdo, 'Community', 4, $user_id);
    $education_events = fetchEventsByCategory($pdo, 'Education', 4, $user_id);
    $sports_events = fetchEventsByCategory($pdo, 'Sports', 4, $user_id);

} catch(PDOException $e) {
    error_log("Database error fetching events on index: " . $e->getMessage());
    // Set empty arrays to prevent errors later
    $upcoming_events = $arts_events = $community_events = $education_events = $sports_events = [];
     // You might want to display a user-friendly error message here as well
    echo "<p class='text-red-600 text-center my-4'>Error loading events. Please try again later.</p>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CECT - Community Event Calendar and Participation Tracker</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/2.8.2/alpine.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="/assets/js/datepicker.js" defer></script>
    <style>
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
        
        .floating-animation {
            animation: float 6s ease-in-out infinite;
        }
        
        .event-card-hover {
            transition: all 0.3s ease;
        }
        
        .event-card-hover:hover {
            transform: translateY(-8px);
            box-shadow: 0 10px 20px rgba(99, 102, 241, 0.1);
        }

        .nav-icon {
            position: relative;
        }

        .nav-icon::after {
            content: '';
            position: absolute;
            top: -2px;
            right: -2px;
            width: 6px;
            height: 6px;
            background-color: #6366F1;
            border-radius: 50%;
        }

        .search-input:focus {
            box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.2);
        }

        .gradient-text {
            background: linear-gradient(45deg, #6366F1, #8B5CF6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        @keyframes toast-in {
          from {
            opacity: 0;
            transform: translateY(20px) scale(0.95);
          }
          to {
            opacity: 1;
            transform: translateY(0) scale(1);
          }
        }

        @keyframes toast-out {
          from {
            opacity: 1;
            transform: translateY(0) scale(1);
          }
          to {
            opacity: 0;
            transform: translateY(20px) scale(0.95);
          }
        }

        .animate-toast-in {
          animation: toast-in 0.3s ease-out forwards;
        }

        .animate-toast-out {
          animation: toast-out 0.5s ease-in forwards; /* Adjust duration if needed */
        }

        /* Ensure the toast container is positioned */
        #toastContainer {
          position: fixed;
          bottom: 1.25rem; /* Equivalent to bottom-5 */
          right: 1.25rem; /* Equivalent to right-5 */
          z-index: 100; /* Ensure it's on top */
          display: flex;
          flex-direction: column-reverse; /* Show newest toast at the bottom */
          gap: 0.5rem; /* Equivalent to space-y-2 */
        }
        /* --- End of pasted CSS --- */
    </style>

</head>

</head>
<body class="bg-gradient-to-b from-white via-purple-200 via-purple-400 to-purple-600 min-h-screen bg-fixed bg-no-repeat bg-cover">

    <?php require_once __DIR__ . '/includes/nav.php'; ?>

    <!-- Container for positioning context -->
    <div class="relative">
        <!-- Hero Section -->
        <div class="bg-white relative w-full">
            <!-- Background Image -->
            <div class="w-full">
                <img src="/assets/images/home_page_img.png"
                    alt="Background" 
                    class="w-full"
                    style="filter: brightness(1);">
            </div>

            <!-- Hero Content - Absolute positioned over the image -->
            <?php /* Reduced top padding (pt-*) values */ ?>
            <div class="absolute inset-0 flex items-center sm:items-start pt-0 sm:pt-2 lg:pt-4">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 w-full">
                    <div class="text-center">
                        <div class="max-w-4xl mx-auto">
                            <div class="relative space-y-6 md:space-y-8">
                                <h1 class="text-xl sm:text-3xl md:text-4xl lg:text-5xl font-bold text-gray-900 mb-4 sm:mb-6 lg:mb-8 drop-shadow-lg leading-tight sm:leading-tight md:leading-tight lg:leading-tight">
                                    Exclusive events,<br class="hidden sm:inline">
                                    <span class="bg-gradient-to-r from-purple-600 to-blue-500 bg-clip-text text-transparent">
                                        priceless moments
                                    </span>
                                </h1>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search Section with Date Picker -->
        <!-- Positioned relative on mobile (below hero), absolute overlay on sm+ screens -->
        <div class="relative mt-[-40px] sm:absolute sm:bottom-0 sm:left-1/2 sm:transform sm:-translate-x-1/2 sm:translate-y-1/2 z-30 w-full max-w-4xl px-4 sm:px-6 lg:px-8 sm:mt-0">
            <div class="bg-white rounded-xl shadow-lg p-5"> <!-- Increased padding, removed backdrop blur -->
                <form action="pages/search_results.php" method="GET" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">

                    <!-- Search Input with Icon -->
                    <div class="flex-1 relative"> <!-- Added relative positioning -->
                        <label for="search-input" class="sr-only">Search Events</label>
                        <!-- Icon -->
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <input type="text"
                            id="search-input"
                            name="q"
                            placeholder="Search events, locations..."
                            class="w-full h-11 pl-10 pr-4 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-400 focus:border-purple-400 transition-shadow"> <!-- Added h-11, pl-10, adjusted border/focus -->
                    </div>

                    <!-- Date Picker -->
                    <div class="relative z-40 w-full sm:w-auto flex-shrink-0"> <!-- Added flex-shrink-0 -->
                        <label for="date-picker-root" class="sr-only">Select Dates</label>  
                        <input type="hidden" name="start_date" id="start_date">
                        <input type="hidden" name="end_date" id="end_date">
                        <button type="button" id="date-picker-root"
                        class="w-full sm:w-auto h-11 flex items-center justify-center gap-2 px-4 py-2.5 text-sm cursor-pointer text-gray-700"> 
                            <svg class="w-5 h-5 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"> <!-- Adjusted icon color -->
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <span class="whitespace-nowrap" id="date-display">Select dates</span>
                        </button>
                    </div>

                    <!-- Search Button Wrapper -->
                    <div class="w-full sm:w-auto flex-shrink-0"> <!-- Added wrapper and flex-shrink-0 -->
                        <button type="submit"
                            class="w-full sm:w-auto h-11 py-2.5 px-6 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-all duration-300 font-semibold shadow-md hover:shadow-lg text-sm flex items-center justify-center"> <!-- Added h-11, adjusted shadow, added focus ring -->
                            Search
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="h-20 sm:h-32"></div>

    <!-- Calendar Section -->
    <?php include __DIR__ . '/includes/calendar.php'; ?>
    <?php generateEventCalendar($pdo); ?>

    <!-- Event Sections -->
    <?php
        renderEventSection('Upcoming Events', $upcoming_events, 'Upcoming', $user_id);
        renderEventSection('Browse Arts & Culture', $arts_events, 'Arts & Culture', $user_id);
        renderEventSection('Browse Community', $community_events, 'Community', $user_id);
        renderEventSection('Browse Education', $education_events, 'Education', $user_id);
        renderEventSection('Browse Sports', $sports_events, 'Sports', $user_id);
    ?>

    <?php include __DIR__ . '/includes/footer.php'; ?>
    <script src="/assets/js/events.js"></script> <?php // Needed for save button functionality ?>

</body>
</html>