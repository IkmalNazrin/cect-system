<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');
ob_start();
error_log("=== Reset Password Page Load ===");

require_once '../config/db.php';
require_once '../lib/functions.php';

// Initialize variables
$error = '';
$successMessage = '';
$token = $_GET['token'] ?? ''; // Get token from URL
$validTokenData = false; // Flag to track if token is valid

// Verify token on page load (GET request)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && !empty($token)) {
    error_log("Verifying password reset token from GET request: " . $token);
    $validTokenData = verifyPasswordResetToken($pdo, $token);
    if (!$validTokenData) {
        $error = "Invalid or expired password reset link. Please request a new password reset.";
        error_log("Token verification failed: " . $token);
    } else {
        error_log("Token verification successful for user ID: " . $validTokenData['user_id']);
        // Token is valid, form will be displayed
    }
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log('========= RESET PASSWORD PROCESS START =========');
    error_log('POST data: ' . print_r($_POST, true));

    try {
        // CSRF validation
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token_reset'], $_POST['csrf_token'])) {
            error_log('CSRF validation failed for reset password form');
            throw new Exception("Invalid form submission. Please try again.");
        }
        error_log('CSRF validation passed for reset password form');

        // Honeypot check
        if (!empty($_POST['hp_field'])) {
            error_log('Honeypot field filled in reset password form. Possible bot.');
            throw new Exception("Invalid submission.");
        }
        error_log('Honeypot check passed for reset password form.');

        $token = $_POST['reset_token'] ?? ''; // Get token from POST
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($token) || empty($password) || empty($confirm_password)) {
            error_log('Validation failed: Missing required fields in reset password form.');
            throw new Exception("All fields are required.");
        }
        error_log('Required fields validation passed for reset password form.');

        if ($password !== $confirm_password) {
            error_log('Validation failed: Passwords do not match in reset password form.');
            throw new Exception("Passwords do not match.");
        }
        if (strlen($password) < 8) {
            error_log('Validation failed: Password too short in reset password form.');
            throw new Exception("Password must be at least 8 characters long.");
        }
        // Add more complex password requirements check if desired (regex)
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[\W]/', $password)) {
            error_log('Validation failed: Password does not meet complexity requirements in reset password form.');
            throw new Exception("Password must include uppercase, lowercase, number, and special character.");
        }
        error_log('Password validation passed for reset password form.');

        // Verify token again (double check)
        $validTokenData = verifyPasswordResetToken($pdo, $token);
        if (!$validTokenData) {
            error_log("Token verification failed during password reset POST: " . $token);
            throw new Exception("Invalid or expired password reset link. Please request a new password reset.");
        }
        error_log("Token verification successful during password reset POST for user ID: " . $validTokenData['user_id']);

        $userId = $validTokenData['user_id'];
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        if ($hashed_password === false) {
            error_log('Password hashing failed during reset password.');
            throw new Exception('Could not process password.');
        }
        error_log('Password hashed successfully during reset password.');

        // Update password in database
        if (!updatePassword($pdo, $userId, $hashed_password)) {
            throw new Exception("Failed to update password. Please try again.");
        }
        error_log('Password updated in database.');

        // Mark token as used
        if (!markPasswordResetTokenAsUsed($pdo, $token)) {
            error_log("Warning: Failed to mark reset token as used, but password was reset.");
            // Non-critical error, continue success flow
        }
        error_log('Password reset token marked as used.');

        $successMessage = "Your password has been reset successfully. You can now sign in with your new password.";
        $_SESSION['success'] = $successMessage;
        header("Location: /pages/login.php?password_reset_success=1"); // Redirect to login with success flag
        exit();


    } catch (PDOException $e) {
        error_log('Database Error during reset password process: ' . $e->getMessage());
        $error = "A database error occurred. Please try again later.";
    } catch (Exception $e) {
        error_log('General Error during reset password process: ' . $e->getMessage());
        $error = $e->getMessage();
    }

    error_log('Reset password process ended with error: ' . $error);
}

