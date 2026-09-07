<?php
// --- Start of file ---
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
$csrf_token = $_SESSION['csrf_token'];
// --- End CSRF Token Generation ---

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
require_once '../config/db.php'; // Includes APP_BASE_URL
require_once '../lib/functions.php';

if (!defined('BILL_EXPIRY_DAYS')) {
    define('BILL_EXPIRY_DAYS', 3);
}

$event_id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;
$event = null;
$related_events = [];
$payment_status = null;         // Raw status from DB ('paid', 'free', 'pending', 'failed', null)
$registration_date = null;    // DateTime object or null
$expiry_message = '';           // Message about expiry/retry
$effective_status = null;       // Status used for button logic ('paid', 'free', 'pending', 'failed', 'expired', null)
$isPastEvent = false;
$has_submitted_feedback = false;
$location = '';
$is_registered = false;         // Flag if user has a 'paid' or 'free' registration

if ($event_id <= 0) {
    // Redirect or show error for invalid ID before DB query
    header('Location: /404.php'); // Or display an error message
    exit;
}

try {
    // --- 1. Fetch main event data ---
    $sql = "
        SELECT
            e.*,
            l.city, l.state,
            c.name AS category_name,
            u.name AS organizer_name,
            u.email AS organizer_email, -- Organizer email
            u.phone AS organizer_phone, -- Added organizer phone
            COALESCE(u.profile_image, 'default-avatar.jpg') AS profile_image,
            GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS event_tags,
            -- Add subquery to count current successful registrations
            (SELECT COUNT(*) FROM registrations r_count
             WHERE r_count.event_id = e.id
             AND r_count.payment_status IN ('paid', 'free')) AS current_participant_count";
            

    // Add follow status check if user is logged in
    $followStatusSelect = ", NULL AS is_following "; // Default for guests
    if ($user_id > 0) {
        $followStatusSelect = ",
            EXISTS (
                SELECT 1 FROM user_follows uf
                WHERE uf.user_id = :user_id_follow AND uf.organizer_id = e.created_by
            ) AS is_following ";
    }

    // Add saved status check if user is logged in
    $savedStatusSelect = ", NULL AS is_saved "; // Default for guests
    if ($user_id > 0) {
        $savedStatusSelect = ",
            EXISTS (
                SELECT 1 FROM user_saved_events usev
                WHERE usev.user_id = :user_id_save AND usev.event_id = e.id
            ) AS is_saved ";
    }

    // Add registration status check if user is logged in
    $registrationStatusSelect = ", NULL AS current_status_and_date "; // Default for guests
    if ($user_id > 0) {
         $registrationStatusSelect = ",
             (SELECT
                 CONCAT(r_status.payment_status, '|', r_status.registration_date)
             FROM registrations r_status
             WHERE r_status.user_id = :user_id_status
             AND r_status.event_id = e.id
             AND r_status.payment_status IN ('paid', 'free', 'pending', 'failed')
             ORDER BY r_status.id DESC
             LIMIT 1) AS current_status_and_date ";
    }

    $sql .= "
        {$registrationStatusSelect} -- Add registration status
        {$savedStatusSelect}        -- Add saved status
        {$followStatusSelect}        -- Add follow status
        FROM events e
        LEFT JOIN locations l ON e.city_id = l.id AND e.is_online = 0
        LEFT JOIN categories c ON e.category_id = c.id
        LEFT JOIN users u ON e.created_by = u.id
        LEFT JOIN event_tags et ON e.id = et.event_id
        LEFT JOIN tags t ON et.tag_id = t.id
        WHERE e.id = :event_id
        GROUP BY e.id";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':event_id', $event_id, PDO::PARAM_INT);
    if ($user_id > 0) {
        // Bind parameters used in the subqueries/selects
        $stmt->bindParam(':user_id_status', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':user_id_follow', $user_id, PDO::PARAM_INT); // Bind for follow check
        $stmt->bindParam(':user_id_save', $user_id, PDO::PARAM_INT); // Bind for save check
    }
    $stmt->execute();
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    // Process follow status AFTER fetching event
    $is_following = ($user_id > 0 && $event && isset($event['is_following'])) ? (bool)$event['is_following'] : false;
    $is_currently_saved = ($user_id > 0 && $event && isset($event['is_saved'])) ? (bool)$event['is_saved'] : false;

    // --- Exit if event not found ---
    if (!$event) {
        header('Location: /404.php');
        exit;
    }

    // --- 2. Process Registration Status and Date (MUST BE HERE) ---
    if ($user_id > 0 && !empty($event['current_status_and_date'])) {
        list($payment_status, $reg_date_str) = explode('|', $event['current_status_and_date'], 2);
        if ($reg_date_str) {
             try {
                 // Adjust timezone if needed
                 $registration_date = new DateTime($reg_date_str /*, new DateTimeZone('UTC')*/);
             } catch (Exception $e) {
                 error_log("Error parsing registration date '{$reg_date_str}': " . $e->getMessage());
                 $registration_date = null;
             }
        }
        // Set is_registered flag correctly
        if ($payment_status === 'paid' || $payment_status === 'free') {
            $is_registered = true;
        }
    }
    // Keep $payment_status, $registration_date, $is_registered as null/false if no status found or user not logged in

    // --- 3. Calculate if Event is Past ---
    if (isset($event['event_date']) && isset($event['event_time'])) {
        try {
            $eventDateTimeStr = $event['event_date'] . ' ' . $event['event_time'];
            $appTimeZone = new DateTimeZone('Asia/Kuala_Lumpur'); // SET YOUR TIMEZONE
            $eventDateTime = new DateTime($eventDateTimeStr, $appTimeZone);
            $now = new DateTime('now', $appTimeZone);
            $isPastEvent = ($eventDateTime < $now);
        } catch (Exception $e) {
            error_log("Error creating DateTime object for event ID {$event_id}: " . $e->getMessage());
            $isPastEvent = false;
        }
    }

    // --- 4. Check Profile Completion ---
    $profileComplete = ($user_id > 0) ? isUserProfileComplete($pdo, $user_id) : false;

    // --- 5. Determine Effective Status & Expiry Message (Now uses correct $payment_status/$registration_date) ---
    $effective_status = $payment_status; // Start with the raw status from step 2
    $bill_expired = false;

    if (($payment_status === 'pending' || $payment_status === 'failed') && $registration_date) {
        $now = new DateTime();
        $interval = $now->diff($registration_date);
        $daysOld = (int)$interval->format('%a');
        $expiry_days_constant = BILL_EXPIRY_DAYS;

        if ($daysOld >= $expiry_days_constant) {
            $bill_expired = true;
            $effective_status = 'expired'; // Override status
            $expiry_message = "Your previous payment attempt expired (older than {$expiry_days_constant} days).";
        } else {
            $days_remaining = $expiry_days_constant - $daysOld - 1;
            if ($days_remaining < 0) $days_remaining = 0;

            if ($days_remaining === 0) {
                $hours_left = 23 - (int)$interval->format('%h');
                 if($hours_left > 0) {
                    $expiry_message = "You have less than {$hours_left} hour(s) left to complete or retry the payment.";
                 } else {
                    $expiry_message = "This payment attempt expires very soon.";
                 }
            } else {
                $day_word = ($days_remaining == 1) ? 'day' : 'days';
                $expiry_message = "You have ~{$days_remaining} {$day_word} left to complete or retry the payment.";
            }
        }
    }
    // --- End Effective Status & Expiry Calculation ---


    // --- 6. Determine Button State (Now uses correct $effective_status) ---
    $buttonClasses = "w-full px-6 py-3 rounded-lg font-semibold text-lg text-white hover:shadow-lg transform hover:scale-[1.02] active:scale-95 register-button-transition focus:outline-none focus:ring-2 focus:ring-offset-2 ";
    $buttonText = 'Register Now';
    $iconClass = 'fa-user-plus';
    $buttonDisabled = false;
    $disableReason = ''; // Tooltip message

    if ($isPastEvent) {
        $buttonDisabled = true; $buttonClasses .= 'bg-gray-400 cursor-not-allowed opacity-70'; $buttonText = 'Event Finished'; $iconClass = 'fa-calendar-times'; $disableReason = 'This event has already passed.';
    } elseif ($user_id <= 0) {
        $buttonDisabled = true; $buttonClasses .= 'bg-gray-400 cursor-not-allowed opacity-70'; $buttonText = 'Login to Register'; $iconClass = 'fa-sign-in-alt'; $disableReason = 'Login required to register.';
    } elseif (!$profileComplete) {
        $buttonDisabled = true; $buttonClasses .= 'bg-gray-400 cursor-not-allowed opacity-70'; $buttonText = 'Complete Profile to Register'; $iconClass = 'fa-user-edit'; $disableReason = 'Please complete your profile to register.';
    }
    // Use $effective_status for the rest of the logic
    elseif ($effective_status === 'paid' || $effective_status === 'free') {
        $buttonDisabled = true; $buttonClasses .= 'bg-green-600 hover:bg-green-700 focus:ring-green-500 cursor-default'; $buttonText = 'Registered'; $iconClass = 'fa-check-circle'; $disableReason = 'You are already registered for this event.';
    } elseif (($effective_status === 'pending' || $effective_status === 'failed') && !$bill_expired) {
        // COMBINED: Pending or Failed, and NOT expired - Allow Retry/Complete
        $buttonDisabled = false; // ENABLED for retry/complete
        $buttonClasses .= 'bg-yellow-600 hover:bg-yellow-700 focus:ring-yellow-500'; // Yellow/orange for action needed
        $buttonText = ($effective_status === 'pending') ? 'Complete Payment?' : 'Retry Payment?';
        $iconClass = 'fa-redo';
        $disableReason = ($effective_status === 'pending')
                         ? 'Your payment is pending completion. ' . $expiry_message . ' Click to continue.'
                         : 'Your last payment attempt failed. ' . $expiry_message . ' Click to retry.';
    } elseif ($effective_status === 'expired') {
        // Expired - Allow new registration (Check limit first)
        if (isset($event['participant_limit']) && $event['participant_limit'] > 0 && isset($event['current_participant_count']) && $event['current_participant_count'] >= $event['participant_limit']) {
            // Limit reached, even if expired attempt exists
            $buttonDisabled = true;
            $buttonClasses .= 'bg-red-400 cursor-not-allowed opacity-70';
            $buttonText = 'Event Full';
            $iconClass = 'fa-users-slash';
            $disableReason = 'This event has reached its participant limit.';
        } else {
            // Limit not reached, allow new registration after expiry
            $buttonDisabled = false; // ENABLED for new registration
            $buttonClasses .= 'bg-purple-600 hover:bg-purple-700 focus:ring-purple-500';
            $buttonText = 'Register Now';
            $iconClass = 'fa-user-plus';
            $disableReason = $expiry_message . ' Click to start a new registration.';
        }
    }
    // --- NEW CHECK: Participant limit for completely new registrations ---
    elseif (isset($event['participant_limit']) && $event['participant_limit'] > 0 && isset($event['current_participant_count']) && $event['current_participant_count'] >= $event['participant_limit']) {
        // This condition applies if user is logged in, profile complete, not registered, no pending/failed/expired status, BUT event is full
        $buttonDisabled = true;
        $buttonClasses .= 'bg-red-400 cursor-not-allowed opacity-70';
        $buttonText = 'Event Full';
        $iconClass = 'fa-users-slash';
        $disableReason = 'This event has reached its participant limit.';
    }
    // --- END NEW CHECK ---
    else {
        // Default: Not registered, no previous attempt found, AND event is NOT full
        $buttonDisabled = false;
        $buttonClasses .= 'bg-purple-600 hover:bg-purple-700 focus:ring-purple-500';
        $buttonText = 'Register Now';
        $iconClass = 'fa-user-plus';
        $disableReason = 'Click to register for this event.';
    }
    // --- End Button State Determination ---


    // --- 7. Location String ---
    if ($event['is_online']) {
        $location = 'Online Event';
    } elseif (!empty($event['city']) && !empty($event['state'])) {
        $location = $event['city'] . ', ' . $event['state'];
    } else {
        $location = 'Location TBD';
    }

    // --- 8. Profile Image Path ---
    $profile_img_base_url = '/assets/images/profiles/';
    $default_avatar_url = $profile_img_base_url . 'default-avatar.jpg';
    if (!empty($event['profile_image']) && $event['profile_image'] !== 'default-avatar.jpg') {
        $event['profile_image_path'] = $profile_img_base_url . htmlspecialchars($event['profile_image']);
    } else {
        $event['profile_image_path'] = $default_avatar_url;
    }

    // --- 9. Fetch Related Events ---
    if ($event['category_id']) {
        $stmtRel = $pdo->prepare("
            SELECT e.id, e.title, e.event_date, e.image_url, l.city,
                (SELECT COUNT(*) FROM registrations WHERE event_id = e.id AND payment_status IN ('paid', 'free')) AS participant_count
            FROM events e
            LEFT JOIN locations l ON e.city_id = l.id AND e.is_online = 0
            WHERE e.category_id = :category_id AND e.id != :event_id AND e.event_date >= CURDATE()
            ORDER BY e.event_date ASC
            LIMIT 3
        ");
        $stmtRel->bindParam(':category_id', $event['category_id'], PDO::PARAM_INT);
        $stmtRel->bindParam(':event_id', $event_id, PDO::PARAM_INT);
        $stmtRel->execute();
        $related_events = $stmtRel->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- 10. Check Feedback Status ---
    if ($user_id > 0 && $event) { // Check $event exists
        $stmtFeedbackCheck = $pdo->prepare("SELECT COUNT(*) FROM feedback WHERE user_id = :user_id AND event_id = :event_id");
        $stmtFeedbackCheck->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmtFeedbackCheck->bindParam(':event_id', $event_id, PDO::PARAM_INT);
        $stmtFeedbackCheck->execute();
        $has_submitted_feedback = $stmtFeedbackCheck->fetchColumn() > 0;
    }

} catch(PDOException $e) {
    error_log("Database error on event page (ID: {$event_id}): " . $e->getMessage());
    echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
    echo "<p>Sorry, there was a problem loading event details. Please try again later.</p>";
    echo "<p>Database Error: " . $e->getMessage() . "</p>"; // Show error details during dev
    echo "</body></html>";
    exit;
} catch(Exception $e) {
    $is_following = false; // Default on error
     error_log("General error on event page (ID: {$event_id}): " . $e->getMessage());
     echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
     echo "<p>Sorry, an unexpected error occurred.</p>";
     echo "</body></html>";
     exit;
}

// --- 11. Prepare Event Image URL ---
$event_image_base_url = '/assets/images/';
$event_image_url = $event_image_base_url . 'default-event.jpg';
if (!empty($event['image_url']) && $event['image_url'] !== 'default-event.jpg') {
    $event_image_url = $event_image_base_url . htmlspecialchars($event['image_url']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>"> <!-- CSRF Token -->
    <title><?= htmlspecialchars($event['title'] ?? 'Event Details') ?> - CECT</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        .register-button-transition { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .feedback-submitted textarea:disabled,
        .feedback-submitted button[type=submit]:disabled { cursor: not-allowed; opacity: 0.6; }
        /* Style for loading spinner inside button */
        .loading svg { vertical-align: middle; }

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

        .animate-toast-out {
        animation: toast-out 0.5s ease-in forwards;
        }

    </style>
</head>
<body class="bg-gradient-to-b from-white via-purple-200 via-purple-400 to-purple-600 min-h-screen bg-fixed bg-no-repeat bg-cover">
    <?php include '../includes/nav.php'; ?>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
            <!-- Event Image -->
            <div class="relative h-64 sm:h-80 md:h-96 bg-gray-200"> <!-- Added bg-gray-200 as fallback -->
                <img src="<?= $event_image_url ?>"
                     alt="<?= htmlspecialchars($event['title']) ?>"
                     class="w-full h-full object-cover"
                     onerror="this.onerror=null; this.src='<?= $event_image_base_url ?>default-event.jpg';"> <!-- Fallback image -->
                <div class="absolute top-4 right-4">
                    <span class="px-4 py-2 bg-purple-600 text-white rounded-full text-sm font-semibold shadow">
                        <?= htmlspecialchars($event['category_name']) ?>
                    </span>
                </div>
            </div>

            <div class="p-6 md:p-8">
                <div class="flex flex-col md:flex-row justify-between items-start gap-6">
                    <!-- Main Content -->
                    <div class="flex-1 min-w-0"> <!-- Added min-w-0 for flex child -->
                        <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2 break-words">
                            <?= htmlspecialchars($event['title']) ?>
                        </h1>

                        <!-- Date, Time, Past Event Badge -->
                        <div class="flex flex-col sm:flex-row sm:items-center sm:flex-wrap sm:gap-x-6 gap-y-1 mb-6 text-gray-600">
                            <div class="flex items-center">
                                <i class="fas fa-calendar-day text-purple-600 mr-2 w-4 text-center flex-shrink-0"></i>
                                <span><?= date('l, j F Y', strtotime($event['event_date'])) ?></span>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-clock text-purple-600 mr-2 w-4 text-center flex-shrink-0"></i>
                                <span><?= date('g:i A', strtotime($event['event_time'])) ?></span>
                            </div>
                            <?php if ($isPastEvent): ?>
                                <span class="mt-1 sm:mt-0 px-2.5 py-0.5 bg-yellow-100 text-yellow-800 text-xs font-semibold rounded-full shadow-sm self-start">
                                    Event Finished
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Online Event Link Section (Modified for JS Update) -->
                        <?php if ($event['is_online'] === 1): ?>
                            <div id="onlineEventAccessSection" class="mt-6 mb-8 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg shadow-sm"
                                 data-event-link="<?= htmlspecialchars($event['google_map_link'] ?? '') ?>"
                                 data-event-venue="<?= htmlspecialchars($event['venue'] ?? 'N/A') ?>"
                                 data-event-link-valid="<?= (!empty($event['google_map_link']) && filter_var($event['google_map_link'], FILTER_VALIDATE_URL)) ? 'true' : 'false' ?>">
                                <h3 class="text-lg font-semibold flex items-center mb-3 text-indigo-800">
                                    <i class="fas fa-video text-indigo-600 mr-2"></i> Online Event Access
                                </h3>

                                <?php // --- Message for users who need to register or login --- ?>
                                <div id="onlineEventMessageNotRegistered" class="
                                    <?php
                                    // This block is visible if:
                                    // 1. User is logged in, event is upcoming, AND user is NOT registered.
                                    // OR 2. User is NOT logged in AND event is upcoming.
                                    // It's hidden if user is registered & upcoming OR if event is past.
                                    if (($user_id > 0 && !$isPastEvent && !$is_registered) || ($user_id <= 0 && !$isPastEvent)) {
                                        echo ''; // Visible
                                    } else {
                                        echo 'hidden'; // Hidden
                                    }
                                    ?>">
                                    <?php if ($user_id > 0 && !$isPastEvent && !$is_registered): // Logged in, upcoming, not registered ?>
                                        <div class="flex items-center p-3 bg-white border border-yellow-300 rounded-md">
                                            <i class="fas fa-info-circle text-yellow-500 mr-2 text-lg flex-shrink-0"></i>
                                            <p class="text-sm text-yellow-800">
                                                Please <a href="#mainRegisterButton" onclick="highlightRegisterButton(event)" class="font-medium hover:underline cursor-pointer">register</a> to get access to the online event link. The link will be shown here after successful registration.
                                            </p>
                                        </div>
                                    <?php elseif ($user_id <= 0 && !$isPastEvent): // Not logged in, upcoming ?>
                                        <?php
                                            $current_url = urlencode(APP_BASE_URL . $_SERVER['REQUEST_URI']);
                                            $login_url = "/pages/login.php?redirect=" . $current_url;
                                        ?>
                                        <div class="flex items-center p-3 bg-white border border-purple-300 rounded-md">
                                            <i class="fas fa-sign-in-alt text-purple-500 mr-2 text-lg flex-shrink-0"></i>
                                            <p class="text-sm text-purple-800">
                                                <a href="<?= $login_url ?>" class="font-medium hover:underline">Log in</a> and register to access the online event link.
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php // --- Content shown to registered users for an upcoming event --- ?>
                                <div id="onlineEventRegisteredContent" class="
                                    <?php
                                    // This block is visible if: User is logged in, event is upcoming, AND user IS registered.
                                    // Hidden otherwise.
                                    if ($user_id > 0 && !$isPastEvent && $is_registered) {
                                        echo ''; // Visible
                                    } else {
                                        echo 'hidden'; // Hidden
                                    }
                                    ?>">
                                    <div id="onlineEventLinkAvailable" class="<?= (!empty($event['google_map_link']) && filter_var($event['google_map_link'], FILTER_VALIDATE_URL)) ? '' : 'hidden' ?>">
                                        <p class="text-sm text-gray-700 mb-2">You're registered! Access the event using the link below:</p>
                                        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                                            <a id="onlineEventJoinLink" href="<?= htmlspecialchars($event['google_map_link'] ?? '#') ?>" target="_blank" rel="noopener noreferrer"
                                                class="inline-flex items-center justify-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 shadow-sm break-all">
                                                <i class="fas fa-external-link-alt mr-1.5"></i> Join Event
                                            </a>
                                            <span class="text-xs text-gray-500 sm:ml-2">(Platform: <span id="onlineEventPlatform"><?= htmlspecialchars($event['venue'] ?? 'N/A') ?></span>)</span>
                                        </div>
                                    </div>
                                    <div id="onlineEventLinkNotAvailable" class="flex items-center p-3 bg-white border border-yellow-300 rounded-md <?= (!empty($event['google_map_link']) && filter_var($event['google_map_link'], FILTER_VALIDATE_URL)) ? 'hidden' : '' ?>">
                                        <i class="fas fa-info-circle text-yellow-500 mr-2 text-lg flex-shrink-0"></i>
                                        <p class="text-sm text-yellow-800">
                                            You are registered, but the event link is not available yet. Please check back later or contact the organizer.
                                        </p>
                                    </div>
                                </div>

                                <?php // --- Message for past events --- ?>
                                <div id="onlineEventPastMessage" class="
                                    <?php if ($isPastEvent) { echo ''; } else { echo 'hidden'; } ?>
                                ">
                                     <div class="flex items-center p-3 bg-white border border-gray-300 rounded-md">
                                        <i class="fas fa-times-circle text-gray-500 mr-2 text-lg flex-shrink-0"></i>
                                        <p class="text-sm text-gray-600">
                                            This online event has finished.
                                            <?= $is_registered ? 'Thank you for attending.' : 'You were not registered for this event.' ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <!-- End Online Event Link Section -->


                        <!-- Event Info Section -->
                        <div class="mb-8">
                            <h3 class="text-xl font-semibold mb-4 flex items-center">
                                <i class="fas fa-info-circle text-purple-600 mr-2"></i> Event Information
                            </h3>
                            <div class="space-y-4 text-sm">
                                <div class="flex items-start">
                                    <i class="fas fa-landmark text-gray-400 mt-1 mr-3 w-4 flex-shrink-0 text-center"></i>
                                    <div>
                                        <h4 class="font-semibold text-gray-700">Venue / Platform</h4>
                                        <p class="text-gray-600"><?= htmlspecialchars($event['venue']) ?></p>
                                    </div>
                                </div>
                                <?php if (!$event['is_online'] && !empty($event['venue'])): // Check if venue exists instead of map link ?>
                                    <div class="mt-8 mb-12">
                                        <div class="space-y-4">
                                            <div class="flex items-center justify-between">
                                                <h3 class="text-xl font-semibold flex items-center">
                                                    <i class="fas fa-map-marker-alt text-purple-600 mr-2"></i>
                                                    Location Information
                                                </h3>
                                            </div>

                                            <?php // --- Start of Static Location Block --- ?>
                                            <div class="p-4 bg-purple-50 rounded-lg border border-purple-100">
                                                <div class="flex items-start">
                                                    <div class="flex-shrink-0 mt-1"> <?php // Adjusted margin-top ?>
                                                        <i class="fas fa-info-circle text-purple-600 text-lg"></i>
                                                    </div>
                                                    <div class="ml-3 flex-1"> <?php // Added flex-1 ?>
                                                        <?php /* <h3 class="text-sm font-medium text-purple-800">Location Information</h3> */ // Title removed as it's now the section title ?>
                                                        <div class="mt-1 text-sm text-purple-700"> <?php // Adjusted margin-top ?>
                                                            <p class="mb-2 text-xs text-purple-600">The event will be held at:</p> <?php // Slightly smaller prompt ?>
                                                            <p class="font-semibold text-base text-purple-900"><?= htmlspecialchars($event['venue']) ?></p> <?php // Larger venue name ?>
                                                            <p class="text-purple-800"><?= htmlspecialchars($location) ?></p> <?php // Location (City, State) ?>
                                                        </div>
                                                        <div class="mt-4 pt-3 border-t border-purple-200"> <?php // Added border top ?>
                                                            <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode(htmlspecialchars($event['venue'] . ', ' . $location)) ?>"
                                                            target="_blank"
                                                            rel="noopener noreferrer" <?php // Added rel attribute ?>
                                                            class="inline-flex items-center text-sm font-medium text-purple-600 hover:text-purple-800 hover:underline">
                                                                <i class="fas fa-external-link-alt mr-2"></i>
                                                                View on Google Maps
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php // --- End of Static Location Block --- ?>

                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="flex items-start">
                                    <i class="fas fa-users text-gray-400 mt-1 mr-3 w-4 flex-shrink-0 text-center"></i>
                                    <div>
                                        <h4 class="font-semibold text-gray-700">Audience</h4>
                                        <p class="text-gray-600"><?= htmlspecialchars($event['audience']) ?></p>
                                    </div>
                                </div>
                                <?php if (!empty($event['attention'])): ?>
                                <div class="flex items-start p-3 bg-yellow-50 border border-yellow-200 rounded-md">
                                    <i class="fas fa-exclamation-triangle text-yellow-500 mt-1 mr-3 w-4 flex-shrink-0 text-center"></i>
                                    <div>
                                        <h4 class="font-semibold text-yellow-800">Attention</h4>
                                        <p class="text-yellow-700"><?= nl2br(htmlspecialchars($event['attention'])) ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="mb-8 prose prose-sm max-w-none text-gray-700 leading-relaxed">
                            <h3 class="text-xl font-semibold mb-3 flex items-center not-prose">
                                <i class="fas fa-align-left text-purple-600 mr-2"></i> Description
                            </h3>
                            <?= nl2br(htmlspecialchars($event['description'])) // Use nl2br for line breaks ?>
                        </div>


                        <!-- Terms & Conditions -->
                        <?php if (!empty($event['terms_and_conditions'])): ?>
                        <div class="mb-8">
                             <h3 class="text-xl font-semibold mb-3 flex items-center">
                                <i class="fas fa-file-contract text-purple-600 mr-2"></i> Terms & Conditions
                            </h3>
                            <div class="bg-gray-50 border border-gray-200 p-4 rounded-lg text-sm text-gray-600 leading-relaxed max-h-48 overflow-y-auto">
                                <?= nl2br(htmlspecialchars($event['terms_and_conditions'])) ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Tags -->
                        <?php if (!empty($event['event_tags'])): ?>
                        <div class="mt-8 pt-6 border-t border-gray-100">
                            <h3 class="text-xl font-semibold mb-4 flex items-center">
                                <i class="fas fa-tags text-purple-600 mr-2"></i> Event Tags
                            </h3>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach (explode(',', $event['event_tags']) as $tag): if(!empty(trim($tag))): ?>
                                <span class="px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full text-xs font-medium hover:bg-purple-100 transition-colors cursor-default">
                                    <?= htmlspecialchars(trim($tag)) ?>
                                </span>
                                <?php endif; endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sidebar -->
                    <div class="w-full md:w-72 lg:w-80 shrink-0">
                        <div class="sticky top-8 bg-gradient-to-br from-white to-gray-50 p-6 rounded-xl shadow-lg border border-gray-100">
                            <!-- Price -->
                            <div class="mb-6 pb-6 border-b border-gray-200">
                                <?php $eventPrice = isset($event['price']) ? (float)$event['price'] : 0.0; ?>
                                <?php if ($eventPrice > 0): ?>
                                    <div class="text-sm text-gray-500 mb-1">Ticket Price</div>
                                    <div class="text-4xl font-bold text-purple-600">
                                        RM <?= number_format($eventPrice, 2) ?>
                                    </div>
                                    <!-- Non-Refundable Notice -->
                                    <div class="mt-3 p-2 bg-yellow-50 border border-yellow-200 rounded-md text-xs text-yellow-800 flex items-start gap-2">
                                        <svg class="w-4 h-4 flex-shrink-0 mt-0.5 text-yellow-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.616-1.23 2.87-1.23 3.486 0l5.58 11.161c.413.826-.097 1.84-.943 1.84H3.62c-.846 0-1.356-1.014-.943-1.84L8.257 3.099zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 7a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"></path></svg>
                                        <span><strong>Important:</strong> Paid registrations are non-refundable and cannot be cancelled.</span>
                                    </div>
                                    <!-- End Non-Refundable Notice -->
                                <?php else: ?>
                                    <div class="text-sm text-gray-500 mb-1">Admission</div>
                                    <div class="text-2xl font-semibold text-green-600">
                                        Free Event
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Save/Unsave Button -->
                            <?php if ($user_id > 0 && !$isPastEvent): // Only show for logged-in users on upcoming events ?>
                            <div class="mt-4 mb-6"> <?php // Add some margin ?>
                                <button type="button"
                                        class="save-event-btn w-full inline-flex items-center justify-center px-4 py-2 border text-sm font-medium rounded-md transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2 relative min-h-[38px] group
                                            <?= $is_currently_saved
                                                ? 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100 focus:ring-red-500'
                                                : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-indigo-500' ?>"
                                        data-event-id="<?= $event['id'] ?>"
                                        data-saved="<?= $is_currently_saved ? 'true' : 'false' ?>"
                                        onclick="toggleSaveEvent(this)"
                                        title="<?= $is_currently_saved ? 'Remove this event from your saved list' : 'Save this event for later' ?>">

                                    <span class="button-text flex items-center gap-2">
                                        <i class="fa-fw <?= $is_currently_saved ? 'fas fa-bookmark text-red-500' : 'far fa-bookmark text-gray-400 group-hover:text-indigo-500' ?>"></i> <?php // Use fas for solid, far for regular ?>
                                        <span><?= $is_currently_saved ? 'Event Saved' : 'Save Event' ?></span>
                                    </span>
                                    <span class="loading hidden absolute inset-0 flex items-center justify-center bg-opacity-50">
                                        <svg class="animate-spin h-5 w-5 text-current" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                    </span>
                                </button>
                                <div id="save-error-<?= $event['id'] ?>" class="text-red-600 text-xs mt-1 text-center hidden"></div>
                            </div>
                            <?php endif; ?>
                            <!-- End Save/Unsave Button -->


                            <!-- Registration Area -->
                            <div class="space-y-3">
                                <!-- THE ONE AND ONLY Main Registration Button -->
                                <button
                                    onclick="<?= ($buttonDisabled) ? '' : "initiateRegistration(event, {$event['id']}, {$eventPrice})" ?>"
                                    class="<?= $buttonClasses ?>"
                                    id="mainRegisterButton"
                                    data-event-id="<?= $event['id'] ?>"
                                    data-registered="<?= $is_registered ? '1' : '0' ?>"
                                    data-payment-status="<?= $effective_status ?? 'none' ?>"
                                    <?= $buttonDisabled ? 'disabled' : '' ?>
                                    <?= ($user_id > 0 && !$profileComplete) ? 'data-profile-incomplete="true"' : '' ?>
                                    title="<?= htmlspecialchars($disableReason) // Use the dynamically generated tooltip ?>"
                                    >
                                    <div class="flex items-center justify-center gap-2">
                                        <i class="fas <?= $iconClass ?> mr-1"></i>
                                        <span class="button-text"><?= $buttonText ?></span>
                                        <span class="loading hidden"> <!-- Loading Spinner -->
                                            <svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                        </span>
                                    </div>
                                </button>

                                <!-- Messages Below Button (Conditional Display) -->
                                <?php if ($user_id > 0 && !$profileComplete && !$isPastEvent && !$is_registered): // Show profile complete prompt only if relevant ?>
                                    <p class="text-center text-sm text-yellow-700 bg-yellow-50 border border-yellow-200 p-2 rounded-md mt-2"> <i class="fas fa-exclamation-circle mr-1"></i> <a href="/pages/account_info.php?reason=incomplete_profile&redirect=<?= urlencode(APP_BASE_URL . $_SERVER['REQUEST_URI']) ?>" class="font-medium underline hover:text-yellow-800">Complete your profile</a> to register. </p>

                                <?php elseif ($is_registered): // Already paid or free ?>
                                    <p class="text-center text-sm text-green-700 mt-3 font-medium">
                                        <i class="fas fa-check-circle mr-1"></i> You are registered for this event.
                                    </p>
                                    <?php if (!$isPastEvent): // Show manage link only if registered and upcoming ?>
                                        <div class="mt-3 text-center">
                                            <a href="/pages/registered_events.php?showEvent=<?= $event['id'] ?>" 
                                               class="inline-flex items-center justify-ce   nter px-4 py-2 border border-indigo-200 hover:border-indigo-300 text-sm font-medium rounded-md text-indigo-700 bg-indigo-50 hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-150"
                                               title="Manage your registration (e.g., cancel, check details) on the My Events page">
                                                <i class="fas fa-calendar-check mr-1.5 text-xs"></i>
                                                Manage in My Events
                                            </a>
                                        </div>
                                    <?php else: // Event is past, still show registered status but no manage link ?>
                                        <p class="text-center text-xs text-gray-500 mt-2">(This event has finished)</p>
                                    <?php endif; ?>

                                <?php elseif (($effective_status === 'pending' || $effective_status === 'failed') && !$bill_expired): // COMBINED: Pending or Failed, Not Expired ?>
                                    <div class="text-center text-sm text-yellow-800 bg-yellow-50 border border-yellow-200 p-2 rounded-md mt-2">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        <?php if ($effective_status === 'pending'): ?>
                                            Your payment is awaiting completion.
                                        <?php else: ?>
                                            Your previous payment attempt failed.
                                        <?php endif; ?>
                                        <?php if ($expiry_message): ?> <span class="block text-xs text-gray-600 mt-1"><?= htmlspecialchars($expiry_message) ?></span> <?php endif; ?>
                                    </div>

                                <?php elseif ($effective_status === 'expired'): // Expired ?>
                                    <p class="text-center text-sm text-red-700 mt-3"><?= htmlspecialchars($expiry_message) ?></p>
                                    <p class="text-center text-xs text-gray-500 mt-1">You can start a new registration attempt now.</p>

                                <?php elseif ($user_id <= 0 && !$isPastEvent) : // Show login prompt only if needed ?>
                                     <p class="text-center text-sm text-gray-500 mt-3">Please <a href="/pages/login.php?redirect=<?= urlencode(APP_BASE_URL . $_SERVER['REQUEST_URI']) ?>" class="text-purple-600 hover:underline font-medium">log in</a> or <a href="/pages/register.php" class="text-purple-600 hover:underline font-medium">sign up</a> to register.</p>
                                <?php endif; ?>

                            </div>


                            <!-- Organizer Info -->
                            <?php if ($event['organizer_name']): // Only show if organizer exists ?>
                            <div class="mt-8 pt-6 border-t border-gray-200">
                                <h4 class="text-sm font-medium text-gray-500 mb-4">Hosted by</h4>
                                <div class="flex items-center gap-4"> <?php // Use items-center for vertical alignment ?>
                                    <img src="<?= htmlspecialchars($event['profile_image_path']) ?>"
                                         class="w-12 h-12 rounded-full object-cover border-2 border-purple-100 shadow-sm flex-shrink-0" <?php // Slightly larger, added shadow ?>
                                         alt="<?= htmlspecialchars($event['organizer_name']) ?>'s profile picture"
                                         onerror="this.onerror=null; this.src='<?= $default_avatar_url ?>';">
                                    <div class="flex-1 min-w-0"> <?php // Ensure text wraps correctly ?>
                                        <?php // Organizer Name ?>
                                        <p class="font-semibold text-base text-gray-800 truncate" title="<?= htmlspecialchars($event['organizer_name']) ?>"> <?php // Slightly larger font, truncate long names ?>
                                            <?= htmlspecialchars($event['organizer_name']) ?>
                                        </p>
                                        <?php // Indicator if viewing own event ?>
                                        <?php if ($user_id > 0 && $user_id == $event['created_by']): ?>
                                            <span class="block text-xs text-purple-600 mt-0.5">(This is you)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Organizer Actions & Contact Container -->
                                <div class="mt-4 pt-4 border-t border-gray-100 space-y-3"> <?php // Added top padding/border ?>

                                    <?php // --- View Organizer Events Link --- ?>
                                    <div> <?php // Wrapper div for layout ?>
                                        <a href="/pages/organizer_events.php?id=<?= (int)$event['created_by'] ?>"
                                           class="w-full inline-flex items-center justify-center px-3 py-1.5 border border-purple-200 text-xs font-medium rounded-md text-purple-700 bg-purple-50 hover:bg-purple-100 hover:border-purple-300 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-purple-500 transition-colors duration-150 group"
                                           title="View all events hosted by <?= htmlspecialchars($event['organizer_name']) ?>">
                                            <i class="fas fa-calendar-week mr-1.5 text-purple-500 group-hover:text-purple-600 text-xs"></i>
                                            View Organizer's Events
                                        </a>
                                    </div>
                                     <?php // --- End View Organizer Events Link --- ?>


                                    <?php // --- Follow Button --- ?>
                                    <?php if ($user_id > 0 && $user_id != $event['created_by']): // Only show if logged in and not the organizer themselves ?>
                                        <div> <?php // Wrapper div for layout ?>
                                            <button type="button"
                                                    class="follow-organizer-btn w-full inline-flex items-center justify-center px-3 py-1.5 border text-xs font-medium rounded-md transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-1 relative min-h-[28px] <?= $is_following ? 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100 focus:ring-red-500' : 'border-green-300 bg-green-50 text-green-700 hover:bg-green-100 focus:ring-green-500' ?>"
                                                    data-organizer-id="<?= (int)$event['created_by'] ?>"
                                                    data-following="<?= $is_following ? 'true' : 'false' ?>"
                                                    onclick="toggleFollowOrganizer(this)">
                                                <span class="button-text flex items-center gap-1.5">
                                                    <i class="fas <?= $is_following ? 'fa-user-minus' : 'fa-user-plus' ?> text-xs"></i> <?php // Icon size fixed ?>
                                                    <span><?= $is_following ? 'Unfollow Organizer' : 'Follow Organizer' ?></span> <?php // More explicit text ?>
                                                </span>
                                                <span class="loading hidden absolute inset-0 flex items-center justify-center bg-opacity-50">
                                                    <svg class="animate-spin h-4 w-4 text-current" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                                </span>
                                            </button>
                                            <div id="follow-error-<?= (int)$event['created_by'] ?>" class="text-red-600 text-xs mt-1 text-center hidden"></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php // --- End Follow Button --- ?>


                                    <?php // --- Contact Info --- ?>
                                    <?php if ($user_id > 0 && $user_id != $event['created_by']): // Show contact if logged in and not the host ?>
                                        <div class="text-center border-t border-gray-100 pt-3 space-y-1"> <?php // Centered contact info ?>
                                            <p class="text-xs text-gray-500 mb-1">Contact Organizer:</p>
                                            <?php // Email Link ?>
                                            <?php if (!empty($event['organizer_email'])): ?>
                                                <a href="mailto:<?= htmlspecialchars($event['organizer_email']) ?>"
                                                   class="inline-flex items-center text-purple-600 text-xs hover:text-purple-700 hover:underline px-2 py-0.5 rounded hover:bg-purple-50">
                                                    <i class="fas fa-envelope w-3 mr-1.5 text-center opacity-75"></i> Email
                                                </a>
                                            <?php endif; ?>
                                            <?php // Phone Link (only if phone exists) ?>
                                            <?php if (!empty($event['organizer_phone'])): ?>
                                                <a href="tel:<?= htmlspecialchars($event['organizer_phone']) ?>"
                                                   class="inline-flex items-center text-purple-600 text-xs hover:text-purple-700 hover:underline px-2 py-0.5 rounded hover:bg-purple-50">
                                                    <i class="fas fa-phone w-3 mr-1.5 text-center opacity-75"></i> <?= htmlspecialchars($event['organizer_phone']) ?>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (empty($event['organizer_email']) && empty($event['organizer_phone'])): ?>
                                                <span class="text-xs text-gray-400 italic">Contact info not available</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php // --- End Contact Info --- ?>

                                </div> <!-- End Organizer Actions & Contact Container -->

                            </div> <!-- End Organizer Info Section -->
                            <?php endif; ?>

                        </div> <!-- End Sticky Sidebar -->
                    </div> <!-- End Sidebar Wrapper -->
                </div> <!-- End Main Flex Container -->
            </div> <!-- End Padding Container -->
        </div> <!-- End Main Card -->


        <!-- *** Feedback Section *** -->
         <?php
         // Determine if feedback section should be shown:
         // 1. Event must be past.
         // 2. User must be logged in.
         // 3. User must have a 'paid' or 'free' registration status for this event.
         $show_feedback_section = $isPastEvent && $user_id > 0 && $is_registered;
         ?>
        <?php if ($show_feedback_section) : ?>
            <section class="mt-10 mb-10 bg-white rounded-xl shadow-lg p-6 md:p-8" id="feedbackSection">
                <h2 class="text-2xl font-bold text-gray-900 mb-5 flex items-center">
                    <i class="fas fa-comments text-purple-600 mr-2"></i>
                    Leave Feedback
                </h2>

                <?php if ($has_submitted_feedback) : ?>
                    <div class="bg-green-50 border-l-4 border-green-400 p-4 rounded-md shadow-sm">
                        <div class="flex items-center">
                            <div class="flex-shrink-0"> <i class="fas fa-check-circle text-green-500 text-xl"></i> </div>
                            <div class="ml-3"> <p class="text-sm font-medium text-green-800"> Thank you! You have already submitted feedback for this event. </p> </div>
                        </div>
                    </div>
                <?php else : ?>
                    <form id="feedbackForm" novalidate>
                        <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                        <div class="mb-4">
                            <label for="feedbackComment" class="block text-sm font-medium text-gray-700 mb-1">Your Feedback:</label>
                            <textarea id="feedbackComment" name="comment" rows="4" required maxlength="1000"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500 sm:text-sm placeholder-gray-400"
                                      placeholder="Share your thoughts about the event... (max 1000 characters)"></textarea>
                            <div id="feedbackError" class="text-red-600 text-sm mt-1 hidden"></div>
                        </div>
                        <div class="text-right">
                            <button type="submit" id="submitFeedbackBtn"
                                    class="inline-flex items-center justify-center px-6 py-2 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition duration-150 ease-in-out disabled:opacity-50 disabled:cursor-not-allowed">
                                <span class="button-text">Submit Feedback</span>
                                <span class="loading hidden ml-2">
                                    <svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                </span>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php elseif ($isPastEvent && $user_id <= 0): // Show login prompt if past and guest ?>
            <section class="mt-10 mb-10 bg-white rounded-xl shadow-lg p-6 md:p-8">
                <div class="text-center text-gray-600">
                    <i class="fas fa-comments text-purple-400 text-3xl mb-2"></i><br>
                    <a href="/pages/login.php?redirect=<?= urlencode(APP_BASE_URL . $_SERVER['REQUEST_URI']) ?>" class="text-purple-600 hover:underline font-medium">Log in</a> to leave feedback for this event.
                </div>
            </section>
        <?php endif; ?>
        <!-- End Feedback Section -->


        <!-- Related Events -->
        <?php if ($related_events): ?>
        <section class="mt-12">
            <h2 class="text-2xl md:text-3xl font-bold text-gray-900 mb-6 flex items-center">
                <i class="fas fa-calendar-alt text-purple-500 mr-2"></i> Similar Upcoming Events
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($related_events as $related):
                    // Prepare data for the card
                    $related_card_link = "event.php?id=" . $related['id'];
                    $related_title = htmlspecialchars($related['title']);
                    $related_image_url = '/assets/images/' . (!empty($related['image_url']) && $related['image_url'] !== 'default-event.jpg' ? htmlspecialchars($related['image_url']) : 'default-event.jpg');
                    $related_default_image_url = '/assets/images/default-event.jpg';
                    $related_location = htmlspecialchars($related['city'] ?? 'Online'); // Use city or 'Online'
                    $related_is_online = empty($related['city']); // Infer online status if city is not set (based on query limitation)
                    $related_date_formatted = date('D, M j, Y', strtotime($related['event_date']));
                    // Time is not fetched in the related query, so we omit it.
                    $related_participant_count = $related['participant_count'] ?? 0;
                    // Price is not fetched in the related query, so we omit the price badge.

                    $related_card_classes = "event-card group bg-white rounded-xl shadow hover:shadow-lg transition-all duration-300 overflow-hidden flex flex-col border border-gray-100";

                ?>
                <a href="<?= $related_card_link ?>" class="<?= $related_card_classes ?>">
                    <div class="relative h-48 bg-gray-200 overflow-hidden"> <?php // Image container ?>
                        <img src="<?= $related_image_url ?>"
                             alt="<?= $related_title ?>"
                             class="w-full h-full object-cover event-image" <?php // Added event-image class for consistency if needed ?>
                             onerror="this.onerror=null; this.src='<?= $related_default_image_url ?>';">
                        <?php // Price badge omitted as price is not fetched for related events ?>
                        <?php // ADDED: Online Indicator Badge on Image ?>
                        <?php if ($related_is_online): ?>
                        <div class="absolute top-2 left-2 bg-blue-600 text-white px-2 py-0.5 rounded-full text-xs font-medium flex items-center shadow">
                            <i class="fas fa-video mr-1 text-xs opacity-90"></i> Online
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-5 flex flex-col flex-grow"> <?php // Increased padding ?>
                        <h3 class="font-semibold text-lg mb-2 text-gray-800 group-hover:text-purple-700 transition-colors line-clamp-2" title="<?= $related_title ?>"> <?php // Increased font size, line-clamp ?>
                            <?= $related_title ?>
                        </h3>
                        <div class="text-sm text-gray-600 mb-4 space-y-1.5"> <?php // Adjusted spacing ?>
                            <div class="flex items-center">
                                <i class="fas fa-calendar-day w-4 mr-2 text-center text-gray-400 flex-shrink-0"></i>
                                <span><?= $related_date_formatted ?></span>
                            </div>
                            <?php /* Time omitted as not available in $related
                            <div class="flex items-center">
                                <i class="fas fa-clock w-4 mr-2 text-center text-gray-400 flex-shrink-0"></i>
                                <span>Event Time</span>
                            </div>
                            */ ?>
                            <div class="flex items-center"> <?php // Location/Online Indicator Line ?>
                                <?php if ($related_is_online): ?>
                                    <i class="fas fa-video w-4 mr-2 text-center text-blue-500 flex-shrink-0"></i> <?php // Specific icon for online ?>
                                    <span class="text-blue-700 font-medium"><?= $related_location ?></span> <?php // Style online text differently ?>
                                <?php else: ?>
                                    <i class="fas fa-map-marker-alt w-4 mr-2 text-center text-gray-400 flex-shrink-0"></i>
                                    <span class="truncate" title="<?= $related_location ?>"><?= $related_location ?></span>
                                <?php endif; ?>
                            </div>
                            <?php // Participant Count ?>
                            <div class="flex items-center pt-1">
                                <i class="fas fa-users w-4 mr-2 text-center text-purple-500 flex-shrink-0"></i>
                                <span class="font-medium text-purple-700"><?= $related_participant_count ?></span>
                                <span class="ml-1 text-gray-500">participant<?= $related_participant_count !== 1 ? 's' : '' ?> registered</span>
                            </div>
                        </div>
                        <div class="mt-auto pt-3 border-t border-gray-100 text-right">
                            <span class="inline-flex items-center text-sm font-medium text-purple-600 group-hover:text-purple-800 group-hover:underline">
                                View Details
                                <i class="fas fa-arrow-right ml-1.5 text-xs transition-transform duration-200 group-hover:translate-x-1"></i> <?php // Added arrow icon ?>
                            </span>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

         <!-- Confirmation Modal (for My Events page primarily, but can be used here) -->
        <div id="confirmationModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[60] hidden items-center justify-center p-4 transition-opacity"
            x-data="{ showConfirm: false }" x-show="showConfirm"
            x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6"
                 @click.away="showConfirm = false; hideConfirmation();"
                 x-show="showConfirm"
                 x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">
                <div class="flex items-start space-x-4">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left flex-1">
                        <h3 class="text-lg leading-6 font-medium text-gray-900">Confirm Cancellation</h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-600">Are you sure you want to cancel your registration for this event?</p>
                            <p class="text-xs text-red-600 mt-1">Note: Refunds for paid events are subject to organizer policy and are not processed automatically here.</p>
                        </div>
                    </div>
                </div>
                <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                     <button type="button" onclick="confirmCancellation()"
                             class="inline-flex justify-center w-full rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:w-auto sm:text-sm disabled:opacity-50 relative min-w-[120px]">
                         <span class="button-text">Yes, Cancel</span>
                         <span class="loading hidden absolute inset-0 flex items-center justify-center">
                              <svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                         </span>
                     </button>
                     <button type="button" onclick="hideConfirmation()"
                             class="mt-3 inline-flex justify-center w-full rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:w-auto sm:text-sm">
                         No, Keep Registration
                     </button>
                </div>
            </div>
        </div>


    </main>

    <script>
        function highlightRegisterButton(clickEvent) {
            clickEvent.preventDefault(); // Prevent the default '#' link behavior

            const button = document.getElementById('mainRegisterButton');
            if (button) {
                // 1. Scroll to the button (optional, but helpful)
                button.scrollIntoView({ behavior: 'smooth', block: 'center' });

                // 2. Add highlight class(es)
                button.classList.add('ring-4', 'ring-offset-2' ,'ring-purple-400', 'transition-all', 'duration-1000', 'ease-out');

                // 3. Remove highlight class(es) after a delay
                setTimeout(() => {
                    button.classList.remove('ring-4', 'ring-offset-2', 'ring-purple-400', 'duration-1000');
                    // Optional: Focus after highlight fades
                     // button.focus({ preventScroll: true }); // focus without scrolling again
                }, 1000); // Keep highlight for 1 second (1000ms)
            }
        }

         // Define setButtonLoading if not already global from events.js
        if (typeof setButtonLoading === 'undefined') {
            function setButtonLoading(button, isLoading) {
                if (!button) return;
                button.disabled = isLoading;
                const buttonTextSpan = button.querySelector('.button-text');
                const loadingSpan = button.querySelector('.loading');
                if (isLoading) {
                    if (buttonTextSpan) buttonTextSpan.style.visibility = 'hidden';
                    if (loadingSpan) loadingSpan.classList.remove('hidden');
                } else {
                    if (buttonTextSpan) buttonTextSpan.style.visibility = 'visible';
                    if (loadingSpan) loadingSpan.classList.add('hidden');
                }
            }
        }
        // Define showToast if not already global from events.js
        if (typeof showToast === 'undefined') {
            function showToast(message, type = 'info') {
                // Basic alert fallback if toast system isn't loaded
                console.log(`Toast (${type}): ${message}`);
                // alert(`(${type.toUpperCase()}) ${message}`);
            }
        }

        function handleMapError(iframe) {
            document.getElementById('map-error').classList.remove('hidden');
            iframe.style.display = 'none';
        }

        function handleMapLoad(iframe) {
            iframe.addEventListener('error', function() {
                handleMapError(iframe);
            });

            // Check if loaded successfully after 1 second
            setTimeout(function() {
                try {
                    const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                    if (!iframeDoc) {
                        handleMapError(iframe);
                    }
                } catch (e) {
                    handleMapError(iframe);
                }
            }, 1000);
        }

    </script>

    <script src="/assets/js/events.js"></script>
    <?php include '../includes/footer.php'; ?>
</body>
</html>