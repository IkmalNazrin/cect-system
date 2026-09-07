<?php
session_start();
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
require_once '../config/db.php';
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Initialize filters
$search = $_GET['q'] ?? '';
$online = $_GET['online'] ?? '';
$state = isset($_GET['state']) ? (array)$_GET['state'] : [];
$city = isset($_GET['city']) ? (array)$_GET['city'] : [];
$category = isset($_GET['category']) ? (array)$_GET['category'] : [];
$tags = isset($_GET['tags']) ? (array)$_GET['tags'] : [];
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Fetch states
function getStates($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT state FROM locations ORDER BY state");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Fetch cities by state
function getCitiesByState($pdo, $states) {
    if (empty($states)) {
        return [];
    }
    
    // If $states is a string, convert it to an array
    if (!is_array($states)) {
        $states = [$states];
    }
    
    // Create placeholders for the IN clause
    $placeholders = str_repeat('?,', count($states) - 1) . '?';
    
    $sql = "SELECT DISTINCT city FROM locations WHERE state IN ($placeholders) ORDER BY city";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($states);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Fetch categories
function getCategories($pdo) {
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
    return $stmt->fetchAll();
}

// Fetch tags by category
function getTagsByCategory($pdo, $categories) {
    if (empty($categories)) {
        return [];
    }
    
    // If $categories is a string, convert it to an array
    if (!is_array($categories)) {
        $categories = [$categories];
    }
    
    // Create placeholders for the IN clause
    $placeholders = str_repeat('?,', count($categories) - 1) . '?';
    
    $sql = "SELECT * FROM tags WHERE category_id IN ($placeholders) ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($categories);
    return $stmt->fetchAll();
}

function searchEvents($pdo, $filters) {
    $user_id = $filters['user_id'] ?? 0; // Extract user_id from filters
    $where = [];
    $params = [];

    // Handle online events filter
    if (!empty($filters['online'])) {
        $where[] = "e.is_online = 1";
    }

    // Handle search term
    if (!empty($filters['search'])) {
        $where[] = "(e.title LIKE ? OR e.description LIKE ? OR l.city LIKE ? OR c.name LIKE ?)";
        $searchTerm = "%{$filters['search']}%";
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }

    // Handle state filter
    if (!empty($filters['state'])) {
        $stateWhere = [];
        foreach ($filters['state'] as $state) {
            $stateWhere[] = "l.state = ?";
            $params[] = $state;
        }
        if (!empty($stateWhere)) {
            $where[] = "(" . implode(" OR ", $stateWhere) . ")";
        }
    }

    // Handle city filter
    if (!empty($filters['city'])) {
        $cityPlaceholders = str_repeat('?,', count($filters['city']) - 1) . '?';
        $where[] = "l.city IN ($cityPlaceholders)";
        $params = array_merge($params, $filters['city']);
    }

    // Handle category filter
    if (!empty($filters['category'])) {
        $categoryPlaceholders = str_repeat('?,', count($filters['category']) - 1) . '?';
        $where[] = "c.name IN ($categoryPlaceholders)";
        $params = array_merge($params, $filters['category']);
    }

    // Handle tags filter
    if (!empty($filters['tags'])) {
        $tagPlaceholders = str_repeat('?,', count($filters['tags']) - 1) . '?';
        $where[] = "e.id IN (SELECT event_id FROM event_tags WHERE tag_id IN
            (SELECT id FROM tags WHERE name IN ($tagPlaceholders)))";
        $params = array_merge($params, $filters['tags']);
    }

    // Handle date range
    if (!empty($filters['start_date'])) {
        $where[] = "e.event_date >= ?";
        $params[] = $filters['start_date'];
    }

    if (!empty($filters['end_date'])) {
        $where[] = "e.event_date <= ?";
        $params[] = $filters['end_date'];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // --- START: Add saved status logic ---
    $savedStatusSelect = ", 0 AS is_saved"; // Default for guests or if user_id is 0
    if ($user_id > 0) {
        // Directly inject integer user_id for the subquery, as mixing named/positional placeholders is tricky
        $safe_user_id = (int)$user_id;
        $savedStatusSelect = ", EXISTS(SELECT 1 FROM user_saved_events usev WHERE usev.event_id = e.id AND usev.user_id = {$safe_user_id}) AS is_saved";
    }
    // --- END: Add saved status logic ---

    $sql = "SELECT DISTINCT
                e.*,
                l.city as location,
                c.name as category,
                -- Add a sorting column: 0 for upcoming/today, 1 for past
                CASE
                    WHEN e.event_date >= CURDATE() THEN 0
                    ELSE 1
                END AS is_past_sort
                {$savedStatusSelect} -- Include the saved status select here
            FROM events e
            LEFT JOIN locations l ON e.city_id = l.id
            LEFT JOIN categories c ON e.category_id = c.id
            $whereClause
            -- Sort by upcoming first (0), then by event date descending (nearest upcoming first)
            ORDER BY is_past_sort ASC, e.event_date DESC"; // Keep DESC for search results to show newest upcoming first? Or change to ASC like index? Using DESC for now.

    try {
        $stmt = $pdo->prepare($sql);
        // No extra binding needed here for the subquery as user_id was injected (safely)
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Search Events Error: " . $e->getMessage());
        return [];
    }
}

// Get all locations grouped by state
function getGroupedLocations($pdo) {
    $stmt = $pdo->query("SELECT state, GROUP_CONCAT(DISTINCT city ORDER BY city) AS cities FROM locations GROUP BY state");
    $grouped = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $grouped[] = [
            'name' => $row['state'],
            'cities' => explode(',', $row['cities'])
        ];
    }
    return $grouped;
}

// Get categories with their tags
function getCategoriesWithTags($pdo) {
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.color,
               GROUP_CONCAT(t.name ORDER BY t.name) AS tags 
        FROM categories c
        LEFT JOIN tags t ON c.id = t.category_id
        GROUP BY c.id
    ");
    $categories = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $categories[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'color' => $row['color'],
            'tags' => $row['tags'] ? explode(',', $row['tags']) : []
        ];
    }
    return $categories;
}

