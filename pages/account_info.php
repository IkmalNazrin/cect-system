<?php
session_start();
$profile_error_message = $_SESSION['profile_error'] ?? null;
$reason = $_GET['reason'] ?? null;
unset($_SESSION['profile_error']); // Clear the message after reading

// Include Database Configuration FIRST
require_once '../config/db.php';
require_once '../lib/functions.php';

// Get messages from session and clear them
$success_message = $_SESSION['success_message'] ?? null; // Renamed for clarity
$info_message = $_SESSION['info_message'] ?? null; // For general info
$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['success_message'], $_SESSION['info_message'], $_SESSION['errors']);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // Redirect to login, passing the current page as the redirect target
    $redirectUrl = '/pages/account_info.php';
    header('Location: /pages/login.php?redirect=' . urlencode($redirectUrl));
    exit();
}

// Fetch user data from database
try {
    $user_id = $_SESSION['user_id'];
    // Fetch all columns needed for this page
    $query = "SELECT id, name, email, profile_image, first_name, last_name, phone, address, city, country, pincode, organizer_status, organization, org_type, contact_person, website, organizer_description, approval_notes, is_admin
              FROM users WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_unset();
        session_destroy();
        header('Location: login.php?error=InvalidSession');
        exit();
    }

    // --- Determine profile image path/URL for display ---
    $defaultAvatarWebPath = '/assets/images/profiles/default-avatar.jpg';
    $profileImageValue = $user['profile_image'] ?? $defaultAvatarWebPath; // Get value from DB
    $displayUserProfileImage = $defaultAvatarWebPath; // Start with default

    // --- Fetch Data for Notification Preferences ---
    $allCategories = [];
    $allTags = [];
    $tagsByCategory = []; // NEW: To store tags grouped by category
    $preferredCategoryIds = [];
    $preferredTagIds = [];

    // Fetch all categories
    $stmtCategories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC");
    $allCategories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all tags and group them by category_id
    $stmtTags = $pdo->query("SELECT id, name, category_id FROM tags WHERE category_id IS NOT NULL ORDER BY category_id, name ASC");
    $allTags = $stmtTags->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allTags as $tag) {
        $tagsByCategory[$tag['category_id']][] = $tag; // Group tags
    }

    // Fetch user's preferred category IDs
    $stmtPrefCat = $pdo->prepare("SELECT category_id FROM user_category_preferences WHERE user_id = :user_id");
    $stmtPrefCat->execute(['user_id' => $user_id]);
    $preferredCategoryIds = $stmtPrefCat->fetchAll(PDO::FETCH_COLUMN, 0);

    // Fetch user's preferred tag IDs
    $stmtPrefTag = $pdo->prepare("SELECT tag_id FROM user_tag_preferences WHERE user_id = :user_id");
    $stmtPrefTag->execute(['user_id' => $user_id]);
    $preferredTagIds = $stmtPrefTag->fetchAll(PDO::FETCH_COLUMN, 0);
    // --- End Fetch Data for Notification Preferences ---

    // Safety check: Ensure profileImageValue isn't just the short default name or empty
    if (empty($profileImageValue) || $profileImageValue === 'default-avatar.jpg') {
         $profileImageValue = $defaultAvatarWebPath;
    }

    // Check if the stored value looks like a URL (starts with http)
    if (strpos($profileImageValue, 'http://') === 0 || strpos($profileImageValue, 'https://') === 0) {
        // It's a URL (like from Google), use it directly
        // Escape it here for safe output later
        $displayUserProfileImage = htmlspecialchars($profileImageValue, ENT_QUOTES, 'UTF-8');
    }
    // Check if it's a non-empty local path AND not the default path already
    elseif (!empty($profileImageValue) && $profileImageValue !== $defaultAvatarWebPath) {
        // It's potentially a custom local path.
        // Construct full server path to check existence within allowed directories.
        $localFilePath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $profileImageValue);

        // Use @file_exists to suppress warnings (like open_basedir if path is weird)
        if (@file_exists($localFilePath)) {
            // Local file exists, use the relative web path (already stored in $profileImageValue)
            // Escape it here for safe output later
             $displayUserProfileImage = htmlspecialchars($profileImageValue, ENT_QUOTES, 'UTF-8');
        } else {
            // Local file specified doesn't exist, log error and use default.
            error_log("ACCOUNT_INFO Warning: Profile image file not found: " . $localFilePath . " - User: " . $user_id);
            $displayUserProfileImage = $defaultAvatarWebPath; // Fallback to default path
        }
    } else {
         // It is the default path or was empty after safety checks, use default
         $displayUserProfileImage = $defaultAvatarWebPath;
         // No need to escape the default path again if it's hardcoded safely
    }
    // --- End Image Path Determination ---

} catch (PDOException $e) {
    error_log("Database error fetching user data in account_info.php (User ID: {$user_id}): " . $e->getMessage());
    die("We encountered a problem loading your account information. Please try again later.");
} catch (Exception $e) {
    error_log("General error in account_info.php (User ID: {$user_id}): " . $e->getMessage());
    die("An unexpected error occurred. Please try again later.");
}

// Check profile completeness
$profileComplete = isUserProfileComplete($pdo, $user_id);
$canApply = $profileComplete && 
           ($user['organizer_status'] !== 'pending' && 
           $user['organizer_status'] !== 'approved');

// Determine if we should show the alert
$showProfileAlert = $profile_error_message || ($reason === 'incomplete_profile');
if (!$profile_error_message && $reason === 'incomplete_profile') {
     $profile_error_message = 'Please complete your profile (First Name, Last Name, Phone Number) to access certain features.';
}

