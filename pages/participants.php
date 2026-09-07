<?php
session_start();
require_once '../config/db.php';

// Check organizer status
if (!isset($_SESSION['user_id']) || $_SESSION['organizer_status'] !== 'approved') {
    header('Location: /pages/login.php');
    exit();
}

// Get event ID and ensure it belongs to the organizer
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$organizer_id = $_SESSION['user_id'];

if ($event_id <= 0) {
    $_SESSION['errors'] = ["Invalid event specified."];
    header('Location: /pages/organizer_dashboard.php');
    exit();
}

// Fetch event details AND verify ownership
try {
    $stmt = $pdo->prepare("SELECT title, participant_limit FROM events WHERE id = ? AND created_by = ?");
    $stmt->execute([$event_id, $organizer_id]);
    $event = $stmt->fetch();

    if (!$event) {
        $_SESSION['errors'] = ["Event not found or you do not have permission to view its participants."];
        header('Location: organizer_dashboard.php');
        exit();
    }

} catch(PDOException $e) {
     error_log("Database error fetching event details: " . $e->getMessage());
     $_SESSION['errors'] = ["Could not load event details."];
     header('Location: organizer_dashboard.php');
     exit();
}


// Fetch participants
try {
    $stmt = $pdo->prepare("
        SELECT u.name, u.email, r.registration_date
        FROM registrations r
        JOIN users u ON r.user_id = u.id
        WHERE r.event_id = ?
        ORDER BY r.registration_date ASC
    ");
    $stmt->execute([$event_id]);
    $participants = $stmt->fetchAll();
} catch(PDOException $e) {
    error_log("Database error fetching participants: " . $e->getMessage());
    $_SESSION['errors'] = ["Could not load participant list."];
     // Don't exit, show the page with an error or empty state
     $participants = [];
}

$current_participants = count($participants);
$limit = $event['participant_limit'];
$is_full = ($limit !== null && $current_participants >= $limit);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Participants for <?= htmlspecialchars($event['title']) ?> - CECT</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
     <style>
         /* Custom styling if needed */
         .table-cell-icon {
             @apply w-4 h-4 text-gray-400 mr-2 shrink-0;
         }
     </style>
</head>
<body class="bg-gray-100">
    <?php include '../includes/nav.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Display Errors if any -->
        <?php if (isset($_SESSION['errors']) && is_array($_SESSION['errors'])): ?>
            <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 shadow-md" role="alert">
                 <div class="flex items-start gap-3">
                    <div class="shrink-0 text-red-600 pt-0.5"><i class="fas fa-exclamation-circle fa-lg"></i></div>
                    <div class="flex-1 space-y-1">
                        <?php foreach ($_SESSION['errors'] as $error): ?>
                            <p class="text-sm font-medium text-red-800"><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php unset($_SESSION['errors']); ?>
            </div>
        <?php endif; ?>


        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                 <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 mb-1">Participants</h1>
                        <p class="text-sm text-gray-600">Event: <span class="font-medium text-purple-700"><?= htmlspecialchars($event['title']) ?></span></p>
                        <div class="mt-2 flex items-center text-sm text-gray-500 gap-3">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 shadow-sm">
                                <i class="fas fa-users mr-1.5"></i>
                                <?= $current_participants ?> /
                                <?php if ($limit !== null): ?>
                                    <?= $limit ?> Registered
                                <?php else: ?>
                                    Unlimited
                                <?php endif; ?>
                            </span>
                            <?php if ($is_full): ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 shadow-sm">
                                    <i class="fas fa-times-circle mr-1.5"></i> Event Full
                                </span>
                            <?php elseif ($limit !== null): ?>
                                 <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 shadow-sm">
                                    <i class="fas fa-check-circle mr-1.5"></i> Spots Available
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                     <a href="/pages/organizer_dashboard.php"
                       class="flex items-center bg-white text-gray-700 px-4 py-2 rounded-lg border border-gray-300 hover:bg-gray-100 transition-colors font-medium text-sm shadow-sm group">
                        <i class="fas fa-arrow-left w-4 h-4 mr-2 text-gray-500 transition-transform group-hover:-translate-x-1"></i>
                        Back to Dashboard
                    </a>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                Participant
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                Email Address
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                Registered On
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($participants)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-16 text-center">
                                    <div class="flex flex-col items-center justify-center space-y-3 text-gray-400">
                                        <i class="fas fa-user-slash fa-3x text-purple-200"></i>
                                        <p class="text-lg font-medium text-gray-600">No Participants Yet</p>
                                        <p class="text-sm max-w-xs">Registrations for this event will appear here.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($participants as $participant): ?>
                            <tr class="hover:bg-purple-50/30 transition-colors group">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center space-x-3">
                                        <div class="relative flex-shrink-0">
                                            <div class="h-10 w-10 bg-purple-100 rounded-full flex items-center justify-center ring-1 ring-purple-200">
                                                <span class="text-purple-600 font-semibold text-sm">
                                                    <?= strtoupper(substr(htmlspecialchars($participant['name']), 0, 1)) ?>
                                                </span>
                                            </div>
                                            <!-- Online status indicator (optional)
                                            <span class="absolute -bottom-0.5 -right-0.5 block h-2.5 w-2.5 rounded-full bg-green-400 ring-2 ring-white"></span>
                                            -->
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($participant['name']) ?></div>
                                            <!-- You could add registration ID or other info here -->
                                             <!-- <div class="text-xs text-gray-500">ID: REG-XXXX</div> -->
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="mailto:<?= htmlspecialchars($participant['email']) ?>" class="inline-flex items-center text-sm text-purple-600 hover:text-purple-800 hover:underline">
                                        <i class="table-cell-icon fas fa-envelope"></i>
                                        <?= htmlspecialchars($participant['email']) ?>
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <div class="flex items-center">
                                        <i class="table-cell-icon fas fa-calendar-check"></i>
                                        <?= date('M d, Y \a\t H:i', strtotime($participant['registration_date'])) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800 items-center">
                                        <i class="fas fa-check-circle mr-1.5"></i>
                                        Confirmed
                                    </span>
                                    <!-- Add other statuses if applicable (e.g., Waitlisted, Cancelled) -->
                                </td>
                            </tr>
                            <?php endforeach; ?>
                         <?php endif; ?>
                    </tbody>
                </table>
            </div>
             <?php if (!empty($participants)): ?>
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 text-sm text-gray-600">
                Showing <?= count($participants) ?> participant<?= count($participants) !== 1 ? 's' : '' ?>.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>