// Generate CSRF token for POST form
if (empty($_SESSION['csrf_token_reset'])) {
    $_SESSION['csrf_token_reset'] = bin2hex(random_bytes(32));
    error_log('Generated new CSRF token for reset password form');
}
$csrf_token_reset = $_SESSION['csrf_token_reset'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - CECT</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">

    <script>
    // Global toggle function accessible to all buttons
    function togglePasswordVisibility(fieldId) {
        const passwordInput = document.getElementById(fieldId);
        const icon = document.getElementById(`${fieldId}Icon`);
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Password Strength Logic (single instance)
        const passwordInput = document.getElementById('password');
        const strengthBars = document.querySelectorAll('.strength-bar');
        const strengthText = document.getElementById('password-strength-text');
        
        if(passwordInput) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                const criteria = {
                    length: password.length >= 8,
                    uppercase: /[A-Z]/.test(password),
                    lowercase: /[a-z]/.test(password),
                    number: /[0-9]/.test(password),
                    special: /[\W_]/.test(password)
                };

                const strengthScore = Object.values(criteria).filter(Boolean).length;
                const feedback = Object.entries(criteria)
                    .filter(([_, met]) => !met)
                    .map(([type]) => type.replace(/[A-Z]/g, ' $&').toLowerCase());

                // Update strength indicator
                const colors = ['bg-red-500', 'bg-red-500', 'bg-yellow-500', 'bg-green-500', 'bg-green-500'];
                const barsToShow = Math.min(4, Math.floor(strengthScore / 1.25));
                
                strengthBars.forEach((bar, index) => {
                    bar.className = `strength-bar flex-1 transition-all duration-300 ml-1 ${index < barsToShow ? colors[barsToShow] : 'bg-gray-200'}`;
                });

                strengthText.textContent = password.length === 0 
                    ? 'Password must be at least 8 characters.'
                    : feedback.length > 0 
                        ? `Needs: ${feedback.slice(0, 2).join(', ')}${feedback.length > 2 ? '...' : ''}`
                        : ['Very Weak', 'Weak', 'Okay', 'Good', 'Strong'][barsToShow];
                
                strengthText.className = `text-xs mt-2 ${
                    feedback.length > 0 ? 'text-red-500' :
                    barsToShow >= 3 ? 'text-green-600' : 'text-yellow-600'
                }`;
            });
        }

        // Form submission handling
        const resetPasswordForm = document.getElementById('resetPasswordForm');
        if (resetPasswordForm) {
            resetPasswordForm.addEventListener('submit', function(e) {
                const button = document.getElementById('resetButton');
                button.disabled = true;
                button.classList.add('opacity-75', 'cursor-not-allowed');
                button.querySelector('.button-text').classList.add('opacity-0');
                button.querySelector('.loading').classList.remove('hidden');
            });
        }

        // Page restoration handling
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
    });
    </script>

        <div class="min-h-screen flex">
        <!-- Left side - Image -->
        <div class="hidden md:block md:w-1/2 bg-cover bg-center relative" style="background-image: url('/assets/images/reset_password_img.png');">
            <div class="absolute inset-0 bg-gradient-to-br from-purple-700 via-purple-800 to-indigo-900 opacity-75"></div>
            <div class="relative h-full w-full flex flex-col justify-center items-center px-12 text-white text-center">
                <i class="fas fa-lock fa-4x mb-6 text-purple-300"></i>
                <h2 class="text-4xl font-bold mb-4">Reset Your Password</h2>
                <p class="text-lg mb-8">Enter your new password below to regain access to your account.</p>
                <a href="/pages/login.php" class="mt-4 inline-block px-8 py-3 border-2 border-white text-white rounded-full text-center font-semibold hover:bg-white hover:text-purple-700 transition duration-300 ease-in-out transform hover:scale-105">
                    Back to Sign In
                </a>
            </div>
        </div>

        <!-- Right side - Form -->
        <div class="w-full md:w-1/2 flex items-center justify-center p-8 sm:p-12">
            <div class="max-w-md w-full space-y-8">
                <div>
                    <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                        Set New Password
                    </h2>
                    <p class="mt-2 text-center text-sm text-gray-600">
                        Enter your new password and confirm it below.
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

                <?php if ($validTokenData): ?>
                <form class="mt-8 space-y-6" id="resetPasswordForm" action="reset_password.php" method="POST"> <!-- Added form and classes from other pages -->
                    <input type="hidden" name="reset_token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token_reset); ?>">
                    <input type="hidden" name="hp_field" value="">

                    <div class="rounded-md shadow-sm -space-y-px">
                        <div>
                            <label for="password" class="sr-only">New Password</label>
                            <div class="relative">
                                <input type="password" id="password" name="password" required
                                       class="appearance-none rounded-none relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-t-md focus:outline-none focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm"
                                       placeholder="New Password">
                                <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5" onclick="togglePasswordVisibility('password')" aria-label="Toggle password visibility">
                                    <i id="passwordIcon" class="fas fa-eye text-gray-400 hover:text-gray-600"></i>
                                </button>
                            </div>
                            <div class="pt-2">
                                <div class="password-strength flex h-2 rounded-full overflow-hidden">
                                    <div class="strength-bar flex-1 bg-gray-200 transition-all duration-300"></div>
                                    <div class="strength-bar flex-1 bg-gray-200 transition-all duration-300 ml-1"></div>
                                    <div class="strength-bar flex-1 bg-gray-200 transition-all duration-300 ml-1"></div>
                                    <div class="strength-bar flex-1 bg-gray-200 transition-all duration-300 ml-1"></div>
                                </div>
                                <p id="password-strength-text" class="text-xs text-gray-500 mt-2">Password must be at least 8 characters.</p>
                            </div>
                        </div>

                        <div>
                            <label for="confirm_password" class="sr-only">Confirm New Password</label>
                            <div class="relative">
                                <input type="password" id="confirm_password" name="confirm_password" required
                                       class="appearance-none rounded-none relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-b-md focus:outline-none focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm"
                                       placeholder="Confirm New Password">
                                <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5" onclick="togglePasswordVisibility('confirm_password')" aria-label="Toggle confirm password visibility">
                                    <i id="confirm_passwordIcon" class="fas fa-eye text-gray-400 hover:text-gray-600"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <!-- reCAPTCHA can be added here if needed -->
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
                            <span class="button-text">Reset Password</span>
                        </button>
                    </div>
                </form>
                <?php else: ?>
                    <p class="text-gray-600 text-center">
                        Please request a password reset link if you haven't already.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>