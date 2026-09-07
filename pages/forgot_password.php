<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');
error_log("=== Forgot Password Page Load ===");

require_once '../config/db.php';
require_once '../lib/functions.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    error_log('Generated new CSRF token for forgot password form');
}

$error = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log('========= FORGOT PASSWORD PROCESS START =========');
    error_log('POST data: ' . print_r($_POST, true));

    try {
        // CSRF validation
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            error_log('CSRF validation failed for forgot password request');
            throw new Exception("Invalid form submission. Please try again.");
        }
        error_log('CSRF validation passed for forgot password request');

        // Honeypot check
        if (!empty($_POST['hp_field'])) {
            error_log('Honeypot field filled in forgot password form. Possible bot.');
            throw new Exception("Invalid submission.");
        }
        error_log('Honeypot check passed for forgot password form.');

        $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));

        if (empty($email)) {
            error_log('Validation failed: Email field empty for forgot password.');
            throw new Exception("Please enter your email address.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log('Validation failed: Invalid email format for forgot password: ' . $email);
            throw new Exception("Invalid email format.");
        }
        error_log('Email format validation passed for forgot password.');

        // Check if email exists in the database
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            error_log('Email not found in database for forgot password: ' . $email);
            // Intentionally do NOT reveal email not found for security reasons.
            $successMessage = "If an account with this email exists, a password reset link has been sent.";
            // Still return success message to avoid information disclosure.
            $_SESSION['success'] = $successMessage; // For display on the same page
            header("Location: forgot_password.php?reset_request_sent=1"); // Redirect to GET to prevent form resubmission
            exit();
        }
        error_log('Email found in database for forgot password. User ID: ' . $user['id']);

        $userId = $user['id'];
        $resetToken = generatePasswordResetToken();

        // Store reset token in database
        if (!storePasswordResetToken($pdo, $userId, $resetToken)) {
            throw new Exception("Failed to generate password reset link. Please try again later.");
        }
        error_log('Password reset token stored in database.');

        // Send password reset email
        if (!sendPasswordResetEmail($pdo, $email, $resetToken)) {
            throw new Exception("Failed to send password reset email. Please try again later.");
        }
        error_log('Password reset email sending initiated.');

        $successMessage = "If an account with this email exists, a password reset link has been sent.";
        $_SESSION['success'] = $successMessage; // For display on the same page
        header("Location: /pages/forgot_password.php?reset_request_sent=1"); // Redirect to GET to prevent form resubmission
        exit();


    } catch (PDOException $e) {
        error_log('Database Error during forgot password process: ' . $e->getMessage());
        $error = "A database error occurred. Please try again later.";
    } catch (Exception $e) {
        error_log('General Error during forgot password process: ' . $e->getMessage());
        $error = $e->getMessage();
    }

    error_log('Forgot password process ended with error: ' . $error);

} else {
    if (isset($_GET['reset_request_sent']) && $_GET['reset_request_sent'] == '1') {
        $successMessage = $_SESSION['success'] ?? "If an account with this email exists, a password reset link has been sent.";
        unset($_SESSION['success']); // Clear from session after displaying once
    }
    // Clear any previous errors if it's a GET request
    $error = ''; // Explicitly clear error on GET request
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - CECT</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Left side - Image -->
        <div class="hidden md:block md:w-1/2 bg-cover bg-center relative" style="background-image: url('/assets/images/forgot_password_img.png');">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-700 via-purple-800 to-purple-900 opacity-75"></div>
            <div class="relative h-full w-full flex flex-col justify-center items-center px-12 text-white text-center">
                <i class="fas fa-question-circle fa-4x mb-6 text-indigo-300"></i>
                <h2 class="text-4xl font-bold mb-4">Forgot Your Password?</h2>
                <p class="text-lg mb-8">No worries! Just enter your registered email address and we'll send you a link to reset your password.</p>
                <a href="/pages/login.php" class="mt-4 inline-block px-8 py-3 border-2 border-white text-white rounded-full text-center font-semibold hover:bg-white hover:text-indigo-700 transition duration-300 ease-in-out transform hover:scale-105">
                    Back to Sign In
                </a>
            </div>
        </div>

        <!-- Right side - Form -->
        <div class="w-full md:w-1/2 flex items-center justify-center p-8 sm:p-12">
            <div class="max-w-md w-full space-y-8">
                <div>
                    <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                        Reset your password
                    </h2>
                    <p class="mt-2 text-center text-sm text-gray-600">
                        Enter your registered email address below to receive password reset instructions.
                    </p>
                </div>

                <?php if (!empty($successMessage)): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md" role="alert">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm">
                                    <?php echo htmlspecialchars($successMessage); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md" role="alert">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-.707-4.293a1 1 0 001.414 0L12 12.414l1.293 1.293a1 1 0 001.414-1.414L13.414 11l1.293-1.293a1 1 0 00-1.414-1.414L12 9.586l-1.293-1.293a1 1 0 00-1.414 1.414L10.586 11l-1.293 1.293a1 1 0 000 1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium">
                                    <?php echo htmlspecialchars($error); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form class="mt-8 space-y-6" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="forgotPasswordForm" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <!-- Honeypot Field -->
                    <div style="position: absolute; left: -5000px;" aria-hidden="true">
                        <input type="text" name="hp_field" tabindex="-1" autocomplete="off">
                    </div>
                    <div>
                        <label for="email-address" class="sr-only">Email address</label>
                        <input id="email-address" name="email" type="email" autocomplete="email" required
                               class="appearance-none rounded-md relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 focus:outline-none focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm"
                               placeholder="Email address"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>

                    <div>
                        <button type="submit" id="resetButton"
                                class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition duration-150 ease-in-out">
                            <span class="absolute left-0 inset-y-0 flex items-center pl-3 loading hidden">
                                <svg class="animate-spin h-5 w-5 text-purple-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </span>
                            <span class="button-text">Send Reset Link</span>
                        </button>
                    </div>
                </form>
                <div class="text-sm text-gray-600 text-center">
                    Remember your password?
                    <a href="/pages/login.php" class="font-medium text-purple-600 hover:text-purple-500">
                        Sign in
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('forgotPasswordForm').addEventListener('submit', function(e) {
            const button = document.getElementById('resetButton');
            // Disable button and show spinner
            button.disabled = true;
            button.classList.add('opacity-75', 'cursor-not-allowed');
            button.querySelector('.button-text').classList.add('opacity-0');
            button.querySelector('.loading').classList.remove('hidden');
        });

        // Re-enable button if the page is reloaded via back button
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                const button = document.getElementById('resetButton');
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