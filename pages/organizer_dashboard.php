<?php
session_start();
require_once '../config/db.php';
require_once '../lib/functions.php';

function get_participant_count($event_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = ?");
    $stmt->execute([$event_id]);
    return $stmt->fetchColumn();
}

function get_paid_participant_count($event_id) {
    global $pdo;
    try {
        // Count only registrations where payment_status is 'paid'
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND payment_status = 'paid'");
        $stmt->execute([$event_id]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        // Log error or handle appropriately
        error_log("Error fetching paid participant count for event $event_id: " . $e->getMessage());
        return 0; // Return 0 on error
    }
}

// Check organizer status
if (!isset($_SESSION['user_id']) || $_SESSION['organizer_status'] !== 'approved') {
    header('Location: /pages/login.php');
    exit();
}

// Fetch organizer's events
$organizer_id = $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare("
        SELECT
            e.*,
            c.name AS category,
            l.city, l.state, /* Added state for display in dropdown */
            (SELECT GROUP_CONCAT(tag_id) FROM event_tags WHERE event_id = e.id) AS tag_ids
            /* google_map_link is already selected by e.* */
        FROM events e
        LEFT JOIN categories c ON e.category_id = c.id
        LEFT JOIN locations l ON e.city_id = l.id /* city_id will be null for online */
        WHERE e.created_by = ?
        ORDER BY e.event_date DESC
    ");
    $stmt->execute([$organizer_id]);
    $events = $stmt->fetchAll(); // Initial fetch for count display
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}


// Fetch categories and locations for dropdowns
$categories = $pdo->query("SELECT * FROM categories")->fetchAll();
$locations = $pdo->query("SELECT * FROM locations ORDER BY state, city")->fetchAll();
$tags = $pdo->query("SELECT * FROM tags")->fetchAll();

// Fetch filter parameters
$status_filter = $_GET['status'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build the base query
$query = "
    SELECT e.*, c.name AS category, l.city, l.state,
        (SELECT GROUP_CONCAT(tag_id) FROM event_tags WHERE event_id = e.id) AS tag_ids
    FROM events e
    LEFT JOIN categories c ON e.category_id = c.id
    LEFT JOIN locations l ON e.city_id = l.id
    WHERE e.created_by = ?
";

$params = [$organizer_id];
//$types = 'i'; // Not needed for PDO fetchAll with positional placeholders

// Add filters
if ($search_query) {
    $query .= " AND e.title LIKE ?";
    $params[] = "%$search_query%";
    //$types .= 's';
}

if ($category_filter !== 'all') {
    $query .= " AND e.category_id = ?";
    $params[] = $category_filter;
    //$types .= 'i';
}

// Status filter
$current_date = date('Y-m-d');
if ($status_filter === 'upcoming') {
    $query .= " AND e.event_date >= ?";
    $params[] = $current_date;
    //$types .= 's';
} elseif ($status_filter === 'past') {
    $query .= " AND e.event_date < ?";
    $params[] = $current_date;
    //$types .= 's';
}

$query .= " ORDER BY e.event_date DESC";

// Generate CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token']; // Assign to variable for easier use in JS/HTML

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $filtered_events = $stmt->fetchAll(); // Fetch filtered events
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>">
    <title>Organizer Dashboard - CECT</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        .bg-select-arrow {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.75rem center;
            background-size: 1.25em 1.25em;
            background-repeat: no-repeat;
        }

        .event-card:hover .event-actions {
            opacity: 1;
            transform: translateY(0);
        }

        /* Remove default select arrow */
        select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }

        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        .tooltip-text {
            pointer-events: none;
            white-space: nowrap;
        }

        /* Custom scrollbar styling */
        .modal-scroll::-webkit-scrollbar {
            width: 8px;
            background-color: #f1f5f9;
            border-radius: 0 12px 12px 0;
        }

        .modal-scroll::-webkit-scrollbar-thumb {
            background-color: #cbd5e1;
            border-radius: 8px;
            border: 2px solid #f1f5f9;
        }

        .modal-scroll::-webkit-scrollbar-thumb:hover {
            background-color: #94a3b8;
        }

        /* Ensure modal container maintains rounded corners */
        .modal-container {
            scrollbar-gutter: stable;
        }

        .required-asterisk {
            content: '*';
            @apply text-red-500 ml-1;
        }

        [required] + label::after {
            @apply required-asterisk;
        }

        .animate-progress {
            animation: progressBar linear forwards;
        }

        @keyframes progressBar {
            0% { transform: scaleX(1); }
            100% { transform: scaleX(0); }
        }

        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50"
x-data="{
    openModal: false,
    editMode: false,
    currentEvent: null,
    imagePreview: null,
    isFree: true,
    processing: false, // General processing state
    formError: false,
    errorMessage: '',
    activeTab: 'details',
    successMessage: null,
    showDeleteConfirm: false, // <<< NEW: For delete confirmation modal
    deleting: false, // <<< NEW: Specific state for delete action
    eventToDeleteId: null, // <<< NEW: Store ID of event to delete
    eventToDeleteTitle: '', // <<< NEW: Store Title of event to delete
    // *** NEW FEEDBACK MODAL STATE ***
    showFeedbackModal: false,
    feedbackData: [],
    feedbackLoading: false,
    feedbackError: null, // Store error message string
    currentFeedbackEventTitle: '',
    currentFeedbackEventId: null,
    // *** END NEW FEEDBACK MODAL STATE ***

    get isCurrentEventExpired() {
        if (!this.editMode || !this.currentEvent || !this.currentEvent.event_date) {
            return false; // Only applicable when editing an event with a date
        }
        try {
            const today = new Date();
            today.setHours(0, 0, 0, 0); // Set time to midnight for accurate date comparison
            // Ensure the event date is parsed correctly, adding time avoids timezone issues potentially shifting the date
            const eventDate = new Date(this.currentEvent.event_date + 'T00:00:00');
            eventDate.setHours(0, 0, 0, 0); // Also normalize event date just in case
            return eventDate < today;
        } catch (e) {
            return false; // Treat errors as 'not expired'
        }
    },

    // Preview uploaded image
    previewImage(event) {
        const file = event.target.files[0] || event.dataTransfer.files[0];
        if (file) {
            this.imagePreview = URL.createObjectURL(file);
        }
    },

    // submitForm method (Create/Update)
    async submitForm() {
        if (this.editMode && this.isCurrentEventExpired) {
             console.warn('Attempted to save an expired event. Action blocked.');
             // Use the existing error display mechanism
             this.errorMessage = 'Expired events cannot be updated.';
             this.formError = true;
             // Scroll error into view
             this.$nextTick(() => {
                 const errorAlert = document.getElementById('modal-error-alert');
                 if (errorAlert && window.getComputedStyle(errorAlert).display !== 'none') {
                     errorAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                 }
             });
             return; // Stop submission
         }
         // <<< END ADDED CHECK >>>

        console.log('AJAX Form submission started');
        this.processing = true;
        this.formError = false; // Reset before check
        this.errorMessage = ''; // Reset before check

        const form = this.$refs.eventForm;

        if (!form) {
            console.error('Event form ref not found!');
            // Set error state before returning
            this.errorMessage = 'Internal error: Form reference missing. Please refresh the page.';
            this.formError = true;
            this.processing = false;
            return;
        }

        // Explicitly check HTML5 validation across the entire form
        if (!form.checkValidity()) {
            console.warn('Form validation failed. Check all tabs.');

            // --- Ensure error state is set FIRST ---
            this.errorMessage = 'Validation failed. Please review all fields in both the Details and Settings tabs. Required fields marked with * must be completed.';
            this.formError = true;
            this.processing = false; // Stop processing indicator

            // --- Use nextTick to wait for DOM update based on formError=true ---
            this.$nextTick(() => {
                const errorAlert = document.getElementById('modal-error-alert');
                if (errorAlert) {
                    // Make sure it's actually visible before scrolling
                    // Note: Check 'display' style, as x-show uses display:none
                    if (window.getComputedStyle(errorAlert).display !== 'none') {
                         console.log('Scrolling validation error alert into view.');
                         errorAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } else {
                        console.warn('Error alert (#modal-error-alert) is not visible, cannot scroll.');
                        // Idea for future enhancement: Find first invalid input and switch tab
                        // const firstInvalid = form.querySelector(':invalid');
                        // if (firstInvalid) { ... logic to determine tab ... this.activeTab = '...'; }
                    }
                } else {
                     console.error('Error alert element (#modal-error-alert) not found.');
                }
            });

            // We are disabling browser's default validation bubbles with novalidate
            // form.reportValidity(); // Usually only shows errors on the current tab anyway

            return; // Stop the submission
        }


        // --- If validation passes, proceed with fetch ---
        console.log('Form validation passed. Proceeding with fetch.');
        const formData = new FormData(form);
        // Action is set via hidden input in the form based on editMode

        try {
            const response = await fetch('/actions/handle_event.php', {
                method: 'POST',
                body: formData,
                headers: {
                   'Accept': 'application/json' // Expect JSON response
                }
            });

             // --- Refined Error Handling for Fetch ---
             if (!response.ok) {
                 let errorData = { message: `Server error: ${response.status} ${response.statusText}` }; // Default error
                 try {
                     // Try to parse JSON error from backend
                     const potentialJson = await response.json();
                     if (potentialJson && potentialJson.message) {
                         errorData = potentialJson;
                     }
                 } catch (e) {
                    // Ignore if response is not JSON
                 }
                 throw new Error(errorData.message); // Throw unified error
             }

            const result = await response.json();
            console.log('Server response:', result);

            if (result.status === 'success') {
                this.openModal = false;
                sessionStorage.setItem('eventSuccessMessage', result.message);
                window.location.reload();
            } else {
                // Handle application-level errors from backend (e.g., PHP validation)
                this.errorMessage = result.message || (result.errors ? result.errors.join('; ') : 'An unknown error occurred.');
                this.formError = true;
                 this.$nextTick(() => { // Scroll error into view on backend errors too
                    const errorAlert = document.getElementById('modal-error-alert');
                    if (errorAlert && window.getComputedStyle(errorAlert).display !== 'none') {
                        errorAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                 });
            }
        } catch (error) { // Catches fetch network errors AND errors thrown from !response.ok
            console.error('Form submission error:', error);
            this.errorMessage = error.message || 'An error occurred during submission. Please check your network connection.';
            this.formError = true;
             this.$nextTick(() => { // Scroll error into view on catch block errors
                 const errorAlert = document.getElementById('modal-error-alert');
                 if (errorAlert && window.getComputedStyle(errorAlert).display !== 'none') {
                     errorAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                 }
             });
        } finally {
            this.processing = false; // Ensure loading indicator stops
        }
    },

    // <<< NEW: deleteEvent method
    async deleteEvent() {
        if (!this.eventToDeleteId) return;
        console.log('AJAX Delete request started for event ID:', this.eventToDeleteId);
        this.deleting = true;
        this.formError = false; // Clear potential previous errors
        this.errorMessage = '';

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('event_id', this.eventToDeleteId);
        formData.append('csrf_token', '<?= $csrf_token ?>'); // Use PHP variable

        try {
            const response = await fetch('/actions/handle_event.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                let errorData;
                try {
                    errorData = await response.json();
                } catch (e) {
                    errorData = { message: `Server error: ${response.status} ${response.statusText}` };
                }
                throw new Error(errorData.message || 'An unknown server error occurred during deletion.');
            }

            const result = await response.json();
            console.log('Delete response:', result);

            if (result.status === 'success') {
                this.showDeleteConfirm = false; // Close confirmation modal
                sessionStorage.setItem('eventSuccessMessage', result.message); // Use session storage for message
                window.location.reload(); // Reload page to reflect deletion
            } else {
                // Display error within the delete confirmation modal or a general page alert
                this.errorMessage = result.message || 'Failed to delete the event.';
                this.formError = true; // Reuse formError for delete modal error display
                // Keep the delete confirmation modal open to show the error
            }
        } catch (error) {
            console.error('Delete request error:', error);
            this.errorMessage = error.message || 'An error occurred while trying to delete the event.';
            this.formError = true; // Show error in modal
        } finally {
            this.deleting = false;
            // Don't reset eventToDeleteId here, keep it in case of error retry needed or inspection
        }
    },

    // *** NEW viewFeedback method ***
    async viewFeedback(eventId, eventTitle) {
        console.log(`Fetching feedback for event ID: ${eventId}, Title: ${eventTitle}`);
        this.currentFeedbackEventTitle = eventTitle;
        this.showFeedbackModal = true; // Open modal immediately
        this.feedbackLoading = true;
        this.feedbackError = null;
        this.feedbackData = []; // Clear previous data
        this.currentFeedbackEventId = eventId; 

        try {
            // Construct URL safely
            const url = `/actions/get_feedback.php?event_id=${encodeURIComponent(eventId)}`;
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                }
            });

            if (!response.ok) {
                let errorData = { message: `Server error: ${response.status}` };
                try {
                    errorData = await response.json();
                } catch (e) { /* ignore if not json */ }
                throw new Error(errorData.message || 'Failed to load feedback data.');
            }

            const result = await response.json();

            if (result.success && result.feedback) {
                this.feedbackData = result.feedback;
            } else {
                throw new Error(result.message || 'Invalid response format from server.');
            }

        } catch (error) {
            console.error('Error fetching feedback:', error);
            this.feedbackError = error.message || 'Could not load feedback. Please try again.';
        } finally {
            this.feedbackLoading = false;
        }
    },
    // *** END NEW viewFeedback method ***

    // MODIFIED displaySuccessMessage function
    displaySuccessMessage() {
        const msg = sessionStorage.getItem('eventSuccessMessage');
        if (msg) {
            this.successMessage = msg; // SET THE PROPERTY
            sessionStorage.removeItem('eventSuccessMessage'); // Clear message after reading
             // Optional: Auto-hide after a delay - handled by x-init in the alert div now
        }
    },

    resetForm() {
        this.currentEvent = {
            id: '',
            title: '',
            event_date: '',
            event_time: '',
            city_id: '<?= $locations[0]['id'] ?? '' ?>', // Default to first location if exists
            category_id: '<?= $categories[0]['id'] ?? '' ?>', // Default to first category if exists
            venue: '',
            price: '0',
            audience: '',
            description: '',
            tags: [],
            is_online: '0',
            google_map_link: '',
            attention: '',
            terms_and_conditions: '',
            participant_limit: null
        };
        this.imagePreview = null;
        this.isFree = true;
        this.activeTab = 'details'; // Also reset active tab
        this.formError = false; // Reset form error state
        this.errorMessage = ''; // Reset error message
        this.processing = false; // Reset processing state
    },

    // MODIFIED init function for modal opening
    initModal(isEditMode = false, eventData = null) {
        this.openModal = true;
        this.editMode = isEditMode;
        this.formError = false; // Reset errors when opening
        this.errorMessage = '';

        if (isEditMode && eventData) {
            // Deep copy to avoid modifying original data directly if needed elsewhere
            this.currentEvent = JSON.parse(JSON.stringify(eventData));

            // --- START FIX for event_time format ---
            if (this.currentEvent.event_time && typeof this.currentEvent.event_time === 'string' && this.currentEvent.event_time.length > 5) {
                 // Assuming database format might be HH:MM:SS, extract HH:MM
                 this.currentEvent.event_time = this.currentEvent.event_time.substring(0, 5);
            }
            // --- END FIX for event_time format ---

            // Handle price initialization
            const priceValue = parseFloat(this.currentEvent.price);
            this.isFree = !isNaN(priceValue) && priceValue === 0;
             // Ensure price is a string for the input model
            this.currentEvent.price = this.currentEvent.price !== null ? String(this.currentEvent.price) : '0';


            // Convert tags string/array
            if (this.currentEvent && typeof this.currentEvent.tag_ids === 'string' && this.currentEvent.tag_ids) {
                 this.currentEvent.tags = this.currentEvent.tag_ids.split(',').map(t => t.trim()).filter(t => t);
             } else if (!this.currentEvent || !Array.isArray(this.currentEvent.tags)) {
                 if (this.currentEvent) this.currentEvent.tags = [];
            }

             // Ensure numeric fields are numbers if needed downstream, but models often work better with strings initially
             this.currentEvent.category_id = this.currentEvent.category_id ? Number(this.currentEvent.category_id) : null;
             this.currentEvent.city_id = this.currentEvent.city_id ? Number(this.currentEvent.city_id) : null;
             this.currentEvent.is_online = String(this.currentEvent.is_online ?? '0'); // Ensure it's '0' or '1' string
             this.currentEvent.participant_limit = this.currentEvent.participant_limit !== null ? Number(this.currentEvent.participant_limit) : null;


            // Set image preview
            if (this.currentEvent.image_url && this.currentEvent.image_url !== 'default-event.jpg') {
                this.imagePreview = `/assets/images/${this.currentEvent.image_url}`;
            } else {
                this.imagePreview = null;
            }
        } else {
            // It's 'New Event' mode, ensure form is reset
            this.resetForm();
            // Alpine's x-model handles defaults from resetForm
            this.imagePreview = null; // Ensure preview is null
        }
        this.activeTab = 'details'; // Start on details tab
    },

    // <<< NEW: Prepare for delete confirmation
    confirmDelete(eventId, eventTitle) {
        this.eventToDeleteId = eventId;
        this.eventToDeleteTitle = eventTitle;
        this.formError = false; // Clear any previous errors
        this.errorMessage = '';
        this.showDeleteConfirm = true; // Open the confirmation modal
    },

    // Call displaySuccessMessage on initial page load
    initPage() {
        this.displaySuccessMessage();
    }
}"
x-init="initPage()">
    <?php include '../includes/nav.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex flex-col gap-6 mb-8">
            <!-- Header Section -->
            <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4">
                <h1 class="text-3xl font-bold text-gray-900">Event Management</h1>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-500">
                        <?= count($filtered_events) ?> event<?= count($filtered_events) !== 1 ? 's' : '' ?> found
                    </span>
                    <div class="hidden md:block w-px h-6 bg-gray-200"></div>
                    <button @click="initModal(false)"
                            class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2.5 rounded-lg flex items-center gap-2 transition-all transform hover:scale-[1.02] shadow-md hover:shadow-lg">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        <span class="hidden sm:inline">New Event</span>
                    </button>
                </div>
            </div>

             <!-- Filters Container -->
            <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100">
                <form method="GET" action="?" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <!-- Search Input -->
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400 group-focus-within:text-purple-600 transition-colors"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <input type="text" name="search" placeholder="Search events..."
                            value="<?= htmlspecialchars($search_query) ?>"
                            class="w-full pl-10 pr-4 py-2.5 rounded-lg border border-gray-200 focus:border-purple-500
                            focus:ring-2 focus:ring-purple-100 transition-all placeholder-gray-400 text-sm">
                    </div>

                    <!-- Status Filter -->
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400 group-focus-within:text-purple-600 transition-colors"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <select name="status" onchange="this.form.submit()"
                                class="w-full pl-10 pr-8 py-2.5 rounded-lg border border-gray-200 bg-select-arrow
                                focus:border-purple-500 focus:ring-2 focus:ring-purple-100 transition-all text-sm
                                appearance-none cursor-pointer">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="upcoming" <?= $status_filter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                            <option value="past" <?= $status_filter === 'past' ? 'selected' : '' ?>>Past Events</option>
                        </select>
                    </div>

                    <!-- Category Filter -->
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400 group-focus-within:text-purple-600 transition-colors"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <select name="category" onchange="this.form.submit()"
                                class="w-full pl-10 pr-8 py-2.5 rounded-lg border border-gray-200 bg-select-arrow
                                focus:border-purple-500 focus:ring-2 focus:ring-purple-100 transition-all text-sm
                                appearance-none cursor-pointer">
                            <option value="all">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $category_filter == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Filter Actions -->
                    <div class="flex items-center gap-2 md:justify-end">
                        <button type="submit"
                                class="px-4 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg
                                transition-colors flex items-center gap-2 text-sm w-full md:w-auto justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                            </svg>
                            Apply Filters
                        </button>
                        <?php if ($status_filter !== 'all' || $category_filter !== 'all' || $search_query): ?>
                        <a href="?"
                        class="px-4 py-2.5 text-gray-600 hover:text-purple-600 rounded-lg border border-gray-200
                        hover:border-purple-300 transition-colors flex items-center gap-2 text-sm">
                            Clear All
                        </a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Active Filters -->
                <?php if ($status_filter !== 'all' || $category_filter !== 'all' || $search_query): ?>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php if ($search_query): ?>
                    <span class="px-3 py-1.5 bg-purple-50 text-purple-700 text-sm rounded-full flex items-center gap-2">
                        Search: "<?= htmlspecialchars($search_query) ?>"
                        <a href="?<?= http_build_query(array_merge($_GET, ['search' => ''])) ?>"
                        class="text-purple-500 hover:text-purple-700">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </a>
                    </span>
                    <?php endif; ?>

                    <?php if ($status_filter !== 'all'): ?>
                    <span class="px-3 py-1.5 bg-purple-50 text-purple-700 text-sm rounded-full flex items-center gap-2">
                        Status: <?= ucfirst($status_filter) ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'all'])) ?>"
                        class="text-purple-500 hover:text-purple-700">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </a>
                    </span>
                    <?php endif; ?>

                    <?php if ($category_filter !== 'all'):
                        // Find the category name for display
                        $category_name_display = 'Unknown';
                        foreach ($categories as $cat) {
                            if ($cat['id'] == $category_filter) {
                                $category_name_display = htmlspecialchars($cat['name']);
                                break;
                            }
                        }
                    ?>
                    <span class="px-3 py-1.5 bg-purple-50 text-purple-700 text-sm rounded-full flex items-center gap-2">
                        Category: <?= $category_name_display ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['category' => 'all'])) ?>"
                        class="text-purple-500 hover:text-purple-700">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </a>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Page Success Alert -->
        <div x-show="successMessage" x-cloak
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 transform -translate-y-2"
             x-transition:enter-end="opacity-100 transform translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="relative mb-4 p-4 rounded-xl bg-green-50 border border-green-200 shadow-lg"
             role="alert"
             x-init="setTimeout(() => { successMessage = null }, 5000)">
            <div class="flex items-start gap-3">
                <div class="shrink-0">
                    <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-medium text-green-800" x-text="successMessage"></p>
                </div>
                <button @click="successMessage = null" class="text-green-600 hover:text-green-800 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div x-show="successMessage" class="absolute bottom-0 left-0 h-1 w-full bg-green-200 origin-left animate-progress" style="animation-duration: 5s"></div>
        </div>
        <!-- End Page Success Alert -->

        <!-- Events Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($filtered_events)): ?>
                <div class="col-span-1 md:col-span-2 lg:col-span-3 text-center py-10">
                    <svg class="w-16 h-16 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="mt-4 text-lg font-medium text-gray-700">No events found.</p>
                    <p class="text-sm text-gray-500">Try adjusting your filters or create a new event.</p>
                     <?php if ($status_filter !== 'all' || $category_filter !== 'all' || $search_query): ?>
                        <a href="?"
                        class="mt-4 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500">
                            Clear Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($filtered_events as $event):
                    // Prepare event data for Alpine.js, ensuring tags are an array
                    $event_js_data = $event;
                    // Split tag_ids into an array for JS/Alpine model
                    $event_js_data['tags'] = !empty($event['tag_ids']) ? explode(',', $event['tag_ids']) : [];
                    // Ensure numeric IDs are treated as numbers if  needed by JS, but strings often safer for x-model
                    $event_js_data['category_id'] = $event['category_id'] ? (int) $event['category_id'] : null;
                    $event_js_data['city_id'] = $event['city_id'] ? (int) $event['city_id'] : null;
                    // Pass the whole prepared object
                    $event_json = htmlspecialchars(json_encode($event_js_data), ENT_QUOTES, 'UTF-8');
                    $is_past_event = strtotime($event['event_date']) < strtotime(date('Y-m-d'));

                    $profit = 0;
                    $paid_participants = 0;
                    $priceValue = floatval($event['price']); // Reuse existing price value

                    if ($priceValue > 0) {
                        $paid_participants = get_paid_participant_count($event['id']);
                        $profit = $priceValue * $paid_participants;
                    }
                ?>
                <div class="bg-white rounded-xl shadow-sm hover:shadow-lg transition-all duration-300 relative overflow-hidden event-card group <?= $is_past_event ? 'opacity-70 hover:opacity-90' : '' ?>">
                    <!-- Image Container -->
                    <div class="relative h-48">
                        <img src="/assets/images/<?= htmlspecialchars($event['image_url'] ?? 'default-event.jpg') ?>"
                            alt="<?= htmlspecialchars($event['title']) ?>"
                            class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105 <?= $is_past_event ? 'filter grayscale group-hover:grayscale-0' : '' ?>">
                        <!-- Gradient Overlay -->
                        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/40 to-transparent"></div>

                        <!-- Online & Price Badges -->
                        <div class="absolute top-2 left-2 flex flex-wrap gap-2">
                            <?php if ($is_past_event): ?>
                            <span class="px-3 py-1 bg-gray-500 text-white text-xs font-medium rounded-full flex items-center shadow-sm order-first"> <?php // Added order-first ?>
                                <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/> <!-- Clock Icon -->
                                </svg>
                                Past
                            </span>
                            <?php endif; ?>
                            <?php if ($event['is_online']): ?>
                            <span class="px-3 py-1 bg-blue-600 text-white text-xs rounded-full flex items-center shadow-sm">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 18.364a9 9 0 010-12.728M18.364 5.636a9 9 0 010 12.728m-9.9-1.414a5 5 0 010-7.072m7.072 0a5 5 0 010 7.072M12 12h.01"/> <!-- Wifi Icon -->
                                </svg>
                                Online
                            </span>
                            <?php endif; ?>
                            <?php $priceValue = floatval($event['price']); ?>
                             <span class="px-3 py-1 <?= $priceValue > 0 ? 'bg-purple-600' : 'bg-green-600' ?> text-white text-xs rounded-full shadow-sm">
                                <?= $priceValue > 0 ? 'RM'.number_format($priceValue, 2) : 'Free' ?>
                            </span>
                        </div>
                    </div>

                    <!-- Content Container -->
                    <div class="p-4 space-y-2">
                        <!-- Title & Category -->
                        <h3 class="text-xl font-bold text-gray-900 truncate" title="<?= htmlspecialchars($event['title']) ?>">
                            <?= htmlspecialchars($event['title']) ?>
                        </h3>
                        <p class="text-sm text-purple-600 font-medium"><?= htmlspecialchars($event['category'] ?? 'Uncategorized') ?></p>

                        <!-- Date & Time -->
                        <div class="flex items-center text-sm text-gray-600">
                            <svg class="w-4 h-4 mr-2 text-purple-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <span><?= date('M d, Y', strtotime($event['event_date'])) ?> • <?= date('g:i A', strtotime($event['event_time'])) ?></span>
                        </div>

                        <!-- Location -->
                        <div class="flex items-center text-sm text-gray-600">
                            <svg class="w-4 h-4 mr-2 text-purple-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                             <span class="truncate" title="<?= $event['is_online'] ? 'Online Event' : htmlspecialchars(($event['venue'] ?? 'N/A') . ', ' . ($event['city'] ?? 'N/A')) ?>">
                                <?= $event['is_online'] ? 'Online Event' : htmlspecialchars(($event['city'] ?? 'N/A') . ', ' . ($event['state'] ?? 'N/A')) ?>
                            </span>
                        </div>

                        <!-- Profit Display -->
                        <?php if ($priceValue > 0): // Only show for paid events ?>
                        <div class="flex items-center text-sm text-gray-600 pt-3 border-t border-gray-100 mt-3" title="Total revenue from paid registrations (<?= $paid_participants ?> x RM<?= number_format($priceValue, 2) ?>)">
                            <svg class="w-4 h-4 mr-2 text-green-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path> <!-- Simple plus/money icon idea -->
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0c-1.657 0-3-.895-3-2s1.343-2 3-2 3-.895 3-2-1.343-2-3-2m-1.401-3.001a2.002 2.002 0 00-2.599-1M13.401 4.001a2.002 2.002 0 012.599-1M12 18c-1.11 0-2.08-.402-2.599-1M12 18v1m0-1v-8m-1.401 9.001a2.002 2.002 0 01-2.599 1M10.599 20.001a2.002 2.002 0 002.599 1" /> <!-- More detailed money icon -->
                            </svg>
                            <span>Est. Revenue: <span class="font-semibold text-green-700">RM <?= number_format($profit, 2) ?></span></span>
                            <span class="text-xs text-gray-500 ml-1">(<?= $paid_participants ?> paid)</span>
                        </div>
                        <?php endif; ?>

                        <!-- Action Buttons -->
                        <div class="flex justify-end gap-2 pt-3 border-t border-gray-100 mt-3">
                            <button
                                @click="initModal(true, <?= $event_json ?>)"
                                class="bg-purple-100 text-purple-600 px-3 py-2 rounded-lg hover:bg-purple-200 transition-colors flex items-center gap-1 text-xs"
                                aria-label="Edit event">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                </svg>
                                <span>Edit</span>
                            </button>
                            <a href="/pages/participants.php?event_id=<?= $event['id'] ?>"
                                class="bg-green-100 text-green-600 px-3 py-2 rounded-lg hover:bg-green-200 transition-colors flex items-center gap-1 relative text-xs"
                                aria-label="View participants">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                                <span>Participants</span>
                                <span class="absolute -top-2 -right-2 bg-purple-600 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center shadow-sm">
                                    <?= get_participant_count($event['id']) ?>
                                </span>
                            </a>

                            <button
                                @click="viewFeedback(<?= $event['id'] ?>, '<?= htmlspecialchars(addslashes($event['title']), ENT_QUOTES) ?>')"
                                class="bg-blue-100 text-blue-600 px-3 py-2 rounded-lg hover:bg-blue-200 transition-colors flex items-center gap-1 text-xs"
                                aria-label="View feedback">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                                </svg>
                                <span>Feedback</span>
                            </button>

                             <!-- <<< NEW Delete Button >>> -->
                             <button
                                @click="confirmDelete(<?= $event['id'] ?>, '<?= htmlspecialchars(addslashes($event['title']), ENT_QUOTES) ?>')"
                                class="bg-red-100 text-red-600 px-3 py-2 rounded-lg hover:bg-red-200 transition-colors flex items-center gap-1 text-xs"
                                aria-label="Delete event">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                <span>Delete</span>
                             </button>
                             <!-- <<< End Delete Button >>> -->
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Event Form Modal (Create/Edit) -->
        <div x-show="openModal" x-cloak class="fixed inset-0 bg-black/30 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-all">
            <div class="bg-white rounded-2xl w-full max-w-4xl flex flex-col modal-container"
                style="height: 90vh;"
                @click.away="openModal = false"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-4">

                <!-- Header Section -->
                <div class="flex flex-col">
                    <div class="flex justify-between items-start p-8 pb-4">
                        <div class="space-y-2">
                            <h2 class="text-3xl font-bold text-gray-900 bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent">
                                <span x-text="editMode ? 'Edit Event' : 'New Event'"></span>
                            </h2>
                            <p class="text-sm text-gray-500">
                                <span x-text="editMode ? 'Update your event details' : 'Fill in the form to create a new event'"></span>
                            </p>
                        </div>
                        <button @click="openModal = false"
                                class="text-gray-400 hover:text-gray-600 transition-colors p-2 rounded-full hover:bg-gray-100">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Navigation tabs -->
                    <div class="px-8">
                        <nav class="flex space-x-4 border-b border-gray-200">
                            <button type="button"
                                @click="activeTab = 'details'"
                                :class="activeTab === 'details' ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                class="py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-150">
                                Event Details
                            </button>
                            <button type="button"
                                @click="activeTab = 'settings'"
                                :class="activeTab === 'settings' ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                class="py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-150">
                                Event Settings
                            </button>
                        </nav>
                    </div>
                </div>

                <!-- Scrollable Content -->
                <div class="modal-scroll overflow-y-auto flex-1 px-8">

                <!-- Form Validation Alert -->
                <div x-show="formError" x-cloak class="my-4 sticky top-0 z-10" id="modal-error-alert"> <!--<<< ID IS CRUCIAL -->
                    <div class="flex items-start gap-3 p-4 bg-red-50 border-l-4 border-red-400 rounded-r-lg shadow">
                        <svg class="w-5 h-5 text-red-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-red-800" x-text="errorMessage"></p>
                        </div>
                        <button @click="formError = false; errorMessage = '';" class="text-red-500 hover:text-red-700 transition-colors" aria-label="Dismiss error">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- <<< EXPIRY MESSAGE >>> -->
                <div x-show="editMode && isCurrentEventExpired" x-cloak
                         class="my-4">
                        <div class="p-4 bg-yellow-50 border-l-4 border-yellow-400 rounded-r-lg text-sm text-yellow-800" role="alert">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 text-yellow-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                </svg>
                                <div>
                                    <p><strong class="font-medium">Event Expired:</strong> This event's date has passed.</p>
                                    <p class="mt-1">Updates are disabled for expired events. You can still view the details or choose to delete the event.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- End Expiry Message -->

                <form method="POST"
                    action="/actions/handle_event.php"
                    enctype="multipart/form-data"
                    class="flex flex-col h-full"
                    x-ref="eventForm"
                    @submit.prevent="submitForm"
                    novalidate>

                        <div class="flex-1 space-y-8 py-6"> <!-- Added py-6 -->
                            <input type="hidden" name="action" x-bind:value="editMode ? 'update' : 'create'">
                            <input type="hidden" name="event_id" x-bind:value="currentEvent ? currentEvent.id : ''">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                            <!-- Tab Content Container -->
                            <div x-show="currentEvent"> <!-- Ensure currentEvent is loaded before showing form -->
                                <!-- Details Tab -->
                                <div x-show="activeTab === 'details'" class="space-y-6">
                                    <!-- Event Title -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                            Event Title
                                            <span class="text-red-500 ml-1">*</span>
                                        </label>
                                        <div class="relative">
                                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                <svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                                </svg>
                                            </div>
                                            <input type="text" name="title" required
                                                x-model="currentEvent.title"
                                                class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 placeholder-gray-400/80 text-sm transition-all hover:border-gray-300"
                                                placeholder="Tech Conference 2024">
                                        </div>
                                    </div>

                                    <!-- Event Type (Online/Offline) -->
                                     <div class="group">
                                         <label class="block text-sm font-medium text-gray-700 mb-2">
                                             Event Type
                                             <span class="text-red-500 ml-1">*</span>
                                         </label>
                                         <div class="grid grid-cols-2 gap-4">
                                             <label :class="currentEvent.is_online == '0' ? 'border-purple-500 bg-purple-50 ring-1 ring-purple-500' : 'border-gray-200 hover:border-gray-300'"
                                                    class="border-2 rounded-xl p-4 cursor-pointer transition-all duration-150">
                                                 <input type="radio" name="is_online" value="0" x-model="currentEvent.is_online" class="sr-only">
                                                 <div class="flex items-center space-x-3">
                                                     <div class="shrink-0 w-5 h-5 rounded-full border-2 flex items-center justify-center"
                                                          :class="currentEvent.is_online == '0' ? 'border-purple-600 bg-purple-600' : 'border-gray-300'">
                                                         <div class="w-2 h-2 rounded-full bg-white" x-show="currentEvent.is_online == '0'"></div>
                                                     </div>
                                                     <div>
                                                         <p class="text-sm font-medium text-gray-800">In-Person Event</p>
                                                         <p class="text-xs text-gray-500">Physical location required</p>
                                                     </div>
                                                 </div>
                                             </label>
                                             <label :class="currentEvent.is_online == '1' ? 'border-purple-500 bg-purple-50 ring-1 ring-purple-500' : 'border-gray-200 hover:border-gray-300'"
                                                    class="border-2 rounded-xl p-4 cursor-pointer transition-all duration-150">
                                                 <input type="radio" name="is_online" value="1" x-model="currentEvent.is_online" class="sr-only">
                                                 <div class="flex items-center space-x-3">
                                                     <div class="shrink-0 w-5 h-5 rounded-full border-2 flex items-center justify-center"
                                                          :class="currentEvent.is_online == '1' ? 'border-purple-600 bg-purple-600' : 'border-gray-300'">
                                                         <div class="w-2 h-2 rounded-full bg-white" x-show="currentEvent.is_online == '1'"></div>
                                                     </div>
                                                     <div>
                                                          <p class="text-sm font-medium text-gray-800">Online Event</p>
                                                         <p class="text-xs text-gray-500">Virtual event link</p>
                                                     </div>
                                                 </div>
                                             </label>
                                         </div>
                                     </div>


                                    <!-- Date & Time -->
                                    <div class="space-y-3">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Date & Time
                                            <span class="text-red-500 ml-1">*</span>
                                        </label>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div class="relative group">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                    <svg class="w-5 h-5 text-gray-400 group-focus-within:text-purple-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                    </svg>
                                                </div>
                                                <input type="date" name="event_date" required
                                                    x-model="currentEvent.event_date"
                                                    min="<?= date('Y-m-d') ?>"
                                                    class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors">
                                            </div>
                                            <div class="relative group">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                    <svg class="w-5 h-5 text-gray-400 group-focus-within:text-purple-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                    </svg>
                                                </div>
                                                <input type="time" name="event_time" required
                                                    x-model="currentEvent.event_time"
                                                    class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Location / Online Link -->
                                    <div class="grid grid-cols-1 gap-6">
                                        <template x-if="currentEvent.is_online == '0'">
                                        <div class="group">
                                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                                Location (City/State)
                                                <span class="text-red-500 ml-1">*</span>
                                            </label>
                                            <div class="relative">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                    <svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    </svg>
                                                </div>
                                                <select name="city_id"
                                                    x-model.number="currentEvent.city_id"
                                                    :required="currentEvent.is_online == '0'"
                                                    class="w-full pl-11 pr-10 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 bg-select-arrow appearance-none text-sm hover:border-gray-300 transition-colors">
                                                    <option value="">Select Location</option>
                                                    <?php foreach ($locations as $location): ?>
                                                    <option value="<?= $location['id'] ?>">
                                                        <?= htmlspecialchars($location['city']) ?>, <?= htmlspecialchars($location['state']) ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        </template>

                                        <!-- Google Map Link (shown for In-Person) / Online Link (shown for Online) -->
                                        <div class="group">
                                             <label class="block text-sm font-medium text-gray-700 mb-2">
                                                <span x-text="currentEvent.is_online == '1' ? 'Online Event Link' : 'Google Map Link'"></span>
                                                <span class="text-red-500 ml-1">*</span>
                                            </label>
                                            <div class="relative">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                    <svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600"
                                                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <!-- Link Icon -->
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                                    </svg>
                                                </div>
                                                <input type="url" name="google_map_link"
                                                    required
                                                    x-model="currentEvent.google_map_link"
                                                    class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors"
                                                    :placeholder="currentEvent.is_online == '1' ? 'https://zoom.us/j/...' : 'https://goo.gl/maps/...'">
                                            </div>
                                            <p x-show="currentEvent.is_online == '0'" class="text-xs text-gray-500 mt-1">Provide the Google Maps link for the venue.</p>
                                            <p x-show="currentEvent.is_online == '1'" class="text-xs text-gray-500 mt-1">Provide the link for attendees to join the online event (e.g., Zoom, Teams).</p>
                                        </div>


                                        <!-- Category -->
                                        <div class="group">
                                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                                Category
                                                <span class="text-red-500 ml-1">*</span>
                                            </label>
                                            <div class="relative">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                    <svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/>
                                                    </svg>
                                                </div>
                                                <select name="category_id" required
                                                    x-model.number="currentEvent.category_id"
                                                    @change="currentEvent.tags = []" 
                                                    class="w-full pl-11 pr-10 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 bg-select-arrow appearance-none text-sm hover:border-gray-300 transition-colors">
                                                    <option value="">Select Category</option>
                                                    <?php foreach ($categories as $category): ?>
                                                    <option value="<?= $category['id'] ?>">
                                                        <?= htmlspecialchars($category['name']) ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- Category-Specific Tags -->
                                         <div class="group" x-data="{ allTags: <?= htmlspecialchars(json_encode($tags), ENT_QUOTES) ?> }">
                                             <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                                 Event Tags
                                                 <span class="text-gray-400 font-normal ml-2">(Optional, Max 5 tags)</span>
                                             </label>
                                             <template x-if="currentEvent.category_id">
                                                 <div class="space-y-2 p-4 border-2 border-gray-100 rounded-xl bg-gray-50/50">
                                                    <p class="text-xs text-gray-500 mb-2">Select tags relevant to the '<span x-text="allTags.find(t => t.category_id == currentEvent.category_id)?.category_name || 'selected category'"></span>' category:</p>
                                                     <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                                         <template x-for="tag in allTags.filter(t => t.category_id == currentEvent.category_id)" :key="tag.id">
                                                             <label
                                                                 class="flex items-center space-x-2 bg-white border-2 rounded-lg p-3 cursor-pointer hover:border-purple-400 transition-colors text-xs"
                                                                 :class="{
                                                                     'border-purple-500 bg-purple-50 ring-1 ring-purple-200': currentEvent.tags && currentEvent.tags.includes(tag.id.toString()),
                                                                     'border-gray-200': !(currentEvent.tags && currentEvent.tags.includes(tag.id.toString())),
                                                                     'opacity-50 cursor-not-allowed': currentEvent.tags && currentEvent.tags.length >= 5 && !(currentEvent.tags.includes(tag.id.toString()))
                                                                 }"
                                                                 >
                                                                 <input type="checkbox"
                                                                        name="tags[]"
                                                                        x-model="currentEvent.tags"
                                                                        :value="tag.id.toString()"
                                                                        :disabled="currentEvent.tags && currentEvent.tags.length >= 5 && !currentEvent.tags.includes(tag.id.toString())"
                                                                        class="hidden">
                                                                 <div class="w-4 h-4 border-2 rounded flex items-center justify-center shrink-0"
                                                                      :class="currentEvent.tags && currentEvent.tags.includes(tag.id.toString()) ? 'bg-purple-500 border-purple-500' : 'border-gray-300 bg-white'">
                                                                     <svg x-show="currentEvent.tags && currentEvent.tags.includes(tag.id.toString())" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                         <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                                                     </svg>
                                                                 </div>
                                                                 <span class="text-gray-700" x-text="tag.name"></span>
                                                             </label>
                                                         </template>
                                                     </div>
                                                     <p class="text-xs text-right text-gray-500 mt-1" x-text="`${currentEvent.tags ? currentEvent.tags.length : 0}/5 tags selected`"></p>
                                                 </div>
                                             </template>
                                              <template x-if="!currentEvent.category_id">
                                                  <p class="text-sm text-gray-500 p-4 border-2 border-dashed border-gray-200 rounded-xl text-center">Please select a category first to see available tags.</p>
                                              </template>
                                         </div>
                                    </div>

                                    <!-- Event Description -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Event Description
                                            <span class="text-red-500 ml-1">*</span>
                                        </label>
                                        <div class="relative">
                                            <div class="absolute top-3 left-0 pl-3 pointer-events-none">
                                                <svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                                                </svg>
                                            </div>
                                            <textarea name="description" rows="5" required
                                                x-model="currentEvent.description"
                                                class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 placeholder-gray-400/80 text-sm hover:border-gray-300 transition-colors"
                                                placeholder="Describe your event details..."></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Settings Tab -->
                                <div x-show="activeTab === 'settings'" class="space-y-6">
                                    <!-- Image Upload -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Event Banner
                                            <span class="text-gray-400 font-normal ml-2">(Optional, Recommended: 1200x600px)</span>
                                        </label>
                                        <div class="relative border-2 border-dashed border-gray-200 rounded-2xl hover:border-purple-500 transition-all duration-300 p-6 bg-gray-50/50 hover:bg-white"
                                            @dragover.prevent="$el.classList.add('border-purple-500', 'bg-purple-50')"
                                            @dragleave.prevent="$el.classList.remove('border-purple-500', 'bg-purple-50')"
                                            @drop.prevent="$el.classList.remove('border-purple-500', 'bg-purple-50'); previewImage($event)"
                                            :class="{ 'border-purple-500 bg-purple-50': imagePreview }">
                                            <input type="file" name="event_image" accept="image/jpeg, image/png, image/gif"
                                                class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                                @change="previewImage($event)">
                                            <div class="text-center space-y-4">
                                                <template x-if="!imagePreview">
                                                    <div class="space-y-3">
                                                        <svg class="w-12 h-12 mx-auto text-gray-400 transition-colors group-hover:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                        </svg>
                                                        <div class="space-y-1">
                                                            <p class="text-sm text-gray-600 group-hover:text-purple-700 transition-colors font-medium">
                                                                <span class="text-purple-600 font-semibold">Click to upload</span> or drag & drop
                                                            </p>
                                                            <p class="text-xs text-gray-500">
                                                                JPG, PNG, GIF (Max 2MB)
                                                            </p>
                                                        </div>
                                                    </div>
                                                </template>
                                                <template x-if="imagePreview">
                                                    <div class="relative group/preview">
                                                        <img :src="imagePreview" class="w-full h-40 object-cover rounded-xl shadow-sm border border-gray-200">
                                                        <button type="button" @click.stop.prevent="imagePreview = null; $refs.eventForm.querySelector('input[name=event_image]').value = null;"
                                                                class="absolute -top-2 -right-2 p-1.5 bg-white rounded-full shadow-md hover:bg-red-100 text-red-500 hover:text-red-600 transition-all opacity-0 group-hover/preview:opacity-100 scale-75 group-hover/preview:scale-100">
                                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Price -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Ticket Price<span class="text-red-500 ml-1">*</span></label>
                                        <div class="relative">
                                            <!-- Radio Buttons for Free/Paid -->
                                            <div class="flex items-center gap-4 mb-3">
                                                <label for="org_price_type_free" class="flex items-center cursor-pointer"> <!-- Added unique ID -->
                                                    <input type="radio" id="org_price_type_free" name="price_type" :value="true" 
                                                        x-model="isFree"  
                                                        @change="isFree = true; currentEvent.price = '0'" 
                                                        class="hidden peer">
                                                    <span class="px-4 py-2 rounded-lg border-2 text-sm transition-colors"
                                                        :class="isFree ? 'border-purple-500 bg-purple-50 text-purple-700 font-medium' : 'border-gray-200 text-gray-600 peer-hover:border-gray-300'">
                                                        Free Event
                                                    </span>
                                                </label>
                                                <label for="org_price_type_paid" class="flex items-center cursor-pointer"> <!-- Added unique ID -->
                                                    <input type="radio" id="org_price_type_paid" name="price_type" :value="false"
                                                        x-model="isFree"
                                                        @change="isFree = false; if (currentEvent.price == '0' || currentEvent.price === null || currentEvent.price === '') currentEvent.price = ''"
                                                        class="hidden peer">
                                                    <span class="px-4 py-2 rounded-lg border-2 text-sm transition-colors"
                                                        :class="!isFree ? 'border-purple-500 bg-purple-50 text-purple-700 font-medium' : 'border-gray-200 text-gray-600 peer-hover:border-gray-300'">
                                                        Paid Event
                                                    </span>
                                                </label>
                                            </div>

                                            <!-- Conditional Price Input (Only shows when 'Paid' is selected) -->
                                            <div x-show="!isFree" x-transition class="relative">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                    <span class="text-gray-500 text-sm">RM</span>
                                                </div>
                                                <input type="number" name="price" step="0.01" min="0.01"
                                                    x-model="currentEvent.price"
                                                    :disabled="isFree"
                                                    :required="!isFree"
                                                    class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors disabled:bg-gray-100 disabled:cursor-not-allowed"
                                                    placeholder="Enter ticket price (e.g., 25.50)">
                                            </div>
                                            <!-- No hidden input needed here -->
                                        </div>
                                    </div>
                                    <!-- End Price -->

                                    <!-- Participant Limit -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Participant Limit
                                            <span class="text-gray-400 font-normal ml-2">(Optional)</span>
                                        </label>
                                        <div class="relative">
                                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                <svg class="w-5 h-5 text-gray-400 group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M12 7a4 4 0 110-5.292A4 4 0 0112 7z" />
                                                </svg>
                                            </div>
                                            <input type="number" name="participant_limit"
                                                x-model.number="currentEvent.participant_limit"
                                                min="1"
                                                class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors"
                                                placeholder="Leave empty for unlimited">
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1">Set maximum number of participants. Minimum 1 if set.</p>
                                    </div>

                                    <!-- Venue Details -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Venue Name / Details
                                            <span class="text-red-500 ml-1">*</span>
                                        </label>
                                        <div class="relative">
                                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                <svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                                </svg>
                                            </div>
                                            <input type="text" name="venue" required
                                                x-model="currentEvent.venue"
                                                class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors"
                                                :placeholder="currentEvent.is_online == '1' ? 'e.g., Online Platform' : 'e.g., Convention Center Hall A'">
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1">Specify the exact hall, room, or platform name.</p>
                                    </div>

                                    <!-- Audience -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Target Audience
                                            <span class="text-red-500 ml-1">*</span>
                                        </label>
                                        <div class="relative">
                                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                <svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                                </svg>
                                            </div>
                                            <input type="text" name="audience" required
                                                x-model="currentEvent.audience"
                                                class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors"
                                                placeholder="e.g., General Public, Students, Professionals">
                                        </div>
                                    </div>

                                    <!-- Attention Notice -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Special Instructions / Notes
                                            <span class="text-gray-400 font-normal ml-2">(Optional)</span>
                                        </label>
                                        <div class="relative">
                                            <div class="absolute top-3 left-0 pl-3 pointer-events-none">
                                                <svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                                </svg>
                                            </div>
                                            <textarea name="attention" rows="3"
                                                x-model="currentEvent.attention"
                                                class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 placeholder-gray-400/80 text-sm hover:border-gray-300 transition-colors"
                                                placeholder="Important notes for attendees (e.g., Bring ID, Dress code)..."></textarea>
                                        </div>
                                    </div>

                                    <!-- Terms & Conditions -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Terms & Conditions
                                            <span class="text-gray-400 font-normal ml-2">(Optional)</span>
                                        </label>
                                        <div class="relative">
                                            <div class="absolute top-3 left-0 pl-3 pointer-events-none">
                                                <svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                </svg>
                                            </div>
                                            <textarea name="terms_and_conditions" rows="4"
                                                x-model="currentEvent.terms_and_conditions"
                                                class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 placeholder-gray-400/80 text-sm hover:border-gray-300 transition-colors"
                                                placeholder="Event terms and conditions (e.g., Refund policy, Code of conduct)..."></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="sticky bottom-0 bg-white pt-6 pb-6 border-t border-gray-100 -mx-8 px-8">
                                <div class="flex justify-end gap-4">
                                    <button type="button" @click="openModal = false"
                                            class="px-6 py-3 text-gray-600 hover:text-gray-800 rounded-xl transition-all font-medium border-2 border-gray-200 hover:border-gray-300 text-sm hover:bg-gray-50 flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                        Cancel
                                    </button>
                                    <button type="submit"
                                            class="px-6 py-3 bg-gradient-to-br from-purple-600 to-blue-600 text-white rounded-xl hover:shadow-lg transition-all font-medium flex items-center justify-center gap-2 text-sm shadow-md hover:shadow-purple-200/50 hover:-translate-y-0.5 relative min-w-[150px]"
                                            :disabled="processing || (editMode && isCurrentEventExpired)" 
                                            :class="{ 'opacity-50 cursor-not-allowed': processing || (editMode && isCurrentEventExpired) }">
                                        <span x-show="!processing" x-text="editMode ? 'Save Changes' : 'Create Event'"></span>
                                        <span x-show="processing">
                                            <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>
                </form>
                </div> <!-- End Scrollable -->
            </div>
        </div><!-- End Event Form Modal -->

        <!-- <<< NEW: Delete Confirmation Modal >>> -->
        <div x-show="showDeleteConfirm" x-cloak class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[60] flex items-center justify-center p-4 transition-opacity"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6"
                 @click.away="showDeleteConfirm = false"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95">

                <div class="flex items-start space-x-4">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                        <svg class="h-6 w-6 text-red-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left flex-1">
                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Delete Event</h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-600">
                                Are you sure you want to delete the event: <strong class="font-semibold" x-text="eventToDeleteTitle"></strong>?
                            </p>
                            <p class="text-sm text-red-600 mt-1">This will permanently remove the event, its registrations, and associated tags. This action cannot be undone.</p>
                        </div>
                    </div>
                </div>

                 <!-- Delete Error Alert -->
                <div x-show="formError && deleting == false && showDeleteConfirm" x-cloak class="mt-4" id="delete-error-alert">
                    <div class="flex items-start gap-3 p-3 bg-red-50 border border-red-200 rounded-lg">
                        <svg class="w-5 h-5 text-red-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                        <p class="text-sm font-medium text-red-800 flex-1" x-text="errorMessage"></p>
                    </div>
                </div>

                <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                    <button type="button"
                            @click="deleteEvent()"
                            :disabled="deleting"
                            class="inline-flex justify-center w-full rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:w-auto sm:text-sm disabled:opacity-50 min-w-[120px] relative"
                            >
                         <span x-show="!deleting">Confirm Delete</span>
                         <span x-show="deleting" class="absolute inset-0 flex items-center justify-center">
                             <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                 <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                 <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                             </svg>
                         </span>
                    </button>
                    <button type="button"
                            @click="showDeleteConfirm = false"
                            :disabled="deleting"
                            class="mt-3 inline-flex justify-center w-full rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:w-auto sm:text-sm disabled:opacity-50">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
         <!-- <<< End Delete Confirmation Modal >>> -->

                <!-- <<< ENHANCED: Feedback Review Modal >>> -->
                <div x-show="showFeedbackModal" x-cloak
             class="fixed inset-0 bg-gradient-to-br from-purple-500/10 via-blue-500/10 to-transparent backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-opacity duration-300"
             x-transition:enter="ease-out"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">

            <div class="bg-gradient-to-br from-white to-gray-50 rounded-2xl w-full max-w-2xl flex flex-col shadow-2xl border border-gray-200/50 modal-container"
                 style="height: 85vh; max-height: 700px;"
                 @click.away="showFeedbackModal = false"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95">

                <!-- Modal Header -->
                <div class="flex justify-between items-start p-6 border-b border-gray-200 shrink-0 bg-white rounded-t-2xl">
                    <div>
                        <h2 class="text-xl font-bold text-gray-800">Participant Feedback</h2>
                        <p class="text-sm text-purple-700 font-medium truncate" x-text="currentFeedbackEventTitle || 'Loading Event Title...'"></p>
                    </div>
                    <button @click="showFeedbackModal = false"
                            class="text-gray-500 hover:text-red-600 transition-colors p-1.5 rounded-full hover:bg-red-50 -mt-1 -mr-1">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Scrollable Feedback Content -->
                <div class="modal-scroll overflow-y-auto flex-1 p-6 space-y-5 bg-gray-50/50"> <!-- Added slight bg color -->
                    <!-- Loading State -->
                    <div x-show="feedbackLoading" class="flex flex-col items-center justify-center h-full min-h-[200px] text-center py-10">
                        <svg class="animate-spin h-10 w-10 text-purple-600 mx-auto mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p class="text-md font-medium text-gray-600">Fetching Feedback...</p>
                        <p class="text-sm text-gray-500">Please wait a moment.</p>
                    </div>

                    <!-- Error State -->
                    <div x-show="feedbackError" class="p-5 bg-red-50 border border-red-200 rounded-xl text-center shadow-sm">
                         <div class="flex justify-center mb-2">
                            <svg class="w-8 h-8 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                         </div>
                         <p class="text-sm font-semibold text-red-700">Oops! Could not load feedback.</p>
                         <p class="text-xs text-red-600 mt-1 mb-3" x-text="feedbackError"></p>
                         <!-- Ensure currentFeedbackEventId is set in x-data and viewFeedback -->
                         <button @click="viewFeedback(currentFeedbackEventId, currentFeedbackEventTitle)" class="mt-2 text-xs text-blue-600 hover:underline">
                            Retry
                        </button>
                    </div>

                     <!-- No Feedback State -->
                     <div x-show="!feedbackLoading && !feedbackError && feedbackData.length === 0" class="text-center py-16 px-6 border-2 border-dashed border-gray-200 rounded-xl bg-white">
                         <svg class="w-16 h-16 mx-auto text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                             <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                         </svg>
                         <p class="mt-4 text-lg font-semibold text-gray-700">No Feedback Yet</p>
                         <p class="mt-1 text-sm text-gray-500">Check back later to see what participants thought about this event.</p>
                     </div>

                     <!-- Feedback List -->
                     <!-- Use space-y on parent div -->
                     <template x-for="(item, index) in feedbackData" :key="index">
                        <div class="flex items-start space-x-4 p-5 bg-white rounded-xl border border-gray-200 shadow-sm transition-shadow hover:shadow-md">
                            <!-- Avatar -->
                            <img :src="item.user_profile_image"
                                 alt="User avatar"
                                 class="w-11 h-11 rounded-full object-cover border-2 border-gray-100 flex-shrink-0 shadow-sm"
                                 onerror="this.onerror=null; this.src='/assets/images/profiles/default-avatar.jpg';">
                            <!-- Content -->
                            <div class="flex-1 min-w-0"> <!-- min-w-0 prevents flex item overflow -->
                                <div class="flex justify-between items-baseline mb-1.5 flex-wrap"> <!-- Allow wrap for small screens -->
                                    <p class="text-sm font-semibold text-gray-900 truncate mr-2" x-text="item.user_name"></p>
                                    <p class="text-xs text-gray-500 whitespace-nowrap" x-text="item.submitted_at_formatted"></p>
                                </div>
                                <div class="prose prose-sm max-w-none text-gray-700 leading-relaxed" x-html="item.comment">
                                    <!-- Comment content rendered here -->
                                </div>
                            </div>
                        </div>
                     </template>
                </div>

                 <!-- Modal Footer -->
                <div class="p-5 bg-gray-100 border-t border-gray-200 text-right shrink-0 rounded-b-2xl">
                    <button @click="showFeedbackModal = false"
                            class="px-5 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition shadow-sm hover:shadow-none">
                        Close Window
                    </button>
                </div>
            </div>
        </div>
        <!-- <<< End Feedback Review Modal >>> -->

    </div><!-- End Max Width Container -->

    <?php include '../includes/footer.php'; ?>
</body>
</html>