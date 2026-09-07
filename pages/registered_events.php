<?php
// FILE: D:\xampp\htdocs\cect_system\pages\registered_events.php
// PURPOSE: Handles AJAX calendar requests AND displays the registered events page.

require_once __DIR__ . '/../config/db.php';

// --- Session Handling ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- AJAX Handler (TOP of the file) ---
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    $output = ['success' => false, 'error' => 'Unknown error']; // Default output

    try {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            throw new Exception('User not authenticated');
        }
        $userId = $_SESSION['user_id'];

        // --- Check if requesting a specific event ID ---
        $specificEventId = filter_input(INPUT_GET, 'eventId', FILTER_VALIDATE_INT);

        if ($specificEventId) {
            // --- Fetch specific event details ---
            $stmt = $pdo->prepare("
                SELECT
                    e.id, e.title, e.event_date, e.event_time, e.description, e.price, e.image_url,
                    r.registration_date, r.id AS registration_id,
                    COALESCE(c.name, 'Uncategorized') AS category_name,
                    COALESCE(c.color, '#6366F1') AS category_color,
                    l.city AS location,
                    DATE_FORMAT(e.event_date, '%Y-%m-%d') AS formatted_date,
                    e.event_time AS raw_time,
                    DATE_FORMAT(e.event_time, '%H:%i') AS formatted_time,
                    (l.city IS NULL OR l.city = '') AS is_online
                FROM events e
                INNER JOIN registrations r ON e.id = r.event_id
                LEFT JOIN categories c ON e.category_id = c.id
                LEFT JOIN locations l ON e.city_id = l.id
                WHERE r.user_id = :user_id
                  AND e.id = :event_id
                  AND r.payment_status IN ('paid', 'free')
                LIMIT 1
            ");
            $stmt->execute(['user_id' => $userId, 'event_id' => $specificEventId]);
            $eventData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($eventData) {
                $eventData['is_online'] = (bool)$eventData['is_online'];
                $output = ['success' => true, 'event' => $eventData];
            } else {
                 http_response_code(404);
                 $output = ['success' => false, 'error' => 'Event not found or you are not registered for it.'];
            }

        } else {
            // --- Fetch calendar events for a month (Existing Logic) ---
            $month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
            $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
            if ($month === false || $month === null || $year === false || $year === null) {
                 http_response_code(400);
                 throw new Exception('Invalid date parameters.');
            }

            $startDate = (new DateTimeImmutable("$year-$month-01"))->format('Y-m-d');
            $endDate = (new DateTimeImmutable($startDate))->modify('last day of this month')->format('Y-m-d');

            $stmt = $pdo->prepare("
                SELECT
                    e.id, e.title, e.event_date, e.event_time, e.description, e.price, e.image_url,
                    r.registration_date, r.id AS registration_id,
                    COALESCE(c.name, 'Uncategorized') AS category_name,
                    COALESCE(c.color, '#6366F1') AS category_color,
                    l.city AS location,
                    DATE_FORMAT(e.event_date, '%Y-%m-%d') AS formatted_date,
                    e.event_time AS raw_time,
                    DATE_FORMAT(e.event_time, '%H:%i') AS formatted_time,
                    (l.city IS NULL OR l.city = '') AS is_online
                FROM events e
                INNER JOIN registrations r ON e.id = r.event_id
                LEFT JOIN categories c ON e.category_id = c.id
                LEFT JOIN locations l ON e.city_id = l.id
                WHERE r.user_id = :user_id
                  AND e.event_date BETWEEN :start_date AND :end_date
                  AND r.payment_status IN ('paid', 'free')
                ORDER BY e.event_date, e.event_time
            ");
            $stmt->execute(['user_id' => $userId, 'start_date' => $startDate, 'end_date' => $endDate]);

            $events = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $row['is_online'] = (bool)$row['is_online'];
                $day = date('j', strtotime($row['formatted_date']));
                $events[$day][] = $row;
            }

            $output = [
                'success' => true,
                'month' => date('F Y', strtotime($startDate)),
                'events' => $events,
                'firstDay' => (int)date('w', strtotime($startDate)),
                'totalDays' => (int)date('t', strtotime($startDate)),
            ];
        } // End check for specificEventId vs month/year

    } catch (Throwable $e) {
        error_log("Reg Events AJAX Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        // Set error code only if not already set (like 401, 400, 404)
        if (http_response_code() === 200) {
             http_response_code(500);
        }
        $output['error'] = 'Could not load data. Please try again later.';
        // Avoid exposing details in production unless intended
        // if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) { $output['error'] .= ' (' . $e->getMessage() . ')'; }
    } finally {
        // Ensure JSON is always output
        if (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    exit; // CRITICAL: Stop script execution after AJAX response
}
// --- End of AJAX Handler ---


// --- Page Generation Logic (Only runs if NOT an AJAX request) ---

// Check authentication for page view
if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php'); // Redirect to login page
    exit;
}
$user_id = $_SESSION['user_id'];

// Fetch registered events list data (for the side list)
try {
    // Upcoming Events
    $stmtUpcoming = $pdo->prepare("
        SELECT
            e.id, e.title, e.event_date, e.event_time, e.description, e.price, e.image_url,
            COALESCE(c.name, 'Uncategorized') AS category_name,
            COALESCE(c.color, '#6366F1') AS category_color,
            l.city AS location,
            (l.city IS NULL OR l.city = '' OR e.is_online = 1) AS is_online, -- Correct is_online check
            DATE_FORMAT(r.registration_date, '%Y-%m-%d %H:%i:%s') AS registration_date, -- Use registration_date alias consistently
            r.id AS registration_id,
            r.send_reminder,
            r.payment_status,
            -- Add fields needed by showEventModal to match calendar AJAX response
            DATE_FORMAT(e.event_date, '%Y-%m-%d') AS formatted_date,
            e.event_time AS raw_time,
            DATE_FORMAT(e.event_time, '%H:%i') AS formatted_time
        FROM events e
        JOIN registrations r ON e.id = r.event_id
        LEFT JOIN categories c ON e.category_id = c.id
        LEFT JOIN locations l ON e.city_id = l.id AND e.is_online = 0 -- Ensure location join respects is_online
        WHERE r.user_id = :user_id
          AND e.event_date >= CURDATE()
          AND r.payment_status IN ('paid', 'free')
        ORDER BY e.event_date ASC, e.event_time ASC
    ");
    $stmtUpcoming->execute(['user_id' => $user_id]);
    $upcomingEvents = $stmtUpcoming->fetchAll(PDO::FETCH_ASSOC);

    // Convert numeric/string fields expected as boolean/numbers by JS if needed
    foreach ($upcomingEvents as $key => $event) {
        $upcomingEvents[$key]['is_online'] = (bool)$event['is_online'];
        $upcomingEvents[$key]['send_reminder'] = (bool)$event['send_reminder'];
        $upcomingEvents[$key]['price'] = isset($event['price']) ? (float)$event['price'] : 0.0;
    }

    // Completed Events
    $stmtCompleted = $pdo->prepare("
        SELECT
            e.id, e.title, e.event_date, e.event_time, e.description, e.price, e.image_url,
            COALESCE(c.name, 'Uncategorized') AS category_name,
            COALESCE(c.color, '#6366F1') AS category_color,
            l.city AS location,
            (l.city IS NULL OR l.city = '' OR e.is_online = 1) AS is_online, -- Correct is_online check
            DATE_FORMAT(r.registration_date, '%Y-%m-%d %H:%i:%s') AS registration_date, -- Use registration_date alias consistently
            r.id AS registration_id, -- Include registration ID for consistency if needed later
            r.payment_status,
            -- Add fields needed by showEventModal to match calendar AJAX response
            DATE_FORMAT(e.event_date, '%Y-%m-%d') AS formatted_date,
            e.event_time AS raw_time,
            DATE_FORMAT(e.event_time, '%H:%i') AS formatted_time,
            -- Check if feedback exists for this user and event
            (SELECT COUNT(*) FROM feedback f WHERE f.user_id = r.user_id AND f.event_id = e.id) > 0 AS has_feedback
        FROM events e
        JOIN registrations r ON e.id = r.event_id
        LEFT JOIN categories c ON e.category_id = c.id
        LEFT JOIN locations l ON e.city_id = l.id AND e.is_online = 0 -- Ensure location join respects is_online
        WHERE r.user_id = :user_id
          AND e.event_date < CURDATE()
          AND r.payment_status IN ('paid', 'free')
        ORDER BY e.event_date DESC, e.event_time DESC -- Often better to show most recent completed first
    ");
    $stmtCompleted->execute(['user_id' => $user_id]);
    $completedEvents = $stmtCompleted->fetchAll(PDO::FETCH_ASSOC);

    // Convert numeric/string fields expected as boolean/numbers by JS if needed
    // AND convert has_feedback to boolean
    foreach ($completedEvents as $key => $event) {
        $completedEvents[$key]['is_online'] = (bool)$event['is_online'];
        $completedEvents[$key]['price'] = isset($event['price']) ? (float)$event['price'] : 0.0;
        $completedEvents[$key]['has_feedback'] = (bool)$event['has_feedback']; // Convert to boolean
        // No send_reminder for completed events typically
    }

    // Combine for empty state check
    $allRegisteredEvents = array_merge($upcomingEvents, $completedEvents);

} catch (PDOException $e) {
    // Display a user-friendly error on the page itself for database issues during page load
    $pageError = "Database error loading event list: " . htmlspecialchars($e->getMessage());
    // Log the detailed error for the admin
    error_log("Registered Events Page DB Error: " . $e->getMessage());
    // Set empty arrays to prevent errors in the loops later
    $upcomingEvents = [];
    $completedEvents = [];
    $allRegisteredEvents = [];
}


// --- Calendar Component Function Definition ---
function generateRegisteredCalendar() {
    // This function generates the HTML, JS, and CSS specific to the calendar component
    ?>
    <section class="relative" id="calendarSection">
        <div id="calendarLoader" class="absolute inset-0 bg-white/80 backdrop-blur-sm flex items-center justify-center rounded-2xl z-20 transition-opacity duration-300 opacity-0 pointer-events-none">
             <div class="animate-spin rounded-full h-10 w-10 border-t-2 border-b-2 border-purple-500"></div>
        </div>

         <div class="max-w-7xl mx-auto">
            <div class="bg-transparent relative">
                <div id="calendarContainer" class="min-h-[400px]">
                    <!-- Calendar will be rendered here by JS -->
                </div>
            </div>
        </div>

         <div id="eventModal" class="fixed inset-0 bg-black/40 backdrop-blur-sm p-4 z-[9990] flex items-center justify-center opacity-0 invisible transition-opacity duration-300 ease-out" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
             <div class="bg-white rounded-xl max-w-lg w-full p-6 shadow-xl border border-gray-200/50 transform scale-95 opacity-0 transition-all duration-300 ease-out max-h-[85vh] flex flex-col">
                 <div class="flex justify-between items-center pb-4 border-b border-gray-200 flex-shrink-0">
                    <h3 id="modalTitle" class="text-xl font-semibold text-gray-900">Event Details</h3>
                    <button onclick="registeredCalendarScope.closeModal()" aria-label="Close modal" class="p-1.5 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-full transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                 </div>
                 <div id="modalContent" class="space-y-5 pt-5 overflow-y-auto flex-grow">
                     {/* Modal content loaded by JS */}
                     <div class="text-center text-gray-500 py-8">Loading details...</div>
                 </div>
            </div>
        </div>
    </section>

     <div id="confirmationModal" class="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm opacity-0 invisible transition-opacity duration-300 ease-out" role="alertdialog" aria-modal="true" aria-labelledby="confirmationTitle" aria-describedby="confirmationMessage">
        <div class="w-full max-w-md bg-white rounded-xl shadow-xl transform scale-95 opacity-0 transition-all duration-300 ease-out" onclick="event.stopPropagation()">
            <div class="p-6 space-y-6">
                <div class="text-center space-y-2">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100">
                        <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900" id="confirmationTitle">Confirm Cancellation</h3>
                    <p class="text-sm text-gray-600" id="confirmationMessage">Are you sure you want to cancel your registration for this event?</p>
                </div>
                <div class="flex gap-3 justify-center sm:justify-end">
                    <button onclick="registeredCalendarScope.hideConfirmation()" type="button" class="px-4 py-2 text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 rounded-lg text-sm font-medium transition-colors">
                        Cancel
                    </button>
                    <button id="confirmActionButton" onclick="registeredCalendarScope.confirmAction()" type="button" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2">
                        Confirm Cancellation
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // --- Calendar Script (IIFE, Namespace) ---
        (function() {
            // Prevent multiple initializations
            if (window.registeredCalendarInitialized) {
                console.warn("Duplicate Registered Calendar initialization attempt blocked.");
                return;
            }
            window.registeredCalendarInitialized = true;
            console.log("Initializing Registered Calendar Script...");

            // State variables
            let currentMonth = new Date().getMonth() + 1;
            let currentYear = new Date().getFullYear();
            let calendarEvents = {};
            let modalHistory = [];
            let isNavigating = false;
            let cancelEventId = null;

            // DOM References
            const modalElement = document.getElementById('eventModal');
            const modalDialog = modalElement?.querySelector(':scope > div');
            const modalTitleEl = document.getElementById('modalTitle');
            const modalContentEl = document.getElementById('modalContent');
            const calendarContainerEl = document.getElementById('calendarContainer');
            const calendarLoaderEl = document.getElementById('calendarLoader');
            const confirmationModalEl = document.getElementById('confirmationModal');
            const confirmationDialogEl = confirmationModalEl?.querySelector(':scope > div');
            const confirmActionButtonEl = document.getElementById('confirmActionButton');

            // --- Utility Functions ---
            function getCsrfToken() {
                const tokenMeta = document.querySelector('meta[name="csrf-token"]');
                if (!tokenMeta) {
                    console.error("CSRF meta tag not found!");
                     showToast('Cannot perform action: Security token missing.', 'error'); // Notify user
                    return null;
                }
                return tokenMeta.getAttribute('content');
            }

            function escapeHtml(text) { if (text === null || typeof text === 'undefined') return ''; const div = document.createElement('div'); div.textContent = String(text); return div.innerHTML; }
            function nl2br(str) {
                if (typeof str === 'undefined' || str === null) {
                    return '';
                }
                // Replace line breaks (\n, \r\n, \r) with <br> tags
                return String(str).replace(/(\r\n|\n\r|\r|\n)/g, '<br>$&'); // Using $& keeps the original newline, crucial for accurate spacing
            }
            function showLoader() { if(calendarLoaderEl) { calendarLoaderEl.style.opacity = 1; calendarLoaderEl.style.pointerEvents = 'auto';} }
            function hideLoader() { if(calendarLoaderEl) { calendarLoaderEl.style.opacity = 0; calendarLoaderEl.style.pointerEvents = 'none';} }
            function showToast(message, type = 'info') { const el = document.querySelector('.calendar-toast'); if(el) el.remove(); const t = document.createElement('div'); t.className = `calendar-toast pointer-events-auto fixed bottom-5 right-5 p-4 rounded-lg shadow-lg text-white ${type === 'success' ? 'bg-green-600' : type === 'error' ? 'bg-red-600' : 'bg-indigo-600'} flex items-center gap-3 animate-slide-up z-[10000]`; t.innerHTML = `<span class="flex-shrink-0">${type === 'success' ? '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>' : type === 'error' ? '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>' : '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>'}</span><span class="flex-grow">${escapeHtml(message)}</span>`; document.body.appendChild(t); setTimeout(() => { t.style.transition = 'opacity 0.3s ease, transform 0.3s ease'; t.style.opacity = '0'; t.style.transform = 'translateY(20px)'; setTimeout(() => t.remove(), 300); }, 4000); }

            // --- Calendar Rendering Logic ---
            // --- Calendar Rendering Logic ---
            function loadCalendar(month, year) {
                showLoader();
                calendarEvents = {};
                if (!calendarContainerEl) {
                    console.error("#calendarContainer not found!");
                    hideLoader();
                    return Promise.reject(new Error("#calendarContainer not found!")); // Return rejected promise
                }
                calendarContainerEl.innerHTML = '<div class="text-center p-10 text-gray-500">Loading Calendar...</div>';

                // --- MODIFICATION: Return the fetch promise ---
                return fetch(`/pages/registered_events.php?ajax=1&month=${month}&year=${year}`)
                    .then(response => {
                        if (!response.ok) {
                            return response.json().catch(() => {
                                // Use statusText if JSON parsing fails or no error message
                                throw new Error(`HTTP ${response.status}: ${response.statusText || 'Server Error'}`);
                            }).then(errData => {
                                // Use specific error from JSON if available
                                throw new Error(errData.error || `HTTP ${response.status}`);
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            calendarEvents = data.events || {};
                            renderCalendar(data);
                            // Return data or true on success if needed for chaining
                            return true;
                        } else {
                            // Throw error from API response
                            throw new Error(data.error || 'API returned an error');
                        }
                    })
                    .catch(error => {
                        console.error('Calendar load error:', error);
                        showToast(`Error loading calendar: ${error.message}`, 'error');
                        if(calendarContainerEl) calendarContainerEl.innerHTML = `<div class="p-4 text-red-700 bg-red-100 rounded-lg border border-red-200">Failed to load calendar data. Please check the console or try again later.</div>`;
                        isNavigating = false; // Ensure flag reset on error
                        throw error; // Re-throw error to allow chaining .catch in DOMContentLoaded
                    })
                    .finally(() => {
                        hideLoader();
                        isNavigating = false; // Ensure flag reset on success/failure
                    });
                 // --- END MODIFICATION ---
            }

            function renderCalendar({ month: monthName, totalDays, firstDay }) {
                 if (!calendarContainerEl) return;
                 calendarContainerEl.innerHTML = `
                    <div class="flex flex-wrap items-center justify-between mb-6 gap-4 px-4 sm:px-0">
                        ${renderHeader(monthName)}
                        <button onclick="registeredCalendarScope.goToToday()" title="Go to Today" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition-colors flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            Today
                        </button>
                    </div>
                    <div class="grid grid-cols-7 border-t border-l border-gray-200">
                        ${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(day => `<div class="bg-gray-50 p-2 text-center text-xs font-semibold text-gray-500 uppercase border-b border-r border-gray-200">${day}</div>`).join('')}
                        ${renderDaysGrid(firstDay, totalDays)}
                    </div>`;
                 console.log("Calendar rendered for", monthName);
            }

            function renderHeader(monthText) {
                return `<div class="flex items-center gap-2">
                    <button onclick="registeredCalendarScope.navigateMonth(-1)" title="Previous Month" class="nav-button p-1.5 text-gray-500 hover:text-indigo-600 hover:bg-gray-100 rounded-full transition-colors group">
                        <svg class="w-5 h-5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <h2 class="text-lg font-semibold text-gray-900 w-32 text-center">${escapeHtml(monthText)}</h2>
                    <button onclick="registeredCalendarScope.navigateMonth(1)" title="Next Month" class="nav-button p-1.5 text-gray-500 hover:text-indigo-600 hover:bg-gray-100 rounded-full transition-colors group">
                        <svg class="w-5 h-5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>`;
            }

            function renderDaysGrid(firstDay, totalDays) {
                let html = ''; let dayCount = 1;
                const totalCells = Math.ceil((firstDay + totalDays) / 7) * 7;
                const today = new Date();
                const clientYear = today.getFullYear();
                const clientMonth = today.getMonth(); // 0-based
                const clientDay = today.getDate();
                const viewedMonth = currentMonth - 1; // 0-based
                const viewedYear = currentYear;

                for (let i = 0; i < totalCells; i++) {
                    if (i < firstDay || dayCount > totalDays) {
                        html += `<div class="bg-gray-50/50 min-h-[110px] p-1 border-b border-r border-gray-200"></div>`; // Empty cell
                    } else {
                        const currentDay = dayCount;
                        const isToday = viewedYear === clientYear && viewedMonth === clientMonth && currentDay === clientDay;
                        const dayEvents = calendarEvents[currentDay] || [];
                        const hasEvents = dayEvents.length > 0;

                        html += `
                        <div class="calendar-day bg-white min-h-[110px] p-1.5 border-b border-r border-gray-200 relative group transition-colors duration-150 ${isToday ? 'bg-indigo-50 font-semibold' : 'hover:bg-gray-50'} ${hasEvents ? 'cursor-pointer' : ''}"
                            ${hasEvents ? `onclick="registeredCalendarScope.showMoreEvents(${currentDay})"` : ''}
                            title="${hasEvents ? `${dayEvents.length} event(s) on ${currentDay}` : `No events on ${currentDay}`}">
                        <div class="flex justify-between items-center mb-1">
                                <span class="text-xs ${isToday ? 'text-indigo-700 font-bold' : 'text-gray-600'}">
                                    ${currentDay}
                                </span>
                                ${isToday ? `<span class="text-[9px] bg-indigo-600 text-white px-1 py-0 rounded font-medium shadow-sm">Today</span>` : ''}
                        </div>
                        ${hasEvents ? `
                            <div class="space-y-1 mt-1">
                                ${dayEvents.slice(0, 2).map(evt => `
                                    <div class="event-dot-indicator flex items-center gap-1.5 text-[10px] font-medium truncate leading-tight p-0.5 rounded" style="background-color: ${evt.category_color || '#6366F1'}20; color: ${evt.category_color || '#6366F1'};">
                                            <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background-color: ${evt.category_color || '#6366F1'};"></span>
                                            <span class="truncate">${escapeHtml(evt.title)}</span>
                                    </div>
                                `).join('')}
                                ${dayEvents.length > 2 ? `
                                    <div class="text-[10px] text-gray-500 font-medium text-center pt-0.5">+ ${dayEvents.length - 2} more</div>
                                ` : ''}
                            </div>
                            ` : '<div class="h-full"></div>' /* Placeholder */}
                        </div>`;
                        dayCount++;
                    }
                }
                return html;
            }

            function navigateMonth(offset) {
                if (isNavigating) return;
                isNavigating = true;

                let newMonth = currentMonth + offset;
                let newYear = currentYear;
                if (newMonth < 1) { newMonth = 12; newYear--; }
                if (newMonth > 12) { newMonth = 1; newYear++; }

                currentMonth = newMonth;
                currentYear = newYear;

                loadCalendar(currentMonth, currentYear); // loadCalendar resets isNavigating in finally
            }

            function goToToday() {
                const today = new Date();
                const targetMonth = today.getMonth() + 1;
                const targetYear = today.getFullYear();
                if (targetMonth !== currentMonth || targetYear !== currentYear) {
                     currentMonth = targetMonth; // Update state before loading
                     currentYear = targetYear;
                     loadCalendar(currentMonth, currentYear);
                } else {
                    showToast("Already viewing the current month.", "info");
                }
            }

            // --- Modal Logic ---
            function showModal() {
                 if (!modalElement || !modalDialog) return;
                 modalElement.classList.remove('invisible');
                 requestAnimationFrame(() => {
                     modalElement.classList.add('opacity-100');
                     modalDialog.classList.add('opacity-100', 'scale-100');
                 });
            }

            function closeModal() {
                 if (!modalElement || !modalDialog) return;
                 modalElement.classList.remove('opacity-100');
                 modalDialog.classList.remove('opacity-100', 'scale-100');
                 setTimeout(() => {
                     modalElement.classList.add('invisible');
                     if (modalContentEl) modalContentEl.innerHTML = '';
                     modalHistory = [];
                     document.querySelectorAll('.active-event-highlight').forEach(el => el.classList.remove('active-event-highlight', 'ring-2', 'ring-indigo-500', 'ring-offset-1'));
                 }, 300);
            }

            function showMoreEvents(day) {
                 const events = calendarEvents[day] || [];
                 if (events.length === 0 || !modalContentEl || !modalTitleEl) return;

                 modalTitleEl.textContent = `Events on ${new Date(currentYear, currentMonth - 1, day).toLocaleDateString('en-US', { month: 'long', day: 'numeric' })}`;
                 modalContentEl.innerHTML = `
                    <div class="space-y-3">
                        ${events.map(event => `
                            <div class="block p-4 rounded-lg border border-gray-200 hover:border-indigo-300 hover:bg-indigo-50 cursor-pointer transition-colors"
                                 data-event='${JSON.stringify(event).replace(/'/g, "&#39;")}'
                                 onclick="registeredCalendarScope.showEventModal(this)">
                                <div class="flex items-center justify-between gap-3 mb-1.5">
                                     <span class="font-semibold text-gray-800">${escapeHtml(event.title)}</span>
                                     <span class="text-xs px-2 py-0.5 rounded-full font-medium" style="background-color: ${event.category_color || '#6366F1'}20; color: ${event.category_color || '#6366F1'};">
                                         ${escapeHtml(event.category_name)}
                                     </span>
                                </div>
                                <div class="flex items-center gap-2 text-sm text-gray-500">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    <span>${event.formatted_time || 'All Day'}</span>
                                    <span class="text-gray-300">|</span>
                                     ${event.is_online
                                         ? `<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M12 6v.01M12 12v.01M12 18v.01"></path></svg><span>Online Event</span>`
                                         : `<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg><span>${escapeHtml(event.location || 'TBC')}</span>`
                                     }
                                </div>
                            </div>
                        `).join('')}
                    </div>`;
                 modalHistory = [{ type: 'list', day: day }];
                 showModal();
            }

            function showEventModal(eventSource) {
                 if (!modalContentEl || !modalTitleEl) return;
                 try {
                    let event;
                    // --- MODIFICATION START: Accept object or element ---
                    if (typeof eventSource === 'object' && eventSource !== null && eventSource.id && eventSource.formatted_date) {
                        // Argument looks like an event data object (check for key properties)
                        event = eventSource;
                        console.log("showEventModal called with event object:", event);
                    } else if (eventSource instanceof HTMLElement && eventSource.dataset.event) {
                        // Argument is an HTML element with data-event
                        event = JSON.parse(eventSource.dataset.event.replace(/'/g, "'")); // Handle potential single quotes
                        console.log("showEventModal called with HTML element:", eventSource);
                    } else {
                        console.error("Invalid argument passed to showEventModal:", eventSource);
                        showToast('Could not display event details: Invalid data.', 'error');
                        return;
                    }
                    // --- MODIFICATION END ---

                    // --- Rest of the function logic remains the same ---

                     // Remove previous highlights and highlight current list item
                    document.querySelectorAll('.active-event-highlight').forEach(el => el.classList.remove('active-event-highlight', 'ring-2', 'ring-indigo-500', 'ring-offset-1'));
                    // Try to find list item regardless of source, but might not exist if event isn't in current list view
                    const listItem = document.querySelector(`.event-list-card[data-event-id="${event.id}"]`);
                    if (listItem) {
                        listItem.classList.add('active-event-highlight', 'ring-2', 'ring-indigo-500', 'ring-offset-1');
                        // Optional: Scroll list item into view if not opened from list click
                         if (!(eventSource instanceof HTMLElement)) {
                            listItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                         }
                    }

                     // --- START: Past Event Check ---
                     const now = new Date();
                     const eventDateTimeString = event.formatted_date + (event.raw_time ? 'T' + event.raw_time : 'T00:00:00');
                     const eventDate = new Date(eventDateTimeString);
                     const isPastEvent = eventDate.getTime() < now.getTime();
                     // --- END: Past Event Check ---

                     const formattedFullDate = new Date(event.formatted_date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
                     const formattedTime = event.formatted_time ? new Date(`1970-01-01T${event.formatted_time}:00`).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'All day';
                     const registrationDate = event.registration_date ? new Date(event.registration_date).toLocaleDateString() : 'N/A';

                     modalTitleEl.textContent = escapeHtml(event.title) || 'Event Details';

                    // --- START: Conditional Cancel Button / Info ---
                    let cancelOrInfoHtml = '';
                    const eventPrice = parseFloat(event.price || 0); // Ensure price is a number

                    if (isPastEvent) {
                        cancelOrInfoHtml = `
                            <div class="mt-4 p-3 bg-gray-100 border border-gray-200 rounded-lg text-center">
                                <p class="text-sm font-medium text-gray-600 flex items-center justify-center gap-2">
                                    <svg class="w-4 h-4 text-gray-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                    Event Completed / Past
                                </p>
                            </div>
                        `;
                    } else if (eventPrice > 0) {
                        // Upcoming PAID event - Not cancellable
                        cancelOrInfoHtml = `
                            <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-center">
                                <p class="text-sm font-medium text-yellow-800 flex items-center justify-center gap-2">
                                    <svg class="w-4 h-4 text-yellow-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"></path></svg>
                                    Paid Registration (Non-Cancellable)
                                </p>
                                <p class="text-xs text-yellow-700 mt-1">This registration is non-refundable.</p>
                            </div>
                        `;
                    } else {
                        // Upcoming FREE event - Cancellable
                        cancelOrInfoHtml = `
                            <button onclick="registeredCalendarScope.showConfirmation(${event.id})" class="w-full mt-4 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-colors flex items-center justify-center gap-2 text-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                Cancel Registration
                            </button>
                        `;
                    }
                    // --- END: Conditional Cancel Button / Info ---

                     // --- START: Add View Event Page Link ---
                    const viewEventPageLinkHtml = `
                        <a href="/pages/event.php?id=${event.id}" target="_blank" rel="noopener noreferrer" class="block w-full mt-4 mb-2 px-4 py-2 text-center text-sm font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg transition-colors flex items-center justify-center gap-1.5 group" title="View full event details on its page (opens new tab)">
                            <svg class="w-4 h-4 text-indigo-600 group-hover:text-indigo-800 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                            View Event Page
                        </a>
                    `;
                    // --- END: Add View Event Page Link ---

                     // --- MODAL CONTENT HTML (No major changes needed here, uses 'event' var) ---
                     modalContentEl.innerHTML = `
                        <div class="space-y-4">
                            ${event.image_url ? `<img src="/assets/images/${escapeHtml(event.image_url)}" alt="${escapeHtml(event.title)}" class="w-full h-48 object-cover rounded-lg mb-3 bg-gray-100">` : ''}
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="inline-flex items-center gap-2 text-sm font-medium px-3 py-1 rounded-full" style="background-color: ${event.category_color || '#6366F1'}20; color: ${event.category_color || '#6366F1'};">
                                    <span class="w-2 h-2 rounded-full" style="background-color: ${event.category_color || '#6366F1'};"></span>
                                    ${escapeHtml(event.category_name)}
                                </span>
                                <span class="text-sm font-semibold px-3 py-1 rounded-full ${event.price > 0 ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'}">
                                    ${event.price > 0 ? `RM ${parseFloat(event.price).toFixed(2)}` : 'FREE'}
                                </span>
                            </div>
                            <dl class="space-y-2 text-sm border-t border-b border-gray-200 py-3">
                                <div class="flex items-start gap-3"> <dt class="w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></dt> <dd class="text-gray-700">${formattedFullDate}</dd> </div>
                                <div class="flex items-start gap-3"> <dt class="w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></dt> <dd class="text-gray-700">${formattedTime}</dd> </div>
                                <div class="flex items-start gap-3">
                                    ${event.is_online
                                        ? `<dt class="w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M12 6v.01M12 12v.01M12 18v.01"/></svg></dt><dd class="text-gray-700 font-medium">Online Event</dd>`
                                        : `<dt class="w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg></dt><dd class="text-gray-700">${escapeHtml(event.location || 'Location TBC')}</dd>`
                                    }
                                </div>
                            </dl>
                            <div>
                                <h4 class="text-base font-semibold text-gray-800 mb-2">Description</h4>
                                <div class="prose prose-sm max-w-none text-gray-600">
                                    ${event.description ? nl2br(escapeHtml(event.description)) : '<p><em>No description provided.</em></p>'}
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 text-center border-t pt-3">Registered on: ${registrationDate}</p>
                            ${viewEventPageLinkHtml}
                            ${cancelOrInfoHtml} 
                        </div>
                    `;
                    // --- END MODAL CONTENT HTML ---

                    modalHistory.push({ type: 'detail', eventId: event.id }); // Push to history stack
                    showModal();
                } catch (error) {
                    console.error('Error parsing event data or showing modal:', error);
                    if(modalContentEl) modalContentEl.innerHTML = '<div class="p-4 bg-red-100 text-red-700 rounded border border-red-200">Could not load event details.</div>';
                    showModal(); // Show modal even with error message inside
                }
            }

            // --- Confirmation Modal Logic ---
             function showConfirmation(eventIdToCancel) {
                 if (!confirmationModalEl || !confirmationDialogEl) return;
                 // Additional check: Ensure we don't somehow show confirmation for a past event
                 // (although the button should be disabled, this is a safeguard)
                 // This requires finding the event data again, which might be complex here.
                 // Relying on the disabled button is the primary mechanism.
                 // If needed, you could pass the `isPastEvent` flag from showEventModal.

                 cancelEventId = eventIdToCancel;
                 confirmationModalEl.classList.remove('invisible');
                 requestAnimationFrame(() => {
                    confirmationModalEl.classList.add('opacity-100');
                    confirmationDialogEl.classList.add('opacity-100', 'scale-100');
                 });
             }

             function hideConfirmation() {
                 if (!confirmationModalEl || !confirmationDialogEl) return;
                 cancelEventId = null;
                 confirmationModalEl.classList.remove('opacity-100');
                 confirmationDialogEl.classList.remove('opacity-100', 'scale-100');
                  setTimeout(() => {
                     confirmationModalEl.classList.add('invisible');
                 }, 300);
             }

             function confirmAction() {
                 if (cancelEventId !== null) {
                     cancelRegistration(cancelEventId);
                 } else {
                     console.warn("Confirm action called but no event ID was stored.");
                     hideConfirmation(); // Hide if something went wrong
                 }
                 // Don't hide confirmation here, let cancelRegistration handle it on success/error
             }

             async function cancelRegistration(eventId) {
                 if (!confirmActionButtonEl) return;
                 const originalButtonText = confirmActionButtonEl.innerHTML;
                 confirmActionButtonEl.disabled = true;
                 confirmActionButtonEl.innerHTML = `<span class="animate-spin rounded-full h-4 w-4 border-t-2 border-b-2 border-white mr-2"></span> Processing...`;

                const csrfToken = getCsrfToken();
                if (!csrfToken) {
                    // Error handled within getCsrfToken, just stop here
                    confirmActionButtonEl.disabled = false;
                    confirmActionButtonEl.innerHTML = originalButtonText;
                    hideConfirmation(); // Also hide the confirmation modal
                    return;
                }

                 try {
                     // *** IMPORTANT SERVER-SIDE VALIDATION NEEDED ***
                     // The /actions/cancel_registration.php script MUST
                     // re-verify that the event is not in the past before deleting!
                     // This client-side check is for UX only.
                     // ***********************************************

                     const response = await fetch('/actions/cancel_registration.php', {
                         method: 'POST',
                         headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                         body: JSON.stringify({
                         event_id: eventId,
                         csrf_token: getCsrfToken() // <<< ADDED: Get and send the token
                     })
                     });
                     const result = await response.json();
                     if (!response.ok) { throw new Error(result.error || `HTTP ${response.status}`); }
                     if (result.success) {
                         showToast('Registration cancelled successfully!', 'success');
                         closeModal(); // Close the event detail modal
                         hideConfirmation(); // Close confirmation on success
                         loadCalendar(currentMonth, currentYear); // Reload calendar data
                         // TODO: Add dynamic update for the right-side list (more complex)
                         // Simple solution: Reload the page after a short delay or ask user
                         // location.reload(); // Or trigger a specific list reload function
                         setTimeout(() => location.reload(), 500); // Simple page reload for list update
                     } else {
                         // Throw specific error from backend if provided
                         throw new Error(result.error || 'Failed to cancel registration.');
                     }
                 } catch (error) {
                     console.error("Cancellation error:", error);
                     showToast(`Error cancelling: ${error.message}`, 'error');
                     // Restore button state and hide confirmation on error
                     confirmActionButtonEl.disabled = false;
                     confirmActionButtonEl.innerHTML = originalButtonText;
                     hideConfirmation();
                 }
                 // No finally block needed here as button/modal state handled within try/catch
             }

            // --- ADDED: Function to fetch and show a specific event modal ---
            async function fetchAndShowEventModal(eventId) {
                 console.log(`Fetching details for event ID: ${eventId}`);
                 showLoader(); // Show loader while fetching specific event
                 try {
                     // Using the same AJAX endpoint but with eventId param
                    const response = await fetch(`/pages/registered_events.php?ajax=1&eventId=${eventId}`);
                    if (!response.ok) {
                         // Try to parse error JSON, fallback to status text
                         let errorMsg = `HTTP ${response.status}`;
                         try {
                             const errData = await response.json();
                             errorMsg = errData.error || errorMsg;
                         } catch (parseError) { /* Ignore parsing error, use status text */ }
                         throw new Error(errorMsg);
                    }
                    const result = await response.json();
                     if (result.success && result.event) {
                         console.log("Successfully fetched specific event data:", result.event);
                         // Call the updated showEventModal with the data object
                         showEventModal(result.event); // Pass the event data directly
                    } else {
                         throw new Error(result.error || 'Event not found or not registered.');
                    }
                 } catch (error) {
                    console.error(`Error fetching event details for ID ${eventId}:`, error);
                    showToast(`Could not load event details: ${error.message}`, 'error');
                 } finally {
                     hideLoader();
                 }
             }

            // --- Global Scope Access (Namespace) ---
            window.registeredCalendarScope = {
                navigateMonth, goToToday, showMoreEvents, showEventModal, closeModal,
                showConfirmation,
                hideConfirmation,
                confirmAction
            };

            // --- Initialization ---
            // --- Initialization ---
            document.addEventListener('DOMContentLoaded', () => {
                if (!calendarContainerEl || !modalElement || !confirmationModalEl) {
                     console.error("Essential DOM elements (calendar, modals) missing!");
                     // Attempt to show an error to the user on the page itself
                     const bodyEl = document.body;
                     if(bodyEl) {
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'p-4 m-4 bg-red-100 border border-red-300 text-red-800 rounded shadow';
                        errorDiv.textContent = 'Error: Essential page components could not be found. The calendar may not function correctly.';
                        bodyEl.prepend(errorDiv);
                     }
                     return; // Stop further initialization if critical elements are missing
                }

                 // --- MODIFICATION START: Handle URL param after initial load ---
                loadCalendar(currentMonth, currentYear)
                    .then(() => {
                        console.log("Initial calendar rendered successfully (or finished attempt).");
                        // Check for URL parameter *after* initial calendar load attempt
                        checkForUrlEventParameter();
                    })
                    .catch(initialLoadError => {
                        console.error("Initial calendar load failed:", initialLoadError);
                        // Even if calendar fails, still try to show the specific event if requested
                        checkForUrlEventParameter();
                    });
                 // --- MODIFICATION END ---

                // Event Listeners
                modalElement.addEventListener('click', (e) => { if (e.target === modalElement) closeModal(); });
                confirmationModalEl.addEventListener('click', (e) => { if (e.target === confirmationModalEl) hideConfirmation(); });
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                         if (!confirmationModalEl.classList.contains('invisible')) {
                             hideConfirmation();
                         } else if (!modalElement.classList.contains('invisible')) {
                            closeModal();
                         }
                    }
                });
                console.log("Registered Calendar Initialized and Listeners Active.");
            });

            // --- ADDED Helper function to check URL parameter ---
            function checkForUrlEventParameter() {
                const urlParams = new URLSearchParams(window.location.search);
                const eventIdToShow = urlParams.get('showEvent');
                if (eventIdToShow && /^\d+$/.test(eventIdToShow)) {
                     console.log(`URL parameter found: showEvent=${eventIdToShow}`);
                     // Use setTimeout to ensure the browser has rendered the initial page/calendar attempt
                     // and the modal call doesn't happen too quickly during potential layout shifts.
                     setTimeout(() => {
                        fetchAndShowEventModal(parseInt(eventIdToShow, 10));
                        // Optional: Clean the URL parameter after processing
                        // history.replaceState(null, '', window.location.pathname + window.location.hash);
                     }, 100); // Short delay
                } else {
                    console.log("No valid 'showEvent' URL parameter found.");
                }
            }

        })(); // End IIFE
    </script>

    <style>
        /* Basic Styles for Calendar & Modals */
        #calendarLoader { transition: opacity 0.3s ease; }
        .calendar-day { transition: background-color 0.15s ease; }
        /* Modal Base Styles (Tailwind handles most) */
        #modalContent::-webkit-scrollbar { width: 5px; }
        #modalContent::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 3px; }
        #modalContent::-webkit-scrollbar-thumb { background: #ccc; border-radius: 3px; }
        #modalContent::-webkit-scrollbar-thumb:hover { background: #aaa; }
        /* Active Event Highlight (in list view) */
        .active-event-highlight { /* Tailwind classes added dynamically */ }
        /* Toast Animation */
        @keyframes slide-up { 0% { transform: translateY(100%); opacity: 0; } 100% { transform: translateY(0); opacity: 1; } }
        .animate-slide-up { animation: slide-up 0.3s ease-out forwards; }
        /* Alpine Hiding */
        [x-cloak] { display: none !important; }
    </style>
    <?php
} // --- End of generateRegisteredCalendar function ---

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <title>My Events - CECT</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,aspect-ratio"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        /* Custom Scrollbar for Event List */
        #eventListContainer::-webkit-scrollbar { width: 6px; }
        #eventListContainer::-webkit-scrollbar-track { background: #f8fafc; border-radius: 3px; }
        #eventListContainer::-webkit-scrollbar-thumb { background-color: #d1d5db; border-radius: 3px; border: 1px solid #f8fafc; }
        #eventListContainer::-webkit-scrollbar-thumb:hover { background-color: #9ca3af; }
        #eventListContainer { scrollbar-width: thin; scrollbar-color: #d1d5db #f8fafc; }
        /* Add a class for the actual list items if needed for empty state checking */
        .event-list-item { /* Add styles if needed, or just use as a selector */ }
    </style>
</head>
<body class="antialiased">
    <?php require_once '../includes/nav.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

        <?php if (isset($pageError)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md shadow" role="alert">
                <p class="font-bold">Error</p>
                <p><?php echo $pageError; ?></p>
            </div>
        <?php endif; ?>

        <div class="grid lg:grid-cols-3 gap-6 lg:gap-8">
            <!-- Calendar Section -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm p-4 sm:p-6 border border-gray-200/80">
                 <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"> <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/> </svg>
                        My Event Calendar <span class="text-sm text-gray-500 font-normal">(Confirmed Registrations)</span>
                    </h2>
                 </div>
                 <?php generateRegisteredCalendar(); // Call the function to output calendar HTML/JS/CSS ?>
            </div>

            <!-- Event List Section Container -->
            <div class="lg:col-span-1">
                <!-- Make list container sticky within its column -->
                <div class="lg:sticky lg:top-8">
                    <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6 border border-gray-200/80" x-data="{ activeTab: 'upcoming' }">
                         <div class="flex items-center justify-between mb-5">
                            <h3 class="text-lg font-semibold text-gray-900">
                               My Confirmed Events
                            </h3>
                             <div class="flex gap-1 bg-gray-100 p-1 rounded-lg">
                                 <button @click="activeTab = 'upcoming'" :class="activeTab === 'upcoming' ? 'bg-white shadow-sm text-indigo-600' : 'text-gray-500 hover:text-gray-700'" class="px-3 py-1 rounded-md text-xs font-medium transition-all"> Upcoming </button>
                                 <button @click="activeTab = 'completed'" :class="activeTab === 'completed' ? 'bg-white shadow-sm text-indigo-600' : 'text-gray-500 hover:text-gray-700'" class="px-3 py-1 rounded-md text-xs font-medium transition-all"> Completed </button>
                             </div>
                        </div>

                        <!-- Set max height and overflow for the list itself -->
                        <div id="eventListContainer" class="max-h-[calc(100vh-10rem)] overflow-y-auto pr-1 space-y-3">
                            <!-- Upcoming Events List -->
                            <div x-show="activeTab === 'upcoming'" x-cloak class="space-y-3">
                                <?php if(empty($upcomingEvents)): ?>
                                     <div class="empty-state-message text-center py-6 text-sm text-gray-500">No upcoming confirmed events.</div> <!-- Added class -->
                                <?php else: ?>
                                    <?php foreach($upcomingEvents as $event):
                                        $eventDate = new DateTime($event['event_date']);
                                        $now = new DateTime();
                                        $interval = $now->diff($eventDate);
                                        $daysLeft = $interval->invert ? 0 : $interval->days; // Days left (non-negative)
                                        $isPaidEvent = isset($event['price']) && (float)$event['price'] > 0; // Check if paid
                                    ?>
                                     <!-- Added class event-list-item -->
                                     <div class="event-list-item event-list-card group relative block bg-white rounded-lg p-3.5 shadow-sm border border-gray-200 hover:border-indigo-300 transition-all duration-200 ease-out cursor-pointer"
                                        data-event-id="<?= $event['id'] ?>"
                                        data-event='<?= json_encode($event, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>'
                                        onclick="event.stopPropagation(); registeredCalendarScope.showEventModal(this)">
                                         <div class="flex gap-3 items-start">
                                             <div class="flex-shrink-0 text-center w-11 h-11 bg-indigo-50 rounded-md flex flex-col items-center justify-center border border-indigo-100 group-hover:bg-indigo-100 transition-colors">
                                                 <span class="text-indigo-600 font-bold text-lg leading-none block"><?= $eventDate->format('d') ?></span>
                                                 <span class="text-indigo-500 text-[10px] font-medium uppercase tracking-wide block"><?= $eventDate->format('M') ?></span>
                                             </div>
                                             <div class="flex-1 min-w-0 pr-14">
                                                <h4 class="font-semibold text-sm text-gray-800 group-hover:text-indigo-700 transition-colors truncate flex items-center gap-1.5" title="<?= htmlspecialchars($event['title']) ?>">
                                                     <?= htmlspecialchars($event['title']) ?>
                                                     <?php if ($isPaidEvent): ?>
                                                        <span title="Paid / Non-refundable">
                                                            <svg class="w-3 h-3 text-yellow-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"></path></svg>
                                                        </span>
                                                     <?php endif; ?>
                                                 </h4>
                                                 <div class="mt-1.5 space-y-1">
                                                    <!-- ... time and location ... -->
                                                    <p class="flex items-center gap-1.5 text-xs text-gray-500">
                                                        <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                        <?= !empty($event['event_time']) ? date('g:i A', strtotime($event['event_time'])) : 'All Day' ?>
                                                    </p>
                                                    <p class="flex items-center gap-1.5 text-xs text-gray-500">
                                                        <?php if ($event['is_online']): ?>
                                                            <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M12 6v.01M12 12v.01M12 18v.01"/></svg>
                                                            <span class="font-medium">Online Event</span>
                                                        <?php else: ?>
                                                            <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                                            <span class="truncate"><?= htmlspecialchars($event['location'] ?: 'Location TBC') ?></span>
                                                        <?php endif; ?>
                                                    </p>
                                                 </div>
                                                <div class="text-xs text-indigo-600 font-medium mt-2 pt-2 border-t border-gray-100">
                                                    <?= $daysLeft ?> day<?= $daysLeft != 1 ? 's' : '' ?> left
                                                </div>
                                                <!-- ... Reminder Toggle Section ... -->
                                                <div class="mt-2 pt-2 border-t border-gray-100 flex items-center justify-between gap-2">
                                                <label for="reminder-toggle-<?= $event['registration_id'] ?>" class="flex items-center cursor-pointer text-xs text-gray-600 select-none" title="Toggle email reminder sent approx. 24 hours before the event" onclick="event.stopPropagation();">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 mr-1 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                                            <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" />
                                                        </svg>
                                                        <span>Email Reminder <span class="text-gray-400">(~24h before)</span></span>
                                                    </label>
                                                    <button
                                                        type="button"
                                                        id="reminder-toggle-<?= $event['registration_id'] ?>"
                                                        role="switch"
                                                        aria-checked="<?= $event['send_reminder'] ? 'true' : 'false' ?>"
                                                        data-registration-id="<?= $event['registration_id'] ?>"
                                                        data-current-state="<?= $event['send_reminder'] ? 'true' : 'false' ?>"
                                                        onclick="event.stopPropagation(); toggleReminder(this)"
                                                        class="reminder-toggle relative inline-flex items-center h-5 rounded-full w-9 transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-500 <?= $event['send_reminder'] ? 'bg-indigo-600' : 'bg-gray-300' ?>"
                                                        aria-labelledby="reminder-toggle-label-<?= $event['registration_id'] ?>"
                                                        >
                                                        <span class="sr-only" id="reminder-toggle-label-<?= $event['registration_id'] ?>">Toggle Event Reminders (24h before)</span>
                                                        <span class="reminder-toggle-knob inline-block w-3.5 h-3.5 transform bg-white rounded-full transition-transform duration-200 ease-in-out <?= $event['send_reminder'] ? 'translate-x-4' : 'translate-x-1' ?>"></span>
                                                    </button>
                                                </div>
                                                <!-- End Reminder Toggle Section -->
                                             </div>
                                            <div class="absolute top-2 right-2">
                                                <span class="text-[10px] px-1.5 py-0.5 rounded font-medium" style="background-color: <?= $event['category_color'] ?>20; color: <?= $event['category_color'] ?>;">
                                                    <?= htmlspecialchars($event['category_name']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Completed Events List -->
                            <div x-show="activeTab === 'completed'" x-cloak class="space-y-3">
                                <?php if(empty($completedEvents)): ?>
                                     <div class="empty-state-message text-center py-6 text-sm text-gray-500">No completed events found.</div> <!-- Added class -->
                                <?php else: ?>
                                    <?php foreach($completedEvents as $event):
                                         $eventDate = new DateTime($event['event_date']);
                                     ?>
                                     <!-- Added class event-list-item -->
                                     <div class="event-list-item event-list-card group relative block bg-white rounded-lg p-3.5 shadow-sm border border-gray-200 hover:border-indigo-300 transition-all duration-200 ease-out cursor-pointer opacity-80 hover:opacity-100"
                                        data-event-id="<?= $event['id'] ?>"
                                        data-event='<?= json_encode($event, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                                        onclick="event.stopPropagation(); registeredCalendarScope.showEventModal(this)">
                                        <div class="flex gap-3 items-start">
                                             <div class="flex-shrink-0 text-center w-11 h-11 bg-green-50 rounded-md flex flex-col items-center justify-center border border-green-100 group-hover:bg-green-100 transition-colors">
                                                 <span class="text-green-600 font-bold text-lg leading-none block"><?= $eventDate->format('d') ?></span>
                                                 <span class="text-green-500 text-[10px] font-medium uppercase tracking-wide block"><?= $eventDate->format('M') ?></span>
                                             </div>
                                             <div class="flex-1 min-w-0 pr-16">
                                                 <h4 class="font-semibold text-sm text-gray-700 group-hover:text-gray-900 transition-colors truncate" title="<?= htmlspecialchars($event['title']) ?>">
                                                     <?= htmlspecialchars($event['title']) ?>
                                                 </h4>
                                                 <div class="mt-1.5 space-y-1">
                                                     <p class="flex items-center gap-1.5 text-xs text-gray-500">
                                                         <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                         <?= !empty($event['event_time']) ? date('g:i A', strtotime($event['event_time'])) : 'All Day' ?>
                                                     </p>
                                                     <p class="flex items-center gap-1.5 text-xs text-gray-500">
                                                         <?php if ($event['is_online']): ?>
                                                             <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M12 6v.01M12 12v.01M12 18v.01"/></svg>
                                                             <span class="font-medium">Online Event</span>
                                                         <?php else: ?>
                                                             <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                                             <span class="truncate"><?= htmlspecialchars($event['location'] ?: 'Location TBC') ?></span>
                                                         <?php endif; ?>
                                                     </p>
                                                 </div>

                                                 <!-- === START FEEDBACK PROMPT === -->
                                                 <div class="mt-2 pt-2 border-t border-gray-100">
                                                     <?php if (!$event['has_feedback']): ?>
                                                         <a href="/pages/event.php?id=<?= $event['id'] ?>#feedbackSection"
                                                            class="inline-flex items-center gap-1.5 text-xs text-purple-600 hover:text-purple-800 hover:underline font-medium transition-colors group-hover:text-purple-700"
                                                            title="Share your feedback for this event on the event page">
                                                             <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                                                 <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L4 17.5V13.5A8 8 0 012 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd" />
                                                             </svg>
                                                             Leave Feedback
                                                         </a>
                                                     <?php else: ?>
                                                         <p class="flex items-center gap-1.5 text-xs text-green-600 font-medium">
                                                             <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                                                             Feedback Submitted
                                                         </p>
                                                     <?php endif; ?>
                                                 </div>
                                                 <!-- === END FEEDBACK PROMPT === -->

                                             </div>
                                             <div class="absolute top-2 right-2 flex items-center gap-1">
                                                 <!-- Category Chip -->
                                                 <span class="text-[10px] px-1.5 py-0.5 rounded font-medium" style="background-color: <?= $event['category_color'] ?>20; color: <?= $event['category_color'] ?>;">
                                                     <?= htmlspecialchars($event['category_name']) ?>
                                                 </span>
                                                 <!-- Completed Icon -->
                                                 <span class="inline-flex items-center justify-center p-0.5 rounded-full bg-green-100 text-green-600" title="Completed">
                                                     <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                                                 </span>
                                             </div>
                                         </div>
                                     </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                         <!-- Overall Empty State (Shows if BOTH upcoming/completed are empty AFTER filtering) -->
                         <?php if(empty($allRegisteredEvents)): ?>
                             <!-- Added class empty-state-message -->
                             <div x-show="activeTab === 'upcoming' || activeTab === 'completed'" class="empty-state-message text-center py-10 px-4 space-y-3">
                                 <div class="mx-auto w-12 h-12 bg-indigo-50 rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                                 </div>
                                 <p class="text-sm text-gray-700 font-medium">No confirmed events registered yet.</p>
                                 <p class="text-xs text-gray-500">Find events to join!</p>
                                 <a href="/pages/events.php" class="inline-block mt-2 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-xs font-medium transition-colors">
                                     Explore Events
                                 </a>
                             </div>
                         <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once '../includes/footer.php'; ?>

    <!-- Reminder Toggle Script -->
    <script>
        // Simple Toast Notification Function (can be replaced with a more robust library)
        function showToast(message, type = 'info') {
            // Reuse the calendar's toast if available, or create a basic one
            if (window.registeredCalendarScope && typeof window.registeredCalendarScope.showToast === 'function') {
                window.registeredCalendarScope.showToast(message, type);
                return;
            }

            // Fallback basic toast
            const existingToast = document.getElementById('simple-toast');
            if (existingToast) existingToast.remove();

            const toast = document.createElement('div');
            toast.id = 'simple-toast';
            const bgColor = type === 'success' ? 'bg-green-600' : type === 'error' ? 'bg-red-600' : 'bg-indigo-600';
            toast.className = `fixed bottom-5 right-5 p-3 rounded-lg shadow-md text-white text-sm ${bgColor} z-[10000] transition-opacity duration-300 ease-out opacity-0`;
            toast.textContent = message;
            document.body.appendChild(toast);

            // Fade in
            requestAnimationFrame(() => {
                toast.style.opacity = '1';
            });

            // Fade out and remove
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }, 3500);
        }


        async function toggleReminder(button) {
            const registrationId = button.dataset.registrationId;
            const currentState = button.dataset.currentState === 'true';
            const newState = !currentState;
            const knob = button.querySelector('.reminder-toggle-knob');

            if (!registrationId) {
                console.error('Registration ID missing from toggle button.');
                showToast('Could not update reminder: Missing ID.', 'error');
                return;
            }

            // --- Optimistic UI Update ---
            button.disabled = true; // Prevent rapid clicks
            button.setAttribute('aria-checked', newState.toString());
            button.dataset.currentState = newState.toString(); // Update state immediately
            if (newState) {
                button.classList.remove('bg-gray-300');
                button.classList.add('bg-indigo-600');
                if(knob) knob.classList.remove('translate-x-1');
                if(knob) knob.classList.add('translate-x-4');
            } else {
                button.classList.remove('bg-indigo-600');
                button.classList.add('bg-gray-300');
                if(knob) knob.classList.remove('translate-x-4');
                if(knob) knob.classList.add('translate-x-1');
            }
            // Add a subtle loading indicator (optional)
            button.classList.add('opacity-70', 'cursor-wait');

            try {
                const response = await fetch('/actions/toggle_event_reminder.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        registration_id: parseInt(registrationId, 10), // Ensure it's an integer
                        send_reminder: newState
                    })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    // --- Revert UI on Error ---
                    throw new Error(result.message || `HTTP Error ${response.status}`);
                }

                // Success! UI is already updated. Maybe show a success toast.
                showToast(result.message || 'Reminder preference updated.', 'success');
                console.log(`Reminder for Reg ID ${registrationId} set to ${newState}`);

            } catch (error) {
                console.error('Error toggling reminder:', error);
                showToast(`Error: ${error.message}`, 'error');

                // --- Revert UI on Error ---
                const originalState = !newState; // The state before the failed attempt
                button.setAttribute('aria-checked', originalState.toString());
                button.dataset.currentState = originalState.toString();
                if (originalState) {
                    button.classList.remove('bg-gray-300');
                    button.classList.add('bg-indigo-600');
                    if(knob) knob.classList.remove('translate-x-1');
                    if(knob) knob.classList.add('translate-x-4');
                } else {
                    button.classList.remove('bg-indigo-600');
                    button.classList.add('bg-gray-300');
                    if(knob) knob.classList.remove('translate-x-4');
                    if(knob) knob.classList.add('translate-x-1');
                }
            } finally {
                // --- Always re-enable the button and remove loading state ---
                button.disabled = false;
                button.classList.remove('opacity-70', 'cursor-wait');
            }
        }
    </script>

    <!-- Alpine.js Init (Ensure it runs after Alpine is loaded) -->
    <script>
        document.addEventListener('alpine:init', () => {
            // No specific Alpine data needed for this page beyond the tab state which is inline
            console.log("Alpine initialized for registered events page.");
        });
    </script>

</body>
</html>