<?php
// Ensure session is started if not already
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$isOrganizer = isset($_SESSION['organizer_status']) && $_SESSION['organizer_status'] === 'approved';
$currentPage = basename($_SERVER['PHP_SELF']);

// --- Helper function for Nav Link Class ---
function getNavLinkClass($pageName, $currentPage) {
    $baseClass = "px-3 py-2 rounded-md text-sm font-medium transition-colors duration-150 ease-in-out";
    if ($pageName === $currentPage) {
        return $baseClass . " bg-purple-50 text-purple-700";
    } else {
        return $baseClass . " text-gray-600 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:bg-gray-100 focus:text-gray-900";
    }
}

// --- Helper function for Mobile Nav Link Class ---
function getMobileNavLinkClass($pageName, $currentPage) {
    $baseClass = "block px-3 py-2 rounded-md text-base font-medium transition-colors duration-150 ease-in-out";
     if ($pageName === $currentPage) {
        return $baseClass . " bg-purple-50 text-purple-700";
    } else {
        return $baseClass . " text-gray-700 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:bg-gray-100 focus:text-gray-900";
    }
}

// --- Get Profile Image ---
$defaultAvatarPath = '/assets/images/profiles/default-avatar.jpg';
$profileImageValue = $_SESSION['profile_image'] ?? $defaultAvatarPath;
$imageUrl = $defaultAvatarPath; // Start with default
$displayImage = false; // Assume we display initial, unless a valid image is found

// Safety check: Ensure profileImageValue isn't just the short default name
if (empty($profileImageValue) || $profileImageValue === 'default-avatar.jpg') {
     $profileImageValue = $defaultAvatarPath;
}

// Check if the stored value looks like a URL (starts with http)
if (strpos($profileImageValue, 'http://') === 0 || strpos($profileImageValue, 'https://') === 0) {
    // It's a URL (like from Google), use it directly and display the image
    $imageUrl = htmlspecialchars($profileImageValue, ENT_QUOTES, 'UTF-8');
    $displayImage = true;
}
// Check if it's a non-empty local path AND not the default path already
elseif (!empty($profileImageValue) && $profileImageValue !== $defaultAvatarPath) {
    // It's potentially a custom local path.
    // Construct full server path to check existence within allowed directories.
    $localFilePath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $profileImageValue);

    // Use @file_exists to suppress warnings if open_basedir check itself fails for some reason
    if (@file_exists($localFilePath)) {
        // Local file exists, use the relative path and display the image
        $imageUrl = htmlspecialchars($profileImageValue, ENT_QUOTES, 'UTF-8');
        $displayImage = true;
    } else {
        // Local file specified in session doesn't exist, log error and use default.
        error_log("NAV Warning: Profile image file not found: " . $localFilePath . " - User: " . ($_SESSION['user_id'] ?? 'Unknown'));
        $imageUrl = $defaultAvatarPath; // Fallback to default path for display
        $displayImage = false; // Display initial
    }
} else {
     // It is the default path or empty after safety checks, use default and display initial
     $imageUrl = $defaultAvatarPath;
     $displayImage = false;
}


// --- Get User Initial (only needed if not displaying image) ---
$userInitial = '?';
if (!$displayImage) {
    $userFirstNameForInitial = $_SESSION['first_name'] ?? '';
    $userInitial = !empty(trim($userFirstNameForInitial)) ? strtoupper(substr($userFirstNameForInitial, 0, 1)) : '?';
}
// --- End Get Profile Image ---
?>