$highlightClass = $showProfileAlert ? 'text-red-600 font-semibold' : 'text-gray-700';
$inputErrorClass = $showProfileAlert ? 'border-yellow-400 ring-1 ring-yellow-300 focus:border-yellow-500 focus:ring-yellow-500' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500';

// Determine user role for display
$user_role_display = 'User';
if ($user['is_admin']) {
    $user_role_display = 'Administrator';
} elseif ($user['organizer_status'] === 'approved') {
    $user_role_display = 'Organizer';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - CECT</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in { animation: fadeIn 0.3s ease-out; }

        /* Enhanced focus styles */
        input:focus, select:focus, textarea:focus {
            outline: 2px solid transparent;
            outline-offset: 2px;
            box-shadow: 0 0 0 3px rgba(167, 139, 250, 0.3); /* Tailwind violet-300 */
            border-color: #a78bfa; /* Tailwind violet-400 */
        }

        /* Profile image interaction */
        #profile_photo_label:hover .overlay { opacity: 1; }
        #profile_photo_label:hover img { filter: brightness(0.7); }
        .overlay { transition: opacity 0.3s ease; }

        /* Sticky sidebar */
        @media (min-width: 1024px) { /* lg breakpoint */
            .lg\:sticky { position: sticky; }
            .lg\:top-24 { top: 6rem; } /* Adjust based on nav height */
        }

        /* Save button loading state */
        button.is-loading .button-text { opacity: 0; }
        button.is-loading .loading-spinner { opacity: 1; }
        button .loading-spinner { transition: opacity 0.2s ease; }

        /* Alpine cloak */
         [x-cloak] { display: none !important; }

         .animate-pulse-slow { 
            animation: pulse 2.5s cubic-bezier(0.4, 0, 0.6, 1) infinite; 
        }
        @keyframes pulse { 
            0%, 100% { opacity: 1; } 
            50% { opacity: .8; } 
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 via-white to-gray-50">
<?php require_once '../includes/nav.php'; ?>

    <div class="min-h-screen pb-16">
        <!-- Main Content -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="flex flex-col lg:flex-row gap-8">
                <!-- Sidebar -->
                 <div class="w-full lg:w-72 flex-shrink-0">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 self-start lg:sticky lg:top-24">
                        <h2 class="p-6 text-xl font-semibold border-b border-gray-100 text-gray-700 flex items-center">
                            <svg class="w-6 h-6 inline-block mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"> <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Settings
                        </h2>
                        <div class="flex flex-col p-2 space-y-1">
                            <a href="account_info.php" class="flex items-center px-4 py-3 rounded-lg bg-purple-50 text-purple-700 font-medium transition-colors">
                                <svg class="w-5 h-5 mr-3" fill="currentColor" viewBox="0 0 20 20"> <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/> </svg>
                                Profile
                            </a>
                            <a href="/pages/change_email.php" class="flex items-center px-4 py-3 rounded-lg text-gray-600 hover:bg-gray-100 transition-colors font-medium">
                                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"> <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/> </svg>
                                Email
                            </a>
                            <a href="/pages/change_password.php" class="flex items-center px-4 py-3 rounded-lg text-gray-600 hover:bg-gray-100 transition-colors font-medium">
                                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"> <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/> </svg>
                                Password
                            </a>
                             <!-- Divider -->
                            <div class="pt-2 my-2 border-t border-gray-100"></div>

                            <!-- Organizer Application Section -->
                            <?php if ($user['organizer_status'] !== 'pending' && $user['organizer_status'] !== 'approved'): ?>
                                <div class="space-y-2">
                                    <?php if ($profileComplete): ?>
                                        <a href="/pages/organizer_application.php" 
                                        class="group flex items-center px-4 py-3 rounded-lg text-purple-700 bg-purple-50 hover:bg-purple-100 transition-colors font-medium">
                                            <svg class="w-5 h-5 mr-3 text-purple-600 group-hover:text-purple-700 transition-colors" 
                                                fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                                            </svg>
                                            Apply Organizer Now
                                            <svg class="w-4 h-4 ml-auto text-purple-500 group-hover:translate-x-1 transition-transform" 
                                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                            </svg>
                                        </a>
                                    <?php else: ?>
                                        <div class="px-4 py-3 text-xs text-yellow-700 bg-yellow-50 rounded-lg border border-yellow-100">
                                            <p class="font-medium mb-1"><i class="fas fa-exclamation-circle mr-1"></i>Profile Incomplete</p>
                                            <p>Complete your profile (First Name, Last Name, Phone) to apply. 
                                            <a href="/pages/account_info.php" class="underline hover:text-yellow-800">Update Profile</a>
                                            </p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($user['organizer_status'] === 'rejected' && !empty($user['approval_notes'])): ?>
                                        <div class="px-4 py-3 text-xs text-red-700 bg-red-50 rounded-lg border border-red-100">
                                            <p class="font-medium mb-1">Previous Rejection:</p>
                                            <p><?= nl2br(htmlspecialchars($user['approval_notes'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($user['organizer_status'] === 'pending'): ?>
                                <!-- Keep existing pending message -->
                                <div class="px-4 py-3 text-sm text-amber-700 bg-amber-50 rounded-lg border border-amber-200 flex items-center gap-2 font-medium">
                                    <svg class="w-5 h-5 flex-shrink-0 text-amber-500 animate-pulse" ...></svg>
                                    <span>Application Pending</span>
                                </div>
                            <?php elseif ($user['organizer_status'] === 'approved'): ?>
                                <!-- Keep existing approved message -->
                                <div class="px-4 py-3 text-sm text-green-700 bg-green-50 rounded-lg border border-green-200 flex items-center gap-2 font-medium">
                                    <svg class="w-5 h-5 flex-shrink-0 text-green-500" ...></svg>
                                    <span>Organizer Approved</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Main Content Area -->
                <div class="flex-1 bg-white rounded-xl shadow-sm border border-gray-100 min-w-0">
                    <div class="p-6 border-b border-gray-100">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <h2 class="text-2xl font-semibold text-gray-800">Account Information</h2>
                            <span class="text-sm px-3 py-1 rounded-full font-medium <?= match($user_role_display) {
                                'Administrator' => 'bg-red-100 text-red-800 ring-1 ring-red-200',
                                'Organizer' => 'bg-green-100 text-green-800 ring-1 ring-green-200',
                                default => 'bg-blue-100 text-blue-800 ring-1 ring-blue-200'
                            } ?>">
                                <?= $user_role_display ?>
                            </span>
                        </div>
                        <p class="mt-1 text-gray-600 text-sm">Manage your personal details and contact information.</p>
                    </div>

                    <!-- Messages Display Area -->
                    <div class="px-6 pt-4 space-y-4" x-data="{
                            showSuccess: <?= $success_message ? 'true' : 'false' ?>,
                            showInfo: <?= $info_message ? 'true' : 'false' ?>,
                            showError: <?= !empty($errors) ? 'true' : 'false' ?>
                         }"
                         x-init="
                            if(showSuccess) { setTimeout(() => showSuccess = false, 5000) }; /* Optional: Auto-hide success after 5s */
                         ">

                         <?php if ($showProfileAlert): ?>
                        <div role="alert" class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md shadow-sm animate-pulse-slow">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-yellow-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M8.485 3.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 3.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-yellow-800">Profile Completion Required</p>
                                    <p class="mt-1 text-sm text-yellow-700">
                                        <?= htmlspecialchars($profile_error_message) ?> Please update the highlighted fields below.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Success Message -->
                        <?php if ($success_message): ?>
                        <div role="alert"
                            x-show="showSuccess"
                            x-transition:enter="transition ease-out duration-300"
                            x-transition:enter-start="opacity-0 transform -translate-y-2"
                            x-transition:enter-end="opacity-100 transform translate-y-0"
                            x-transition:leave="transition ease-in duration-200"
                            x-transition:leave-start="opacity-100"
                            x-transition:leave-end="opacity-0"
                            @keydown.escape.window="showSuccess = false"
                            class="relative bg-green-50 border-l-4 border-green-400 text-green-800 px-4 py-3 rounded-lg shadow-sm flex items-start gap-3 text-sm">
                            <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            <span class="font-medium flex-1"><?= htmlspecialchars($success_message) ?></span>
                            <button @click="showSuccess = false" class="absolute top-1.5 right-1.5 p-1 text-green-500 hover:text-green-700 hover:bg-green-100 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-green-300" aria-label="Dismiss success message">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            </button>
                        </div>
                        <?php endif; ?>

                        <!-- Info Message -->
                        <?php if ($info_message): ?>
                        <div role="alert"
                             x-show="showInfo"
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 transform -translate-y-2"
                             x-transition:enter-end="opacity-100 transform translate-y-0"
                             x-transition:leave="transition ease-in duration-200"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0"
                             @keydown.escape.window="showInfo = false"
                             class="relative bg-blue-50 border-l-4 border-blue-400 text-blue-800 px-4 py-3 rounded-lg shadow-sm flex items-start gap-3 text-sm">
                            <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                            <span class="font-medium flex-1"><?= htmlspecialchars($info_message) ?></span>
                            <button @click="showInfo = false" class="absolute top-1.5 right-1.5 p-1 text-blue-500 hover:text-blue-700 hover:bg-blue-100 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-blue-300" aria-label="Dismiss info message">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            </button>
                         </div>
                        <?php endif; ?>

                        <!-- Error Messages -->
                        <?php if (!empty($errors)): ?>
                        <div role="alert"
                             x-show="showError"
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 transform -translate-y-2"
                             x-transition:enter-end="opacity-100 transform translate-y-0"
                             x-transition:leave="transition ease-in duration-200"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0"
                             @keydown.escape.window="showError = false"
                             class="relative bg-red-50 border-l-4 border-red-400 text-red-800 px-4 py-3 rounded-lg shadow-sm text-sm">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                                <div class="flex-1">
                                    <p class="font-medium mb-1">Please fix the following errors:</p>
                                    <ul class="list-disc list-inside ml-1 space-y-0.5">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?= htmlspecialchars($error) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <button @click="showError = false" class="absolute top-1.5 right-1.5 p-1 text-red-500 hover:text-red-700 hover:bg-red-100 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-red-300" aria-label="Dismiss error message">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <form action="/actions/update_profile.php" method="POST" enctype="multipart/form-data" class="p-6">
                         <!-- CSRF Token for profile update -->
                         <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                        <!-- Profile Photo Section -->
                         <div class="mb-10">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-4">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-800">Profile Photo</h3>
                                    <p class="text-sm text-gray-500 mt-1">Recommended: Square image, Max 5MB (JPG, PNG)</p>
                                </div>
                                <div class="relative flex-shrink-0" x-data="{ imagePreview: '<?= $displayUserProfileImage ?>' }">
                                    <label for="profile_photo" id="profile_photo_label" class="relative block w-24 h-24 rounded-xl cursor-pointer group overflow-hidden bg-gray-100 border border-gray-200 hover:border-purple-300 shadow-sm">
                                        <img x-bind:src="imagePreview"
                                             alt="Profile Preview"
                                             class="w-full h-full object-cover transition-all duration-300"
                                             onerror="this.onerror=null; this.src='<?= $defaultAvatarWebPath ?>';">
                                         <div class="overlay absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                             <svg class="w-8 h-8 text-white/80" fill="none" stroke="currentColor" viewBox="0 0 24 24"> <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/> </svg>
                                         </div>
                                        <span class="sr-only">Upload profile photo</span>
                                    </label>
                                    <input type="file" id="profile_photo" name="profile_photo" class="hidden" accept="image/jpeg, image/png"
                                           @change="
                                               const file = $event.target.files[0];
                                               if (file && file.size <= 5 * 1024 * 1024) { // Basic size check
                                                   const reader = new FileReader();
                                                   reader.onload = (e) => { imagePreview = e.target.result };
                                                   reader.readAsDataURL(file);
                                               } else if (file) {
                                                   alert('File is too large. Max 5MB.');
                                                   $event.target.value = null; // Clear selection
                                               }
                                           ">
                                </div>
                                <!-- Pass the original DB value -->
                                <input type="hidden" name="existing_profile_image" value="<?= htmlspecialchars($user['profile_image']) ?>">
                            </div>
                        </div>

                        <!-- Form Sections -->
                        <div class="space-y-8">
                            <!-- Personal Information -->
                            <fieldset class="bg-gray-50/60 rounded-xl p-6 ring-1 ring-gray-100 shadow-sm">
                                <legend class="text-base font-semibold text-gray-800 mb-5 flex items-center gap-2 text-purple-700 bg-purple-50 px-3 py-1 rounded-full ring-1 ring-purple-100 w-fit">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    Personal Details
                                </legend>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                                <div>
                                    <label for="first_name" class="block text-sm font-medium <?= $highlightClass ?> mb-1.5">
                                        First Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="first_name" name="first_name" required
                                        class="w-full px-4 py-2 rounded-lg bg-white transition-all duration-150 text-sm shadow-sm <?= $inputErrorClass ?>"
                                        value="<?= htmlspecialchars($user['first_name'] ?? '') ?>">
                                </div>
                                <div>
                                    <label for="last_name" class="block text-sm font-medium <?= $highlightClass ?> mb-1.5">
                                        Last Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="last_name" name="last_name" required
                                        class="w-full px-4 py-2 rounded-lg bg-white transition-all duration-150 text-sm shadow-sm <?= $inputErrorClass ?>"
                                        value="<?= htmlspecialchars($user['last_name'] ?? '') ?>">
                                </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Email Address</label>
                                        <div class="relative">
                                            <input type="email"
                                                class="w-full pl-4 pr-32 py-2 border border-gray-200 rounded-lg bg-gray-100 cursor-not-allowed text-sm text-gray-500 shadow-inner"
                                                value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                                                readonly aria-label="Email address (cannot be changed here)">
                                            <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                                                <a href="/pages/change_email.php"
                                                class="text-purple-600 hover:text-purple-700 text-sm font-medium flex items-center gap-1 hover:underline"
                                                title="Change email address">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                                    Change
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </fieldset>

                            <!-- Organizer Information (Displayed ONLY if application exists - pending, approved, or rejected) -->
                            <?php if ($user['organizer_status'] !== null): ?>
                            <fieldset class="rounded-xl p-6 ring-1 shadow-sm
                                <?= match($user['organizer_status']) {
                                    'approved' => 'bg-green-50/60 ring-green-200',
                                    'rejected' => 'bg-red-50/60 ring-red-200',
                                    default => 'bg-amber-50/60 ring-amber-200' // pending
                                } ?>">
                                <legend class="text-base font-semibold mb-5 flex items-center gap-2 px-3 py-1 rounded-full ring-1 w-fit
                                    <?= match($user['organizer_status']) {
                                        'approved' => 'text-green-800 bg-green-100 ring-green-200',
                                        'rejected' => 'text-red-800 bg-red-100 ring-red-200',
                                        default => 'text-amber-800 bg-amber-100 ring-amber-200' // pending
                                    } ?>">
                                    <?= match($user['organizer_status']) {
                                        'approved' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>',
                                        'rejected' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>',
                                        default => '<svg class="w-5 h-5 animate-pulse" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"></path></svg>' // Changed icon for pending
                                    } ?>
                                    Organizer Status: <?= ucfirst(htmlspecialchars($user['organizer_status'])) ?>
                                </legend>
                                <div class="space-y-3 text-sm">
                                    <?php // Display details only if they exist (user might be rejected/pending without full data yet) ?>
                                    <?php if (!empty($user['organization'])): ?>
                                    <div class="flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-3">
                                        <span class="font-medium text-gray-600 w-32 flex-shrink-0">Organization:</span>
                                        <span class="text-gray-800 font-medium"><?= htmlspecialchars($user['organization']) ?> (<?= htmlspecialchars($user['org_type'] ?? 'N/A') ?>)</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($user['contact_person'])): ?>
                                    <div class="flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-3">
                                        <span class="font-medium text-gray-600 w-32 flex-shrink-0">Contact Person:</span>
                                        <span class="text-gray-800"><?= htmlspecialchars($user['contact_person']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($user['website'])): ?>
                                     <div class="flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-3">
                                        <span class="font-medium text-gray-600 w-32 flex-shrink-0">Website:</span>
                                         <a href="<?= htmlspecialchars($user['website']) ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline truncate"><?= htmlspecialchars($user['website']) ?></a>
                                    </div>
                                    <?php endif; ?>
                                     <?php if (!empty($user['organizer_description'])): ?>
                                    <div class="flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-3 pt-2 border-t border-gray-200/50 mt-3">
                                        <span class="font-medium text-gray-600 w-32 flex-shrink-0 pt-0.5">Description:</span>
                                        <p class="text-gray-700 leading-relaxed text-xs"><?= nl2br(htmlspecialchars($user['organizer_description'])) ?></p>
                                    </div>
                                    <?php endif; ?>

                                    <?php // Show rejection reason specific to this section if rejected ?>
                                     <?php if ($user['organizer_status'] === 'rejected' && !empty($user['approval_notes'])): ?>
                                       <div class="flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-3 pt-2 border-t border-red-200/50 mt-3">
                                        <span class="font-medium text-red-600 w-32 flex-shrink-0 pt-0.5">Rejection Reason:</span>
                                        <p class="text-red-700 leading-relaxed font-medium"><?= nl2br(htmlspecialchars($user['approval_notes'])) ?></p>
                                    </div>
                                    <?php endif; ?>

                                     <!-- Link to view verification doc - THIS LINK ASSUMES THE USER IS AN ADMIN OR view_verification_doc.php handles permissions correctly. -->
                                     <!-- Typically, only admins should see this link, usually on the review_applications page. -->
                                     <!-- Keeping it here as per your provided code, but be aware of the security implication. -->
                                      <?php if (!empty($user['verification_doc'])): // Show link regardless of status if doc exists ?>
                                         <div class="flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-3 pt-2 border-t border-gray-200/50 mt-3">
                                            <span class="font-medium text-gray-600 w-32 flex-shrink-0 pt-0.5">Verification Doc:</span>
                                            <!-- Uses the secure viewing script which checks admin login -->
                                            <a href="/admin/view_verification_doc.php?user_id=<?= $user['id'] ?>" target="_blank" class="text-blue-600 hover:underline text-sm inline-flex items-center gap-1" title="View submitted document (Admin Only Access)">
                                                View Submitted Document
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                            </a>
                                            <span class="text-xs text-gray-400">(Requires Admin Login)</span>
                                         </div>
                                      <?php endif; ?>
                                </div>
                            </fieldset>
                            <?php endif; ?>

                            <!-- Contact Information -->
                             <fieldset class="bg-gray-50/60 rounded-xl p-6 ring-1 ring-gray-100 shadow-sm">
                                 <legend class="text-base font-semibold text-gray-800 mb-5 flex items-center gap-2 text-purple-700 bg-purple-50 px-3 py-1 rounded-full ring-1 ring-purple-100 w-fit">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                    Contact Details <span class="text-xs text-gray-500">(Optional)</span>
                                </legend>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                                <div>
                                    <label for="phone" class="block text-sm font-medium <?= $highlightClass ?> mb-1.5">
                                        Phone Number <span class="text-red-500">*</span>
                                    </label>
                                    <input type="tel" id="phone" name="phone" required
                                        class="w-full px-4 py-2 rounded-lg bg-white transition-all duration-150 text-sm shadow-sm <?= $inputErrorClass ?>"
                                        value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                                </div>
                                    <div>
                                        <label for="city" class="block text-sm font-medium text-gray-700 mb-1.5">City/Town</label>
                                        <input type="text" id="city" name="city"
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white transition-all duration-150 text-sm shadow-sm"
                                            value="<?= htmlspecialchars($user['city'] ?? '') ?>">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label for="address" class="block text-sm font-medium text-gray-700 mb-1.5">Address</label>
                                        <textarea id="address" name="address" rows="3"
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white transition-all duration-150 text-sm shadow-sm resize-y min-h-[4rem]"
                                            ><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                                    </div>
                                    <div>
                                        <label for="country" class="block text-sm font-medium text-gray-700 mb-1.5">Country</label>
                                        <input type="text" id="country" name="country"
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white transition-all duration-150 text-sm shadow-sm"
                                            value="<?= htmlspecialchars($user['country'] ?? '') ?>">
                                    </div>
                                    <div>
                                        <label for="pincode" class="block text-sm font-medium text-gray-700 mb-1.5">Postal Code</label>
                                        <input type="text" id="pincode" name="pincode"
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white transition-all duration-150 text-sm shadow-sm"
                                            value="<?= htmlspecialchars($user['pincode'] ?? '') ?>">
                                    </div>
                                </div>
                            </fieldset>
                        </div>

                        <!-- Save Button -->
                        <div class="mt-8 pt-6 border-t border-gray-200 flex justify-end">
                            <button type="submit" id="saveButton"
                                    class="relative inline-flex items-center justify-center px-8 py-2.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-all duration-200 font-medium group disabled:opacity-70 disabled:cursor-not-allowed shadow-md hover:shadow-lg">
                                <span class="button-text transition-opacity duration-200 group-[.is-loading]:opacity-0">Save Changes</span>
                                <span class="loading-spinner absolute inset-0 flex items-center justify-center opacity-0 group-[.is-loading]:opacity-100 transition-opacity duration-200 pointer-events-none">
                                    <svg class="w-5 h-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </span>
                            </button>
                        </div>                        
                    </form>

                    <!-- Notification Preferences Section -->
                    <div class="mt-8 lg:mt-10">
                        <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6 border border-gray-100">
                             <div class="pb-4 border-b border-gray-100">
                                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                                    New Event Notifications
                                </h3>
                                <p class="text-sm text-gray-500 mt-1">
                                    Get notified via email about new events matching your interests. Select categories and optionally refine by specific tags within them.
                                </p>
                             </div>

                            <form id="notificationPrefsForm" class="pt-5">
                                 <!-- CSRF Token for Prefs update -->
                                 <input type="hidden" name="csrf_token_prefs" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                                <div class="space-y-3" x-data="{ openCategory: null }">
                                    <?php if (empty($allCategories)): ?>
                                        <p class="text-sm text-gray-500 px-3 py-2">No categories available to set preferences.</p>
                                    <?php else: ?>
                                        <?php foreach ($allCategories as $category):
                                            $categoryId = $category['id'];
                                            $categoryTags = $tagsByCategory[$categoryId] ?? []; // Get tags for this category
                                            $hasTags = !empty($categoryTags);
                                        ?>
                                            <div class="border border-gray-200 rounded-lg overflow-hidden bg-white shadow-sm" x-bind:class="{ 'ring-2 ring-indigo-200': openCategory === <?= $categoryId ?> }">
                                                <div class="flex items-center justify-between p-3 hover:bg-gray-50 transition-colors">
                                                    <!-- Category Checkbox and Label -->
                                                    <div class="flex items-center flex-grow mr-4">
                                                        <input id="cat_pref_<?= $categoryId ?>"
                                                               name="category_prefs[]"
                                                               type="checkbox"
                                                               value="<?= $categoryId ?>"
                                                               <?= in_array($categoryId, $preferredCategoryIds) ? 'checked' : '' ?>
                                                               class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 transition duration-150 ease-in-out shadow-sm">
                                                        <label for="cat_pref_<?= $categoryId ?>" class="ml-3 block text-sm font-medium text-gray-800 hover:text-indigo-700 cursor-pointer">
                                                            <?= htmlspecialchars($category['name']) ?>
                                                        </label>
                                                    </div>

                                                    <!-- Expand Button (only if tags exist) -->
                                                    <?php if ($hasTags): ?>
                                                    <button type="button"
                                                            @click="openCategory = (openCategory === <?= $categoryId ?> ? null : <?= $categoryId ?>)"
                                                            class="p-1 rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1"
                                                            :aria-expanded="openCategory === <?= $categoryId ?>">
                                                        <span class="sr-only">Expand tags for <?= htmlspecialchars($category['name']) ?></span>
                                                        <svg class="w-5 h-5 transition-transform duration-200 ease-in-out"
                                                             :class="{ 'rotate-180': openCategory === <?= $categoryId ?> }"
                                                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                        </svg>
                                                    </button>
                                                    <?php else: ?>
                                                        <span class="text-xs text-gray-400 italic mr-2">No specific tags</span>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Tags Section (Expandable) -->
                                                <?php if ($hasTags): ?>
                                                <div x-show="openCategory === <?= $categoryId ?>"
                                                     x-transition:enter="transition ease-out duration-200"
                                                     x-transition:enter-start="opacity-0 -translate-y-1"
                                                     x-transition:enter-end="opacity-100 translate-y-0"
                                                     x-transition:leave="transition ease-in duration-150"
                                                     x-transition:leave-start="opacity-100 translate-y-0"
                                                     x-transition:leave-end="opacity-0 -translate-y-1"
                                                     class="bg-gray-50/70 border-t border-gray-200 px-4 py-3"
                                                     x-cloak>
                                                    <h5 class="text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wide">Tags within <?= htmlspecialchars($category['name']) ?>:</h5>
                                                    <div class="max-h-32 overflow-y-auto space-y-1.5 pl-1 pr-2">
                                                        <?php foreach ($categoryTags as $tag): ?>
                                                            <div class="flex items-center">
                                                                <input id="tag_pref_<?= $tag['id'] ?>"
                                                                       name="tag_prefs[]"
                                                                       type="checkbox"
                                                                       value="<?= $tag['id'] ?>"
                                                                       <?= in_array($tag['id'], $preferredTagIds) ? 'checked' : '' ?>
                                                                       class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-offset-0 focus:ring-indigo-500 transition duration-150 ease-in-out shadow-sm">
                                                                <label for="tag_pref_<?= $tag['id'] ?>" class="ml-2 block text-sm text-gray-600 hover:text-gray-800 cursor-pointer">
                                                                    <?= htmlspecialchars($tag['name']) ?>
                                                                </label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Save Button -->
                                <div class="mt-6 pt-4 border-t border-gray-100 flex justify-end">
                                    <button type="submit" id="savePrefsButton"
                                            class="relative inline-flex items-center justify-center px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-all duration-200 text-sm font-medium group disabled:opacity-70 disabled:cursor-not-allowed shadow">
                                        <span class="prefs-button-text transition-opacity duration-200 group-[.is-loading]:opacity-0">Save Notification Preferences</span>
                                        <span class="prefs-loading-spinner absolute inset-0 flex items-center justify-center opacity-0 group-[.is-loading]:opacity-100 transition-opacity duration-200 pointer-events-none">
                                            <svg class="w-4 h-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <!-- Notification Preferences Section -->
                </div>
            </div>
        </div>
    </div>

    <!-- Unsaved Changes Modal -->
    <div id="unsavedChangesModal" class="hidden fixed inset-0 bg-gray-600/50 z-50 backdrop-blur-sm transition-opacity">
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                        <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                        <h3 class="text-base font-semibold leading-6 text-gray-900">Unsaved changes</h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-500">You have unsaved changes. Are you sure you want to leave this page?</p>
                        </div>
                    </div>
                </div>
                <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                    <button type="button" id="modalConfirmButton" class="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:ml-3 sm:w-auto">Leave</button>
                    <button type="button" id="modalCancelButton" class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto">Stay</button>
                </div>
            </div>
        </div>
    </div>

    <?php require_once '../includes/footer.php'; ?>

    <script>
        // Form Submission Loading State
        const profileForm = document.querySelector('form[action="/actions/update_profile.php"]');
        const saveProfileButton = document.getElementById('saveButton');
        const prefsForm = document.getElementById('notificationPrefsForm'); // Get prefs form
        const savePrefsButton = document.getElementById('savePrefsButton'); // Get prefs save button

        // Unsaved Changes Tracking
        document.addEventListener('DOMContentLoaded', () => {
            // --- Flags for Unsaved Changes ---
            // Initialize based on whether a success message is present (meaning the last action *was* a save)
            // If a success message exists, assume no unsaved changes initially. Otherwise, assume potential unsaved changes (safer default).
            // However, for simplicity and less chance of annoying popups on initial load after an error, let's start false.
            let hasUnsavedChanges = false; // For main profile form
            let hasUnsavedPrefsChanges = false; // For notification preferences form
            let intendedNavigationTarget = null;

            // --- Track Profile Form Changes ---
            if (profileForm) {
                const formElements = profileForm.querySelectorAll('input, textarea, select');
                formElements.forEach(element => {
                    // Use 'input' for text fields/textarea, 'change' for selects/checkboxes/radio/file
                    const eventType = (element.type === 'checkbox' || element.type === 'radio' || element.type === 'file' || element.tagName === 'SELECT') ? 'change' : 'input';
                    element.addEventListener(eventType, () => {
                        console.log("Profile change detected on:", element.name);
                        hasUnsavedChanges = true;
                    });
                });

                // Handle profile form submission
                profileForm.addEventListener('submit', function(e) {
                    hasUnsavedChanges = false; // Reset flag *before* submitting
                    if(saveProfileButton){
                        saveProfileButton.disabled = true;
                        saveProfileButton.classList.add('is-loading');
                    }
                });
            } else {
                 console.warn("Profile form not found.");
            }

            // --- Track Notification Preferences Form Changes ---
            if (prefsForm) {
                const prefCheckboxes = prefsForm.querySelectorAll('input[type="checkbox"]');
                prefCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', () => {
                         console.log("Preference change detected on:", checkbox.name, checkbox.value);
                        hasUnsavedPrefsChanges = true;
                    });
                });

                // Handle preferences form submission (inside the async function below)
                // We reset the flag *after* successful save in saveNotificationPreferences()
                 console.log("Event listeners attached to preference checkboxes.");
            } else {
                console.warn("Notification preferences form not found.");
            }


            // --- Handle Navigation Attempts (Links, Other Forms, Browser Close/Refresh) ---

            // Handle link clicks
            document.body.addEventListener('click', function(e) {
                const link = e.target.closest('a');
                // Ignore if not a link, or if it's just an anchor on the same page, or has target="_blank"
                if (!link || link.getAttribute('href')?.startsWith('#') || link.target === '_blank') {
                    return;
                }
                // Ignore clicks within the forms themselves (like labels)
                if (link.closest('form')) {
                    return;
                }

                // Check *both* flags
                if (hasUnsavedChanges || hasUnsavedPrefsChanges) {
                    console.log("Navigation attempt with unsaved changes (link).");
                    e.preventDefault();
                    intendedNavigationTarget = link.href;
                    showUnsavedModal();
                }
            });

            // Handle *other* form submissions (prevents losing changes if user submits prefs while profile has changes, or vice-versa)
            document.body.addEventListener('submit', function(e) {
                // Ignore submissions of the forms we are tracking changes *for*
                if (e.target === profileForm || e.target === prefsForm) {
                    return;
                }

                // Check *both* flags
                if (hasUnsavedChanges || hasUnsavedPrefsChanges) {
                     console.log("Navigation attempt with unsaved changes (other form submit).");
                    e.preventDefault();
                    // Try to get action, fallback to current location reload indication
                    intendedNavigationTarget = e.target.action || window.location.href;
                    showUnsavedModal();
                }
            });

            // Handle browser beforeunload (close tab, refresh, back button)
            window.addEventListener('beforeunload', (e) => {
                 // Check *both* flags
                if (hasUnsavedChanges || hasUnsavedPrefsChanges) {
                    console.log("Navigation attempt with unsaved changes (beforeunload).");
                    e.preventDefault();
                    // Standard practice for cross-browser compatibility
                    e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
                    return 'You have unsaved changes. Are you sure you want to leave?';
                }
            });

            // --- Modal Functions ---
            function showUnsavedModal() {
                const modal = document.getElementById('unsavedChangesModal');
                if (modal) {
                    modal.classList.remove('hidden');
                    modal.classList.add('fade-in'); // Optional: if you have a fade-in animation class
                }
            }

            function hideUnsavedModal() {
                 const modal = document.getElementById('unsavedChangesModal');
                 if (modal) {
                    modal.classList.add('hidden');
                    modal.classList.remove('fade-in');
                 }
            }

            // Modal buttons
            const confirmBtn = document.getElementById('modalConfirmButton');
            const cancelBtn = document.getElementById('modalCancelButton');

            if (confirmBtn) {
                confirmBtn.addEventListener('click', () => {
                    // Reset *both* flags as user confirmed leaving
                    hasUnsavedChanges = false;
                    hasUnsavedPrefsChanges = false;
                    hideUnsavedModal();
                    if (intendedNavigationTarget) {
                        window.location.href = intendedNavigationTarget;
                    }
                });
            }

             if (cancelBtn) {
                cancelBtn.addEventListener('click', () => {
                    hideUnsavedModal();
                    intendedNavigationTarget = null; // Clear target
                });
            }

            // --- Notification Preferences Save Logic ---
            async function saveNotificationPreferences() {
                // Ensure elements exist
                if (!prefsForm || !savePrefsButton) {
                    console.error('Preference form or button missing.');
                    showToast('Error saving preferences: Form elements missing.', 'error');
                    return;
                }
                const csrfTokenInput = prefsForm.querySelector('input[name="csrf_token_prefs"]');
                if (!csrfTokenInput || !csrfTokenInput.value) {
                     console.error('CSRF token for preferences missing.');
                    showToast('Error saving preferences: Security token missing.', 'error');
                    return;
                }
                const csrfToken = csrfTokenInput.value;

                // Collect checked IDs
                const categoryIds = Array.from(prefsForm.querySelectorAll('input[name="category_prefs[]"]:checked')).map(cb => parseInt(cb.value, 10));
                const tagIds = Array.from(prefsForm.querySelectorAll('input[name="tag_prefs[]"]:checked')).map(cb => parseInt(cb.value, 10));

                // Show loading state
                savePrefsButton.disabled = true;
                savePrefsButton.classList.add('is-loading');

                try {
                    const response = await fetch('/actions/update_notification_preferences.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            category_ids: categoryIds,
                            tag_ids: tagIds,
                            csrf_token: csrfToken // Send CSRF in body
                        })
                    });

                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        throw new Error(result.message || `HTTP Error ${response.status}`);
                    }

                    // Success: Reset the preferences flag
                    hasUnsavedPrefsChanges = false; // <-- Reset flag here on success
                    showToast(result.message || 'Notification preferences saved successfully!', 'success');
                    console.log('Preferences saved successfully, resetting flag.');

                } catch (error) {
                    console.error('Error saving notification preferences:', error);
                    showToast(`Error saving preferences: ${error.message || 'Unknown error'}`, 'error');
                    // Do NOT reset the flag on error
                } finally {
                    // Always reset button state
                    savePrefsButton.disabled = false;
                    savePrefsButton.classList.remove('is-loading');
                }
            }

            // --- Attach Listener for Notification Prefs Form Submit ---
            if (prefsForm) {
                prefsForm.addEventListener('submit', (event) => {
                    event.preventDefault(); // Prevent default form submission
                    saveNotificationPreferences(); // Call the async function
                });
            }

            // --- Toast Notification Function ---
            function showToast(message, type = 'info') {
                const toastId = 'notification-toast-' + Date.now(); // Unique ID to allow multiple toasts if needed quickly
                // Simple remove logic for previous toasts if you prefer only one
                const existingToast = document.querySelector('.notification-toast-class');
                if(existingToast) existingToast.remove();


                const toast = document.createElement('div');
                toast.id = toastId;
                toast.classList.add('notification-toast-class'); // Add a general class for potential cleanup
                const bgColor = type === 'success' ? 'bg-green-600' : type === 'error' ? 'bg-red-600' : 'bg-indigo-600';
                toast.className = `notification-toast-class fixed bottom-5 right-5 p-3 rounded-lg shadow-md text-white text-sm ${bgColor} z-[10000] transition-all duration-300 ease-out opacity-0 max-w-sm`;
                toast.textContent = message;
                document.body.appendChild(toast);

                // Fade in
                requestAnimationFrame(() => {
                     requestAnimationFrame(() => { // Double request ensures style update happens
                        toast.style.opacity = '1';
                        toast.style.transform = 'translateY(0)';
                     });
                     toast.style.transform = 'translateY(10px)'; // Start slightly down
                });

                // Auto-remove after a delay
                setTimeout(() => {
                    toast.style.opacity = '0';
                     toast.style.transform = 'translateY(10px)';
                    setTimeout(() => {
                        if (toast.parentNode) {
                            toast.parentNode.removeChild(toast);
                        }
                    }, 350); // Wait for fade out transition
                }, 4000); // Display duration

                 // Allow manual dismissal
                 toast.addEventListener('click', () => {
                     toast.style.opacity = '0';
                     toast.style.transform = 'translateY(10px)';
                     setTimeout(() => {
                        if (toast.parentNode) {
                            toast.parentNode.removeChild(toast);
                        }
                    }, 350);
                 });
            }

            // --- Initial check if profile incomplete alert exists ---
            // This part remains unchanged, highlighting fields based on server-side check
            const profileIncompleteAlert = document.querySelector('[role="alert"].bg-yellow-50');
            if (profileIncompleteAlert) {
                // Highlight specific required fields if the alert is shown
                document.getElementById('first_name')?.classList.add('border-yellow-400', 'ring-1', 'ring-yellow-300');
                document.getElementById('last_name')?.classList.add('border-yellow-400', 'ring-1', 'ring-yellow-300');
                document.getElementById('phone')?.classList.add('border-yellow-400', 'ring-1', 'ring-yellow-300');
            }


        }); // End DOMContentLoaded
    </script>
</body>
</html>