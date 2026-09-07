<?php
ob_start();
session_start();
session_regenerate_id(true);
$logFile = __DIR__ . '/login_debug.log';

function writeLog($message) {
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

writeLog("=== New Login Page Load ===");
writeLog("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
writeLog("GET data: " . print_r($_GET, true));

// Clear any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

require_once '../config/db.php';

// Get redirect URL from GET request and sanitize it
$redirect_url = '';
if (isset($_GET['redirect'])) {
    $potential_redirect = filter_var($_GET['redirect'], FILTER_SANITIZE_URL);
    // Basic validation: Must start with /cect_system/
    if ($potential_redirect && strpos($potential_redirect, '/') === 0 && strpos($potential_redirect, '//') !== 0) {
        $redirect_url = $potential_redirect;
        writeLog("Received redirect URL via GET: " . $redirect_url);
    } else {
        writeLog("Ignored invalid GET redirect URL: " . $potential_redirect);
    }
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    writeLog('Generated new CSRF token');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    writeLog('POST request detected for login');
    writeLog('POST data: ' . print_r($_POST, true));

    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            writeLog('CSRF token validation failed');
            $_SESSION['login_error'] = "Invalid form submission. Please try again.";
            // Preserve redirect URL on CSRF failure redirect
            $location = "login.php";
            if (!empty($_POST['redirect'])) {
                 $location .= "?redirect=" . urlencode($_POST['redirect']);
             }
            header("Location: " . $location);
            exit();
        }
        writeLog('CSRF token validation passed');

        $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
        if (!$email) {
            writeLog("Invalid email format: " . $_POST['email']);
            $_SESSION['login_error'] = "Invalid email format.";
            // Preserve redirect URL on error
            $location = "login.php";
            if (!empty($_POST['redirect'])) {
                 $location .= "?redirect=" . urlencode($_POST['redirect']);
             }
            header("Location: " . $location);
            exit();
        }
        $password = $_POST['password'];

        writeLog("Processing login for email: $email");

        $stmt = $pdo->prepare("SELECT id, name, email, password, is_admin, organizer_status, failed_attempts, lockout_time, profile_image, first_name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        writeLog("User found: " . ($user ? 'Yes' : 'No'));

        if ($user) {
            // Check for lockout
            if (($user['failed_attempts'] ?? 0) >= 5 && ($user['lockout_time'] ?? null) && strtotime($user['lockout_time']) > time()) {
                $lockout_ends = strtotime($user['lockout_time']);
                $wait_time = ceil(($lockout_ends - time()) / 60);
                writeLog("Account locked for email: $email. Ends in {$wait_time} minutes.");
                $_SESSION['login_error'] = "Account locked due to too many failed attempts. Please try again in {$wait_time} minute(s).";
                // Preserve redirect URL on lockout
                $location = "login.php";
                if (!empty($_POST['redirect'])) {
                    $location .= "?redirect=" . urlencode($_POST['redirect']);
                }
                header("Location: " . $location);
                exit();
            }

            writeLog("Verifying password");
            $passwordVerified = password_verify($password, $user['password']);
            writeLog("Password verification result: " . ($passwordVerified ? 'Success' : 'Failed'));

            if ($passwordVerified) {
                // Reset failed attempts on successful login
                $updateStmt = $pdo->prepare("UPDATE users SET failed_attempts = 0, lockout_time = NULL, last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$user['id']]);
                writeLog("Failed attempts reset for user ID: " . $user['id']);

                // --- Normalize Profile Image Path for Session ---
                $defaultAvatarPath = '/assets/images/profiles/default-avatar.jpg';
                $shortDefaultName = 'default-avatar.jpg';
                $profileImageForSession = $user['profile_image'] ?? $defaultAvatarPath;

                // Ensure full path if it's the short default name or empty
                if (empty($profileImageForSession) || $profileImageForSession === $shortDefaultName) {
                    $profileImageForSession = $defaultAvatarPath;
                }
                // Regenerate session ID for security
                session_regenerate_id(true);

                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                // Use fetched first_name if available, otherwise fallback to name or empty
                $_SESSION['first_name'] = $user['first_name'] ?? strtok($user['name'] ?? '', ' ') ?: 'User';
                $_SESSION['user_name'] = $user['name']; // Keep full name if needed elsewhere
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['is_admin'] = $user['is_admin'];
                $_SESSION['organizer_status'] = $user['organizer_status'];
                // Set the normalized path in the session
                $_SESSION['profile_image'] = $profileImageForSession;
                writeLog("Session variables set: " . print_r($_SESSION, true));

                // Determine final redirect URL
                $final_redirect_url = '/index.php'; // Default redirect
                if (isset($_POST['redirect']) && !empty($_POST['redirect'])) {
                    $posted_redirect = filter_var($_POST['redirect'], FILTER_SANITIZE_URL);
                    // Security Check: Ensure it's an internal redirect
                    if ($posted_redirect && strpos($posted_redirect, '/') === 0 && strpos($posted_redirect, '//') !== 0) { 
                        $final_redirect_url = $posted_redirect;
                        writeLog("Using valid redirect URL from POST: " . $final_redirect_url);
                    } else {
                         writeLog("Invalid or external redirect URL from POST ignored: " . $posted_redirect);
                    }
                } else {
                    writeLog("No redirect URL in POST, using default: " . $final_redirect_url);
                }

                // Clear any existing output
                while (ob_get_level()) {
                    ob_end_clean();
                }

                writeLog("Login successful. Redirecting to: " . $final_redirect_url);
                header("Location: " . $final_redirect_url);
                exit();

            } else {
                // Increment failed attempts
                $updateStmt = $pdo->prepare("UPDATE users SET
                    failed_attempts = failed_attempts + 1,
                    lockout_time = IF(failed_attempts + 1 >= 5, NOW() + INTERVAL 15 MINUTE, lockout_time)
                    WHERE id = ?");
                $updateStmt->execute([$user['id']]);
                $new_attempts = ($user['failed_attempts'] ?? 0) + 1;
                writeLog("Invalid password provided for email: $email. Attempt count: $new_attempts");

                if ($new_attempts >= 5) {
                     $_SESSION['login_error'] = "Invalid email or password. Your account has been locked for 15 minutes.";
                } else {
                     $remaining_attempts = 5 - $new_attempts;
                     $_SESSION['login_error'] = "Invalid email or password. {$remaining_attempts} attempt(s) remaining before lockout.";
                }

                // Preserve redirect URL on failed login
                $location = "login.php";
                if (!empty($_POST['redirect'])) {
                    $location .= "?redirect=" . urlencode($_POST['redirect']);
                }
                header("Location: " . $location);
                exit();
            }
        } else {
            writeLog("No user found with email: $email");
            $_SESSION['login_error'] = "Invalid email or password.";
            // Preserve redirect URL if user not found
            $location = "login.php";
            if (!empty($_POST['redirect'])) {
                $location .= "?redirect=" . urlencode($_POST['redirect']);
            }
            header("Location: " . $location);
            exit();
        }

    } catch (PDOException $e) {
        writeLog("Database Error during login: " . $e->getMessage());
        $_SESSION['login_error'] = "A database error occurred. Please try again later.";
        $location = "login.php";
         if (!empty($_POST['redirect'])) {
             $location .= "?redirect=" . urlencode($_POST['redirect']);
         }
         header("Location: " . $location);
         exit();
    } catch (Exception $e) {
        writeLog("General Error during login: " . $e->getMessage());
        $_SESSION['login_error'] = "An unexpected error occurred: " . $e->getMessage();
         $location = "login.php";
         if (!empty($_POST['redirect'])) {
             $location .= "?redirect=" . urlencode($_POST['redirect']);
         }
         header("Location: " . $location);
        exit();
    }
}

writeLog("Reached end of PHP processing, preparing to render login form.");
// Prepare redirect URL for the create account link
$register_link_redirect = !empty($redirect_url) ? '?redirect=' . urlencode($redirect_url) : '';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - CECT</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Left side - Image -->
        <div class="hidden md:block md:w-1/2 bg-cover bg-center relative" style="background-image: url('/assets/images/login_img.png');">
            <div class="absolute inset-0 bg-gradient-to-br from-purple-700 via-purple-800 to-indigo-900 opacity-75"></div>
            <div class="relative h-full w-full flex flex-col justify-center items-center px-12 text-white text-center">
                <i class="fas fa-calendar-check fa-4x mb-6 text-purple-300"></i>
                <h2 class="text-4xl font-bold mb-4">Join Your Community</h2>
                <p class="text-lg mb-8">Discover and participate in local events. Sign in to continue.</p>
                <a href="/pages/register.php<?php echo $register_link_redirect; ?>" class="mt-4 inline-block px-8 py-3 border-2 border-white text-white rounded-full text-center font-semibold hover:bg-white hover:text-purple-700 transition duration-300 ease-in-out transform hover:scale-105">
                    Create Account
                </a>
            </div>
        </div>

        <!-- Right side - Form -->
        <div class="w-full md:w-1/2 flex items-center justify-center p-8 sm:p-12">
            <div class="max-w-md w-full space-y-8">
                <div>
                    <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                        Sign in to CECT
                    </h2>
                    <p class="mt-2 text-center text-sm text-gray-600">
                        Or <a href="/pages/register.php<?php echo $register_link_redirect; ?>" class="font-medium text-purple-600 hover:text-purple-500">
                            create a new account
                        </a>
                    </p>
                </div>

                <?php if(!empty($redirect_url)): ?>
                    <div class="bg-blue-50 border-l-4 border-blue-400 text-blue-700 p-4 rounded-md" role="alert">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm">You need to sign in to continue.</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if(isset($_SESSION['login_error'])): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md" role="alert">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-.707-4.293a1 1 0 001.414 0L12 12.414l1.293 1.293a1 1 0 001.414-1.414L13.414 11l1.293-1.293a1 1 0 00-1.414-1.414L12 9.586l-1.293-1.293a1 1 0 00-1.414 1.414L10.586 11l-1.293 1.293a1 1 0 000 1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium">
                                    <?php
                                    echo htmlspecialchars($_SESSION['login_error']);
                                    unset($_SESSION['login_error']);
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if(isset($_GET['password_reset_success']) && $_GET['password_reset_success'] == 1 && isset($_SESSION['success'])): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md" role="alert">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-green-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <p class="text-sm font-medium">
                                <?php
                                echo htmlspecialchars($_SESSION['success']);
                                unset($_SESSION['success']); // Show only once
                                ?>
                            </p>
                        </div>
                    </div>
                 <?php elseif (isset($_SESSION['success'])): ?>
                     <?php unset($_SESSION['success']); // Clear if not meant for this page load ?>
                 <?php endif; ?>

                <form class="mt-8 space-y-6" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="loginForm" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <!-- Hidden field to store the redirect URL -->
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect_url); ?>">
                    <!-- Add this hidden input for login action identification (optional but good practice) -->
                    <input type="hidden" name="action" value="login">

                    <div class="rounded-md shadow-sm -space-y-px">
                        <div>
                            <label for="email-address" class="sr-only">Email address</label>
                            <input id="email-address" name="email" type="email" autocomplete="email" required
                                   class="appearance-none rounded-none relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-t-md focus:outline-none focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm"
                                   placeholder="Email address"
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                        <div>
                            <label for="password" class="sr-only">Password</label>
                            <div class="relative">
                                <input id="password" name="password" type="password" autocomplete="current-password" required
                                       class="appearance-none rounded-none relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-b-md focus:outline-none focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm"
                                       placeholder="Password">
                                <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5" onclick="togglePasswordVisibility()" aria-label="Toggle password visibility">
                                    <i class="fas fa-eye text-gray-400 hover:text-gray-600" id="togglePasswordIcon"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end">
                        <div class="text-sm">
                            <a href="/pages/forgot_password.php" class="font-medium text-purple-600 hover:text-purple-500">
                                Forgot your password?
                            </a>
                        </div>
                    </div>

                    <div>
                        <button type="submit" id="loginButton"
                                class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition duration-150 ease-in-out">
                            <span class="absolute left-0 inset-y-0 flex items-center pl-3 loading hidden">
                                 <svg class="animate-spin h-5 w-5 text-purple-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </span>
                            <span class="button-text">Sign in</span>
                        </button>
                    </div>

                    <div class="relative my-6">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-300"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 bg-gray-50 text-gray-500">
                                Or continue with
                            </span>
                        </div>
                    </div>

                    <div>
                        <a href="/pages/google-auth.php<?php echo !empty($redirect_url) ? '?redirect=' . urlencode($redirect_url) : ''; ?>"
                        class="w-full inline-flex justify-center py-3 px-4 border border-gray-300 rounded-md shadow-sm bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition duration-150 ease-in-out">
                            <img src="/assets/images/google-icon.png" alt="Google" class="w-5 h-5 mr-2">
                            Sign in with Google
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function togglePasswordVisibility() {
        const passwordInput = document.getElementById('password');
        const icon = document.getElementById('togglePasswordIcon');
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    document.getElementById('loginForm').addEventListener('submit', function(e) {
        // Basic client-side validation (optional, server-side is crucial)
        const email = document.getElementById('email-address').value;
        const password = document.getElementById('password').value;
        if (!email || !password) {
             // Let server handle detailed validation, just prevent empty submission visually
             // alert('Please enter both email and password.');
             // e.preventDefault(); // Uncomment if you want client-side blocking
             // return;
        }

        const button = document.getElementById('loginButton');
        // Disable button and show spinner
        button.disabled = true;
        button.classList.add('opacity-75', 'cursor-not-allowed');
        button.querySelector('.button-text').classList.add('opacity-0');
        button.querySelector('.loading').classList.remove('hidden');
    });

    // Re-enable button if the page is reloaded via back button (browser caching)
    window.addEventListener('pageshow', function(event) {
         if (event.persisted) { // Check if page came from cache
             const button = document.getElementById('loginButton');
             if (button) {
                 button.disabled = false;
                 button.classList.remove('opacity-75', 'cursor-not-allowed');
                 button.querySelector('.button-text').classList.remove('opacity-0');
                 button.querySelector('.loading').classList.add('hidden');
             }
         }
     });
    </script>
</body>
</html>