$groupedStates = getGroupedLocations($pdo);
$categoriesWithTags = getCategoriesWithTags($pdo);

// Search results
$filters = [
    'search' => $search,
    'online' => $online,
    'state' => $state,
    'city' => $city,
    'category' => $category,
    'tags' => $tags,
    'start_date' => $start_date,
    'end_date' => $end_date,
    'user_id' => $user_id
];

$events = searchEvents($pdo, $filters);

function renderEventCard($event, $user_id = 0) {
    $is_past_event = isset($event['event_date']) && strtotime($event['event_date']) < strtotime(date('Y-m-d'));
    // --- START: Define is_currently_saved ---
    // Ensure 'is_saved' key exists from the searchEvents query before using it
    $is_currently_saved = ($user_id > 0 && isset($event['is_saved'])) ? (bool)$event['is_saved'] : false;
    // --- END: Define is_currently_saved ---
    $date = date('M d, Y', strtotime($event['event_date']));
    $time = date('g:i A', strtotime($event['event_time']));
    ?>
    <div class="group bg-white rounded-xl shadow-lg hover:shadow-xl transition-shadow duration-300 hover:-translate-y-1 <?= $is_past_event ? 'opacity-70 hover:opacity-90' : '' ?>">
        <?php if ($event['image_url']): ?>
            <div class="relative overflow-hidden rounded-t-xl h-48">
                <img src="<?= htmlspecialchars('/assets/images/' . $event['image_url']) ?>"
                     alt="<?= htmlspecialchars($event['title']) ?>"
                     class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105 <?= $is_past_event ? 'filter grayscale group-hover:grayscale-0' : '' ?>">
            </div>
        <?php endif; ?>
        <div class="p-5 relative"> <?php // Added relative for positioning the save button ?>

            <?php // --- START: Save Button --- (Placed near top-right of content area) ?>
            <?php if ($user_id > 0 && !$is_past_event): ?>
                <button type="button"
                    class="save-event-btn absolute top-3 right-3 p-1.5 rounded-full bg-white/90 hover:bg-purple-50 transition-colors shadow-sm z-10" <?php // Style similar to index.php ?>
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
            <?php // --- END: Save Button --- ?>

            <?php if ($is_past_event): ?>
            <span class="inline-block mb-2 px-2.5 py-1 bg-gray-500 text-white text-xs font-medium rounded-full flex items-center w-fit shadow-sm">
                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Past
            </span>
            <?php endif; ?>

            <h3 class="text-xl font-semibold text-gray-800 mb-3 truncate">
                <?= htmlspecialchars($event['title']) ?>
            </h3>
            <div class="space-y-2 text-gray-600">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2 text-purple-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span class="text-sm"><?= $date ?> • <?= $time ?></span>
                </div>
                <div class="flex items-center">
                    <?php if (!empty($event['is_online'])): ?>
                        <svg class="w-5 h-5 mr-2 text-purple-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                        </svg>
                        <span class="text-sm">Online Event</span>
                    <?php else: ?>
                        <svg class="w-5 h-5 mr-2 text-purple-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span class="text-sm"><?= htmlspecialchars($event['location'] ?? 'N/A') ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2 text-purple-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                    </svg>
                    <span class="text-sm"><?= htmlspecialchars($event['category']) ?></span>
                </div>
                <div class="pt-3 flex justify-between items-center">
                    <?php if ($event['price'] > 0): ?>
                        <span class="px-3 py-1 bg-purple-100 text-purple-600 rounded-full text-sm font-medium">
                            RM<?= number_format($event['price'], 2) ?>
                        </span>
                    <?php else: ?>
                        <span class="px-3 py-1 bg-green-100 text-green-600 rounded-full text-sm font-medium">
                            Free Entry
                        </span>
                    <?php endif; ?>
                    <a href="/pages/event.php?id=<?= $event['id'] ?>"
                       class="flex items-center text-purple-600 hover:text-purple-700 font-medium transition-colors">
                        Details
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <title>CECT - Search Results</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/2.8.2/alpine.js"></script>
    <style>
        [x-cloak] { display: none !important; }
    
        /* Custom scrollbar */
        .custom-scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .custom-scroll::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* Smooth transitions */
        .transition-all-300 {
            transition: all 0.3s ease;
        }

        .checkbox-container {
            min-width: 1rem;
            min-height: 1rem;
        }

        .cursor-pointer {
            cursor: pointer;
        }

        .filter-header {
            @apply text-gray-800 font-semibold text-base flex items-center;
        }
        .filter-count {
            @apply text-purple-600 font-medium ml-1;
        }
        .filter-section {
            @apply bg-white rounded-xl shadow-sm border border-gray-100 transition-all-300;
        }
        .filter-item {
            @apply p-3 hover:bg-purple-50 rounded-xl transition-all-300 cursor-pointer;
        }
        .filter-search {
            @apply placeholder-gray-400 text-sm rounded-xl border-gray-200 
                focus:ring-2 focus:ring-purple-300 focus:border-purple-400;
        }

        .filter-badge {
            transition: all 0.2s ease;
            transform-origin: left;
            @apply transform transition-all-300 hover:scale-95 active:scale-90;
        }

        .filter-badge-enter {
            opacity: 0;
            transform: scale(0.8);
        }

        .filter-badge-leave {
            opacity: 1;
            transform: scale(1);
        }

        .filter-badge-leave-to {
            opacity: 0;
            transform: scale(0.8);
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
    </style>
</head>
<body class="bg-gray-50" x-data="searchPage()" x-init="init()">
    <!-- Reuse navigation from index.php -->
    <?php include '../includes/nav.php'; ?>

    <!-- Search Section -->
    <div class="bg-white border-b">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <form id="searchForm"
                action="search_results.php"
                method="GET"
                class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-4"  <?php /* Changed to flex-col for mobile, adjusted gaps */ ?>
                @submit.prevent="updateFilters">
                <!-- Hidden inputs -->
                <input type="hidden" name="online" x-model="onlineEvent ? '1' : '0'">
                <input type="hidden" name="start_date" x-model="startDate">
                <input type="hidden" name="end_date" x-model="endDate">

                <template x-for="state in selectedStates" :key="state">
                    <input type="hidden" name="state[]" :value="state">
                </template>
                <template x-for="city in selectedCities" :key="city">
                    <input type="hidden" name="city[]" :value="city">
                </template>
                <template x-for="category in selectedCategories" :key="category">
                    <input type="hidden" name="category[]" :value="category">
                </template>
                <template x-for="tag in selectedTags" :key="tag">
                    <input type="hidden" name="tags[]" :value="tag">
                </template>

                <!-- Search input with Alpine binding -->
                <input type="text"
                    name="q"
                    x-model="searchTerm"
                    placeholder="Search events, venues, locations..."
                    class="w-full sm:flex-1 px-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-purple-300"> <?php /* Made full width on mobile */ ?>

                <!-- Date picker -->
                <div id="date-picker-root" class="relative z-20 w-full sm:w-64"></div> <?php /* Added relative positioning and z-index */ ?>

                <!-- Submit button -->
                <button type="submit"
                        class="w-full sm:w-auto py-2 px-6 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-all duration-300"> <?php /* Made full width on mobile */ ?>
                    Search
                </button>
            </form>
        </div>
    </div>

    <!-- Active Filters -->
    <div class="mt-4 pt-4" x-show="hasActiveFilters" x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-sm font-medium text-gray-500 mr-2">Applied filters:</span>

                    <!-- Search Term Filter -->
                    <template x-if="searchTerm">
                        <div class="flex items-center h-8 gap-2 pl-3 pr-2 bg-gray-100 border border-gray-200 rounded-lg text-gray-700 text-sm font-medium transition-all-300 hover:bg-gray-200">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            <span class="italic">"<span x-text="searchTerm" class="font-medium not-italic"></span>"</span>
                            <button @click="searchTerm = ''; updateFilters()"
                                    class="ml-1 p-1 -mr-1 hover:bg-gray-300 rounded-full transition-colors">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 15 15" fill="none" stroke="currentColor">
                                     <path d="M11.7816 4.03157C12.0062 3.80702 12.0062 3.44295 11.7816 3.2184C11.5571 2.99385 11.193 2.99385 10.9685 3.2184L7.50005 6.68682L4.03164 3.2184C3.80708 2.99385 3.44301 2.99385 3.21846 3.2184C2.99391 3.44295 2.99391 3.80702 3.21846 4.03157L6.68688 7.5L3.21846 10.9684C2.99391 11.193 2.99391 11.557 3.21846 11.7816C3.44301 12.0061 3.80708 12.0061 4.03164 11.7816L7.50005 8.31318L10.9685 11.7816C11.193 12.0061 11.5571 12.0061 11.7816 11.7816C12.0062 11.557 12.0062 11.193 11.7816 10.9684L8.31322 7.5L11.7816 4.03157Z"/>
                                </svg>
                            </button>
                        </div>
                    </template>

                    <!-- Online Filter -->
                    <template x-if="onlineEvent">
                         <div class="flex items-center h-8 gap-2 pl-3 pr-2 bg-purple-50 border border-purple-200 rounded-lg text-purple-700 text-sm font-medium transition-all-300 hover:bg-purple-100">
                             <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                             </svg>
                             <span>Online</span>
                             <button @click="onlineEvent = false; updateFilters()"
                                     class="ml-1 p-1 -mr-1 hover:bg-purple-200 rounded-full transition-colors">
                                 <svg class="w-3.5 h-3.5" viewBox="0 0 15 15" fill="none" stroke="currentColor">
                                     <path d="M11.7816 4.03157C12.0062 3.80702 12.0062 3.44295 11.7816 3.2184C11.5571 2.99385 11.193 2.99385 10.9685 3.2184L7.50005 6.68682L4.03164 3.2184C3.80708 2.99385 3.44301 2.99385 3.21846 3.2184C2.99391 3.44295 2.99391 3.80702 3.21846 4.03157L6.68688 7.5L3.21846 10.9684C2.99391 11.193 2.99391 11.557 3.21846 11.7816C3.44301 12.0061 3.80708 12.0061 4.03164 11.7816L7.50005 8.31318L10.9685 11.7816C11.193 12.0061 11.5571 12.0061 11.7816 11.7816C12.0062 11.557 12.0062 11.193 11.7816 10.9684L8.31322 7.5L11.7816 4.03157Z"/>
                                 </svg>
                             </button>
                         </div>
                    </template>

                    <!-- State Filters -->
                    <template x-for="state in selectedStates" :key="state">
                         <div class="flex items-center h-8 gap-2 pl-3 pr-2 bg-blue-50 border border-blue-200 rounded-lg text-blue-700 text-sm font-medium transition-all-300 hover:bg-blue-100">
                             <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/>
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 11.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z"/>
                             </svg>
                             <span x-text="state" class="leading-tight"></span>
                             <button @click="selectedStates = selectedStates.filter(s => s !== state); updateFilters()"
                                     class="ml-1 p-1 -mr-1 hover:bg-blue-200 rounded-full transition-colors">
                                 <svg class="w-3.5 h-3.5" viewBox="0 0 15 15" fill="none" stroke="currentColor">
                                     <path d="M11.7816 4.03157C12.0062 3.80702 12.0062 3.44295 11.7816 3.2184C11.5571 2.99385 11.193 2.99385 10.9685 3.2184L7.50005 6.68682L4.03164 3.2184C3.80708 2.99385 3.44301 2.99385 3.21846 3.2184C2.99391 3.44295 2.99391 3.80702 3.21846 4.03157L6.68688 7.5L3.21846 10.9684C2.99391 11.193 2.99391 11.557 3.21846 11.7816C3.44301 12.0061 3.80708 12.0061 4.03164 11.7816L7.50005 8.31318L10.9685 11.7816C11.193 12.0061 11.5571 12.0061 11.7816 11.7816C12.0062 11.557 12.0062 11.193 11.7816 10.9684L8.31322 7.5L11.7816 4.03157Z"/>
                                 </svg>
                             </button>
                         </div>
                    </template>

                    <!-- City Filters -->
                    <template x-for="city in selectedCities" :key="city">
                         <div class="flex items-center h-8 gap-2 pl-3 pr-2 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm font-medium transition-all-300 hover:bg-green-100">
                             <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 5h1m4-5h1m-1 5h1m-5 5h5"/>
                             </svg>
                             <span x-text="city" class="leading-tight"></span>
                             <button @click="selectedCities = selectedCities.filter(c => c !== city); updateFilters()"
                                     class="ml-1 p-1 -mr-1 hover:bg-green-200 rounded-full transition-colors">
                                 <svg class="w-3.5 h-3.5" viewBox="0 0 15 15" fill="none" stroke="currentColor">
                                     <path d="M11.7816 4.03157C12.0062 3.80702 12.0062 3.44295 11.7816 3.2184C11.5571 2.99385 11.193 2.99385 10.9685 3.2184L7.50005 6.68682L4.03164 3.2184C3.80708 2.99385 3.44301 2.99385 3.21846 3.2184C2.99391 3.44295 2.99391 3.80702 3.21846 4.03157L6.68688 7.5L3.21846 10.9684C2.99391 11.193 2.99391 11.557 3.21846 11.7816C3.44301 12.0061 3.80708 12.0061 4.03164 11.7816L7.50005 8.31318L10.9685 11.7816C11.193 12.0061 11.5571 12.0061 11.7816 11.7816C12.0062 11.557 12.0062 11.193 11.7816 10.9684L8.31322 7.5L11.7816 4.03157Z"/>
                                 </svg>
                             </button>
                         </div>
                    </template>

                    <!-- Category Filters -->
                    <template x-for="category in selectedCategories" :key="category">
                         <div class="flex items-center h-8 gap-2 pl-3 pr-2 rounded-lg text-sm font-medium transition-all-300 hover:opacity-90 border"
                             :style="`background-color: ${categories.find(c => c.name === category)?.color || '#CCCCCC'}1A; border-color: ${categories.find(c => c.name === category)?.color || '#CCCCCC'}33; color: ${categories.find(c => c.name === category)?.color || '#374151'};`">
                             <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/>
                             </svg>
                             <span x-text="category" class="leading-tight"></span>
                             <button @click="selectedCategories = selectedCategories.filter(c => c !== category); updateFilters()"
                                     class="ml-1 p-1 -mr-1 rounded-full transition-colors hover:bg-white/20">
                                 <svg class="w-3.5 h-3.5" viewBox="0 0 15 15" fill="none" stroke="currentColor">
                                     <path d="M11.7816 4.03157C12.0062 3.80702 12.0062 3.44295 11.7816 3.2184C11.5571 2.99385 11.193 2.99385 10.9685 3.2184L7.50005 6.68682L4.03164 3.2184C3.80708 2.99385 3.44301 2.99385 3.21846 3.2184C2.99391 3.44295 2.99391 3.80702 3.21846 4.03157L6.68688 7.5L3.21846 10.9684C2.99391 11.193 2.99391 11.557 3.21846 11.7816C3.44301 12.0061 3.80708 12.0061 4.03164 11.7816L7.50005 8.31318L10.9685 11.7816C11.193 12.0061 11.5571 12.0061 11.7816 11.7816C12.0062 11.557 12.0062 11.193 11.7816 10.9684L8.31322 7.5L11.7816 4.03157Z"/>
                                 </svg>
                             </button>
                         </div>
                    </template>

                    <!-- Date Filter -->
                    <template x-if="startDate && endDate">
                         <div class="flex items-center h-8 gap-2 pl-3 pr-2 bg-indigo-50 border border-indigo-200 rounded-lg text-indigo-700 text-sm font-medium transition-all-300 hover:bg-indigo-100">
                             <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                             </svg>
                             <span x-text="`${formatDate(startDate)} – ${formatDate(endDate)}`" class="leading-tight"></span>
                             <button @click="startDate = ''; endDate = ''; updateFilters()"
                                     class="ml-1 p-1 -mr-1 hover:bg-indigo-200 rounded-full transition-colors">
                                 <svg class="w-3.5 h-3.5" viewBox="0 0 15 15" fill="none" stroke="currentColor">
                                     <path d="M11.7816 4.03157C12.0062 3.80702 12.0062 3.44295 11.7816 3.2184C11.5571 2.99385 11.193 2.99385 10.9685 3.2184L7.50005 6.68682L4.03164 3.2184C3.80708 2.99385 3.44301 2.99385 3.21846 3.2184C2.99391 3.44295 2.99391 3.80702 3.21846 4.03157L6.68688 7.5L3.21846 10.9684C2.99391 11.193 2.99391 11.557 3.21846 11.7816C3.44301 12.0061 3.80708 12.0061 4.03164 11.7816L7.50005 8.31318L10.9685 11.7816C11.193 12.0061 11.5571 12.0061 11.7816 11.7816C12.0062 11.557 12.0062 11.193 11.7816 10.9684L8.31322 7.5L11.7816 4.03157Z"/>
                                 </svg>
                             </button>
                         </div>
                    </template>

                    <!-- Clear All Button -->
                     <button @click="clearAllFilters()"
                             class="h-8 px-3 text-sm font-medium text-red-600 hover:text-red-700 transition-colors flex items-center gap-1.5 bg-red-50 hover:bg-red-100 rounded-lg">
                         <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                             <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                         </svg>
                         <span>Clear All</span>
                     </button>
                 </div>
            </div>
        </div>
    </div>  

    <!-- Filters and Results -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
         <?php /* Changed flex to flex-col and added lg:flex-row for responsive layout */ ?>
        <div class="flex flex-col lg:flex-row gap-8">
            <!-- Filters Sidebar -->
             <?php /* Changed width to w-full and added lg:w-72, removed flex-shrink-0 by default */ ?>
            <div class="w-full lg:w-72 lg:flex-shrink-0 space-y-6">
                <div class="filter-section p-5">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Filters</h3>

                    <!-- Online Events Filter -->
                    <div class="mb-6">
                        <div class="flex items-center justify-between p-2 hover:bg-gray-50 rounded-lg cursor-pointer transition-colors"
                            @click="onlineEvent = !onlineEvent; updateFilters()">
                            <div class="flex items-center space-x-3">
                                <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                                <span class="text-gray-700 font-medium">Online Events</span>
                            </div>
                            <div class="relative inline-block w-10 mr-2 align-middle select-none">
                                <div class="relative">
                                    <div class="w-9 h-5 rounded-full shadow-inner transition-colors duration-300"
                                        :class="onlineEvent ? 'bg-purple-500' : 'bg-gray-300'"></div>
                                    <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow-md transform transition-transform duration-300"
                                        :class="onlineEvent ? 'translate-x-4' : 'translate-x-0'"></div>
                                </div>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500 mt-1 px-2" x-show="onlineEvent">
                            Showing online-only events
                        </p>
                    </div>
                    
                    <!-- Location Filter Section -->
                    <div class="mb-6">
                        <div class="flex items-center justify-between cursor-pointer" 
                            @click="locationExpanded = !locationExpanded">
                            <div class="flex items-center">
                                <h4 class="filter-header">
                                    Location
                                    <span x-show="stateCount > 0 || cityCount > 0" 
                                        class="filter-count"
                                        x-text="`(${stateCount + cityCount})`"></span>
                                </h4>
                            </div>
                            <svg class="w-5 h-5 transform transition-transform text-gray-500" 
                                :class="{'rotate-180': locationExpanded}" 
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                        
                        <div x-show="locationExpanded" x-cloak class="mt-2 space-y-2">
                            <div class="relative mb-2">
                                <input type="text" 
                                    x-model="stateSearch"
                                    placeholder="Search states..."
                                    class="w-full px-3 py-2 text-sm border rounded-lg filter-search">
                            </div>

                            <div class="max-h-[500px] overflow-y-auto custom-scroll space-y-2 pr-2 transition-all-300">
                                <template x-for="state in states" :key="state.name">
                                    <div class="group">
                                        <label class="flex items-center justify-between p-2 hover:bg-gray-50 rounded-lg transition-all-300 hover:shadow-sm cursor-pointer">
                                            <div class="flex items-center space-x-3">
                                                <input type="checkbox" 
                                                    :value="state.name" 
                                                    x-model="selectedStates"
                                                    @change="updateFilters"
                                                    class="hidden">
                                                <div class="w-4 h-4 border-2 border-purple-500 rounded-sm flex items-center justify-center transition-all-300"
                                                    :class="{ 'bg-purple-500 border-purple-600': selectedStates.includes(state.name) }">
                                                    <svg x-show="selectedStates.includes(state.name)" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                    </svg>
                                                </div>
                                                <span class="text-gray-700 text-sm" x-text="state.name"></span>
                                            </div>
                                            <span class="text-gray-400 text-xs bg-gray-100 px-2 py-1 rounded-full" 
                                                x-text="`${state.cities.length} cities`"></span>
                                        </label>
                                        
                                        <div x-show="selectedStates.includes(state.name)" 
                                            x-collapse
                                            class="ml-8 space-y-1 border-l-2 border-purple-100 pl-3 transition-all-300">
                                            <template x-for="city in state.cities" :key="city">
                                                <label class="flex items-center p-2 hover:bg-gray-50 rounded-lg transition-all-300 cursor-pointer">
                                                    <input type="checkbox" 
                                                        :value="city" 
                                                        x-model="selectedCities"
                                                        @change="updateFilters"
                                                        class="hidden">
                                                    <div class="w-4 h-4 border-2 border-purple-500 rounded-sm flex items-center justify-center transition-all-300"
                                                        :class="{ 'bg-purple-500 border-purple-600': selectedCities.includes(city) }">
                                                        <svg x-show="selectedCities.includes(city)" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                        </svg>
                                                    </div>
                                                    <span class="ml-2 text-gray-600 text-sm" x-text="city"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Categories Filter Section -->
                    <div class="mb-6">
                        <div class="flex items-center justify-between cursor-pointer" 
                            @click="categoriesExpanded = !categoriesExpanded">
                            <div class="flex items-center">
                                <h4 class="filter-header">
                                    Categories
                                    <span x-show="categoryCount > 0 || tagCount > 0" 
                                        class="filter-count"
                                        x-text="`(${categoryCount + tagCount})`"></span>
                                </h4>
                            </div>
                            <svg class="w-5 h-5 transform transition-transform text-gray-500" 
                                :class="{'rotate-180': categoriesExpanded}" 
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                        
                        <div x-show="categoriesExpanded" x-cloak class="mt-2 space-y-2">
                            <div class="relative mb-2">
                                <input type="text" 
                                    x-model="categorySearch"
                                    placeholder="Search categories..."
                                    class="w-full px-3 py-2 text-sm border rounded-lg filter-search">
                            </div>

                            <div class="max-h-[600px] overflow-y-auto custom-scroll space-y-2 pr-2 transition-all-300">
                                <template x-for="category in categories" :key="category.id">
                                    <div class="group">
                                        <label class="flex items-center justify-between p-2 hover:bg-gray-50 rounded-lg transition-all-300 hover:shadow-sm cursor-pointer">
                                            <div class="flex items-center space-x-3">
                                                <input type="checkbox" 
                                                    :value="category.name" 
                                                    x-model="selectedCategories"
                                                    @change="updateFilters"
                                                    class="hidden">
                                                <div class="w-4 h-4 border-2 border-purple-500 rounded-sm flex items-center justify-center transition-all-300"
                                                    :class="{ 'bg-purple-500 border-purple-600': selectedCategories.includes(category.name) }">
                                                    <svg x-show="selectedCategories.includes(category.name)" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                    </svg>
                                                </div>
                                                <span class="text-gray-700 text-sm" x-text="category.name"></span>
                                            </div>
                                            <span class="w-3 h-3 rounded-full shadow-sm" 
                                                :style="`background-color: ${category.color}`"></span>
                                        </label>
                                        
                                        <div x-show="selectedCategories.includes(category.name)" 
                                            x-collapse
                                            class="ml-8 space-y-1 border-l-2 border-purple-100 pl-3 transition-all-300">
                                            <div class="relative mb-2">
                                                <input type="text" 
                                                    x-model="tagSearch"
                                                    placeholder="Search tags..."
                                                    class="w-full px-3 py-1.5 text-sm border rounded-lg focus:ring-2 focus:ring-purple-300 focus:border-purple-400 transition-all-300">
                                            </div>

                                            <template x-for="tag in category.tags.filter(t => t.toLowerCase().includes(tagSearch.toLowerCase()))" 
                                                    :key="tag">
                                                <label class="flex items-center p-2 hover:bg-gray-50 rounded-lg transition-all-300 cursor-pointer">
                                                    <input type="checkbox" 
                                                        :value="tag" 
                                                        x-model="selectedTags"
                                                        @change="updateFilters"
                                                        class="hidden">
                                                    <div class="w-4 h-4 border-2 border-purple-500 rounded-sm flex items-center justify-center transition-all-300"
                                                        :class="{ 'bg-purple-500 border-purple-600': selectedTags.includes(tag) }">
                                                        <svg x-show="selectedTags.includes(tag)" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                        </svg>
                                                    </div>
                                                    <span class="ml-2 text-gray-600 text-sm" x-text="tag"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results Grid -->
            <div class="flex-1">
                 <?php /* Grid classes already responsive */ ?>
                 <div id="event-results-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if (empty($events)): ?>
                        <div class="col-span-full flex flex-col items-center justify-center p-12 text-center">
                            <svg class="w-16 h-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">No events found</h3>
                            <p class="text-gray-600">Try adjusting your filters or search terms</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <?php renderEventCard($event, $user_id); // Pass user_id ?> 
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php /* Note: The JS `updateFilters` function also handles injecting a similar 'No events found' message dynamically.
                               Added initial PHP check for consistency on first load. */ ?>
                </div>
            </div>
        </div>
    </div>

<script>
    // Pass PHP data to JavaScript
    window.statesData = <?= json_encode($groupedStates) ?>;
    window.categoriesData = <?= json_encode($categoriesWithTags) ?>;

    function searchPage() {
        return {
            locationExpanded: false,
            categoriesExpanded: false,
            onlineExpanded: true,
            onlineEvent: <?= isset($_GET['online']) && $_GET['online'] === '1' ? 'true' : 'false' ?>,
            selectedStates: <?= json_encode($state) ?>,
            selectedCities: <?= json_encode($city) ?>,
            selectedCategories: <?= json_encode($category) ?>,
            selectedTags: <?= json_encode($tags) ?>,
            searchTerm: '<?= htmlspecialchars($search) ?>',
            startDate: '<?= htmlspecialchars($start_date) ?>',
            endDate: '<?= htmlspecialchars($end_date) ?>',
            stateSearch: '',
            categorySearch: '',
            tagSearch: '',

            // Computed properties
            get hasActiveFilters() { // <-- Added searchTerm check
                return this.searchTerm || // Check if searchTerm has a value
                    this.selectedStates.length > 0 ||
                    this.selectedCities.length > 0 ||
                    this.selectedCategories.length > 0 ||
                    this.selectedTags.length > 0 ||
                    this.onlineEvent ||
                    (this.startDate && this.endDate);
            },

            get states() {
                return window.statesData.filter(state => 
                    state.name.toLowerCase().includes(this.stateSearch.toLowerCase())
                );
            },

            get categories() {
                return window.categoriesData
                    .filter(category =>
                        category.name.toLowerCase().includes(this.categorySearch.toLowerCase())
                    )
                    .map(category => ({
                        ...category,
                        color: category.color || '#6B7280'
                    }));
            },

            get stateCount() {
                return this.selectedStates.length;
            },

            get cityCount() {
                return this.selectedCities.length;
            },

            get categoryCount() {
                return this.selectedCategories.length;
            },

            get tagCount() {
                return this.selectedTags.length;
            },

            // Methods
            clearAllFilters() { // <-- Added searchTerm reset
                this.searchTerm = '' // Clear the search term
                this.onlineEvent = false
                this.selectedStates = []
                this.selectedCities = []
                this.selectedCategories = []
                this.selectedTags = []
                this.startDate = ''
                this.endDate = ''
                this.updateFilters()
            },

            formatDate(dateString) {
                const date = new Date(dateString)
                return date.toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: 'numeric',
                    year: date.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined
                }).replace(/,?\s*\d{4}$/, '')
            }, // Comma added here

            init() {
                // Check screen width on initial load
                if (window.innerWidth >= 1024) { // Tailwind lg breakpoint
                    this.locationExpanded = true;
                    this.categoriesExpanded = true;
                } else {
                    // Ensure they are false for mobile (redundant due to initial state, but safe)
                    this.locationExpanded = false;
                    this.categoriesExpanded = false;
                }

                // Handle date display (ensure #date-display exists or remove this part)
                if (this.startDate && this.endDate) {
                    const startFormatted = this.formatDate(this.startDate);
                    const endFormatted = this.formatDate(this.endDate);
                    const dateDisplay = document.querySelector('#date-display');
                    if (dateDisplay && startFormatted && endFormatted) {
                        dateDisplay.textContent = `${startFormatted} — ${endFormatted}`;
                    }
                }

                // Optional: Add resize listener to handle screen changes after load
                window.addEventListener('resize', () => {
                    if (window.innerWidth >= 1024) {
                        // If screen becomes large, ensure filters are expanded
                        if (!this.locationExpanded) this.locationExpanded = true;
                        if (!this.categoriesExpanded) this.categoriesExpanded = true;
                    } else {
                         // Optional: If screen becomes small, collapse filters
                         // Be cautious with this as it might disrupt user interaction
                         // if (this.locationExpanded) this.locationExpanded = false;
                         // if (this.categoriesExpanded) this.categoriesExpanded = false;
                    }
                });
            },

            async updateFilters() {
                try {
                    const params = {
                        search: this.searchTerm,
                        online: this.onlineEvent,
                        state: this.selectedStates,
                        city: this.selectedCities,
                        category: this.selectedCategories,
                        tags: this.selectedTags,
                        start_date: this.startDate,
                        end_date: this.endDate
                    };

                    const response = await fetch('/pages/fetch_events.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(params)
                    });
                    
                    const data = await response.json();
                    const resultsGrid = document.getElementById('event-results-grid'); 

                    if (!resultsGrid) { 
                        console.error("Results grid container (#event-results-grid) not found."); 
                        return; // Exit if the specific grid isn't found
                    }
                    
                    if (data.html.length === 0) {
                        resultsGrid.innerHTML = `
                            <div class="col-span-full flex flex-col items-center justify-center p-12 text-center">
                                <svg class="w-16 h-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <h3 class="text-lg font-semibold text-gray-900 mb-2">No events found</h3>
                                <p class="text-gray-600">Try adjusting your filters or search terms</p>
                            </div>`;
                    } else {
                        resultsGrid.innerHTML = data.html.join('');
                    }
                    
                    const searchParams = new URLSearchParams();
                    searchParams.set('q', this.searchTerm);
                    if (this.onlineEvent) searchParams.set('online', '1');
                    
                    ['state', 'city', 'category', 'tags'].forEach(param => {
                        searchParams.delete(`${param}[]`);
                        this[`selected${param.charAt(0).toUpperCase() + param.slice(1)}s`].forEach(value => {
                            searchParams.append(`${param}[]`, value);
                        });
                    });
                    
                    if (this.startDate) searchParams.set('start_date', this.startDate);
                    if (this.endDate) searchParams.set('end_date', this.endDate);
                    
                    window.history.pushState({}, '', `?${searchParams.toString()}`);
                } catch (error) {
                    console.error('Error updating filters:', error);
                }
            }
        }
    }
</script>
<script src="/assets/js/datepicker.js" defer></script>
<script src="/assets/js/events.js"></script> <?php // Needed for save button functionality ?>
<?php include '../includes/footer.php'; ?>
</body>
</html>