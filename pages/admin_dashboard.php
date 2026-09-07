<?php
session_start();
require_once '../config/db.php';
require_once '../lib/functions.php';

// Check admin privileges
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    // If AJAX request, send JSON, otherwise redirect
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
         http_response_code(403);
         echo json_encode(['message' => 'Access denied']);
         exit();
    } else {
        $_SESSION['error_message'] = "Access denied. You must be an administrator.";
        header('Location: /pages/login.php'); // Redirect to login
        exit();
    }
}

// --- Fetch data for dashboard ---
$pending_applications_count = 0;
$applications = [];
$users = [];
$events = [];
$categories = [];
$locations = []; // <<< For event modal dropdown
$tags = [];
$event_tags = [];
$organizer_earnings = [];
$dashboard_error = null; // Initialize potential error message

try {
    // Count pending applications
    $stmt_pending = $pdo->query("SELECT COUNT(*) FROM users WHERE organizer_status = 'pending'");
    $pending_applications_count = $stmt_pending->fetchColumn();

    // Fetch pending applications
    $stmt_applications = $pdo->prepare("
        SELECT id, name, email, organization, org_type, contact_person, website, organizer_description, verification_doc, payout_bank_name, payout_account_number, created_at
        FROM users
        WHERE organizer_status = 'pending'
        ORDER BY created_at ASC
    ");
    $stmt_applications->execute();
    $applications = $stmt_applications->fetchAll(PDO::FETCH_ASSOC);

    // Users
    $users = $pdo->query("SELECT *,
        CASE
            WHEN is_admin = 1 THEN 'admin'
            WHEN organizer_status = 'approved' THEN 'organizer'
            ELSE 'user'
        END as role,
        organizer_status, is_admin, last_login
        FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    // Events (Fetch necessary data for display AND editing)
    $events_stmt = $pdo->query("
        SELECT
            e.*,
            c.name AS category,
            l.city, l.state,
            (SELECT GROUP_CONCAT(et.tag_id) FROM event_tags et WHERE et.event_id = e.id) AS tag_ids,
            -- Add a sorting column: 0 for upcoming/today, 1 for past
            CASE
                WHEN e.event_date >= CURDATE() THEN 0
                ELSE 1
            END AS is_past_sort
        FROM events e
        LEFT JOIN categories c ON e.category_id = c.id
        LEFT JOIN locations l ON e.city_id = l.id -- city_id can be null
        -- Sort by upcoming first (0), then by event date descending (nearest upcoming first)
        ORDER BY is_past_sort ASC, e.event_date DESC
    ");
    $events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Categories
    $categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Locations
    $locations = $pdo->query("SELECT * FROM locations ORDER BY state, city ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Tags
    $tags = $pdo->query("
        SELECT t.*, c.name AS category_name
        FROM tags t
        LEFT JOIN categories c ON t.category_id = c.id
        ORDER BY c.name, t.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Event-Tags (For usage count display)
    $event_tags = $pdo->query("SELECT * FROM event_tags")->fetchAll(PDO::FETCH_ASSOC);

    $organizer_earnings_stmt = $pdo->query("
        SELECT
            u.id AS organizer_id,
            u.name AS organizer_name,
            u.payout_bank_name,
            u.payout_account_number,
            -- Calculate total potential earnings from PAID registrations (includes future events)
            COALESCE(SUM(CASE WHEN r.payment_status = 'paid' THEN e.price ELSE 0 END), 0) AS total_potential_earnings,
            -- Count PAST PAID events where payout is still PENDING
            -- Use COUNT(DISTINCT e.id) to count events, not registrations
            COUNT(DISTINCT CASE
                        WHEN e.price > 0                     -- It's a paid event
                         AND e.payout_status = 'pending' -- Payout is pending
                         AND e.event_date < CURDATE()    -- Event date is in the past
                        THEN e.id
                        ELSE NULL
                    END) AS past_pending_payout_events_count
        FROM users u
        LEFT JOIN events e ON u.id = e.created_by
        -- We only need registration join for total_potential_earnings if defined as SUM(e.price)
        -- For counting pending events, joining isn't strictly necessary unless checking if *any* paid reg exists
        LEFT JOIN registrations r ON e.id = r.event_id AND r.payment_status = 'paid'
        WHERE u.is_admin = 0 AND u.organizer_status = 'approved' -- Only approved organizers
        GROUP BY u.id, u.name, u.payout_bank_name, u.payout_account_number
        -- Removed HAVING clause, the WHERE clause already ensures only approved organizers
        ORDER BY total_potential_earnings DESC, u.name ASC
    ");
    $organizer_earnings = $organizer_earnings_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Note: total_potential_earnings still includes future events.
    // past_pending_payout_events_count now accurately reflects past events needing payout action.

} catch(PDOException $e) {
    error_log("Admin Dashboard Data Fetch Error: " . $e->getMessage());
    $dashboard_error = 'Database error loading dashboard data. Please try again later.';
    // Keep arrays empty
} catch(Exception $e) {
     error_log("Admin Dashboard General Error: " . $e->getMessage());
     $dashboard_error = 'An unexpected error occurred: ' . $e->getMessage();
     // Keep arrays empty
}

// --- Get flash messages ---
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null; // General error
$form_errors = $_SESSION['form_errors'] ?? []; // Specific form errors for reject modal
unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['form_errors']);

// --- Generate CSRF token ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - CECT</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        .striped-table tbody tr:nth-child(odd) { background-color: #f9fafb; }
        .hover-table tbody tr { transition: all 0.2s ease; }
        .hover-table tbody tr:hover { transform: translateX(4px); box-shadow: 0 3px 6px -1px rgba(0, 0, 0, 0.1); }
        .react-datepicker { border: 2px solid #e5e7eb !important; border-radius: 1rem !important; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important; }
        .react-datepicker__header { background-color: #f8fafc !important; border-bottom: 2px solid #e5e7eb !important; }
        .react-datepicker__day--selected { background-color: #4f46e5 !important; border-radius: 9999px !important; }
        [x-cloak] { display: none !important; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .fade-in { animation: fadeIn 0.5s ease-out forwards; }
        textarea:focus { outline: 2px solid transparent; outline-offset: 2px; box-shadow: 0 0 0 2px #a78bfa; border-color: #a78bfa; }
        .modal-content { max-height: 80vh; overflow-y: auto; }
        .modal-overlay { background-color: rgba(0, 0, 0, 0.6); backdrop-filter: blur(5px); }

        /* Styles for Event Modal */
        .bg-select-arrow { background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e"); background-position: right 0.75rem center; background-size: 1.25em 1.25em; background-repeat: no-repeat; }
        select { -webkit-appearance: none; -moz-appearance: none; appearance: none; }
        .modal-scroll::-webkit-scrollbar { width: 8px; background-color: #f1f5f9; border-radius: 0 12px 12px 0; }
        .modal-scroll::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 8px; border: 2px solid #f1f5f9; }
        .modal-scroll::-webkit-scrollbar-thumb:hover { background-color: #94a3b8; }
        .modal-container { scrollbar-gutter: stable; }
        .animate-progress { animation: progressBar linear forwards; }
        @keyframes progressBar { 0% { transform: scaleX(1); } 100% { transform: scaleX(0); } }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../includes/nav.php'; ?>

    <script>
        // Define initial data safely
        const initialUsersData = <?= json_encode($users ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
        const categoriesData = <?= json_encode($categories ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
        const locationsData = <?= json_encode($locations ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
        const tagsData = <?= json_encode($tags ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
    </script>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"
x-data="{
        // --- Admin Dashboard STATE ---
        activeTab: 'users', // Default tab
        isLoading: true,
        notification: { show: false, type: 'success', message: '' },

        // --- User Management STATE ---
        users: Array.isArray(initialUsersData) ? initialUsersData : [],
        showUserModal: false,
        selectedUser: null,
        showDeleteModal: false,
        userToDelete: null,
        isSaving: false,
        isDeleting: false,
        deleteError: null,
        searchQuery: '',
        selectedRole: '',

        // --- Application Review STATE ---
        showRejectModal: false,
        rejectingUser: null,
        rejectionNotes: '',
        modalErrors: <?= htmlspecialchars(json_encode($form_errors), ENT_QUOTES, 'UTF-8') ?>,
        isSubmitting: false,
        showApplicationDetailsModal: false,
        selectedApplication: null,
        isProcessingAction: false,

        // --- Event Management STATE ---
        openEventModal: false,
        editEventMode: false,
        currentEvent: null,
        eventImagePreview: null,
        isEventFree: true,
        isEventProcessing: false,
        eventFormError: false,
        eventErrorMessage: '',
        activeEventTab: 'details',
        showEventDeleteConfirm: false,
        isEventDeleting: false,
        eventToDeleteId: null,
        eventToDeleteTitle: '',
        eventSearchQuery: '',

        // --- Category Management STATE ---
        showCategoryModal: false,
        editCategoryMode: false,
        currentCategory: { id: null, name: '', description: '' },
        isCategoryProcessing: false,
        categoryFormError: false,
        categoryErrorMessage: '',
        showCategoryDeleteConfirm: false,
        isCategoryDeleting: false,
        categoryToDelete: null,
        categorySearchQuery: '',
        // --- END Category Management STATE ---

        // --- Tag Management STATE ---
        showTagModal: false,
        editTagMode: false,
        currentTag: { id: null, name: '', category_id: '' }, // Initialize category_id as string for select
        isTagProcessing: false,
        tagFormError: false,
        tagErrorMessage: '',
        showTagDeleteConfirm: false,
        isTagDeleting: false,
        tagToDelete: null,
        tagSearchQuery: '', // Search for tags tab
        selectedTagCategoryFilter: '', // Filter for tags tab
        // --- END Tag Management STATE ---

        // --- Payout Management STATE ---
        showPayoutDetailsModal: false,
        selectedOrganizerForPayout: null, // Store the organizer object { id, name, pending_count }
        pendingEventsForOrganizer: [], // Events fetched via AJAX
        isLoadingPendingEvents: false,
        isMarkingPaid: false, // For loading state on individual buttons
        payoutModalError: null,

        // --- GETTERS ---
        get filteredUsers() {
            return this.performUserFilter();
        },

        get filteredCategories() {
             const categoryList = Array.isArray(categoriesData) ? categoriesData : [];
             const query = this.categorySearchQuery.toLowerCase();
             return categoryList.filter(cat => cat.name.toLowerCase().includes(query));
        },

        get filteredTags() {
            const tagList = Array.isArray(tagsData) ? tagsData : [];
            const query = this.tagSearchQuery.toLowerCase();
            const categoryFilter = this.selectedTagCategoryFilter;
            return tagList.filter(tag => {
                const nameMatch = tag.name.toLowerCase().includes(query);
                // Use == for loose comparison as categoryFilter is string from select, tag.category_id might be number from DB
                const categoryMatch = categoryFilter === '' || tag.category_id == categoryFilter;
                return nameMatch && categoryMatch;
            });
        },

        get isCurrentEventExpired() {
            if (!this.editEventMode || !this.currentEvent || !this.currentEvent.event_date) {
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
                return false;
            }
        },

        // --- METHODS ---
        // User Methods
        performUserFilter() {
            const userList = Array.isArray(this.users) ? this.users : [];
            const query = this.searchQuery.toLowerCase();
            const role = this.selectedRole;
            return userList.filter(user => {
                const name = user?.name || '';
                const email = user?.email || '';
                const userRole = user?.role || '';
                return (name.toLowerCase().includes(query) || email.toLowerCase().includes(query)) &&
                       (role === '' || userRole === role);
            });
        },

        // Tab Initialization
        initializeTabs() {
            const urlParams = new URLSearchParams(window.location.search);
            const tabFromUrl = urlParams.get('tab');
            const validTabs = ['users', 'events', 'categories', 'tags', 'review', 'earnings'];

            if (tabFromUrl && validTabs.includes(tabFromUrl)) {
                this.activeTab = tabFromUrl;
            } else {
                // Fallback to default if URL param is missing or invalid
                this.activeTab = 'users';
            }

             // Logic to reopen reject modal if form errors exist
             if (Object.keys(this.modalErrors).length > 0 && this.modalErrors.user_id) {
                 let targetUserId = this.modalErrors.user_id;
                 let applicationsData = <?= htmlspecialchars(json_encode($applications), ENT_QUOTES, 'UTF-8') ?>;
                 let targetUser = applicationsData.find(app => app.id == targetUserId);
                 if (targetUser) {
                     this.rejectingUser = targetUser;
                     this.rejectionNotes = this.modalErrors.approval_notes_old || '';
                     this.showRejectModal = true;
                     if (this.activeTab !== 'review') { // Ensure review tab is active if modal is shown
                         this.activeTab = 'review';
                         // Update URL without reloading to reflect the state, if needed, or let the reload handle it
                         // window.history.replaceState(null, '', window.location.pathname + '?tab=review');
                     }
                 } else {
                     console.warn('Could not find user ' + targetUserId + ' to reopen rejection modal.');
                     this.modalErrors = {}; // Clear errors if user not found
                 }
             }
             this.isLoading = false; // Set loading to false after initialization
        },

        // --- Event Methods ---
        previewEventImage(event) {
            const file = event.target.files[0] || event.dataTransfer?.files[0];
            if (file && file.type.startsWith('image/')) {
                 this.eventImagePreview = URL.createObjectURL(file);
            } else {
                this.eventImagePreview = null;
                 this.notification = { show: true, type: 'error', message: 'Invalid file type. Please upload an image.' };
                 setTimeout(() => this.notification.show = false, 4000);
                 event.target.value = null;
            }
        },

        async submitEventForm() {
             if (this.editEventMode && this.isCurrentEventExpired) {
                 console.warn('Attempted to save an expired event. Action blocked.');
                 this.notification = { show: true, type: 'error', message: 'Expired events cannot be updated.' };
                 setTimeout(() => this.notification.show = false, 4000);
                 return;
             }

             console.log('Admin Event Form submission started');
             this.isEventProcessing = true;
             this.eventFormError = false;
             this.eventErrorMessage = '';
             const form = this.$refs.eventForm;

             if (!form) {
                 console.error('Event form ref not found!');
                 this.eventErrorMessage = 'Internal error: Form reference missing.';
                 this.eventFormError = true;
                 this.isEventProcessing = false;
                 return;
             }

             if (!form.checkValidity()) {
                 console.warn('Admin Event Form validation failed. Check all tabs.');
                 this.eventErrorMessage = 'Validation failed. Please review all fields in both the Details and Settings tabs. Required fields marked with * must be completed.';
                 this.eventFormError = true;
                 this.isEventProcessing = false;

                 this.$nextTick(() => {
                     const errorAlert = document.getElementById('modal-event-error-alert');
                     if (errorAlert && window.getComputedStyle(errorAlert).display !== 'none') {
                         console.log('Scrolling event validation error alert into view.');
                         errorAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                     } else {
                          console.warn('Event validation error alert (#modal-event-error-alert) is not visible or not found.');
                     }
                 });
                 return;
             }

            console.log('Admin Event Form validation passed. Proceeding with fetch.');
            const formData = new FormData(form);

            try {
                const response = await fetch('/actions/handle_event.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json' }
                });

                if (!response.ok) {
                     let errorData = { message: `Server error: ${response.status} ${response.statusText}` };
                     try { const potentialJson = await response.json(); if (potentialJson && potentialJson.message) { errorData = potentialJson; } } catch (e) {}
                     throw new Error(errorData.message);
                }

                const result = await response.json();
                console.log('Admin Event Submit Response:', result);

                if (result.status === 'success') {
                    this.openEventModal = false;
                    this.notification = { show: true, type: 'success', message: result.message };
                    // <<< MODIFIED FOR REDIRECT >>>
                    setTimeout(() => {
                        // Redirect to the same page but ensure the 'events' tab is specified
                        window.location.href = window.location.pathname + '?tab=events';
                    }, 1500); // Delay to show notification
                } else {
                    this.eventErrorMessage = result.message || (result.errors ? result.errors.join(', ') : 'An unknown error occurred.');
                    this.eventFormError = true;
                     this.$nextTick(() => {
                         const el = document.getElementById('modal-event-error-alert');
                         if(el && window.getComputedStyle(el).display !== 'none') el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                     });
                }
            } catch (error) {
                console.error('Admin Event form submission error:', error);
                this.eventErrorMessage = error.message || 'An error occurred during submission.';
                this.eventFormError = true;
                 this.$nextTick(() => {
                     const el = document.getElementById('modal-event-error-alert');
                     if(el && window.getComputedStyle(el).display !== 'none') el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                 });
            } finally {
                this.isEventProcessing = false;
            }
        },

        async deleteEvent() {
            if (!this.eventToDeleteId) return;
            console.log('Admin Delete request started for event ID:', this.eventToDeleteId);
            this.isEventDeleting = true;
            this.eventFormError = false; this.eventErrorMessage = '';
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('event_id', this.eventToDeleteId);
            formData.append('csrf_token', '<?= $csrf_token ?>');

            try {
                const response = await fetch('/actions/handle_event.php', { method: 'POST', body: formData, headers: { 'Accept': 'application/json' } });
                if (!response.ok) { let errorData; try { errorData = await response.json(); } catch (e) { errorData = { message: `Server error: ${response.status} ${response.statusText}` }; } throw new Error(errorData.message || 'An unknown server error occurred during deletion.'); }
                const result = await response.json();
                if (result.status === 'success') {
                    this.showEventDeleteConfirm = false;
                    this.notification = { show: true, type: 'success', message: result.message };
                    // <<< MODIFIED FOR REDIRECT >>>
                    setTimeout(() => {
                        // Redirect to the same page but ensure the 'events' tab is specified
                        window.location.href = window.location.pathname + '?tab=events';
                    }, 1500); // Delay to show notification
                } else {
                    this.eventErrorMessage = result.message || 'Failed to delete the event.';
                    this.eventFormError = true; // Show error in delete modal
                    // Scroll error into view if applicable (might need adjustment depending on modal structure)
                    this.$nextTick(() => {
                         const el = document.getElementById('delete-event-error-alert');
                         if(el && window.getComputedStyle(el).display !== 'none') el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                     });
                }
            } catch (error) {
                console.error('Delete request error:', error);
                this.eventErrorMessage = error.message || 'An error occurred while trying to delete the event.';
                this.eventFormError = true; // Show error in modal
                this.$nextTick(() => {
                         const el = document.getElementById('delete-event-error-alert');
                         if(el && window.getComputedStyle(el).display !== 'none') el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                 });
            } finally {
                this.isEventDeleting = false;
            }
        },

        resetEventForm() {
            const firstLocationId = locationsData.length > 0 ? locationsData[0].id : '';
            const firstCategoryId = categoriesData.length > 0 ? categoriesData[0].id : '';
            this.currentEvent = { id: '', title: '', event_date: '', event_time: '', city_id: firstLocationId, category_id: firstCategoryId, venue: '', price: '0', audience: '', description: '', tags: [], is_online: '0', google_map_link: '', attention: '', terms_and_conditions: '', participant_limit: null, image_url: null };
            this.eventImagePreview = null; this.isEventFree = true; this.activeEventTab = 'details'; this.eventFormError = false; this.eventErrorMessage = ''; this.isEventProcessing = false;
            const fileInput = this.$refs.eventForm?.querySelector('input[name=event_image]'); if (fileInput) fileInput.value = null;
        },

        initEventModal(isEditMode = false, eventData = null) {
            this.openEventModal = true; this.editEventMode = isEditMode; this.eventFormError = false; this.eventErrorMessage = '';
            if (isEditMode && eventData) {
                this.currentEvent = JSON.parse(JSON.stringify(eventData));
                const priceValue = parseFloat(this.currentEvent.price); this.isEventFree = !isNaN(priceValue) && priceValue === 0; this.currentEvent.price = this.currentEvent.price !== null ? String(this.currentEvent.price) : '0';
                if (this.currentEvent && typeof this.currentEvent.tag_ids === 'string' && this.currentEvent.tag_ids) { this.currentEvent.tags = this.currentEvent.tag_ids.split(',').map(t => t.trim()).filter(t => t); } else { this.currentEvent.tags = []; }
                this.currentEvent.category_id = this.currentEvent.category_id ? Number(this.currentEvent.category_id) : null; this.currentEvent.city_id = this.currentEvent.city_id ? Number(this.currentEvent.city_id) : null; this.currentEvent.is_online = String(this.currentEvent.is_online ?? '0'); this.currentEvent.participant_limit = this.currentEvent.participant_limit !== null ? Number(this.currentEvent.participant_limit) : null; this.currentEvent.google_map_link = this.currentEvent.google_map_link || ''; this.currentEvent.attention = this.currentEvent.attention || ''; this.currentEvent.terms_and_conditions = this.currentEvent.terms_and_conditions || '';
                if (this.currentEvent.image_url && this.currentEvent.image_url !== 'default-event.jpg') { this.eventImagePreview = this.currentEvent.image_url.startsWith('http') || this.currentEvent.image_url.startsWith('/') ? this.currentEvent.image_url : `/assets/images/${this.currentEvent.image_url}`; } else { this.eventImagePreview = null; }
            } else { this.resetEventForm(); }
            this.activeEventTab = 'details';
        },

        confirmEventDelete(eventId, eventTitle) {
            this.eventToDeleteId = eventId; this.eventToDeleteTitle = eventTitle; this.eventFormError = false; this.eventErrorMessage = ''; this.showEventDeleteConfirm = true;
        },
        // --- END Event Methods ---

        // --- Category Methods ---
        resetCategoryForm() {
            this.currentCategory = { id: null, name: '', description: '' };
            this.categoryFormError = false;
            this.categoryErrorMessage = '';
            this.isCategoryProcessing = false;
        },

        initCategoryModal(isEdit = false, category = null) {
            this.resetCategoryForm();
            this.editCategoryMode = isEdit;
            if (isEdit && category) {
                this.currentCategory = JSON.parse(JSON.stringify(category));
            }
            this.showCategoryModal = true;
        },

        async submitCategoryForm() {
            if (!this.currentCategory.name.trim()) { this.categoryFormError = true; this.categoryErrorMessage = 'Category name cannot be empty.'; return; }
            this.isCategoryProcessing = true; this.categoryFormError = false; this.categoryErrorMessage = '';
            const formData = new FormData();
            formData.append('action', this.editCategoryMode ? 'update' : 'create');
            formData.append('csrf_token', '<?= $csrf_token ?>');
            formData.append('name', this.currentCategory.name);
            // formData.append('description', this.currentCategory.description); // Uncomment if using description
            if (this.editCategoryMode && this.currentCategory.id) { formData.append('category_id', this.currentCategory.id); }
            try {
                const response = await fetch('/actions/handle_category.php', { method: 'POST', body: formData, headers: { 'Accept': 'application/json' } });
                const result = await response.json();
                if (!response.ok) { throw new Error(result.message || `Server error: ${response.status}`); }
                if (result.status === 'success') {
                    this.showCategoryModal = false;
                    this.notification = { show: true, type: 'success', message: result.message };
                     // <<< MODIFIED FOR REDIRECT >>>
                    setTimeout(() => {
                         window.location.href = window.location.pathname + '?tab=categories';
                    }, 1500);
                } else { this.categoryErrorMessage = result.message || 'An unknown error occurred.'; this.categoryFormError = true; }
            } catch (error) { console.error('Category form error:', error); this.categoryErrorMessage = error.message || 'An unexpected error occurred.'; this.categoryFormError = true;
            } finally { this.isCategoryProcessing = false; }
        },

        confirmCategoryDelete(category) {
             if (!category || category.id == null) { this.notification = { show: true, type: 'error', message: 'Cannot delete category: Invalid data.'}; setTimeout(() => this.notification.show = false, 4000); return; }
            this.categoryToDelete = category; this.categoryFormError = false; this.categoryErrorMessage = ''; this.showCategoryDeleteConfirm = true;
        },

        async deleteCategory() {
            if (!this.categoryToDelete || this.categoryToDelete.id == null) return;
            this.isCategoryDeleting = true; this.categoryFormError = false; this.categoryErrorMessage = '';
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('category_id', this.categoryToDelete.id);
            formData.append('csrf_token', '<?= $csrf_token ?>');
            try {
                const response = await fetch('/actions/handle_category.php', { method: 'POST', body: formData, headers: { 'Accept': 'application/json' } });
                const result = await response.json();
                if (!response.ok) { throw new Error(result.message || `Server error: ${response.status}`); }
                if (result.status === 'success') {
                    this.showCategoryDeleteConfirm = false; this.categoryToDelete = null;
                    this.notification = { show: true, type: 'success', message: result.message };
                     // <<< MODIFIED FOR REDIRECT >>>
                    setTimeout(() => {
                        window.location.href = window.location.pathname + '?tab=categories';
                    }, 1500);
                } else {
                    this.categoryErrorMessage = result.message || 'Failed to delete category.';
                    this.categoryFormError = true; // Show error in delete modal
                }
            } catch (error) { console.error('Category delete error:', error); this.categoryErrorMessage = error.message || 'An error occurred during deletion.'; this.categoryFormError = true;
            } finally { this.isCategoryDeleting = false; }
        },

        // --- Tag Methods ---
        resetTagForm() {
            const firstCategoryId = categoriesData.length > 0 ? String(categoriesData[0].id) : ''; // Ensure string
            this.currentTag = { id: null, name: '', category_id: firstCategoryId }; // Default to first category
            this.tagFormError = false;
            this.tagErrorMessage = '';
            this.isTagProcessing = false;
        },
        initTagModal(isEdit = false, tag = null) {
            this.resetTagForm();
            this.editTagMode = isEdit;
            if (isEdit && tag) {
                // Ensure category_id is a string for the select model binding
                this.currentTag = { ...tag, category_id: String(tag.category_id) };
            } else {
                 // If adding, ensure category_id is a string if firstCategoryId is set
                 this.currentTag.category_id = this.currentTag.category_id ? String(this.currentTag.category_id) : '';
            }
            this.showTagModal = true;
        },
        async submitTagForm() {
             if (!this.currentTag.name.trim()) { this.tagFormError = true; this.tagErrorMessage = 'Tag name cannot be empty.'; return; }
             if (!this.currentTag.category_id) { this.tagFormError = true; this.tagErrorMessage = 'Please select a category.'; return; }

             this.isTagProcessing = true; this.tagFormError = false; this.tagErrorMessage = '';
             const formData = new FormData(this.$refs.tagForm); // Use the ref here
             // Action, CSRF, tag_id (if editing) are already in hidden inputs

             try {
                 const response = await fetch('/actions/handle_tag.php', { method: 'POST', body: formData, headers: { 'Accept': 'application/json' } });
                 const result = await response.json();
                 if (!response.ok) { throw new Error(result.message || `Server error: ${response.status}`); }

                 if (result.status === 'success') {
                     this.showTagModal = false;
                     this.notification = { show: true, type: 'success', message: result.message };
                     // <<< ALREADY CORRECT - RELOADS TO TAGS TAB >>>
                     setTimeout(() => { window.location.href = window.location.pathname + '?tab=tags'; }, 1500);
                 } else {
                     this.tagErrorMessage = result.message || 'An unknown error occurred.';
                     this.tagFormError = true;
                 }
             } catch (error) {
                 console.error('Tag form error:', error);
                 this.tagErrorMessage = error.message || 'An unexpected error occurred.';
                 this.tagFormError = true;
             } finally {
                 this.isTagProcessing = false;
             }
        },
        confirmTagDelete(tag) {
             if (!tag || tag.id == null) {
                this.notification = { show: true, type: 'error', message: 'Cannot delete tag: Invalid data.'};
                setTimeout(() => this.notification.show = false, 4000);
                return;
             }
             this.tagToDelete = tag;
             this.tagFormError = false; // Clear error from previous modal if any
             this.tagErrorMessage = '';
             this.showTagDeleteConfirm = true;
        },
        async deleteTag() {
             if (!this.tagToDelete || this.tagToDelete.id == null) return;

             this.isTagDeleting = true;
             this.tagFormError = false;
             this.tagErrorMessage = '';

             const formData = new FormData();
             formData.append('action', 'delete');
             formData.append('tag_id', this.tagToDelete.id);
             formData.append('csrf_token', '<?= $csrf_token ?>');

             try {
                 const response = await fetch('/actions/handle_tag.php', { method: 'POST', body: formData, headers: { 'Accept': 'application/json' } });
                 const result = await response.json();
                 if (!response.ok) { throw new Error(result.message || `Server error: ${response.status}`); }

                 if (result.status === 'success') {
                     this.showTagDeleteConfirm = false;
                     this.tagToDelete = null;
                     this.notification = { show: true, type: 'success', message: result.message };
                     // <<< ALREADY CORRECT - RELOADS TO TAGS TAB >>>
                     setTimeout(() => { window.location.href = window.location.pathname + '?tab=tags'; }, 1500);
                 } else {
                     // Display error message inside the delete confirmation modal
                     this.tagErrorMessage = result.message || 'Failed to delete tag.';
                     this.tagFormError = true; // Use this flag to show the error message div
                 }
             } catch (error) {
                 console.error('Tag delete error:', error);
                 this.tagErrorMessage = error.message || 'An error occurred during deletion.';
                 this.tagFormError = true; // Show error in modal
             } finally {
                 this.isTagDeleting = false;
             }
        },
        // --- END Tag Methods ---

        // --- Payout Management Methods ---
        async openPayoutModal(organizer) {
            if (!organizer || !organizer.id) {
                this.notification = { 
                    show: true, 
                    type: 'error', 
                    message: 'Cannot open payout modal: Invalid organizer data.'
                };
                setTimeout(() => this.notification.show = false, 4000);
                return;
            }

            // Set the selected organizer
            this.selectedOrganizerForPayout = organizer;
            this.isLoadingPendingEvents = true;
            this.pendingEventsForOrganizer = [];
            this.payoutModalError = null;
            this.showPayoutDetailsModal = true;

            try {
                // Fetch pending events for this organizer
                const response = await fetch(`/actions/get_organizer_pending_events.php?organizer_id=${organizer.id}`, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' }
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || `Error ${response.status}: Failed to fetch events`);
                }

                if (data.status === 'success') {
                    this.pendingEventsForOrganizer = data.events || [];
                } else {
                    throw new Error(data.message || 'Failed to load event data');
                }
            } catch (error) {
                console.error('Payout modal error:', error);
                this.payoutModalError = error.message || 'An error occurred while loading event data.';
            } finally {
                this.isLoadingPendingEvents = false;
            }
        },

        closePayoutModal() {
            this.showPayoutDetailsModal = false;
            this.selectedOrganizerForPayout = null;
            this.pendingEventsForOrganizer = [];
            this.payoutModalError = null;
        },

        async markEventAsPaid(eventId) {
            if (!eventId) return;
            
            this.isMarkingPaid = eventId; // Set loading state for this specific event
            this.payoutModalError = null;
            
            try {
                const formData = new FormData();
                formData.append('event_id', eventId);
                formData.append('action', 'mark_paid');
                formData.append('csrf_token', '<?= $csrf_token ?>');
                
                const response = await fetch('/actions/handle_payout.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json' }
                });
                
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.message || `Error ${response.status}: Failed to update status`);
                }
                
                if (data.status === 'success') {
                    // Update the local event status
                    const eventIndex = this.pendingEventsForOrganizer.findIndex(e => e.id == eventId);
                    if (eventIndex > -1) {
                        this.pendingEventsForOrganizer[eventIndex].payout_status = 'paid';
                    }
                    
                    this.notification = {
                        show: true,
                        type: 'success',
                        message: data.message || 'Event marked as paid successfully.'
                    };
                    setTimeout(() => this.notification.show = false, 4000);
                } else {
                    throw new Error(data.message || 'Failed to update payout status');
                }
            } catch (error) {
                console.error('Mark as paid error:', error);
                this.payoutModalError = error.message || 'An error occurred while updating the payout status.';
            } finally {
                this.isMarkingPaid = false;
            }
        }
        // --- END Payout Management Methods ---

    }" 
    x-init="initializeTabs()"
    x-effect="performUserFilter(); filteredTags;"
>

        <!-- Global Notification Banner -->
        <div x-show="notification.show" x-cloak
            x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 transform -translate-y-2" x-transition:enter-end="opacity-100 transform translate-y-0"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 transform translate-y-0" x-transition:leave-end="opacity-0 transform -translate-y-2"
            class="mb-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 p-4 rounded-md shadow-md border-l-4 fixed top-5 right-5 z-[150] min-w-[300px]"
            :class="{ 'bg-green-100 border-green-500 text-green-700': notification.type === 'success', 'bg-red-100 border-red-500 text-red-700': notification.type === 'error' }" role="alert">
            <div class="flex items-center">
                <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><template x-if="notification.type === 'success'"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></template><template x-if="notification.type === 'error'"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></template></svg>
                <p class="font-medium" x-text="notification.message"></p>
                <button @click="notification.show = false" class="ml-auto -mx-1.5 -my-1.5 bg-transparent rounded-lg p-1.5 inline-flex h-8 w-8" :class="{ 'text-green-600 hover:bg-green-200': notification.type === 'success', 'text-red-600 hover:bg-red-200': notification.type === 'error' }"><span class="sr-only">Close</span>×</button>
            </div>
        </div>
        <!-- End Global Notification Banner -->

        <!-- PHP message display (for redirects) -->
        <?php if ($success_message): ?>
            <div role="alert" class="mb-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md shadow-sm fade-in"><p class="font-medium"><?= htmlspecialchars($success_message) ?></p></div>
            <script>
                // Automatically hide PHP success message after a delay
                setTimeout(() => {
                    const alert = document.querySelector('[role="alert"].bg-green-100');
                    if (alert) {
                        alert.style.transition = 'opacity 0.5s ease-out';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 4000); // Hide after 4 seconds
            </script>
        <?php endif; ?>
        <?php if ($error_message && !$dashboard_error): ?>
            <div role="alert" class="mb-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md shadow-sm fade-in"><p class="font-medium"><?= htmlspecialchars($error_message) ?></p></div>
             <script>
                // Automatically hide PHP error message after a delay
                setTimeout(() => {
                    const alert = document.querySelector('[role="alert"].bg-red-100');
                    // Exclude the dashboard load error message
                    if (alert && !alert.textContent.includes('Error loading dashboard data')) {
                        alert.style.transition = 'opacity 0.5s ease-out';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 5000); // Hide after 5 seconds
            </script>
        <?php endif; ?>
        <?php if ($dashboard_error): ?>
            <div role="alert" class="mb-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md shadow-sm fade-in"><p class="font-medium">Error loading dashboard data:</p><p><?= htmlspecialchars($dashboard_error) ?></p></div>
        <?php endif; ?>
        <!-- End PHP message display -->

        <!-- Edit User Modal -->
        <div x-show="showUserModal" x-cloak class="fixed inset-0 bg-black/50 z-[100] flex items-center justify-center p-4 backdrop-blur-sm"
             x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 relative transform transition-all scale-95 modal-content"
                @click.outside="showUserModal = false"
                x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">

                <!-- Header -->
                <div class="flex justify-between items-center mb-6 pb-4 border-b border-gray-100">
                    <h3 class="text-2xl font-bold text-gray-900">Edit User Profile</h3>
                    <button @click="showUserModal = false" class="text-gray-400 hover:text-gray-500 transition-colors duration-200"><svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <!-- Form -->
                <template x-if="selectedUser">
                     <form @submit.prevent="
                        isSaving = true;
                        fetch('/actions/update_user.php', { method: 'POST', body: new FormData($event.target), headers: { 'Cache-Control': 'no-cache', 'Accept': 'application/json' }}) // <<< Ensure Accept header
                        .then(async res => {
                            const text = await res.text();
                            try { const data = JSON.parse(text); if (!res.ok) throw new Error(data.message || `Server error: ${res.status} ${res.statusText}`); return data; }
                            catch (e) { console.error('Error parsing JSON:', text); if (e instanceof Error) throw e; throw new Error(text || 'Invalid server response.'); }
                        })
                        .then(data => {
                            if (data.success) {
                                // Update the user in the local Alpine array (optional but good UX)
                                const userIndex = users.findIndex(u => u.id == selectedUser.id);
                                if (userIndex > -1 && data.user) { users[userIndex] = { ...users[userIndex], ...data.user }; }

                                showUserModal = false; // Close modal first
                                notification = { show: true, type: 'success', message: data.message || 'User updated successfully.' };
                                // <<< MODIFIED FOR REDIRECT >>>
                                setTimeout(() => { window.location.href = window.location.pathname + '?tab=users'; }, 1500); // Reload to users tab after delay
                            } else {
                                notification = { show: true, type: 'error', message: data.message || 'Update failed. Please try again.' };
                                setTimeout(() => notification.show = false, 4000); // Hide error notification
                            }
                        })
                        .catch(error => {
                            console.error('Update User Error:', error);
                            notification = { show: true, type: 'error', message: error.message || 'An unexpected error occurred during update.' };
                            setTimeout(() => notification.show = false, 5000); // Hide error notification
                        })
                        .finally(() => isSaving = false)">
                        <input type="hidden" name="user_id" :value="selectedUser.id">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"> <!-- Added CSRF token -->
                        <div class="space-y-6">
                            <!-- Profile Pic Upload -->
                            <div class="flex flex-col items-center group"><div class="relative cursor-pointer"><div class="relative h-32 w-32 rounded-2xl bg-gray-50 border-2 border-dashed border-gray-200 overflow-hidden transition-all hover:border-purple-300"><img :src="selectedUser.profile_image ? (selectedUser.profile_image.startsWith('http') || selectedUser.profile_image.startsWith('/') ? selectedUser.profile_image : `/assets/images/profiles/${selectedUser.profile_image}`) : '/assets/images/default-avatar.jpg'" @error="$el.src='/assets/images/default-avatar.jpg'" class="h-full w-full object-cover" id="profileImagePreview"><input type="file" name="profile_image" class="hidden" id="profileImageInput" accept="image/*" @change=" const file = $event.target.files[0]; if (file) { const reader = new FileReader(); reader.onload = (e) => { document.getElementById('profileImagePreview').src = e.target.result; }; reader.readAsDataURL(file); }"></div><label for="profileImageInput" class="absolute -bottom-2 -right-2 bg-white p-2 rounded-full shadow-sm border border-gray-200 hover:bg-gray-50 transition-colors"><svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/></svg></label></div><span class="mt-3 text-xs text-gray-500 font-medium">Click to change photo</span></div>
                            <!-- Form Fields -->
                            <div class="space-y-5">
                                <div class="space-y-2"><label class="block text-sm font-medium text-gray-700">Full Name</label><input type="text" name="name" x-model="selectedUser.name" required class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-3 focus:ring-purple-500/30 focus:border-purple-500 transition-all placeholder-gray-400"></div>
                                <div class="space-y-2"><label class="block text-sm font-medium text-gray-700">Email Address</label><input type="email" name="email" x-model="selectedUser.email" required class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-3 focus:ring-purple-500/30 focus:border-purple-500 transition-all placeholder-gray-400"></div>
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-gray-700">Account Type</label>
                                    <div class="relative">
                                        <select name="role" x-model="selectedUser.role" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-3 focus:ring-purple-500/30 focus:border-purple-500 transition-all appearance-none bg-select-arrow bg-no-repeat bg-[right_1rem_center] bg-[length:1.25rem]">
                                            <option value="user">Regular User</option><option value="organizer">Event Organizer</option><option value="admin">Administrator</option>
                                        </select>
                                    </div>
                                     <!-- Logic to determine organizer status based on selected role -->
                                     <input type="hidden" name="update_organizer_status"
                                            :value="selectedUser.role === 'organizer' ? 'approved' :
                                                    (selectedUser.role === 'user' ? (selectedUser.organizer_status === 'approved' || selectedUser.organizer_status === 'pending' || selectedUser.organizer_status === 'rejected' ? '' : selectedUser.organizer_status) :
                                                    selectedUser.organizer_status)">
                                     <!-- Explanation:
                                         - If role is 'organizer', set status to 'approved'.
                                         - If role is 'user', and current status was organizer-related, clear it (set to ''). Otherwise, keep original status (like null).
                                         - If role is 'admin', keep the original organizer_status (could be null, approved, pending etc. - admin role overrides).
                                     -->
                                </div>
                            </div>
                        </div>
                        <!-- Footer -->
                        <div class="mt-8 flex justify-end gap-3"><button type="button" @click="showUserModal = false" class="px-5 py-2.5 text-gray-600 hover:text-gray-800 hover:bg-gray-50 rounded-xl transition-all duration-200 font-medium">Cancel</button><button type="submit" :disabled="isSaving" class="px-5 py-2.5 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition-colors font-medium flex items-center gap-2.5 min-w-[120px] justify-center" :class="{'opacity-75 cursor-not-allowed': isSaving}"><svg x-show="isSaving" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span x-show="!isSaving">Save Changes</span></button></div>
                    </form>
                </template>
            </div>
        </div>
        <!-- End Edit User Modal -->

        <!-- Delete User Confirmation Modal -->
         <div x-show="showDeleteModal" x-cloak class="fixed inset-0 bg-black/50 z-[100] flex items-center justify-center p-4 backdrop-blur-sm"
             x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 relative transform transition-all scale-95"
                @click.outside="showDeleteModal = false"
                x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">
                <template x-if="userToDelete">
                    <div class="flex flex-col items-center text-center space-y-5">
                        <div class="p-4 bg-red-50 rounded-2xl mb-3"><svg class="w-12 h-12 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg></div>
                        <div class="space-y-2">
                            <h3 class="text-2xl font-bold text-gray-900">Delete User Account?</h3>
                            <p class="text-gray-600 leading-relaxed">Are you sure you want to permanently delete <span class="font-semibold text-red-600" x-text="userToDelete.name"></span>?<br>All associated data will be removed. This action cannot be undone.</p>
                        </div>
                        <div x-show="deleteError" x-cloak class="w-full mt-4 p-3 bg-red-50/90 border border-red-200 rounded-xl text-red-600 text-sm font-medium transition-all duration-300"><span x-text="deleteError"></span></div>
                        <div class="flex justify-end gap-3 w-full pt-6">
                            <button @click="showDeleteModal = false" :disabled="isDeleting" class="px-5 py-2.5 text-gray-600 hover:text-gray-800 hover:bg-gray-50 rounded-xl transition-all duration-200 font-medium">Cancel</button>
                             <!-- Use POST for delete with FormData -->
                            <button @click="
                                isDeleting = true; deleteError = null;
                                const formData = new FormData();
                                formData.append('user_id', userToDelete.id);
                                formData.append('csrf_token', '<?= $csrf_token ?>'); // Add CSRF token
                                fetch(`/actions/delete_user.php?user_id=${userToDelete.id}`, { method: 'POST', body: formData, headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' } }) // Use POST, user_id in URL
                                .then(async (res) => {
                                    const text = await res.text();
                                    if (!res.ok) { try { const errorData = JSON.parse(text); throw new Error(errorData.message || `Server error: ${res.status}`); } catch { throw new Error(text || `Server error: ${res.status}`); } }
                                    try { return JSON.parse(text); } catch { return { success: true, message: text || 'User deleted successfully.' }; } // Handle potential non-JSON success response
                                })
                                .then(data => {
                                    if (data.success) {
                                        // Remove user from local Alpine array (optional but good UX)
                                        users = users.filter(u => u.id !== userToDelete.id);
                                        showDeleteModal = false;
                                        notification = { show: true, type: 'success', message: data.message };
                                        // <<< MODIFIED FOR REDIRECT >>>fetch(`/actions/delete_user.php?user_id=${userToDelete.id}`, { method: 'POST', body: formData, headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' } }) // Use POST, user_id in URL
                                        setTimeout(() => { window.location.href = window.location.pathname + '?tab=users'; }, 1500); // Reload to users tab
                                    } else {
                                        deleteError = data.message || 'Deletion failed.';
                                    }
                                })
                                .catch(error => {
                                    console.error('Deletion Error:', error);
                                    deleteError = error.message || 'An error occurred during deletion.';
                                })
                                .finally(() => isDeleting = false)"
                            :disabled="isDeleting" class="px-5 py-2.5 bg-red-600 text-white rounded-xl hover:bg-red-700 transition-colors font-medium flex items-center gap-2.5 min-w-[140px] justify-center" :class="{'opacity-75 cursor-not-allowed': isDeleting}">
                                <svg x-show="isDeleting" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                <span x-show="!isDeleting">Confirm Delete</span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
        <!-- End Delete User Modal -->

        <!-- Admin Navigation -->
        <div class="bg-white shadow-sm rounded-lg mb-8 border border-gray-200">
           <nav class="flex flex-wrap gap-2 p-4">
                <!-- Added @click handlers to update URL without full reload -->
                <button @click="activeTab = 'users'; window.history.pushState({}, '', '?tab=users')" :class="activeTab === 'users' ? 'bg-purple-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-50'" class="px-4 py-2 rounded-md font-medium transition-all flex items-center gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Users (<?= count($users) ?>)</button>
                <button @click="activeTab = 'events'; window.history.pushState({}, '', '?tab=events')" :class="activeTab === 'events' ? 'bg-purple-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-50'" class="px-4 py-2 rounded-md font-medium transition-all flex items-center gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Events (<?= count($events) ?>)</button>
                <button @click="activeTab = 'categories'; window.history.pushState({}, '', '?tab=categories')" :class="activeTab === 'categories' ? 'bg-purple-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-50'" class="px-4 py-2 rounded-md font-medium transition-all flex items-center gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>Categories (<?= count($categories) ?>)</button>
                <button @click="activeTab = 'tags'; window.history.pushState({}, '', '?tab=tags')" :class="activeTab === 'tags' ? 'bg-purple-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-50'" class="px-4 py-2 rounded-md font-medium transition-all flex items-center gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>Tags (<?= count($tags) ?>)</button>
                <button @click="activeTab = 'review'; window.history.pushState({}, '', '?tab=review')" :class="activeTab === 'review' ? 'bg-purple-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-50'" class="px-4 py-2 rounded-md font-medium transition-all flex items-center gap-2 relative"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Review Applications<?php if ($pending_applications_count > 0): ?><span class="absolute -top-2 -right-2 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-red-100 bg-red-600 rounded-full"><?= $pending_applications_count ?></span><?php endif; ?></button>
                <button @click="activeTab = 'earnings'; window.history.pushState({}, '', '?tab=earnings')"
                        :class="activeTab === 'earnings' ? 'bg-purple-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-50'"
                        class="px-4 py-2 rounded-md font-medium transition-all flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    Earnings Overview
                </button>
            </nav>
        </div>

        <!-- Users Tab -->
        <div x-show="activeTab === 'users'" class="bg-white shadow rounded-lg p-6 fade-in">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold">User Management</h2>
                <div class="flex gap-4">
                    <div class="relative w-64"><input type="text" x-model="searchQuery" placeholder="Search users..." class="pl-10 pr-4 py-2.5 border-2 border-gray-200 rounded-xl focus:border-purple-500 focus:ring-2 focus:ring-purple-200 hover:border-purple-300 transition-all duration-200 w-full text-gray-700 placeholder-gray-400"><svg class="w-5 h-5 absolute left-3 top-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg></div>
                    <div class="relative w-48"><select x-model="selectedRole" class="w-full pl-4 pr-10 py-2.5 border-2 border-gray-200 rounded-xl focus:border-purple-500 focus:ring-2 focus:ring-purple-200 hover:border-purple-300 transition-all cursor-pointer appearance-none bg-select-arrow"><option value="">All Roles</option><option value="admin">Administrators</option><option value="organizer">Organizers</option><option value="user">Regular Users</option></select></div>
                </div>
            </div>
            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Activity</th><th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        <template x-for="user in filteredUsers" :key="user.id">
                            <tr class="hover:bg-gray-50 transition-colors cursor-pointer" @click="showUserModal = true; selectedUser = { ...user, is_admin: user.is_admin === 1 }">
                                <td class="px-6 py-4"><div class="flex items-center"><img :src="user.profile_image ? (user.profile_image.startsWith('http') || user.profile_image.startsWith('/') ? user.profile_image : '/assets/images/profiles/' + user.profile_image) : '/assets/images/profiles/default-avatar.jpg'" @error="$el.src='/assets/images/profiles/default-avatar.jpg'" class="h-10 w-10 rounded-full object-cover shadow-sm ring-2 ring-white"><div class="ml-4"><div class="font-medium text-gray-900" x-text="user.name"></div><div class="text-sm text-gray-500" x-text="user.email"></div></div></div></td>
                                <td class="px-6 py-4"><div class="flex items-center"><span class="relative flex h-3 w-3 mr-3"><span class="animate-ping absolute inline-flex h-full w-full rounded-full" :class="user.last_login ? 'bg-green-400' : 'bg-gray-400'"></span><span class="relative inline-flex rounded-full h-3 w-3" :class="user.last_login ? 'bg-green-500' : 'bg-gray-500'"></span></span><span class="text-sm font-medium" :class="{'text-green-700': user.last_login, 'text-yellow-700': !user.last_login && user.organizer_status === 'pending', 'text-red-700': !user.last_login && user.organizer_status === 'rejected', 'text-gray-600': !user.last_login && (user.organizer_status === null || user.organizer_status === 'approved') }" x-text="user.last_login ? 'Active' : (user.organizer_status === 'pending' ? 'Pending' : (user.organizer_status === 'rejected' ? 'Rejected' : 'Inactive'))"></span></div></td>
                                <td class="px-6 py-4"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="{'bg-purple-100 text-purple-800': user.role === 'admin', 'bg-green-100 text-green-800': user.role === 'organizer', 'bg-yellow-100 text-yellow-800': user.organizer_status === 'pending', 'bg-red-100 text-red-800': user.organizer_status === 'rejected', 'bg-gray-100 text-gray-600': user.role === 'user' && user.organizer_status === null}" x-text="user.role === 'admin' ? 'Admin' : (user.role === 'organizer' ? 'Organizer' : (user.organizer_status === 'pending' ? 'Pending Applicant' : (user.organizer_status === 'rejected' ? 'Rejected Applicant' : 'User')))"></span></td>
                                <td class="px-6 py-4 text-sm text-gray-500"><span x-text="user.last_login ? new Date(user.last_login).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'Never logged in'"></span></td>
                                <td class="px-6 py-4 text-right space-x-2"><button @click.stop="showUserModal = true; selectedUser = { ...user, is_admin: user.is_admin === 1 }" class="text-purple-600 hover:text-purple-900 p-2 rounded-lg hover:bg-purple-50 transition-colors" title="Edit User"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg></button><button @click.stop="userToDelete = user; showDeleteModal = true;" class="text-red-600 hover:text-red-900 p-2 rounded-lg hover:bg-red-50 transition-colors" title="Delete User"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button></td>
                            </tr>
                        </template>
                         <template x-if="!isLoading && filteredUsers.length === 0">
                             <tr>
                                 <td colspan="5" class="text-center py-6 text-gray-500">
                                     <span x-show="searchQuery === '' && selectedRole === ''">No users found.</span>
                                     <span x-show="searchQuery !== '' || selectedRole !== ''">No users match your search criteria.</span>
                                 </td>
                             </tr>
                         </template>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- End Users Tab -->

        <!-- Events Tab -->
        <div x-show="activeTab === 'events'" class="bg-white shadow rounded-lg p-6 fade-in">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold">Event Management</h2>
                <div class="flex gap-4 items-center">
                    <!-- Search Input for Events -->
                    <div class="relative"><input type="text" x-model="eventSearchQuery" placeholder="Search events..." class="pl-10 pr-4 py-2.5 border-2 border-gray-200 rounded-xl focus:border-purple-500 focus:ring-2 focus:ring-purple-200 hover:border-purple-300 transition-all duration-200 w-64 text-gray-700 placeholder-gray-400" aria-label="Search events"><svg class="w-5 h-5 absolute left-3 top-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg></div>
                    <!-- New Event Button -->
                    <button @click="initEventModal(false)" class="bg-purple-600 text-white px-4 py-2.5 rounded-xl hover:bg-purple-700 flex items-center gap-2 transition-all duration-200 hover:shadow-md active:scale-[0.98]"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>New Event</button>
                </div>
            </div>
             <!-- Events Grid/List -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                 <?php
                    // Prepare the PHP events array for efficient filtering in Alpine
                    $js_events_data = [];
                    foreach ($events as $event) {
                        $searchable_text = strtolower(implode(' ', [
                            $event['title'] ?? '',
                            $event['category'] ?? '',
                            $event['city'] ?? '',
                            $event['state'] ?? '',
                            $event['venue'] ?? '',
                        ]));
                        $event_js = $event;
                        $event_js['search_text'] = addslashes($searchable_text); // Add a combined searchable text field
                        $event_js['tags_arr'] = !empty($event['tag_ids']) ? explode(',', $event['tag_ids']) : [];
                        $event_js['category_id'] = $event['category_id'] ? (int)$event['category_id'] : null;
                        $event_js['city_id'] = $event['city_id'] ? (int)$event['city_id'] : null;
                        // <<< NEW: Add is_past flag >>>
                        $event_js['is_past'] = isset($event['event_date']) && strtotime($event['event_date']) < strtotime(date('Y-m-d'));
                        $js_events_data[] = $event_js;
                    }
                 ?>
                 <script>
                     const eventsData = <?= json_encode($js_events_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
                 </script>

                 <template x-for="event in eventsData.filter(e => !eventSearchQuery || e.search_text.includes(eventSearchQuery.toLowerCase()))" :key="event.id">
                 <div class="bg-white rounded-lg shadow-sm border border-gray-200 hover:shadow-lg transition-all overflow-hidden group flex flex-col" :class="{ 'opacity-70 hover:opacity-90': event.is_past }">
                        <!-- Image and Badges -->
                        <div class="relative">
                        <img :src="`/assets/images/${event.image_url || 'default-event.jpg'}`" :alt="event.title" @error="$el.src='/assets/images/default-event.jpg'" class="w-full h-48 object-cover transform group-hover:scale-105 transition-transform duration-300" :class="{ 'filter grayscale group-hover:grayscale-0': event.is_past }">
                            <span class="absolute top-2 right-2 bg-purple-100 text-purple-800 text-xs font-medium px-2.5 py-1 rounded-full shadow-sm" x-text="event.category || 'N/A'"></span>
                            <div class="absolute top-2 left-2 flex flex-wrap gap-1.5">
                                <template x-if="event.is_past">
                                    <span class="order-first px-2 py-0.5 bg-gray-500 text-white text-xs font-medium rounded-full flex items-center shadow-sm">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        Past
                                    </span>
                                </template>
                                <template x-if="event.is_online == 1"><span class="px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full flex items-center shadow-sm"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 18.364a9 9 0 010-12.728M18.364 5.636a9 9 0 010 12.728m-9.9-1.414a5 5 0 010-7.072m7.072 0a5 5 0 010 7.072M12 12h.01"/></svg> Online</span></template>
                                <span class="px-2 py-0.5 text-xs rounded-full shadow-sm" :class="parseFloat(event.price) > 0 ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'" x-text="parseFloat(event.price) > 0 ? `RM${parseFloat(event.price).toFixed(2)}` : 'Free'"></span>
                            </div>
                        </div>
                        <!-- Content -->
                        <div class="p-4 flex flex-col flex-grow space-y-2">
                            <h3 class="font-semibold text-lg text-gray-800 truncate" :title="event.title" x-text="event.title"></h3>
                            <div class="flex items-center text-xs text-gray-500">
                                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <span x-text="`${new Date(event.event_date).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })} @ ${new Date(`1970-01-01T${event.event_time}`).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}`"></span>
                            </div>
                            <div class="flex items-center text-xs text-gray-500">
                                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <span class="truncate" :title="event.is_online == 1 ? 'Online Event' : `${event.venue || 'N/A'}, ${event.city || 'N/A'}`" x-text="event.is_online == 1 ? 'Online Event' : `${event.city || 'N/A'}, ${event.state || 'N/A'}`"></span>
                            </div>
                        </div>
                        <!-- Footer Actions -->
                        <div class="mt-auto p-4 pt-2 border-t border-gray-100 flex justify-end gap-2">
                            <!-- Pass the filtered event object directly -->
                            <button @click="initEventModal(true, event)" class="text-purple-600 hover:text-purple-900 p-2 rounded-lg hover:bg-purple-50 transition-colors" title="Edit Event"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg></button>
                            <!-- Delete button removed -->
                        </div>
                    </div>
                 </template>

                 <!-- Empty state for Events -->
                <template x-if="!isLoading && eventsData.filter(e => !eventSearchQuery || e.search_text.includes(eventSearchQuery.toLowerCase())).length === 0">
                     <p class="col-span-full text-center text-gray-500 py-10 text-lg">
                         <span x-show="eventSearchQuery === ''">No events found.</span>
                         <span x-show="eventSearchQuery !== ''">No events match your search criteria.</span>
                     </p>
                 </template>
            </div>
        </div>
        <!-- End Events Tab -->

        <!-- Categories Tab -->
        <div x-show="activeTab === 'categories'" class="bg-white shadow rounded-lg p-6 fade-in">
             <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-6">
                 <h2 class="text-2xl font-bold text-gray-800">Category Management</h2>
                 <!-- Optional Search - Keep commented for now -->
                 <!-- <div class="relative flex-grow max-w-xs"><input type="text" x-model="categorySearchQuery" ...></div> -->
                 <button @click="initCategoryModal(false)" class="w-full md:w-auto bg-purple-600 text-white px-4 py-2.5 rounded-xl hover:bg-purple-700 flex items-center justify-center gap-2 transition-all duration-200 hover:shadow-md active:scale-[0.98]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Category
                 </button>
             </div>
             <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                 <!-- Use categoriesData directly since search is commented out -->
                 <template x-for="category in categoriesData" :key="category.id">
                      <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl border border-gray-200 p-5 flex flex-col justify-between hover:shadow-md hover:border-purple-200 transition-all group">
                          <div class="flex-1 mb-3">
                              <h3 class="font-semibold text-lg text-gray-800 mb-1 flex items-center gap-2">
                                  <svg class="w-5 h-5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"></path></svg>
                                  <span x-text="category.name"></span>
                              </h3>
                              <!-- Add description display if needed -->
                              <!-- <p class="text-sm text-gray-600 line-clamp-2" x-text="category.description || 'No description'"></p> -->
                              <div class="text-xs text-gray-500 mt-2">
                                  <span class="inline-block bg-purple-100 text-purple-800 px-2.5 py-1 rounded-full font-medium">
                                    <span x-text="tagsData.filter(t => t.category_id == category.id).length"></span> tags
                                  </span>
                              </div>
                          </div>
                          <div class="flex justify-end space-x-2 pt-3 border-t border-gray-100">
                               <!-- Edit Button -->
                               <button @click="initCategoryModal(true, category)" class="text-blue-600 hover:text-blue-800 p-1.5 rounded-lg hover:bg-blue-50 transition-colors" title="Edit Category">
                                   <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                               </button>
                               <!-- Delete Button -->
                               <button @click="confirmCategoryDelete(category)" class="text-red-600 hover:text-red-800 p-1.5 rounded-lg hover:bg-red-50 transition-colors" title="Delete Category">
                                   <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                               </button>
                          </div>
                      </div>
                 </template>

                 <!-- Empty State for Categories -->
                 <template x-if="!isLoading && categoriesData.length === 0">
                     <p class="col-span-full text-center text-gray-500 py-10 text-lg">No categories created yet.</p>
                 </template>
             </div>
        </div>
        <!-- End Categories Tab -->

        <!-- Tags Tab -->
        <div x-show="activeTab === 'tags'" class="bg-white shadow rounded-lg p-6 fade-in">
             <?php // Calculate tag counts ONCE in PHP
                $tagCounts = [];
                foreach($event_tags as $et) {
                    $tagCounts[$et['tag_id']] = ($tagCounts[$et['tag_id']] ?? 0) + 1;
                }
             ?>
             <script>
                 // Make counts available to JS
                 const tagUsageCounts = <?= json_encode($tagCounts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || {};
             </script>

             <!-- Tag Management Content (Filters, Button) -->
             <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Tag Management</h2>
                <div class="flex gap-4 items-center">
                     <!-- Search Tags -->
                     <div class="relative">
                         <input type="text" x-model="tagSearchQuery" placeholder="Search tags..." class="pl-11 pr-4 py-2.5 border-2 border-gray-200 rounded-xl focus:border-purple-500 focus:ring-2 focus:ring-purple-200 hover:border-purple-300 transition-all duration-200 w-64 text-gray-700 placeholder-gray-400" aria-label="Search tags">
                         <svg class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                     </div>
                     <!-- Filter by Category -->
                     <div class="relative w-48">
                         <select x-model="selectedTagCategoryFilter" class="w-full pl-4 pr-10 py-2.5 border-2 border-gray-200 rounded-xl focus:border-purple-500 focus:ring-2 focus:ring-purple-200 hover:border-purple-300 transition-all duration-200 cursor-pointer bg-white text-gray-700 font-medium shadow-sm hover:shadow-md appearance-none bg-select-arrow" aria-label="Filter by category">
                             <option value="">All Categories</option>
                             <?php foreach($categories as $category): ?>
                                 <option value="<?= $category['id'] ?>" class="text-gray-700 hover:bg-purple-50"><?= htmlspecialchars($category['name']) ?></option>
                             <?php endforeach; ?>
                         </select>
                     </div>
                     <!-- New Tag Button -->
                     <button @click="initTagModal(false)" class="bg-purple-600 text-white px-4 py-2.5 rounded-xl hover:bg-purple-700 flex items-center gap-2 transition-all duration-200 hover:shadow-md active:scale-[0.98]">
                         <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                         New Tag
                     </button>
                 </div>
             </div>
             <div class="overflow-x-auto rounded-lg border border-gray-200">
                 <table class="min-w-full striped-table hover-table">
                     <thead class="bg-gray-50">
                         <tr>
                             <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tag Name</th>
                             <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                             <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usage Count</th>
                             <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                         </tr>
                     </thead>
                     <tbody class="divide-y divide-gray-200">
                         <template x-for="tag in filteredTags" :key="tag.id">
                             <tr>
                                 <td class="px-6 py-4 font-medium text-gray-800" x-text="tag.name"></td>
                                 <td class="px-6 py-4">
                                     <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 hover:bg-purple-200 transition-colors" x-text="tag.category_name || 'N/A'"></span>
                                 </td>
                                 <!-- Use JS to display the count from the pre-calculated JS object -->
                                 <td class="px-6 py-4 text-gray-500" x-text="tagUsageCounts[tag.id] || 0"></td>
                                 <td class="px-6 py-4 text-right space-x-2">
                                     <!-- Pass the Alpine 'tag' object directly to the JS functions -->
                                     <button @click.stop="initTagModal(true, tag)" class="text-blue-600 hover:text-blue-900 px-3 py-1 rounded-md hover:bg-blue-50 transition-colors" title="Edit Tag">
                                         <svg class="w-4 h-4 inline-block align-text-bottom" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                          <span class="hidden sm:inline">Edit</span>
                                     </button>
                                     <button @click.stop="confirmTagDelete(tag)" class="text-red-600 hover:text-red-900 px-3 py-1 rounded-md hover:bg-red-50 transition-colors" title="Delete Tag">
                                         <svg class="w-4 h-4 inline-block align-text-bottom" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                         <span class="hidden sm:inline">Delete</span>
                                     </button>
                                 </td>
                             </tr>
                         </template>
                         <template x-if="!isLoading && filteredTags.length === 0">
                             <tr>
                                 <td colspan="4" class="text-center py-6 text-gray-500">
                                     <span x-show="tagSearchQuery === '' && selectedTagCategoryFilter === ''">No tags created yet.</span>
                                     <span x-show="tagSearchQuery !== '' || selectedTagCategoryFilter !== ''">No tags found matching your criteria.</span>
                                 </td>
                             </tr>
                         </template>
                     </tbody>
                 </table>
             </div>
        </div>
        <!-- End Tags Tab -->

        <!-- Review Applications Tab -->
        <div x-show="activeTab === 'review'" x-cloak id="review-section" class="fade-in">
            <h1 class="text-3xl font-bold text-gray-800 mb-6">Review Organizer Applications</h1>
            <?php if (empty($applications) && !$dashboard_error): ?>
                <div class="bg-white p-6 rounded-lg shadow text-center text-gray-500 border border-gray-200"><svg class="w-12 h-12 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>No pending applications found.</div>
            <?php elseif (!empty($applications)): ?>
                <div class="bg-white shadow-lg rounded-xl overflow-hidden border border-gray-200"><div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Applicant</th><th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Organization</th><th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submitted</th><th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Document</th><th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th></tr></thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($applications as $app): $appJson = htmlspecialchars(json_encode($app), ENT_QUOTES, 'UTF-8'); ?>
                                    <tr class="hover:bg-purple-50 transition-colors duration-150 group cursor-pointer" @click="selectedApplication = <?= $appJson ?>; showApplicationDetailsModal = true;" title="Click to view details for <?= htmlspecialchars($app['contact_person']) ?>">
                                        <td class="px-6 py-4 whitespace-nowrap"><div class="flex items-center"><div class="flex-shrink-0 h-10 w-10 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm font-semibold ring-2 ring-white shadow-sm"><?= strtoupper(substr($app['contact_person'] ?? 'U', 0, 1)) ?></div><div class="ml-3"><div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($app['contact_person']) ?></div><div class="text-xs text-gray-500"><?= htmlspecialchars($app['email']) ?></div></div></div></td>
                                        <td class="px-6 py-4"><div class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($app['organization']) ?></div><div class="text-xs text-gray-500"><?= htmlspecialchars($app['org_type']) ?></div></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('M d, Y', strtotime($app['created_at'])) ?><div class="text-xs text-gray-400"><?= date('H:i', strtotime($app['created_at'])) ?></div></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center"><?php if ($app['verification_doc']): $doc_link = '/admin/view_verification_doc.php?user_id=' . $app['id']; ?><a href="<?= $doc_link ?>" target="_blank" @click.stop class="inline-flex items-center px-2.5 py-1 border border-transparent text-xs font-medium rounded-full shadow-sm text-indigo-700 bg-indigo-100 hover:bg-indigo-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition" title="View: <?= htmlspecialchars($app['verification_doc']) ?>"><svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>Doc</a><?php else: ?><span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-600">None</span><?php endif; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium"><span class="text-purple-600 group-hover:text-purple-800 flex items-center justify-end">Details<svg class="w-4 h-4 ml-1 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                </div></div>
             <?php elseif ($dashboard_error): ?>
                <!-- Keep the dashboard error display if there was a loading issue -->
             <?php endif; ?>
        </div><!-- End Review Applications Tab -->

        <!-- Earnings Overview Tab -->
        <div x-show="activeTab === 'earnings'" class="bg-white shadow rounded-lg p-6 fade-in">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold">Organizer Earnings & Payouts</h2>
                <!-- Optional: Add filters here later if needed -->
            </div>

            <!-- Disclaimer -->
            <div class="mb-6 p-4 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 rounded-r-lg text-sm">
                <p><strong class="font-medium">Note:</strong> 'Potential Earnings' reflects total collected from paid registrations via the platform. 'Pending Events' are paid events whose payout status hasn't been marked as 'paid' by an admin.</p>
                <p class="mt-1">Actual payouts are processed manually. Use the 'Manage Payouts' feature to track which events have been settled.</p>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200 striped-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Organizer</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bank Details</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Potential Earnings (RM)</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider" title="Number of past events with price > 0 and payout status 'pending'">Past Events Pending Payout</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (!empty($organizer_earnings)): ?>
                            <?php foreach ($organizer_earnings as $earning):
                                // Prepare organizer data for Alpine click handler
                                $organizer_js_data = htmlspecialchars(json_encode([
                                    'id' => $earning['organizer_id'],
                                    'name' => $earning['organizer_name'],
                                    'pending_count' => $earning['past_pending_payout_events_count'] ?? 0 // Use the new count field
                                ]), ENT_QUOTES, 'UTF-8');
                            ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($earning['organizer_name']) ?></div>
                                        <div class="text-xs text-gray-500">ID: <?= htmlspecialchars($earning['organizer_id']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <div><?= htmlspecialchars($earning['payout_bank_name'] ?? 'N/A') ?></div>
                                        <div class="text-xs text-gray-500 font-mono"><?= htmlspecialchars($earning['payout_account_number'] ?? 'N/A') ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <span class="text-sm font-semibold <?= ($earning['total_potential_earnings'] ?? 0) > 0 ? 'text-green-700' : 'text-gray-600' ?>">
                                            <?= number_format($earning['total_potential_earnings'] ?? 0, 2) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <?php $pending_count = $earning['past_pending_payout_events_count'] ?? 0; // Use the new count field ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $pending_count > 0 ? 'bg-red-100 text-red-800 animate-pulse' : 'bg-green-100 text-green-800' ?>">
                                            <?= $pending_count ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <button type="button"
                                                @click="openPayoutModal(<?= $organizer_js_data ?>)"
                                                class="text-indigo-600 hover:text-indigo-900 hover:bg-indigo-50 px-3 py-1 rounded-md transition-colors text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                                                :disabled="<?= $pending_count <= 0 ? 'true' : 'false' ?>"
                                                title="Manage pending payouts for this organizer">
                                            Manage Payouts
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-6 text-gray-500">
                                    No approved organizers found or no earnings data available.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- End Earnings Overview Tab -->

        <!-- Reject Confirmation Modal -->
        <div x-show="showRejectModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-lg transform transition-all modal-content" @click.outside="showRejectModal = false" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">
                <!-- NOTE: This uses standard form submission, redirect handled by PHP -->
                <form action="/admin/process_application.php" method="POST" @submit="isSubmitting = true">
                    <input type="hidden" name="user_id" :value="rejectingUser?.id"><input type="hidden" name="action" value="reject"><input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="p-6"><div class="flex items-start justify-between mb-4"><div><h3 class="text-xl font-semibold text-gray-900">Reject Application</h3><p class="text-sm text-gray-600 mt-1">For: <strong x-text="rejectingUser?.contact_person || 'N/A'"></strong> (<span x-text="rejectingUser?.organization || 'N/A'"></span>)</p></div><button type="button" @click="showRejectModal = false; modalErrors = {}; rejectionNotes = '';" class="text-gray-400 hover:text-gray-600 transition p-1 -mt-1 -mr-1 rounded-full hover:bg-gray-100"><span class="sr-only">Close</span>×</button></div>
                        <template x-if="modalErrors && modalErrors.approval_notes"><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded-lg text-sm"><p x-text="modalErrors.approval_notes"></p></div></template>
                        <template x-if="modalErrors && modalErrors.generic"><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded-lg text-sm"><p x-text="modalErrors.generic"></p></div></template>
                        <div class="space-y-2"><label for="rejection_notes" class="block text-sm font-medium text-gray-700">Reason for Rejection <span class="text-red-500">*</span></label><textarea id="rejection_notes" name="approval_notes" x-model="rejectionNotes" rows="4" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm" :class="{ 'border-red-500 focus:border-red-500 focus:ring-red-500': modalErrors && modalErrors.approval_notes }" placeholder="Provide a clear reason for rejecting the application..."></textarea><p class="text-xs text-gray-500">This note will be visible to the applicant.</p></div>
                    </div>
                    <div class="bg-gray-50 px-6 py-4 flex justify-end gap-3 rounded-b-xl border-t border-gray-200"><button type="button" @click="showRejectModal = false; modalErrors = {}; rejectionNotes = '';" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">Cancel</button><button type="submit" :disabled="isSubmitting || !rejectionNotes.trim()" class="inline-flex justify-center items-center px-4 py-2 text-sm font-medium text-white bg-red-600 border border-transparent rounded-md shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition disabled:opacity-50" :class="{ 'cursor-not-allowed': isSubmitting || !rejectionNotes.trim() }"><svg x-show="isSubmitting" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span x-text="isSubmitting ? 'Processing...' : 'Confirm Rejection'"></span></button></div>
                </form>
            </div>
        </div>
        <!-- End Reject Modal -->

        <!-- Application Details Modal -->
        <div x-show="showApplicationDetailsModal" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4 modal-overlay" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl transform transition-all modal-content" @click.outside="showApplicationDetailsModal = false; selectedApplication = null;" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">
                <div class="flex justify-between items-start p-5 border-b border-gray-200 rounded-t-xl bg-gray-50"><div><h3 class="text-xl font-semibold text-gray-800" x-text="`Application: ${selectedApplication?.organization || 'N/A'}`"></h3><p class="text-sm text-gray-500" x-text="`Submitted: ${selectedApplication?.created_at ? new Date(selectedApplication.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'N/A'}`"></p></div><button type="button" @click="showApplicationDetailsModal = false; selectedApplication = null;" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center transition"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg></button></div>
                <div class="p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4"><div><label class="block text-xs font-medium text-gray-500 uppercase mb-1">Contact Person</label><p class="text-sm font-medium text-gray-800" x-text="selectedApplication?.contact_person || 'N/A'"></p></div><div><label class="block text-xs font-medium text-gray-500 uppercase mb-1">Contact Email</label><p class="text-sm text-gray-600 break-words" x-text="selectedApplication?.email || 'N/A'"></p></div><div><label class="block text-xs font-medium text-gray-500 uppercase mb-1">Organization Name</label><p class="text-sm font-medium text-gray-800" x-text="selectedApplication?.organization || 'N/A'"></p></div><div><label class="block text-xs font-medium text-gray-500 uppercase mb-1">Organization Type</label><span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700" x-text="selectedApplication?.org_type || 'N/A'"></span></div><div class="md:col-span-2"><label class="block text-xs font-medium text-gray-500 uppercase mb-1">Website</label><template x-if="selectedApplication?.website"><a :href="selectedApplication.website.startsWith('http') ? selectedApplication.website : '//' + selectedApplication.website" target="_blank" rel="noopener noreferrer" class="text-sm text-blue-600 hover:underline break-all" x-text="selectedApplication.website"></a></template><template x-if="!selectedApplication?.website"><p class="text-sm text-gray-400 italic">Not provided</p></template></div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Payout Bank Name</label>
                        <p class="text-sm font-medium text-gray-800" x-text="selectedApplication?.payout_bank_name || 'Not Provided'"></p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Payout Account Number</label>
                        <p class="text-sm font-medium text-gray-800" x-text="selectedApplication?.payout_account_number || 'Not Provided'"></p>
                    </div>
                    <div class="md:col-span-2"><label class="block text-xs font-medium text-gray-500 uppercase mb-1">Verification Document</label><template x-if="selectedApplication?.verification_doc"><a :href="`/admin/view_verification_doc.php?user_id=${selectedApplication.id}`" target="_blank" class="inline-flex items-center px-3 py-1 border border-indigo-300 text-xs font-medium rounded-md shadow-sm text-indigo-700 bg-indigo-50 hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-400 transition" title="View Document"><svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>View Submitted Document</a><span class="text-xs text-gray-400 ml-2" x-text="`(${selectedApplication.verification_doc})`"></span></template><template x-if="!selectedApplication?.verification_doc"><span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Missing Document</span></template></div></div>
                    <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1">Organization Description</label><div class="text-sm text-gray-700 bg-gray-50 p-3 rounded-md border border-gray-200 min-h-[100px]" style="white-space: pre-wrap;" x-text="selectedApplication?.organizer_description || 'No description provided.'"></div></div>
                </div>
                 <!-- NOTE: Both buttons use standard form submission, redirect handled by PHP -->
                <div class="flex items-center justify-end p-5 space-x-3 border-t border-gray-200 rounded-b-xl bg-gray-50">
                    <button type="button" @click="rejectingUser = selectedApplication; showRejectModal = true; showApplicationDetailsModal = false;" :disabled="isProcessingAction" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-red-600 border border-transparent rounded-md shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition disabled:opacity-50">Reject Application...</button>
                    <form action="/admin/process_application.php" method="POST" class="inline-block" @submit="isProcessingAction = true">
                        <input type="hidden" name="user_id" :value="selectedApplication?.id"><input type="hidden" name="action" value="approve"><input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <button type="submit" :disabled="isProcessingAction" class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-green-600 border border-transparent rounded-md shadow-sm hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition disabled:opacity-50 min-w-[100px]">
                            <svg x-show="isProcessingAction" class="animate-spin mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <span x-text="isProcessingAction ? 'Processing' : 'Approve'"></span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <!-- End Application Details Modal -->

        <!-- Event Form Modal (Create/Edit) -->
        <div x-show="openEventModal" x-cloak class="fixed inset-0 bg-black/30 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-all">
            <div class="bg-white rounded-2xl w-full max-w-4xl flex flex-col modal-container"
                style="height: 90vh;"
                @click.away="openEventModal = false"
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
                                <span x-text="editEventMode ? 'Edit Event' : 'New Event'"></span>
                            </h2>
                            <p class="text-sm text-gray-500">
                                <span x-text="editEventMode ? 'Update event details' : 'Fill in the form to create a new event'"></span>
                                <span x-show="editEventMode && currentEvent?.title" class="font-medium text-purple-700"> for '<span x-text="currentEvent.title"></span>'</span>
                            </p>
                        </div>
                        <button @click="openEventModal = false"
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
                                @click="activeEventTab = 'details'"
                                :class="activeEventTab === 'details' ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                class="py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-150">
                                Event Details
                            </button>
                            <button type="button"
                                @click="activeEventTab = 'settings'"
                                :class="activeEventTab === 'settings' ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                class="py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-150">
                                Event Settings
                            </button>
                        </nav>
                    </div>
                </div>

                <!-- Scrollable Content -->
                <div class="modal-scroll overflow-y-auto flex-1 px-8">

                <!-- Form Validation Alert (Unique ID for Event Modal) -->
                <div x-show="eventFormError && !isEventDeleting" x-cloak class="my-4 sticky top-0 z-10" id="modal-event-error-alert">
                    <div class="flex items-start gap-3 p-4 bg-red-50 border-l-4 border-red-400 rounded-r-lg shadow">
                        <svg class="w-5 h-5 text-red-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-red-800" x-text="eventErrorMessage"></p>
                        </div>
                        <button @click="eventFormError = false; eventErrorMessage = '';" class="text-red-500 hover:text-red-700 transition-colors" aria-label="Dismiss error">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Expiry Message -->
                <div x-show="editEventMode && isCurrentEventExpired" x-cloak class="my-4">
                        <div class="p-4 bg-yellow-50 border-l-4 border-yellow-400 rounded-r-lg text-sm text-yellow-800" role="alert">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 text-yellow-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                </svg>
                                <div>
                                    <p><strong class="font-medium">Event Expired:</strong> This event's date has passed.</p>
                                    <p class="mt-1">Updates are disabled for expired events. You can still view the details or choose to delete the event from the main list.</p>
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
                    @submit.prevent="submitEventForm"
                    novalidate>

                        <div class="flex-1 space-y-8 py-6"> <!-- Added py-6 -->
                            <input type="hidden" name="action" x-bind:value="editEventMode ? 'update' : 'create'">
                            <input type="hidden" name="event_id" x-bind:value="currentEvent ? currentEvent.id : ''">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                            <!-- Tab Content Container -->
                            <div x-show="currentEvent"> <!-- Ensure currentEvent is loaded before showing form -->
                                <!-- Details Tab -->
                                <div x-show="activeEventTab === 'details'" class="space-y-6">
                                    <!-- Event Title -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">Event Title <span class="text-red-500 ml-1">*</span></label>
                                        <div class="relative"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div><input type="text" name="title" required x-model="currentEvent.title" class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 placeholder-gray-400/80 text-sm transition-all hover:border-gray-300" placeholder="Tech Conference 2024"></div>
                                    </div>

                                    <!-- Event Type (Online/Offline) -->
                                     <div class="group">
                                         <label class="block text-sm font-medium text-gray-700 mb-2">Event Type <span class="text-red-500 ml-1">*</span></label>
                                         <div class="grid grid-cols-2 gap-4">
                                             <label :class="currentEvent.is_online == '0' ? 'border-purple-500 bg-purple-50 ring-1 ring-purple-500' : 'border-gray-200 hover:border-gray-300'" class="border-2 rounded-xl p-4 cursor-pointer transition-all duration-150">
                                                 <input type="radio" name="is_online" value="0" x-model="currentEvent.is_online" class="sr-only">
                                                 <div class="flex items-center space-x-3"><div class="shrink-0 w-5 h-5 rounded-full border-2 flex items-center justify-center" :class="currentEvent.is_online == '0' ? 'border-purple-600 bg-purple-600' : 'border-gray-300'"><div class="w-2 h-2 rounded-full bg-white" x-show="currentEvent.is_online == '0'"></div></div><div><p class="text-sm font-medium text-gray-800">In-Person Event</p><p class="text-xs text-gray-500">Physical location required</p></div></div>
                                             </label>
                                             <label :class="currentEvent.is_online == '1' ? 'border-purple-500 bg-purple-50 ring-1 ring-purple-500' : 'border-gray-200 hover:border-gray-300'" class="border-2 rounded-xl p-4 cursor-pointer transition-all duration-150">
                                                 <input type="radio" name="is_online" value="1" x-model="currentEvent.is_online" class="sr-only">
                                                 <div class="flex items-center space-x-3"><div class="shrink-0 w-5 h-5 rounded-full border-2 flex items-center justify-center" :class="currentEvent.is_online == '1' ? 'border-purple-600 bg-purple-600' : 'border-gray-300'"><div class="w-2 h-2 rounded-full bg-white" x-show="currentEvent.is_online == '1'"></div></div><div><p class="text-sm font-medium text-gray-800">Online Event</p><p class="text-xs text-gray-500">Virtual event link</p></div></div>
                                             </label>
                                         </div>
                                     </div>

                                    <!-- Date & Time -->
                                    <div class="space-y-3">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Date & Time <span class="text-red-500 ml-1">*</span></label>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div class="relative group"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><svg class="w-5 h-5 text-gray-400 group-focus-within:text-purple-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div><input type="date" name="event_date" required x-model="currentEvent.event_date" min="<?= date('Y-m-d') ?>" class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors"></div>
                                            <div class="relative group"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><svg class="w-5 h-5 text-gray-400 group-focus-within:text-purple-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><input type="time" name="event_time" required x-model="currentEvent.event_time" class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors"></div>
                                        </div>
                                    </div>

                                    <!-- Location / Online Link -->
                                    <div class="grid grid-cols-1 gap-6">
                                        <div x-show="currentEvent.is_online == '0'" x-transition class="group">
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Location (City/State)<span class="text-red-500 ml-1">*</span></label>
                                            <div class="relative"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><select name="city_id" x-model.number="currentEvent.city_id" :required="currentEvent.is_online == '0'" class="w-full pl-11 pr-10 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 bg-select-arrow appearance-none text-sm hover:border-gray-300 transition-colors"><option value="">Select Location</option><template x-for="location in locationsData" :key="location.id"><option :value="location.id" x-text="`${location.city}, ${location.state}`"></option></template></select></div>
                                        </div>
                                        <div class="group">
                                             <label class="block text-sm font-medium text-gray-700 mb-2"><span x-text="currentEvent.is_online == '1' ? 'Online Event Link' : 'Google Map Link'"></span><span class="text-red-500 ml-1">*</span></label>
                                            <div class="relative"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg></div><input type="url" name="google_map_link" required x-model="currentEvent.google_map_link" class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors" :placeholder="currentEvent.is_online == '1' ? 'https://zoom.us/j/...' : 'https://goo.gl/maps/...'"></div>
                                            <p x-show="currentEvent.is_online == '0'" class="text-xs text-gray-500 mt-1">Provide the Google Maps link for the venue.</p>
                                            <p x-show="currentEvent.is_online == '1'" class="text-xs text-gray-500 mt-1">Provide the link for attendees to join the online event.</p>
                                        </div>
                                    </div> <!-- End Location/Link Grid -->

                                    <!-- Category -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Category<span class="text-red-500 ml-1">*</span></label>
                                        <div class="relative"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg></div><select name="category_id" required x-model.number="currentEvent.category_id" @change="currentEvent.tags = []" class="w-full pl-11 pr-10 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 bg-select-arrow appearance-none text-sm hover:border-gray-300 transition-colors"><option value="">Select Category</option><template x-for="category in categoriesData" :key="category.id"><option :value="category.id" x-text="category.name"></option></template></select></div>
                                    </div>

                                     <!-- Category-Specific Tags -->
                                     <div class="group" x-data="{ allTags: tagsData }">
                                         <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">Event Tags <span class="text-gray-400 font-normal ml-2">(Optional, Max 5 tags)</span></label>
                                         <template x-if="currentEvent.category_id">
                                             <div class="space-y-2 p-4 border-2 border-gray-100 rounded-xl bg-gray-50/50">
                                                <p class="text-xs text-gray-500 mb-2">Select tags relevant to the '<span x-text="categoriesData.find(c => c.id == currentEvent.category_id)?.name || 'selected category'"></span>' category:</p>
                                                 <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                                     <template x-for="tag in allTags.filter(t => t.category_id == currentEvent.category_id)" :key="tag.id">
                                                         <label class="flex items-center space-x-2 bg-white border-2 rounded-lg p-3 cursor-pointer hover:border-purple-400 transition-colors text-xs" :class="{ 'border-purple-500 bg-purple-50 ring-1 ring-purple-200': currentEvent.tags && currentEvent.tags.includes(String(tag.id)), 'border-gray-200': !(currentEvent.tags && currentEvent.tags.includes(String(tag.id))), 'opacity-50 cursor-not-allowed': currentEvent.tags && currentEvent.tags.length >= 5 && !(currentEvent.tags.includes(String(tag.id))) }"><input type="checkbox" name="tags[]" x-model="currentEvent.tags" :value="String(tag.id)" :disabled="currentEvent.tags && currentEvent.tags.length >= 5 && !currentEvent.tags.includes(String(tag.id))" class="hidden"><div class="w-4 h-4 border-2 rounded flex items-center justify-center shrink-0" :class="currentEvent.tags && currentEvent.tags.includes(String(tag.id)) ? 'bg-purple-500 border-purple-500' : 'border-gray-300 bg-white'"><svg x-show="currentEvent.tags && currentEvent.tags.includes(String(tag.id))" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></div><span class="text-gray-700" x-text="tag.name"></span></label>
                                                     </template>
                                                 </div>
                                                 <p class="text-xs text-right text-gray-500 mt-1" x-text="`${currentEvent.tags ? currentEvent.tags.length : 0}/5 tags selected`"></p>
                                             </div>
                                         </template>
                                          <template x-if="!currentEvent.category_id">
                                              <p class="text-sm text-gray-500 p-4 border-2 border-dashed border-gray-200 rounded-xl text-center">Please select a category first to see available tags.</p>
                                          </template>
                                     </div>

                                    <!-- Event Description -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Event Description<span class="text-red-500 ml-1">*</span></label>
                                        <div class="relative"><div class="absolute top-3 left-0 pl-3 pointer-events-none"><svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg></div><textarea name="description" rows="5" required x-model="currentEvent.description" class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 placeholder-gray-400/80 text-sm hover:border-gray-300 transition-colors" placeholder="Describe your event details..."></textarea></div>
                                    </div>
                                </div> <!-- End Details Tab -->

                                <!-- Settings Tab -->
                                <div x-show="activeEventTab === 'settings'" class="space-y-6">
                                    <!-- Image Upload -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Event Banner <span class="text-gray-400 font-normal ml-2">(Optional, Recommended: 1200x600px)</span></label>
                                        <div class="relative border-2 border-dashed border-gray-200 rounded-2xl hover:border-purple-500 transition-all duration-300 p-6 bg-gray-50/50 hover:bg-white" @dragover.prevent="$el.classList.add('border-purple-500', 'bg-purple-50')" @dragleave.prevent="$el.classList.remove('border-purple-500', 'bg-purple-50')" @drop.prevent="$el.classList.remove('border-purple-500', 'bg-purple-50'); previewEventImage($event)" :class="{ 'border-purple-500 bg-purple-50': eventImagePreview }"><input type="file" name="event_image" accept="image/jpeg, image/png, image/gif" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" @change="previewEventImage($event)"><div class="text-center space-y-4"><template x-if="!eventImagePreview"><div class="space-y-3"><svg class="w-12 h-12 mx-auto text-gray-400 transition-colors group-hover:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><div class="space-y-1"><p class="text-sm text-gray-600 group-hover:text-purple-700 transition-colors font-medium"><span class="text-purple-600 font-semibold">Click to upload</span> or drag & drop</p><p class="text-xs text-gray-500">JPG, PNG, GIF (Max 2MB)</p></div></div></template><template x-if="eventImagePreview"><div class="relative group/preview"><img :src="eventImagePreview" class="w-full h-40 object-cover rounded-xl shadow-sm border border-gray-200"><button type="button" @click.stop.prevent="eventImagePreview = null; $refs.eventForm.querySelector('input[name=event_image]').value = null;" class="absolute -top-2 -right-2 p-1.5 bg-white rounded-full shadow-md hover:bg-red-100 text-red-500 hover:text-red-600 transition-all opacity-0 group-hover/preview:opacity-100 scale-75 group-hover/preview:scale-100"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div></template></div></div>
                                    </div>

                                    <!-- Price -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Ticket Price<span class="text-red-500 ml-1">*</span></label>
                                        <div class="relative">
                                            <!-- Radio Buttons for Free/Paid -->
                                            <div class="flex items-center gap-4 mb-3">
                                            <label for="price_type_free" class="flex items-center cursor-pointer">
                                            <input type="radio" id="price_type_free" name="price_type" :value="true" 
                                                x-model="isEventFree" 
                                                @change="isEventFree = true; currentEvent.price = '0'" 
                                                class="hidden peer">
                                                <span class="px-4 py-2 rounded-lg border-2 text-sm transition-colors"
                                                    :class="isEventFree ? 'border-purple-500 bg-purple-50 text-purple-700 font-medium' : 'border-gray-200 text-gray-600 peer-hover:border-gray-300'">
                                                    Free Event
                                                </span>
                                            </label>
                                            <label for="price_type_paid" class="flex items-center cursor-pointer">
                                            <input type="radio" id="price_type_paid" name="price_type" :value="false"
                                                x-model="isEventFree"
                                                @change="isEventFree = false; if (currentEvent.price == '0' || currentEvent.price === null || currentEvent.price === '') currentEvent.price = ''"
                                                class="hidden peer">
                                                <span class="px-4 py-2 rounded-lg border-2 text-sm transition-colors"
                                                    :class="!isEventFree ? 'border-purple-500 bg-purple-50 text-purple-700 font-medium' : 'border-gray-200 text-gray-600 peer-hover:border-gray-300'">
                                                    Paid Event
                                                </span>
                                            </label>
                                            </div>

                                            <!-- Conditional Price Input (Only shows when 'Paid' is selected) -->
                                            <div x-show="!isEventFree" x-transition class="relative">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                    <span class="text-gray-500 text-sm">RM</span>
                                                </div>
                                                <input type="number" name="price" step="0.01" min="0.01"
                                                    x-model="currentEvent.price"
                                                    :disabled="isEventFree"
                                                    :required="!isEventFree"
                                                    class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors disabled:bg-gray-100 disabled:cursor-not-allowed"
                                                    placeholder="Enter ticket price (e.g., 25.50)">
                                            </div>

                                            <!-- Hidden input to ensure '0' is sent when Free is selected and the number input isn't rendered -->
                                            
                                        </div>
                                    </div>
                                    <!-- End Price -->

                                    <!-- Participant Limit -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Participant Limit <span class="text-gray-400 font-normal ml-2">(Optional)</span></label>
                                        <div class="relative"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><svg class="w-5 h-5 text-gray-400 group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M12 7a4 4 0 110-5.292A4 4 0 0112 7z" /></svg></div><input type="number" name="participant_limit" x-model.number="currentEvent.participant_limit" min="1" class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors" placeholder="Leave empty for unlimited"></div><p class="text-xs text-gray-500 mt-1">Set maximum number of participants. Minimum 1 if set.</p>
                                    </div>

                                    <!-- Venue Details -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Venue Name / Details<span class="text-red-500 ml-1">*</span></label><div class="relative"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg></div><input type="text" name="venue" required x-model="currentEvent.venue" class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors" :placeholder="currentEvent.is_online == '1' ? 'e.g., Online Platform' : 'e.g., Convention Center Hall A'"></div><p class="text-xs text-gray-500 mt-1">Specify the exact hall, room, or platform name.</p>
                                    </div>

                                    <!-- Audience -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Target Audience<span class="text-red-500 ml-1">*</span></label><div class="relative"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg></div><input type="text" name="audience" required x-model="currentEvent.audience" class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm hover:border-gray-300 transition-colors" placeholder="e.g., General Public, Students, Professionals"></div>
                                    </div>

                                    <!-- Attention Notice -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Special Instructions / Notes<span class="text-gray-400 font-normal ml-2">(Optional)</span></label><div class="relative"><div class="absolute top-3 left-0 pl-3 pointer-events-none"><svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg></div><textarea name="attention" rows="3" x-model="currentEvent.attention" class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 placeholder-gray-400/80 text-sm hover:border-gray-300 transition-colors" placeholder="Important notes for attendees (e.g., Bring ID, Dress code)..."></textarea></div>
                                    </div>

                                    <!-- Terms & Conditions -->
                                    <div class="group">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Terms & Conditions<span class="text-gray-400 font-normal ml-2">(Optional)</span></label><div class="relative"><div class="absolute top-3 left-0 pl-3 pointer-events-none"><svg class="w-5 h-5 text-gray-400 transition-colors group-focus-within:text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div><textarea name="terms_and_conditions" rows="4" x-model="currentEvent.terms_and_conditions" class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 placeholder-gray-400/80 text-sm hover:border-gray-300 transition-colors" placeholder="Event terms and conditions (e.g., Refund policy, Code of conduct)..."></textarea></div>
                                    </div>
                                </div> <!-- End Settings Tab -->
                            </div> <!-- End x-show="currentEvent" -->

                            <!-- Form Actions -->
                             <div class="sticky bottom-0 bg-white pt-6 pb-6 border-t border-gray-100 -mx-8 px-8">
                                <div class="flex justify-end gap-4">
                                    <button type="button" @click="openEventModal = false" class="px-6 py-3 text-gray-600 hover:text-gray-800 rounded-xl transition-all font-medium border-2 border-gray-200 hover:border-gray-300 text-sm hover:bg-gray-50 flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>Cancel
                                    </button>
                                    <button type="submit" class="px-6 py-3 bg-gradient-to-br from-purple-600 to-blue-600 text-white rounded-xl hover:shadow-lg transition-all font-medium flex items-center justify-center gap-2 text-sm shadow-md hover:shadow-purple-200/50 hover:-translate-y-0.5 relative min-w-[150px]" :disabled="isEventProcessing || (editEventMode && isCurrentEventExpired)" :class="{ 'opacity-50 cursor-not-allowed': isEventProcessing || (editEventMode && isCurrentEventExpired) }">
                                        <span x-show="!isEventProcessing" x-text="editEventMode ? 'Save Changes' : 'Create Event'"></span>
                                        <span x-show="isEventProcessing"><svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></span>
                                    </button>
                                </div>
                            </div>
                        </div> <!-- End flex-1 -->
                </form>
                </div> <!-- End Scrollable -->
            </div>
        </div><!-- End Event Form Modal -->

        <!-- Event Delete Confirmation Modal -->
        <div x-show="showEventDeleteConfirm" x-cloak class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[60] flex items-center justify-center p-4 transition-opacity" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
             <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6" @click.away="showEventDeleteConfirm = false" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">
                 <div class="flex items-start space-x-4">
                     <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10"><svg class="h-6 w-6 text-red-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg></div>
                     <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left flex-1">
                         <h3 class="text-lg leading-6 font-medium text-gray-900">Delete Event</h3>
                         <div class="mt-2"><p class="text-sm text-gray-600">Are you sure you want to delete the event: <strong class="font-semibold" x-text="eventToDeleteTitle"></strong>?</p><p class="text-sm text-red-600 mt-1">This will permanently remove the event and its registrations. This action cannot be undone.</p></div>
                     </div>
                 </div>
                 <div x-show="eventFormError && showEventDeleteConfirm" x-cloak class="mt-4" id="delete-event-error-alert"><div class="flex items-start gap-3 p-3 bg-red-50 border border-red-200 rounded-lg"><svg class="w-5 h-5 text-red-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg><p class="text-sm font-medium text-red-800 flex-1" x-text="eventErrorMessage"></p></div></div>
                 <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                     <button type="button" @click="deleteEvent()" :disabled="isEventDeleting" class="inline-flex justify-center w-full rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:w-auto sm:text-sm disabled:opacity-50 min-w-[120px] relative"><span x-show="!isEventDeleting">Confirm Delete</span><span x-show="isEventDeleting" class="absolute inset-0 flex items-center justify-center"><svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></span></button>
                     <button type="button" @click="showEventDeleteConfirm = false" :disabled="isEventDeleting" class="mt-3 inline-flex justify-center w-full rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:w-auto sm:text-sm disabled:opacity-50">Cancel</button>
                 </div>
             </div>
        </div>
        <!-- End Event Delete Modal -->

        <!-- Add/Edit Category Modal -->
        <div x-show="showCategoryModal" x-cloak class="fixed inset-0 bg-black/50 z-[100] flex items-center justify-center p-4 backdrop-blur-sm"
             x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 relative transform transition-all scale-95"
                @click.outside="showCategoryModal = false"
                x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">

                <!-- Header -->
                <div class="flex justify-between items-center mb-6 pb-4 border-b border-gray-100">
                    <h3 class="text-2xl font-bold text-gray-900" x-text="editCategoryMode ? 'Edit Category' : 'New Category'"></h3>
                    <button @click="showCategoryModal = false" class="text-gray-400 hover:text-gray-500 transition-colors duration-200"><svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>

                 <!-- Form Error Alert -->
                 <div x-show="categoryFormError" x-cloak class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700" x-text="categoryErrorMessage"></div>

                <!-- Form -->
                <form @submit.prevent="submitCategoryForm">
                     <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"> <!-- Added CSRF -->
                    <div class="space-y-5">
                         <!-- Name -->
                         <div class="space-y-2">
                              <label for="category_name" class="block text-sm font-medium text-gray-700">Category Name <span class="text-red-500">*</span></label>
                              <input type="text" id="category_name" name="name" x-model="currentCategory.name" required
                                     class="w-full px-4 py-3 border-2 rounded-xl transition-all placeholder-gray-400"
                                     :class="categoryFormError ? 'border-red-300 focus:border-red-500 focus:ring-red-200' : 'border-gray-200 focus:border-purple-500 focus:ring-purple-200'"
                                     placeholder="e.g., Technology">
                         </div>
                         <!-- Description (Optional - Uncomment if needed) -->
                         <!-- <div class="space-y-2"><label ...>...</label><textarea ...></textarea></div> -->
                    </div>
                    <!-- Footer -->
                    <div class="mt-8 flex justify-end gap-3">
                        <button type="button" @click="showCategoryModal = false" class="px-5 py-2.5 text-gray-600 hover:text-gray-800 hover:bg-gray-50 rounded-xl transition-all duration-200 font-medium">Cancel</button>
                        <button type="submit" :disabled="isCategoryProcessing" class="px-5 py-2.5 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition-colors font-medium flex items-center gap-2.5 min-w-[120px] justify-center" :class="{'opacity-75 cursor-not-allowed': isCategoryProcessing}">
                            <svg x-show="isCategoryProcessing" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <span x-show="!isCategoryProcessing" x-text="editCategoryMode ? 'Save Changes' : 'Create Category'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <!-- End Add/Edit Category Modal -->

        <!-- Delete Category Confirmation Modal -->
         <div x-show="showCategoryDeleteConfirm" x-cloak class="fixed inset-0 bg-black/50 z-[100] flex items-center justify-center p-4 backdrop-blur-sm"
             x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 relative transform transition-all scale-95"
                @click.outside="showCategoryDeleteConfirm = false"
                x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">
                <template x-if="categoryToDelete">
                    <div class="flex flex-col items-center text-center space-y-5">
                        <div class="p-4 bg-red-50 rounded-2xl mb-3"><svg class="w-12 h-12 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg></div>
                        <div class="space-y-2">
                            <h3 class="text-2xl font-bold text-gray-900">Delete Category?</h3>
                            <p class="text-gray-600 leading-relaxed">Are you sure you want to delete the category <strong class="font-semibold text-red-600" x-text="categoryToDelete.name"></strong>?</p>
                            <p class="text-sm text-red-600 mt-1">This action cannot be undone. Deletion might fail if the category is currently used by tags or events.</p>
                        </div>
                         <!-- Delete Error Alert -->
                         <div x-show="categoryFormError && showCategoryDeleteConfirm" x-cloak class="w-full mt-2 p-3 bg-red-50/90 border border-red-200 rounded-xl text-red-600 text-sm font-medium transition-all duration-300"><span x-text="categoryErrorMessage"></span></div>
                        <div class="flex justify-end gap-3 w-full pt-6">
                            <button @click="showCategoryDeleteConfirm = false" :disabled="isCategoryDeleting" class="px-5 py-2.5 text-gray-600 hover:text-gray-800 hover:bg-gray-50 rounded-xl transition-all duration-200 font-medium">Cancel</button>
                            <button @click="deleteCategory()" :disabled="isCategoryDeleting" class="px-5 py-2.5 bg-red-600 text-white rounded-xl hover:bg-red-700 transition-colors font-medium flex items-center gap-2.5 min-w-[140px] justify-center" :class="{'opacity-75 cursor-not-allowed': isCategoryDeleting}">
                                <svg x-show="isCategoryDeleting" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                <span x-show="!isCategoryDeleting">Confirm Delete</span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
        <!-- End Delete Category Modal -->

        <!-- Add/Edit Tag Modal -->
        <div x-show="showTagModal" x-cloak class="fixed inset-0 bg-black/50 z-[110] flex items-center justify-center p-4 backdrop-blur-sm"
             x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 relative transform transition-all scale-95 modal-content"
                @click.outside="showTagModal = false"
                x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">

                <!-- Header -->
                <div class="flex justify-between items-center mb-6 pb-4 border-b border-gray-100">
                    <h3 class="text-2xl font-bold text-gray-900" x-text="editTagMode ? 'Edit Tag' : 'New Tag'"></h3>
                    <button @click="showTagModal = false" class="text-gray-400 hover:text-gray-500 transition-colors duration-200"><svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>

                 <!-- Form Error Alert -->
                 <div x-show="tagFormError" x-cloak class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700" x-text="tagErrorMessage"></div>

                <!-- Form -->
                <form @submit.prevent="submitTagForm" x-ref="tagForm">
                    <input type="hidden" name="action" :value="editTagMode ? 'update' : 'create'">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="tag_id" :value="editTagMode && currentTag ? currentTag.id : ''"> <!-- Conditional value -->

                    <div class="space-y-5">
                         <!-- Name -->
                         <div class="space-y-2">
                              <label for="tag_name" class="block text-sm font-medium text-gray-700">Tag Name <span class="text-red-500">*</span></label>
                              <input type="text" id="tag_name" name="name" x-model="currentTag.name" required
                                     class="w-full px-4 py-3 border-2 rounded-xl transition-all placeholder-gray-400"
                                     :class="tagFormError && (!currentTag || !currentTag.name || !currentTag.name.trim()) ? 'border-red-300 focus:border-red-500 focus:ring-red-200' : 'border-gray-200 focus:border-purple-500 focus:ring-purple-200'"
                                     placeholder="e.g., AI, Web Development">
                         </div>
                         <!-- Category -->
                         <div class="space-y-2">
                              <label for="tag_category" class="block text-sm font-medium text-gray-700">Category <span class="text-red-500">*</span></label>
                               <select id="tag_category" name="category_id" x-model="currentTag.category_id" required
                                      class="w-full px-4 py-3 border-2 rounded-xl transition-all bg-select-arrow appearance-none"
                                      :class="tagFormError && (!currentTag || !currentTag.category_id) ? 'border-red-300 focus:border-red-500 focus:ring-red-200' : 'border-gray-200 focus:border-purple-500 focus:ring-purple-200'">
                                  <option value="" disabled>Select a category</option>
                                  <template x-for="category in categoriesData" :key="category.id">
                                      <option :value="String(category.id)" x-text="category.name"></option> <!-- Ensure value is string -->
                                  </template>
                              </select>
                         </div>
                    </div>
                    <!-- Footer -->
                    <div class="mt-8 flex justify-end gap-3">
                        <button type="button" @click="showTagModal = false" class="px-5 py-2.5 text-gray-600 hover:text-gray-800 hover:bg-gray-50 rounded-xl transition-all duration-200 font-medium">Cancel</button>
                        <button type="submit" :disabled="isTagProcessing" class="px-5 py-2.5 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition-colors font-medium flex items-center gap-2.5 min-w-[120px] justify-center" :class="{'opacity-75 cursor-not-allowed': isTagProcessing}">
                            <svg x-show="isTagProcessing" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <span x-show="!isTagProcessing" x-text="editTagMode ? 'Save Changes' : 'Create Tag'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <!-- End Add/Edit Tag Modal -->

        <!-- Delete Tag Confirmation Modal -->
         <div x-show="showTagDeleteConfirm" x-cloak class="fixed inset-0 bg-black/50 z-[110] flex items-center justify-center p-4 backdrop-blur-sm"
             x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 relative transform transition-all scale-95"
                @click.outside="showTagDeleteConfirm = false"
                x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">
                <template x-if="tagToDelete">
                    <div class="flex flex-col items-center text-center space-y-5">
                        <div class="p-4 bg-red-50 rounded-2xl mb-3"><svg class="w-12 h-12 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg></div>
                        <div class="space-y-2">
                            <h3 class="text-2xl font-bold text-gray-900">Delete Tag?</h3>
                            <p class="text-gray-600 leading-relaxed">Are you sure you want to delete the tag <strong class="font-semibold text-red-600" x-text="tagToDelete.name"></strong>?</p>
                            <p class="text-sm text-red-600 mt-1">This will remove the tag and its association with any events. This action cannot be undone.</p>
                        </div>
                         <!-- Delete Error Alert -->
                         <div x-show="tagFormError && showTagDeleteConfirm" x-cloak class="w-full mt-2 p-3 bg-red-50/90 border border-red-200 rounded-xl text-red-600 text-sm font-medium transition-all duration-300"><span x-text="tagErrorMessage"></span></div>
                        <div class="flex justify-end gap-3 w-full pt-6">
                            <button @click="showTagDeleteConfirm = false" :disabled="isTagDeleting" class="px-5 py-2.5 text-gray-600 hover:text-gray-800 hover:bg-gray-50 rounded-xl transition-all duration-200 font-medium">Cancel</button>
                            <button @click="deleteTag()" :disabled="isTagDeleting" class="px-5 py-2.5 bg-red-600 text-white rounded-xl hover:bg-red-700 transition-colors font-medium flex items-center gap-2.5 min-w-[140px] justify-center" :class="{'opacity-75 cursor-not-allowed': isTagDeleting}">
                                <svg x-show="isTagDeleting" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                <span x-show="!isTagDeleting">Confirm Delete</span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
        <!-- End Delete Tag Modal -->

        <!-- Payout Details Modal -->
        <div x-show="showPayoutDetailsModal" x-cloak class="fixed inset-0 bg-black/50 z-[120] flex items-center justify-center p-4 backdrop-blur-sm"
            x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl p-0 relative transform transition-all scale-95 flex flex-col"
                style="max-height: 85vh;" <
                @click.outside="closePayoutModal()"
                x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">

                <!-- Header -->
                <div class="flex justify-between items-start p-5 border-b border-gray-200 sticky top-0 bg-white z-10 rounded-t-2xl">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">Manage Payouts for Past Events</h3> <!-- Clarified Title -->
                        <p class="text-sm text-gray-600 mt-1">Organizer: <strong class="text-purple-700" x-text="selectedOrganizerForPayout?.name || 'N/A'"></strong></p>
                    </div>
                    <button @click="closePayoutModal()" class="text-gray-400 hover:text-gray-500 transition-colors duration-200 p-1.5 rounded-full hover:bg-gray-100"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>

                <!-- Content Area (Scrollable) -->
                <div class="flex-1 overflow-y-auto p-6 space-y-6">
                    <!-- Loading State -->
                    <template x-if="isLoadingPendingEvents">
                        <div class="text-center py-10 text-gray-500 flex items-center justify-center gap-2">
                            <svg class="animate-spin h-5 w-5 text-purple-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            Loading event details...
                        </div>
                    </template>

                    <!-- Error State -->
                    <template x-if="payoutModalError && !isLoadingPendingEvents">
                        <div class="p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700" role="alert">
                            <p><strong class="font-medium">Error:</strong> <span x-text="payoutModalError"></span></p>
                        </div>
                    </template>

                    <!-- Event List -->
                    <template x-if="!isLoadingPendingEvents && !payoutModalError">
                        <!-- Added x-data for local filter state -->
                        <div x-data="{ activeFilter: 'pending' }" class="space-y-4">
                            <!-- Filters -->
                            <div class="flex space-x-1 p-1 bg-gray-100 rounded-lg max-w-xs">
                                <button @click="activeFilter = 'pending'" :class="activeFilter === 'pending' ? 'bg-white shadow text-purple-700' : 'text-gray-500 hover:text-gray-700'" class="flex-1 py-1.5 px-3 rounded-md text-xs font-medium transition-all">Pending</button>
                                <button @click="activeFilter = 'paid'" :class="activeFilter === 'paid' ? 'bg-white shadow text-green-700' : 'text-gray-500 hover:text-gray-700'" class="flex-1 py-1.5 px-3 rounded-md text-xs font-medium transition-all">Paid</button>
                            </div>

                            <!-- Pending Events Table -->
                            <div x-show="activeFilter === 'pending'">
                                <h4 class="text-sm font-semibold text-gray-700 mb-2">Events Pending Payout</h4>
                                <div class="overflow-x-auto border rounded-lg">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Date</th>
                                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Earnings (RM)</th> <!-- Added Earnings Column -->
                                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <!-- Filter locally based on payout_status -->
                                            <template x-for="event in pendingEventsForOrganizer.filter(e => e.payout_status === 'pending')" :key="event.id">
                                                <tr>
                                                    <td class="px-4 py-2 font-medium text-gray-800 truncate" :title="event.title" x-text="event.title"></td>
                                                    <td class="px-4 py-2 text-right text-gray-600" x-text="new Date(event.event_date).toLocaleDateString('en-GB')"></td>
                                                    <!-- Display event_earnings -->
                                                    <td class="px-4 py-2 text-right font-semibold text-green-700" x-text="parseFloat(event.event_earnings || 0).toFixed(2)"></td>
                                                    <td class="px-4 py-2 flex justify-end">
                                                        <button type="button" @click="markEventAsPaid(event.id)"
                                                                :disabled="isMarkingPaid === event.id"
                                                                class="bg-green-600 hover:bg-green-700 text-white text-xs font-medium px-2.5 py-1 rounded-md transition-colors flex items-center justify-center min-w-[80px] disabled:opacity-60 disabled:cursor-wait">
                                                            <span x-show="isMarkingPaid !== event.id">Mark Paid</span>
                                                            <svg x-show="isMarkingPaid === event.id" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </template>
                                            <!-- Empty state for Pending filter -->
                                            <template x-if="pendingEventsForOrganizer.filter(e => e.payout_status === 'pending').length === 0">
                                                <tr><td colspan="4" class="px-4 py-4 text-center text-gray-500 text-xs italic">No past events currently pending payout.</td></tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Paid Events Table -->
                             <div x-show="activeFilter === 'paid'">
                                <h4 class="text-sm font-semibold text-gray-700 mb-2">Events Already Paid Out</h4>
                                <div class="overflow-x-auto border rounded-lg">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Date</th>
                                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Earnings (RM)</th> <!-- Added Earnings Column -->
                                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <!-- Filter locally based on payout_status -->
                                            <template x-for="event in pendingEventsForOrganizer.filter(e => e.payout_status === 'paid')" :key="event.id">
                                                <tr>
                                                    <td class="px-4 py-2 font-medium text-gray-600 truncate" :title="event.title" x-text="event.title"></td>
                                                    <td class="px-4 py-2 text-right text-gray-500" x-text="new Date(event.event_date).toLocaleDateString('en-GB')"></td>
                                                    <!-- Display event_earnings -->
                                                    <td class="px-4 py-2 text-right font-semibold text-gray-500" x-text="parseFloat(event.event_earnings || 0).toFixed(2)"></td>
                                                    <td class="px-4 py-2 text-center">
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                            <svg class="-ml-0.5 mr-1 h-3 w-3 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                                            Paid
                                                        </span>
                                                    </td>
                                                </tr>
                                            </template>
                                            <!-- Empty state for Paid filter -->
                                            <template x-if="pendingEventsForOrganizer.filter(e => e.payout_status === 'paid').length === 0">
                                                <tr><td colspan="4" class="px-4 py-4 text-center text-gray-500 text-xs italic">No past events have been marked as paid out yet.</td></tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </template>

                </div>

                <!-- Footer -->
                <div class="flex justify-end p-5 border-t border-gray-200 sticky bottom-0 bg-gray-50 rounded-b-2xl">
                    <button type="button" @click="closePayoutModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">Close</button>
                </div>
            </div>
        </div>
        <!-- End Payout Details Modal -->

    </div> <!-- End main x-data div -->

    <!-- Dummy element for filter datepicker.js -->
    <div id="date-picker-root" style="display: none;"></div>

    <!-- DatePicker Dependencies (Only needed if using the filter datepicker) -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/babel-standalone@6/babel.min.js"></script>
    <!-- Ensure datepicker.js path is correct -->
    <script src="/assets/js/datepicker.js"></script>
    <script type="text/babel">
        // This initializes the FILTER datepicker, NOT the standard date input in the modal
        document.addEventListener('DOMContentLoaded', () => {
            const eventDatePickerRoot = document.getElementById('event-date-picker');
            if (eventDatePickerRoot) { // Check if the element exists
                 try {
                     const root = ReactDOM.createRoot(eventDatePickerRoot);
                     root.render(React.createElement(DatePicker));
                 } catch (e) {
                     console.error("Error initializing React DatePicker:", e);
                     // Optionally display an error to the user or fallback
                 }
            }
        });
    </script>

    <!-- Loading overlay -->
    <template x-if="isLoading">
        <div class="fixed inset-0 bg-white/80 backdrop-blur-sm z-[200] flex items-center justify-center">
            <div class="flex items-center space-x-3 text-purple-600">
                <svg class="animate-spin h-8 w-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                <span class="font-medium">Loading Dashboard...</span>
            </div>
        </div>
    </template>

    <?php include '../includes/footer.php'; ?>
</body>
</html>