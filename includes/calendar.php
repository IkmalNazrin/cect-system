<?php
require_once __DIR__ . '/../config/db.php';

// AJAX Handler
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        $month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT,   [
            'options' => ['min_range' => 1, 'max_range' => 12]
        ]);
        $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 2000, 'max_range' => 2100]
        ]);

        if (!$month || !$year) throw new Exception('Invalid date parameters');

        $startDate = (new DateTimeImmutable("$year-$month-01"))->format('Y-m-d');
        $endDate = (new DateTimeImmutable($startDate))->modify('last day of this month')->format('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT e.*, 
                COALESCE(c.name, 'Uncategorized') AS category_name,
                COALESCE(c.color, '#6366F1') AS category_color,
                l.city AS location,
                DATE_FORMAT(e.event_date, '%Y-%m-%d') AS formatted_date,
                DATE_FORMAT(e.event_time, '%H:%i') AS formatted_time,
                e.is_online = 1 AS is_online
            FROM events e
            LEFT JOIN categories c ON e.category_id = c.id
            LEFT JOIN locations l ON e.city_id = l.id
            WHERE e.event_date BETWEEN ? AND ?
            ORDER BY e.event_date, e.event_time
        ");
        $stmt->execute([$startDate, $endDate]);
        $events = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Convert to UTF-8 and sanitize data
            $row = array_map(function($value) {
                return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }, $row);
            $day = date('j', strtotime($row['formatted_date']));
            $events[$day][] = $row;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'month' => date('F Y', strtotime($startDate)),
            'events' => $events,
            'firstDay' => date('w', strtotime($startDate)),
            'totalDays' => date('t', strtotime($startDate)),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Calendar Component
function generateEventCalendar() {
    ?>
    <section class="py-12" id="calendarSection">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white rounded-2xl shadow-xl p-8 border border-gray-100/50 relative">
                <div id="calendarLoader" class="absolute inset-0 bg-white/90 backdrop-blur-sm flex items-center justify-center rounded-2xl z-10">
                    <div class="animate-pulse flex space-x-4">
                        <div class="h-12 w-12 bg-purple-100 rounded-full"></div>
                    </div>
                </div>
                <div id="calendarContainer"></div>
            </div>
        </div>

        <!-- Enhanced Event Modal -->
        <div id="eventModal" class="fixed inset-0 bg-black/30 hidden items-center justify-center p-4 z-50 backdrop-blur-sm transition-opacity">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 animate-modal-in transform transition-all shadow-2xl border border-gray-100/50">
                <div class="flex justify-between items-center pb-4 border-b border-gray-100">
                    <h3 id="modalTitle" class="text-xl font-bold text-gray-800"></h3>
                    <button onclick="closeModal()" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div id="modalContent" class="space-y-4 pt-4"></div>
            </div>
        </div>
    </section>

    <script>
    let currentMonth = new Date().getMonth() + 1; // JavaScript months are 0-based
    let currentYear = new Date().getFullYear();
    let calendarEvents = {};
    let modalHistory = [];

    function loadCalendar(month, year) {
        showLoader();
        fetch(`/includes/calendar.php?ajax=1&month=${month}&year=${year}`)
            .then(response => {
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                return response.json();
            })
            .then(data => {
                console.log('Calendar data received:', data); // Add debug log
                if (data.success) {
                    calendarEvents = data.events;
                    renderCalendar(data);
                }
            })
            .catch(error => {
                console.error('Calendar load error:', error);
                // Error handling code
            })
            .finally(hideLoader);
    }

    function renderCalendar({ month, totalDays, firstDay }) {
        document.getElementById('calendarContainer').innerHTML = `
            <div class="flex flex-wrap items-center justify-between mb-8 gap-4">
                ${renderHeader(month)}
                <div class="flex items-center gap-3">
                    <button onclick="goToToday()" 
                        class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition-colors flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Today
                    </button>
                </div>
            </div>
            <div class="grid grid-cols-7 gap-px bg-gray-50 overflow-hidden rounded-xl border border-gray-100 shadow-sm">
                ${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(day => `
                    <div class="bg-gray-50 p-3 text-center text-sm font-semibold text-gray-500 uppercase tracking-wide">${day}</div>`).join('')}
                ${renderDays(firstDay, totalDays)}
            </div>
        `;
    }

    function renderHeader(monthText) {
        return `
            <div class="flex items-center gap-4">
                <button onclick="navigateMonth(-1)" class="p-2 hover:bg-gray-100 rounded-lg transition-colors group">
                    <svg class="w-6 h-6 text-gray-500 group-hover:text-purple-600 transition-colors" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <h2 class="text-2xl font-bold text-gray-800">${monthText}</h2>
                <button onclick="navigateMonth(1)" class="p-2 hover:bg-gray-100 rounded-lg transition-colors group">
                    <svg class="w-6 h-6 text-gray-500 group-hover:text-purple-600 transition-colors" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>
        `;
    }

    function goToToday() {
        const today = new Date();
        currentMonth = today.getMonth() + 1;
        currentYear = today.getFullYear();
        loadCalendar(currentMonth, currentYear);
    }

    function renderDays(firstDay, totalDays) { // Remove 'today' parameter
        let html = '';
        let dayCount = 1;
        const totalCells = firstDay + totalDays;
        const weeks = Math.ceil(totalCells / 7);

        // Get client's ACTUAL current date
        const today = new Date();
        const clientYear = today.getFullYear();
        const clientMonth = today.getMonth(); // 0-based
        const clientDay = today.getDate();

        // Get currently viewed month/year (0-based month)
        const viewedMonth = currentMonth - 1; // Convert to 0-based
        const viewedYear = currentYear;

        for (let week = 0; week < weeks; week++) {
            for (let day = 0; day < 7; day++) {
                const cellNumber = week * 7 + day;

                if (cellNumber < firstDay) {
                    html += `
                        <div class="bg-gray-50 opacity-50 min-h-[80px] sm:min-h-[120px] lg:min-h-[140px] p-1 sm:p-2 lg:p-3 border-b border-r border-gray-100"></div>
                    `;
                }
                else if (dayCount <= totalDays) {
                    const currentDay = dayCount;
                    
                    // Check if this cell is today (client's local date)
                    const isToday = (
                        viewedYear === clientYear &&
                        viewedMonth === clientMonth &&
                        currentDay === clientDay
                    );

                    // Get events for this specific day from the pre-loaded data
                    const dayEvents = calendarEvents[currentDay] || [];

                    // --- Start: Generate HTML for a valid day cell ---
                    html += `
                        <div class="bg-white min-h-[80px] sm:min-h-[120px] lg:min-h-[140px] p-1 sm:p-2 lg:p-3 border-b border-r border-gray-100 relative hover:bg-gray-50 transition-colors duration-150
                            ${isToday ? '!bg-purple-50 ring-2 ring-purple-500 z-10 today-cell' : ''}">

                            ${isToday ? `
                                <span class="absolute top-1 right-1 text-[8px] bg-purple-600 text-white px-1.5 py-0.5 rounded font-medium shadow-sm">
                                    Today
                                </span>
                            ` : ''}

                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-medium ${isToday ? 'text-purple-700' : 'text-gray-600'}">
                                    ${currentDay}
                                </span>
                                ${dayEvents.length > 0 ? `
                                    <div class="flex gap-1.5 items-center">
                                        ${dayEvents.slice(0, 3).map(e => `
                                            <span class="w-2 h-2 rounded-full shadow-sm" style="background:${e.category_color}"></span>
                                        `).join('')}
                                        ${dayEvents.length > 3 ? `<span class="text-xs text-gray-400">+${dayEvents.length - 3}</span>` : ''}
                                    </div>
                                ` : ''}
                            </div>

                            <div class="space-y-2">
                                ${dayEvents.slice(0, 2).map(event => `
                                    <div class="text-xs p-2 rounded-lg cursor-pointer hover:shadow-sm transition-all group relative"
                                        data-event-id="${event.id}"
                                        style="background:${event.category_color}08; color:${event.category_color}"
                                        data-event='${JSON.stringify(event).replace(/'/g, "\\u0027").replace(/"/g, "&quot;")}'
                                        onclick="event.stopPropagation(); showEventModal(this)">
                                        <div class="flex items-center justify-between">
                                            <span class="font-medium">${event.formatted_time}</span>
                                            ${event.is_online == true ? `
                                                <span class="flex items-center gap-1 text-[0.7rem]">
                                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M5.26 7.24a.75.75 0 00-.44 1.03l3.25 6.5a.75.75 0 001.14.22l6.5-6.5a.75.75 0 00-1.06-1.06l-5.78 5.78-2.75-5.5a.75.75 0 00-1.03-.44z"/>
                                                    </svg>
                                                    Online
                                                </span>
                                            ` : ''}
                                        </div>
                                        <span class="ml-1.5">${escapeHtml(event.title)}</span>
                                    </div>
                                `).join('')}

                                ${dayEvents.length > 2 ? `
                                    <button class="text-xs text-purple-600 hover:text-purple-800 w-full text-left mt-1 px-2 py-1 rounded-lg hover:bg-purple-50 transition-colors"
                                        onclick="showMoreEvents(${currentDay})">
                                        + ${dayEvents.length - 2} more events
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    `;
                    // --- End: Generate HTML for a valid day cell ---

                    // Increment the day counter only after processing a valid day
                    dayCount++;
                }
                // Condition 3: Cells AFTER the last day of the month
                // If cellNumber >= firstDay AND dayCount > totalDays, we simply DO NOTHING.
            }
        }
        return html;
    }

    function showMoreEvents(day) {
        const events = calendarEvents[day] || [];
        const monthName = new Date(currentYear, currentMonth - 1).toLocaleString('default', { month: 'long' });
        
        modalHistory.push({
            title: document.getElementById('modalTitle').textContent,
            day: day
        });

        document.getElementById('modalTitle').textContent = `Events on ${day} ${monthName}`;
        document.getElementById('modalContent').innerHTML = `
            <div class="space-y-3">
                ${events.map(event => `
                    <div class="p-3 rounded-xl border border-gray-100 hover:border-purple-200 cursor-pointer transition-colors bg-white group relative"
                        data-event="${encodeURIComponent(JSON.stringify(event))}"
                        onclick="showEventModal(this)">
                        <div class="flex items-center gap-3">
                            <div class="relative">
                                <div class="w-3 h-3 rounded-full flex-shrink-0 shadow-sm" 
                                    style="background:${event.category_color}">
                                    ${event.is_online == true ? `
                                        <div class="absolute -right-0.5 -bottom-0.5 w-2 h-2 bg-purple-500 rounded-full border-1 border-white"></div>
                                    ` : ''}
                                </div>
                            </div>
                            <div class="flex-1">
                                <div class="text-sm font-medium text-gray-800">${escapeHtml(event.title)}</div>
                                <div class="text-xs text-gray-500 mt-0.5">${event.formatted_time}</div>
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
        document.getElementById('eventModal').classList.remove('hidden');
    }

    function showEventModal(element) {
        try {
            const rawData = decodeURIComponent(element.dataset.event);
            const event = JSON.parse(rawData);
            const numericPrice = parseFloat(event.price) || 0;
            const eventDay = new Date(event.formatted_date).getDate();
            const eventDate = new Date(event.formatted_date);
            const monthName = eventDate.toLocaleString('default', { month: 'long' });
            const formattedDate = eventDate.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            // --- NEW: Store current event data globally ---
            currentModalEvent = event;
            // --- End NEW ---

            modalHistory.push({
                title: `Events on ${eventDay} ${monthName}`,
                day: eventDay
            });

            document.getElementById('modalTitle').textContent = event.title || 'Untitled Event';
            document.getElementById('modalContent').innerHTML = `
                <div class="space-y-6">
                    <div class="flex items-center justify-between">
                        <button onclick="goBackToList()"
                                class="flex items-center gap-2 text-purple-600 hover:text-purple-800 transition-colors group">
                            <svg class="w-5 h-5 transform transition-transform group-hover:-translate-x-0.5"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                            </svg>
                            <span class="text-sm font-medium">Back to Events</span>
                        </button>
                        <span class="px-3 py-1 rounded-full text-sm font-medium
                            ${numericPrice > 0 ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'}">
                                        ${numericPrice > 0 ? `RM${numericPrice.toFixed(2)}` : 'FREE'}
                        </span>
                    </div>

                    <div class="space-y-4">
                        <!-- Category & Date Card -->
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-100">
                            <div class="flex flex-wrap gap-4 items-center">
                                <div class="flex items-center gap-2">
                                    <div class="w-3 h-3 rounded-full" style="background:${event.category_color || '#6366F1'}"></div>
                                    <span class="text-sm font-medium">${escapeHtml(event.category_name || 'Uncategorized')}</span>
                                </div>
                                <div class="h-4 w-px bg-gray-200"></div>
                                <div class="flex items-center gap-2 text-gray-600">
                                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">${formattedDate}</p>
                                        <p class="text-sm">${event.formatted_time || 'All day'}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Location Card -->
                        ${event.location || event.is_online ? `
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-100">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                <div class="flex-1">
                                    ${event.location ? `
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900 mb-1">Location</h4>
                                            <p class="text-gray-600 text-sm">${escapeHtml(event.location)}</p>
                                        </div>
                                    ` : ''}

                                    ${event.is_online == true ? `
                                    <div class="${event.location ? 'mt-3' : ''}">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M2.25 12a9 9 0 1118 0 9 9 0 01-18 0zm9-4a1 1 0 00-1 1v3a1 1 0 002 0V9a1 1 0 00-1-1zm0 6a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                                            </svg>
                                            Online Event
                                        </span>
                                        ${event.is_online && !event.location ? `
                                            <p class="text-gray-600 text-sm mt-1">This event will be held virtually</p>
                                        ` : ''}
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                        ` : ''}

                        <!-- Description Card -->
                        ${event.description ? `
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-100">
                            <h4 class="text-sm font-semibold text-gray-900 mb-2">About This Event</h4>
                            <div class="prose prose-sm text-gray-600 max-w-none">
                                ${escapeHtml(event.description).replace(/\n/g, '<br>')}
                            </div>
                        </div>
                        ` : ''}

                        <!-- Action Buttons -->
                        <div class="grid grid-cols-2 gap-3 pt-4">
                            <a href="/pages/event.php?id=${event.id}"
                                class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition-colors font-medium text-sm flex items-center justify-center gap-2"
                                target="_blank">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                View Full Event Page
                            </a>
                            <!-- --- MODIFIED: Added id and changed onclick --- -->
                            <button id="shareEventButton"
                                    onclick="shareCurrentEvent(this)"
                                    class="w-full px-4 py-2 border border-purple-600 text-purple-600 rounded-lg
                                           hover:bg-purple-50 transition-colors font-medium text-sm flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                </svg>
                                <span id="shareEventButtonText">Share Event</span>
                            </button>
                            <!-- --- End MODIFIED --- -->
                        </div>
                    </div>
                </div>
            `;


            document.getElementById('eventModal').classList.remove('hidden');

        } catch (error) {
            console.error('Error showing event modal:', error);
            currentModalEvent = null; // Clear event data on error
            // Keep existing error display logic
            document.getElementById('modalContent').innerHTML = `
                <div class="p-4 bg-red-50 rounded-lg flex items-center gap-3 text-red-700">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div>
                        <p class="font-medium">Error loading event details</p>
                        <p class="text-sm">${escapeHtml(error.message)}</p>
                    </div>
                </div>`;
            document.getElementById('eventModal').classList.remove('hidden');
        }
    }
    
    async function shareCurrentEvent(buttonElement) {
        if (!currentModalEvent) {
            console.error("No event data available to share.");
            return;
        }

        const event = currentModalEvent;
        const eventUrl = `${window.location.origin}/pages/event.php?id=${event.id}`;
        const eventDate = new Date(event.formatted_date);
        const formattedDateForShare = eventDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric' });

        const shareData = {
            title: event.title,
            text: `Check out this event: ${event.title} on ${formattedDateForShare} at ${event.formatted_time || 'All day'}.`,
            url: eventUrl,
        };

        const shareButtonTextEl = document.getElementById('shareEventButtonText');
        const originalButtonText = shareButtonTextEl.textContent; // Store original text

        if (navigator.share) {
            try {
                await navigator.share(shareData);
                console.log('Event shared successfully');
            } catch (err) {
                // Silently catch AbortError if user cancels share sheet
                if (err.name !== 'AbortError') {
                    console.error('Error sharing:', err);
                    // Fallback to copy if share API fails unexpectedly (optional)
                    // copyLinkToClipboard(eventUrl, buttonElement, shareButtonTextEl, originalButtonText);
                } else {
                    console.log('Share cancelled by user.');
                }
            }
        } else {
            // Fallback for browsers that don't support Web Share API
            copyLinkToClipboard(eventUrl, buttonElement, shareButtonTextEl, originalButtonText);
        }
    }

    async function copyLinkToClipboard(link, buttonElement, textElement, originalText) {
         if (!navigator.clipboard) {
             alert('Sharing is not supported on this browser, and clipboard access is unavailable. Please copy the link manually: ' + link);
             return;
         }
         try {
            await navigator.clipboard.writeText(link);
            console.log('Event link copied to clipboard');
            // Provide feedback
            textElement.textContent = 'Link Copied!';
            buttonElement.classList.add('!text-green-600', '!border-green-600', '!bg-green-50');

            // Revert feedback after a delay
            setTimeout(() => {
                textElement.textContent = originalText;
                buttonElement.classList.remove('!text-green-600', '!border-green-600', '!bg-green-50');
            }, 2500); // Revert after 2.5 seconds

        } catch (err) {
            console.error('Failed to copy link: ', err);
            alert('Failed to copy link. Please try again or copy manually.');
             textElement.textContent = 'Copy Failed';
             buttonElement.classList.add('!text-red-600', '!border-red-600', '!bg-red-50');
             setTimeout(() => {
                textElement.textContent = originalText;
                buttonElement.classList.remove('!text-red-600', '!border-red-600', '!bg-red-50');
            }, 2500);
        }
    }

    function isValidDate(dateString) {
        return !isNaN(Date.parse(dateString));
    }

    function goBackToList() {
        if (modalHistory.length > 0) {
            const prevState = modalHistory.pop();
            if (prevState.day) {
                // Regenerate the event list for the stored day
                showMoreEvents(prevState.day);
            } else {
                // Fallback to previous content
                document.getElementById('modalContent').innerHTML = prevState.content;
                document.getElementById('modalTitle').textContent = prevState.title;
            }
        }
    }

    // Helper functions
    function navigateMonth(offset) {
        currentMonth += offset;
        if (currentMonth < 1) { currentMonth = 12; currentYear--; }
        if (currentMonth > 12) { currentMonth = 1; currentYear++; }
        loadCalendar(currentMonth, currentYear);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function safeJson(data) {
        try {
            return JSON.stringify(data);
        } catch (error) {
            console.error('JSON stringify error:', error);
            return '{}';
        }
    }

    function showLoader() {
        document.getElementById('calendarLoader').classList.remove('hidden');
    }

    function hideLoader() {
        document.getElementById('calendarLoader').classList.add('hidden');
    }

    function closeModal() {
        document.getElementById('eventModal').classList.add('hidden');
        modalHistory = [];
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', () => loadCalendar(currentMonth, currentYear));
    document.getElementById('eventModal').addEventListener('click', e => e.target === e.currentTarget && closeModal());
    </script>

    <style>
    .animate-modal-in { 
        animation: modalIn 0.2s cubic-bezier(0.33, 1, 0.68, 1);
    }

    @keyframes modalIn {
        from { transform: translateY(20px) scale(0.98); opacity: 0; }
        to { transform: translateY(0) scale(1); opacity: 1; }
    }

    #calendarContainer .bg-purple-50 {
        background: rgba(99, 102, 241, 0.05);
    }

    @keyframes slide-up {
        0% { transform: translateY(100%); opacity: 0; }
        100% { transform: translateY(0); opacity: 1; }
    }

    .animate-slide-up {
        animation: slide-up 0.3s ease-out;
    }
    </style>
    <?php
}
?>