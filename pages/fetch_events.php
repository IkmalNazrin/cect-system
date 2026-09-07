<?php
session_start();
require_once '../config/db.php';
require_once '../lib/functions.php'; 
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Define the renderEventCard function here so it's available for array_map
function renderEventCard($event, $user_id = 0) {
    // Ensure date/time parsing doesn't throw errors for null values
    $event_date_str = $event['event_date'] ?? null;
    $event_time_str = $event['event_time'] ?? null;

    $is_past_event = $event_date_str && strtotime($event_date_str) < strtotime(date('Y-m-d'));
    $date = $event_date_str ? date('M d, Y', strtotime($event_date_str)) : 'N/A';
    $time = $event_time_str ? date('g:i A', strtotime($event_time_str)) : 'N/A';

    // Start output buffering to capture the HTML
    ob_start(); 
    ?>
    <div class="group bg-white rounded-xl shadow-lg hover:shadow-xl transition-shadow duration-300 hover:-translate-y-1 <?= $is_past_event ? 'opacity-70 hover:opacity-90' : '' ?>">
        <?php if (!empty($event['image_url'])): ?>
            <div class="relative overflow-hidden rounded-t-xl h-48">
            <img src="<?= htmlspecialchars('/assets/images/' . $event['image_url']) ?>"
                    alt="<?= htmlspecialchars($event['title'] ?? 'Event Image') ?>"
                    class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105 <?= $is_past_event ? 'filter grayscale group-hover:grayscale-0' : '' ?>">
            </div>
        <?php endif; ?>
            <div class="p-5 relative"> <?php // <-- MODIFIED: Added relative class ?>

            <?php // --- START: Add Save Button Logic --- ?>
            <?php
            // Define is_currently_saved based on event data and passed user_id
            $is_currently_saved = ($user_id > 0 && isset($event['is_saved'])) ? (bool)$event['is_saved'] : false;
            ?>
            <?php if ($user_id > 0 && !$is_past_event): ?>
                <button type="button"
                    class="save-event-btn absolute top-3 right-3 p-1.5 rounded-full bg-white/90 hover:bg-purple-50 transition-colors shadow-sm z-10"
                    data-event-id="<?= $event['id'] ?? 0 ?>"
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
            <?php // --- END: Add Save Button Logic --- ?>
            <?php if ($is_past_event): ?>
            <span class="inline-block mb-2 px-2.5 py-1 bg-gray-500 text-white text-xs font-medium rounded-full flex items-center w-fit shadow-sm">
                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Past
            </span>
            <?php endif; ?>
            
            <h3 class="text-xl font-semibold text-gray-800 mb-3 truncate">
                <?= htmlspecialchars($event['title'] ?? 'Untitled Event') ?>
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
                    <span class="text-sm"><?= htmlspecialchars($event['category'] ?? 'N/A') ?></span>
                </div>
                <div class="pt-3 flex justify-between items-center">
                    <?php 
                    $price = $event['price'] ?? 0; 
                    if ($price > 0): ?>
                        <span class="px-3 py-1 bg-purple-100 text-purple-600 rounded-full text-sm font-medium">
                            RM<?= number_format($price, 2) ?>
                        </span>
                    <?php else: ?>
                        <span class="px-3 py-1 bg-green-100 text-green-600 rounded-full text-sm font-medium">
                            Free Entry
                        </span>
                    <?php endif; ?>
                    <a href="/pages/event.php?id=<?= $event['id'] ?? '' ?>" 
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
    // Get the buffered content and clean the buffer
    $output = ob_get_clean(); 
    return $output; // Return the captured HTML string
}

header('Content-Type: application/json');

function searchEvents($pdo, $filters) {
    $where = [];
    $params = [];
    
    if (!empty($filters['online'])) {
        $where[] = "e.is_online = 1";
    }
    
    if (!empty($filters['search'])) {
        $where[] = "(e.title LIKE ? OR e.description LIKE ? OR l.city LIKE ? OR c.name LIKE ?)";
        $searchTerm = "%{$filters['search']}%";
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }
    
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
    
    if (!empty($filters['city'])) {
        $cityPlaceholders = str_repeat('?,', count($filters['city']) - 1) . '?';
        $where[] = "l.city IN ($cityPlaceholders)";
        $params = array_merge($params, $filters['city']);
    }
    
    if (!empty($filters['category'])) {
        $categoryPlaceholders = str_repeat('?,', count($filters['category']) - 1) . '?';
        $where[] = "c.name IN ($categoryPlaceholders)";
        $params = array_merge($params, $filters['category']);
    }
    
    if (!empty($filters['tags'])) {
        $tagPlaceholders = str_repeat('?,', count($filters['tags']) - 1) . '?';
        $where[] = "e.id IN (SELECT event_id FROM event_tags WHERE tag_id IN 
            (SELECT id FROM tags WHERE name IN ($tagPlaceholders)))";
        $params = array_merge($params, $filters['tags']);
    }
    
    if (!empty($filters['start_date'])) {
        $where[] = "e.event_date >= ?";
        $params[] = $filters['start_date'];
    }
    
    if (!empty($filters['end_date'])) {
        $where[] = "e.event_date <= ?";
        $params[] = $filters['end_date'];
    }
    
    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    // Use the user_id passed within the $filters array
    $current_user_id = $filters['user_id'] ?? 0;
    $savedStatusSelect = ", 0 AS is_saved"; // Default for guests or if user_id is 0
    if ($current_user_id > 0) {
        $safe_user_id = (int)$current_user_id; // Ensure it's an integer
        $savedStatusSelect = ", EXISTS(SELECT 1 FROM user_saved_events usev WHERE usev.event_id = e.id AND usev.user_id = {$safe_user_id}) AS is_saved";
    }
    
    $sql = "SELECT DISTINCT 
                e.*,
                l.city as location,
                l.state as state, 
                c.name as category,
                -- Add a sorting column: 0 for upcoming/today, 1 for past
                CASE
                    WHEN e.event_date >= CURDATE() THEN 0
                    ELSE 1
                END AS is_past_sort
                {$savedStatusSelect}
            FROM events e
            LEFT JOIN locations l ON e.city_id = l.id
            LEFT JOIN categories c ON e.category_id = c.id
            $whereClause 
            -- Sort by upcoming first (0), then by event date descending
            ORDER BY is_past_sort ASC, e.event_date DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Search Events Error: " . $e->getMessage());
        return [];
    }
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON data received');
        }

        // --- MODIFIED: Add user_id to filters before searching ---
        $filters = $data; // Get filters from JS
        // Use the $user_id retrieved at the top of the script
        $filters['user_id'] = $user_id;
        // --- END MODIFIED ---

        // Pass the updated filters array containing user_id
        // This calls the searchEvents function DEFINED IN THIS FILE
        $events = searchEvents($pdo, $filters);

        // --- MODIFIED: Use a loop to render cards and pass user_id ---
        $html = [];
        foreach ($events as $event) {
            // Pass both the event data AND the user_id to the render function DEFINED IN THIS FILE
            $html[] = renderEventCard($event, $user_id);
        }
        // --- END MODIFIED ---

        echo json_encode(['html' => $html]);

    } catch (Exception $e) {
        error_log("Fetch Events Error: " . $e->getMessage()); // Log the actual error
        http_response_code(400);
        // Provide a more generic error message to the client for security
        echo json_encode(['error' => 'Failed to process request.']);
    }
} else {
    // Handle cases where the request method is not POST
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Invalid request method.']);
}
?>