<nav x-data="{ mobileMenuOpen: false, userMenuOpen: false }" class="bg-white shadow-md sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <!-- Left Section: Logo and Desktop Nav -->
            <div class="flex items-center">
                <!-- Logo -->
                <a href="/index.php" class="flex-shrink-0 flex items-center space-x-2 group">
                    <div class="p-2 bg-purple-100 rounded-lg transform transition-all duration-300 group-hover:rotate-12 group-focus:ring-2 group-focus:ring-purple-500 group-focus:ring-offset-2">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <span class="text-xl font-bold bg-gradient-to-r from-purple-600 to-blue-500 bg-clip-text text-transparent hidden sm:inline">
                        CECT
                    </span>
                </a>

                <!-- Desktop Navigation Links -->
                <div class="hidden md:ml-6 md:flex md:space-x-2 lg:space-x-4">
                    <a href="/index.php" class="<?= getNavLinkClass('index.php', $currentPage) ?>">
                        Home
                    </a>
                    <a href="/pages/search_results.php" class="<?= getNavLinkClass('search_results.php', $currentPage) ?>">
                        Browse Events
                    </a>
                    <?php if($isLoggedIn): ?>
                        <a href="/pages/registered_events.php" class="<?= getNavLinkClass('registered_events.php', $currentPage) ?>">
                            My Events
                        </a>
                        <a href="/pages/my_feed.php" class="<?= getNavLinkClass('my_feed.php', $currentPage) ?>"> <!-- Added My Feed Link -->
                            My Feed
                        </a>
                    <?php endif; ?>
                    <?php if($isOrganizer || $isAdmin): ?>
                        <?php
                            // Determine the correct dashboard link and page name based on role
                            $dashboardLink = $isAdmin ? '/pages/admin_dashboard.php' : '/pages/organizer_dashboard.php';
                            $dashboardPageName = $isAdmin ? 'admin_dashboard.php' : 'organizer_dashboard.php';
                            $dashboardText = $isAdmin ? 'Admin Dash' : 'Organizer Dash';
                        ?>
                        <a href="<?= $dashboardLink ?>" class="<?= getNavLinkClass($dashboardPageName, $currentPage) ?>">
                           <?= $dashboardText ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Section: Actions and User Menu -->
            <div class="flex items-center space-x-3">
                 <?php if($isLoggedIn): ?>
                    
                    <!-- User Dropdown -->
                    <div class="relative ml-3">
                        <div>
                        <button @click.stop="userMenuOpen = !userMenuOpen" type="button" class="bg-white rounded-full flex text-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500" id="user-menu-button" aria-expanded="false" aria-haspopup="true">
                                <span class="sr-only">Open user menu</span>
                                <div class="h-8 w-8 rounded-full overflow-hidden border border-gray-200 bg-gray-100 flex items-center justify-center">
                                <?php // Display image only if it's custom AND exists, otherwise show initial ?>
                                <?php if ($displayImage): ?>
                                    <img class="h-full w-full object-cover" src="<?= $imageUrl ?>" alt="User profile picture">
                                <?php else: ?>
                                        <span class="text-purple-600 font-medium text-xs">
                                            <?= htmlspecialchars($userInitial) ?>
                                        </span>
                                <?php endif; ?>
                                </div>
                            </button>
                        </div>

                        <!-- Dropdown Menu -->
                        <div x-show="userMenuOpen"
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="transform opacity-0 scale-95"
                             x-transition:enter-end="transform opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-75"
                             x-transition:leave-start="transform opacity-100 scale-100"
                             x-transition:leave-end="transform opacity-0 scale-95"
                             @click.outside="userMenuOpen = false"
                             class="origin-top-right absolute right-0 mt-2 w-56 rounded-md shadow-lg py-1 bg-white ring-1 ring-black ring-opacity-5 focus:outline-none z-50"
                             role="menu" aria-orientation="vertical" aria-labelledby="user-menu-button" tabindex="-1"
                             style="display: none;">

                            <div class="px-4 py-3 border-b border-gray-100">
                                <p class="text-sm font-medium text-gray-900 truncate" role="none">
                                     <?php // Use session variable directly here ?>
                                    <?= htmlspecialchars($_SESSION['first_name'] ?? 'Account') ?>
                                </p>
                                <p class="text-xs text-gray-500 truncate" role="none">
                                     <?php // Use session variable directly here ?>
                                    <?= htmlspecialchars($_SESSION['email'] ?? '') ?>
                                </p>
                            </div>

                            <div class="py-1" role="none">
                                <a href="/pages/account_info.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-700 transition-colors duration-150 group" role="menuitem" tabindex="-1" id="user-menu-item-0">
                                    <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"> <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" /> </svg>
                                    Profile Settings
                                </a>
                                <a href="/pages/saved_events.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-700 transition-colors duration-150 group" role="menuitem" tabindex="-1"> <!-- Added Saved Events Link -->
                                    <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"> <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.5 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" /> </svg>
                                    Saved Events
                                </a>


                                <?php if($isAdmin): ?>
                                    <a href="/pages/admin_dashboard.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-700 transition-colors duration-150 group" role="menuitem" tabindex="-1">
                                        <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                                        </svg>
                                        Admin Dashboard
                                    </a>
                                <?php endif; ?>

                                <?php if($isOrganizer): // Show only if approved organizer ?>
                                    <a href="/pages/organizer_dashboard.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-700 transition-colors duration-150 group" role="menuitem" tabindex="-1">
                                       <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 1.5m-5-1.5l1 1.5m-5-1.5l-1 1.5m16.5-1.5l1 1.5M5.25 6h13.5m-13.5 3h13.5m-13.5 3h13.5" />
                                        </svg>
                                        Organizer Dashboard
                                    </a>
                                <?php endif; ?>

                                <a href="/pages/logout.php" class="flex items-center w-full px-4 py-2 text-sm text-red-600 hover:bg-red-50 hover:text-red-700 transition-colors duration-150 group" role="menuitem" tabindex="-1" id="user-menu-item-2">
                                   <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                                    </svg>
                                    Log Out
                                </a>
                            </div>
                        </div>
                    </div><!-- End User Dropdown -->

                <?php else: ?>
                     <!-- Guest Actions: Login and Sign Up -->
                    <div class="flex items-center space-x-2 sm:space-x-3">
                        <a href="/pages/login.php"
                           class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-colors duration-150">
                           <svg class="-ml-0.5 mr-1.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m-3 0l3-3m0 0l-3-3m3 3H5.25" />
                            </svg>
                            Log In
                        </a>
                        <a href="/pages/register.php"
                           class="inline-flex items-center px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-gradient-to-r from-purple-600 to-blue-500 hover:from-purple-700 hover:to-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-all duration-150">
                            <svg class="-ml-0.5 mr-1.5 h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z" />
                            </svg>
                            Sign Up
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Mobile menu button -->
                <div class="-mr-2 flex items-center md:hidden">
                    <button @click="mobileMenuOpen = !mobileMenuOpen" type="button" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-purple-500" aria-controls="mobile-menu" aria-expanded="false">
                        <span class="sr-only">Open main menu</span>
                        <svg x-show="!mobileMenuOpen" class="block h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <svg x-show="mobileMenuOpen" class="block h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: none;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile menu, show/hide based on menu state. -->
    <div x-show="mobileMenuOpen"
         x-transition:enter="duration-150 ease-out"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="duration-100 ease-in"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="md:hidden" id="mobile-menu" style="display: none;">
        <div class="px-2 pt-2 pb-3 space-y-1 sm:px-3">
            <a href="/index.php" class="<?= getMobileNavLinkClass('index.php', $currentPage) ?>">Home</a>
            <a href="/pages/search_results.php" class="<?= getMobileNavLinkClass('search_results.php', $currentPage) ?>">Browse Events</a>
            <?php if($isLoggedIn): ?>
                <a href="/pages/registered_events.php" class="<?= getMobileNavLinkClass('registered_events.php', $currentPage) ?>">My Events</a>
                <a href="/pages/my_feed.php" class="<?= getMobileNavLinkClass('my_feed.php', $currentPage) ?>">My Feed</a> <!-- Added My Feed Link -->
            <?php endif; ?>
            <?php if($isOrganizer || $isAdmin): ?>
                <?php // Use the same variables as desktop for consistency ?>
                <a href="<?= $dashboardLink ?>" class="<?= getMobileNavLinkClass($dashboardPageName, $currentPage) ?>"><?= $dashboardText ?></a>
            <?php endif; ?>
        </div>
        <!-- Responsive User Actions -->
        <div class="pt-4 pb-3 border-t border-gray-200">
             <?php if(!$isLoggedIn): ?>
                <div class="flex items-center px-5 space-x-3">
                     <a href="/pages/login.php"
                        class="flex-1 inline-flex justify-center items-center px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-colors duration-150">
                         Log In
                    </a>
                    <a href="/pages/register.php"
                        class="flex-1 inline-flex justify-center items-center px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-gradient-to-r from-purple-600 to-blue-500 hover:from-purple-700 hover:to-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-all duration-150">
                         Sign Up
                    </a>
                </div>
            <?php endif; ?>
            <?php // If user is logged in, the profile actions are already handled by the dropdown, no need to duplicate here ?>
        </div>
    </div>
</nav>