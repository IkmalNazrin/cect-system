<?php
// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 1); // Show errors for debugging
ini_set('log_errors', 1);   
ini_set('error_log', __DIR__ . '/debug.log');
error_log("=== New Registration Page Load ===");
error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("GET data: " . print_r($_GET, true));

// Ensure no output has been sent before headers
if (headers_sent($filename, $linenum)) {
    error_log("Headers already sent in $filename on line $linenum before starting output buffering");
    // Optionally die here if this is critical
}

// Start output buffering AFTER session start and potential header check
ob_start();

require_once '../config/db.php';

// Get redirect URL from GET request and sanitize it
$redirect_url = '';
if (isset($_GET['redirect'])) {
    $potential_redirect = filter_var($_GET['redirect'], FILTER_SANITIZE_URL);
    // Basic validation: Must start with /cect_system/
    if ($potential_redirect && strpos($potential_redirect, '/') === 0 && strpos($potential_redirect, '//') !== 0) {
        $redirect_url = $potential_redirect;
        error_log("Received redirect URL via GET for registration: " . $redirect_url);
    } else {
        error_log("Ignored invalid GET redirect URL for registration: " . $potential_redirect);
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    error_log('Generated new CSRF token for registration');
}

$error = ''; // Initialize error variable

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log('========= REGISTRATION PROCESS START =========');
    error_log('POST data: ' . print_r($_POST, true));
    error_log('SESSION data at registration: ' . print_r($_SESSION, true));

    try {
        // Verify database connection is still active
        if (!$pdo) {
            throw new Exception("Database connection lost.");
        }
        error_log('Database connection verified for registration POST');

        // CSRF validation
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            error_log('CSRF validation failed for registration');
            throw new Exception("Invalid form submission. Please try again.");
        }
        error_log('CSRF validation passed for registration');

        // Basic Honeypot Check
         if (!empty($_POST['hp_field'])) {
             error_log('Honeypot field filled. Possible bot.');
             throw new Exception("Invalid submission."); // Generic error
         }
         error_log('Honeypot check passed.');

        // Sanitize inputs
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
        $password = $_POST['password'] ?? ''; // Use null coalescing
        $confirm_password = $_POST['confirm_password'] ?? '';

        error_log('Sanitized inputs for registration:');
        error_log('Name: ' . $name);
        error_log('Email: ' . $email);
        // Do not log passwords

        // Validate required fields
        if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
             error_log('Validation failed: Missing required fields.');
            throw new Exception("All fields are required.");
        }
        error_log('Required fields validation passed');

         // Validate Email Format more strictly
         if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
             error_log('Validation failed: Invalid email format: ' . $email);
             throw new Exception("Invalid email format.");
         }
         error_log('Email format validation passed');

        // Check email existence
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $emailExists = $stmt->fetchColumn();

        if ($emailExists > 0) {
            error_log('Email already exists: ' . $email);
            throw new Exception("This email address is already registered.");
        }
        error_log('Email uniqueness check passed');

        // Password validation
        if ($password !== $confirm_password) {
             error_log('Validation failed: Passwords do not match.');
            throw new Exception("Passwords do not match.");
        }
        if (strlen($password) < 8) {
             error_log('Validation failed: Password too short.');
            throw new Exception("Password must be at least 8 characters long.");
        }
         // Add more complex password requirements check if desired (regex)
         if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[\W]/', $password)) {
              error_log('Validation failed: Password does not meet complexity requirements.');
             throw new Exception("Password must include uppercase, lowercase, number, and special character.");
         }
        error_log('Password validation passed');

        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        if ($hashed_password === false) {
             error_log('Password hashing failed.');
             throw new Exception('Could not process password.');
        }
        error_log('Password hashed successfully');

        // Insert new user
        $sql = "INSERT INTO users (name, email, password, profile_image, created_at) VALUES (?, ?, ?, 'default-avatar.jpg', NOW())";
        error_log('Preparing SQL for insert: ' . $sql);

        $stmt = $pdo->prepare($sql);
        error_log('Statement prepared successfully');

        // Execute insert
        $result = $stmt->execute([$name, $email, $hashed_password]);

        if (!$result) {
            error_log('Insert failed. Error info: ' . print_r($stmt->errorInfo(), true));
            throw new Exception("Failed to create account. Please try again.");
        }

        $newUserId = $pdo->lastInsertId();
        error_log('User inserted successfully with ID: ' . $newUserId);

        // Set success message for the login page
        $_SESSION['success'] = "Registration successful! Please sign in.";
        error_log('Success message set in session');

        // Redirect to login page, passing the original redirect URL
        $login_redirect_url = "/pages/login.php?success=1"; // Add success flag
        // Get redirect URL from the hidden form field
        $post_redirect = isset($_POST['redirect']) ? filter_var($_POST['redirect'], FILTER_SANITIZE_URL) : '';
        if (!empty($post_redirect) && strpos($post_redirect, '/') === 0 && strpos($post_redirect, '//') !== 0) {
            $login_redirect_url .= "&redirect=" . urlencode($post_redirect);
            error_log("Appending redirect to login URL: " . urlencode($post_redirect));
        } else {
             error_log("No valid redirect in POST, redirecting to login without specific destination.");
        }


        // Clear any output buffer before redirecting
        ob_end_clean();

        if (headers_sent($filename, $line)) {
            error_log("Headers already sent in $filename on line $line before registration redirect");
            // Fallback or error message if redirect fails
            echo "Registration successful, but could not redirect automatically. Please <a href='" . htmlspecialchars($login_redirect_url) . "'>click here to login</a>.";
            exit();
        }

        error_log('Redirecting to login page after registration: ' . $login_redirect_url);
        header("Location: " . $login_redirect_url);
        exit();

    } catch (PDOException $e) {
        error_log('Database Error during registration: ' . $e->getMessage());
        error_log('Error Trace: ' . $e->getTraceAsString());
        $error = "A database error occurred. Please try again later.";
    } catch (Exception $e) {
        error_log('General Error during registration: ' . $e->getMessage());
        error_log('Error Trace: ' . $e->getTraceAsString());
        $error = $e->getMessage(); // Use the specific error message
    }

    // If an error occurred, ensure we fall through to display the form again with the error
    error_log('Registration process ended with error: ' . $error);

} else {
     // Clear any previous errors if it's a GET request
     unset($_SESSION['success']);
}

// Prepare redirect URL for the Sign In link
$login_link_redirect = !empty($redirect_url) ? '?redirect=' . urlencode($redirect_url) : '';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - CECT</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Left side - Image -->
        <div class="hidden md:block md:w-1/2 bg-cover bg-center relative" style="background-image: url('/assets/images/sign_up_img.png');">
             <div class="absolute inset-0 bg-gradient-to-br from-indigo-700 via-purple-800 to-purple-900 opacity-75"></div>
            <div class="relative h-full w-full flex flex-col justify-center items-center px-12 text-white text-center">
                <i class="fas fa-users fa-4x mb-6 text-indigo-300"></i>
                <h2 class="text-4xl font-bold mb-4">Already a Member?</h2>
                <p class="text-lg mb-8">Sign in to access your event calendar and registrations.</p>
                <a href="/pages/login.php<?php echo $login_link_redirect; ?>" class="mt-4 inline-block px-8 py-3 border-2 border-white text-white rounded-full text-center font-semibold hover:bg-white hover:text-indigo-700 transition duration-300 ease-in-out transform hover:scale-105">
                    Sign In
                </a>
            </div>
        </div>

        <!-- Right side - Form -->
        <div class="w-full md:w-1/2 flex items-center justify-center p-8 sm:p-12">
            <div class="max-w-md w-full space-y-8">
                 <div>
                    <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                        Create your CECT account
                    </h2>
                    <p class="mt-2 text-center text-sm text-gray-600">
                        Or <a href="/pages/login.php<?php echo $login_link_redirect; ?>" class="font-medium text-purple-600 hover:text-purple-500">
                            sign in to your existing account
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
                                <p class="text-sm">Create an account to continue.</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if(!empty($error)): ?>
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
                                    error_log('Displaying registration error: ' . $error);
                                    echo htmlspecialchars($error);
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form class="mt-8 space-y-6" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . (!empty($redirect_url) ? '?redirect=' . urlencode($redirect_url) : ''); ?>" id="registerForm" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <!-- Hidden field to store the redirect URL -->
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect_url); ?>">
                    <!-- Honeypot Field -->
                    <div style="position: absolute; left: -5000px;" aria-hidden="true">
                        <input type="text" name="hp_field" tabindex="-1" autocomplete="off">
                    </div>
                     <!-- Add this hidden input for registration action identification (optional) -->
                    <input type="hidden" name="action" value="register">

                    <div class="rounded-md shadow-sm -space-y-px">
                    <div>
                            <label for="name" class="sr-only">Full Name</label>
                            <input id="name" name="name" type="text" autocomplete="name" required
                                   class="appearance-none relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm"
                                   placeholder="Full Name"
                                   value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                                   aria-describedby="nameHelp nameError">
                            <p id="nameHelp" class="text-gray-500 text-xs mt-1 px-1">Please enter your full name.</p> <!-- Helper Text Added -->
                            <p id="nameError" class="text-red-500 text-xs mt-1 h-4 px-1" aria-live="polite"></p> <!-- Existing Error Placeholder -->
                        </div>
                        <div>
                            <label for="email-address" class="sr-only">Email address</label>
                            <input id="email-address" name="email" type="email" autocomplete="email" required
                                   class="appearance-none relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm"
                                   placeholder="Email address"
                                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                    aria-describedby="emailHelp emailError">
                             <p id="emailHelp" class="text-gray-500 text-xs mt-1 px-1">Enter a valid email, e.g., user@example.com.</p> <!-- Helper Text Added -->
                             <p id="emailError" class="text-red-500 text-xs mt-1 h-4 px-1" aria-live="polite"></p> <!-- Existing Error Placeholder -->
                        </div>
                        <div>
                            <label for="password" class="sr-only">Password</label>
                            <div class="relative">
                                <input id="password" name="password" type="password" autocomplete="new-password" required
                                       class="appearance-none relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm"
                                       placeholder="Password"
                                       aria-describedby="passwordHelp passwordError password-strength-text"> <!-- Added passwordHelp -->
                                 <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5 z-20" onclick="togglePasswordVisibility('password')" aria-label="Toggle password visibility">
                                    <i class="fas fa-eye text-gray-400 hover:text-gray-600" id="passwordIcon"></i>
                                </button>
                            </div>
                            <p id="passwordHelp" class="text-gray-500 text-xs mt-1 px-1">Must be 8+ characters. Include upper/lowercase, number & symbol.</p> <!-- Helper Text Updated -->
                             <p id="passwordError" class="text-red-500 text-xs mt-1 h-4 px-1" aria-live="polite"></p> <!-- Existing Error Placeholder -->
                             <!-- Password Strength Indicator is now placed after the error message -->
                             <div class="pt-2 mb-4"> <!-- Keep existing strength indicator + Added margin-bottom -->
                                <div class="password-strength flex h-2 rounded-full overflow-hidden">
                                    <div class="strength-bar flex-1 bg-gray-200 transition-all duration-300"></div>
                                    <div class="strength-bar flex-1 bg-gray-200 transition-all duration-300 ml-1"></div>
                                    <div class="strength-bar flex-1 bg-gray-200 transition-all duration-300 ml-1"></div>
                                    <div class="strength-bar flex-1 bg-gray-200 transition-all duration-300 ml-1"></div>
                                </div>
                                <p id="password-strength-text" class="text-xs text-gray-500 mt-2"></p>
                            </div>
                        </div>
                        <div>
                            <label for="confirmPassword" class="sr-only">Confirm Password</label>
                             <div class="relative">
                                <input id="confirmPassword" name="confirm_password" type="password" autocomplete="new-password" required
                                       class="appearance-none relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm"
                                       placeholder="Confirm Password"
                                       aria-describedby="confirmPasswordHelp confirmPasswordError"> <!-- Added confirmPasswordHelp -->
                                <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5 z-20" onclick="togglePasswordVisibility('confirmPassword')" aria-label="Toggle confirm password visibility">
                                    <i class="fas fa-eye text-gray-400 hover:text-gray-600" id="confirmPasswordIcon"></i>
                                </button>
                             </div>
                            <p id="confirmPasswordHelp" class="text-gray-500 text-xs mt-1 px-1">Re-enter your password to confirm.</p> <!-- Helper Text Added -->
                            <p id="confirmPasswordError" class="text-red-500 text-xs mt-1 h-4 px-1" aria-live="polite"></p> <!-- Existing Error Placeholder -->
                        </div>
                    </div>

                    

                    <div>
                        <button type="submit" name="register" id="registerButton"
                                class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition duration-150 ease-in-out">
                             <span class="absolute left-0 inset-y-0 flex items-center pl-3 loading hidden">
                                 <svg class="animate-spin h-5 w-5 text-purple-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </span>
                            <span class="button-text">Sign up</span>
                        </button>
                    </div>

                     <div class="relative my-6">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-300"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 bg-gray-50 text-gray-500">
                                Or sign up with
                            </span>
                        </div>
                    </div>

                    <div>
                        <a href="/pages/google-auth.php<?php echo !empty($redirect_url) ? '?redirect=' . urlencode($redirect_url) : ''; ?>"
                        class="w-full inline-flex justify-center py-3 px-4 border border-gray-300 rounded-md shadow-sm bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition duration-150 ease-in-out">
                        <img src="/assets/images/google-icon.png" alt="Google" class="w-5 h-5 mr-2">
                            Sign up with Google
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function togglePasswordVisibility(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const icon = document.getElementById(`${fieldId}Icon`); // Corrected template literal
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

        // --- START: Input Validation Logic ---

        const form = document.getElementById('registerForm');
        const nameInput = document.getElementById('name');
        const emailInput = document.getElementById('email-address');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirmPassword');

        const nameError = document.getElementById('nameError');
        const emailError = document.getElementById('emailError');
        const passwordError = document.getElementById('passwordError');
        const confirmPasswordError = document.getElementById('confirmPasswordError');

        // Helper function to show error
        function showError(input, errorElement, message) {
            errorElement.textContent = message;
            input.classList.add('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
            input.classList.remove('border-gray-300', 'focus:border-purple-500', 'focus:ring-purple-500');
            input.setAttribute('aria-invalid', 'true');
        }

        // Helper function to clear error
        function clearError(input, errorElement) {
            errorElement.textContent = '';
            input.classList.remove('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
            input.classList.add('border-gray-300', 'focus:border-purple-500', 'focus:ring-purple-500');
             input.removeAttribute('aria-invalid');
        }

        // --- Validation Functions ---
        function validateName() {
            const nameValue = nameInput.value.trim();
            if (nameValue === '') {
                showError(nameInput, nameError, 'Full Name is required.');
                return false;
            }
            clearError(nameInput, nameError);
            return true;
        }

        function validateEmail() {
            const emailValue = emailInput.value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (emailValue === '') {
                showError(emailInput, emailError, 'Email address is required.');
                return false;
            } else if (!emailRegex.test(emailValue)) {
                showError(emailInput, emailError, 'Please enter a valid email address.');
                return false;
            }
            clearError(emailInput, emailError);
            return true;
        }

        function validatePassword() {
            const passwordValue = passwordInput.value;
            let isValid = true;
            let errorMessage = '';

            if (passwordValue.length < 8) {
                // Error message for length handled by static text and strength meter feedback
                isValid = false;
            }
            if (!/[A-Z]/.test(passwordValue)) {
                 errorMessage += 'Needs uppercase. ';
                 isValid = false;
            }
            if (!/[a-z]/.test(passwordValue)) {
                 errorMessage += 'Needs lowercase. ';
                 isValid = false;
            }
            if (!/[0-9]/.test(passwordValue)) {
                 errorMessage += 'Needs number. ';
                 isValid = false;
            }
             if (!/[\W_]/.test(passwordValue)) { // \W is non-word characters + underscore
                 errorMessage += 'Needs special character. ';
                 isValid = false;
             }

            if (!isValid && passwordValue.length > 0) { // Show specific criteria errors only if not empty
                 showError(passwordInput, passwordError, errorMessage.trim());
            } else {
                 clearError(passwordInput, passwordError); // Clear if valid or empty
            }
            // Also re-validate confirm password whenever password changes
            validateConfirmPassword(); // This triggers the real-time check on the confirm field
            return isValid; // Return validity based on criteria for submit check
        }

        function validateConfirmPassword() {
            const passwordValue = passwordInput.value;
            const confirmPasswordValue = confirmPasswordInput.value;

            // This is the core logic for real-time mismatch feedback:
            // Show error only if the confirm field is not empty AND it doesn't match
            if (confirmPasswordValue !== '' && passwordValue !== confirmPasswordValue) {
                 showError(confirmPasswordInput, confirmPasswordError, 'Passwords do not match.');
                return false; // Indicates mismatch
            }

            // Otherwise (if empty or matches), clear the mismatch error.
            clearError(confirmPasswordInput, confirmPasswordError);
            // The final 'required' check happens on submit. Return true here if no mismatch shown.
            return true;
        }

        // --- Event Listeners for Real-time Validation ---
        nameInput.addEventListener('input', validateName);
        emailInput.addEventListener('input', validateEmail);
        passwordInput.addEventListener('input', validatePassword); // Also updates strength meter & triggers confirm check
        confirmPasswordInput.addEventListener('input', validateConfirmPassword); // Checks mismatch on input

        // --- Form Submission Validation ---
        form.addEventListener('submit', function(event) {
             // Run all validations
            const isNameValid = validateName();
            const isEmailValid = validateEmail();
            // Need a stricter password check for submit (including complexity)
            const isPasswordComplex = validatePassword(); // Checks complexity
            const isPasswordValidForSubmit = passwordInput.value.length >= 8 && isPasswordComplex; // Combine length and complexity for submit

            // Need a stricter confirm password check for submit (including required)
            const isConfirmPasswordValidForSubmit = (confirmPasswordInput.value !== '' && passwordInput.value === confirmPasswordInput.value);


            // If any validation fails, prevent submission
            if (!isNameValid || !isEmailValid || !isPasswordValidForSubmit || !isConfirmPasswordValidForSubmit) {
                event.preventDefault(); // Stop form submission

                // --- Ensure specific errors are shown on submit attempt ---
                if (!isNameValid) validateName(); // Re-run to show error if needed
                if (!isEmailValid) validateEmail(); // Re-run to show error if needed

                // Show password complexity/length error if needed
                if (!isPasswordValidForSubmit) {
                    if (passwordInput.value.length === 0) {
                        showError(passwordInput, passwordError, 'Password is required.');
                    } else if (passwordInput.value.length < 8) {
                         showError(passwordInput, passwordError, 'Password must be at least 8 characters.');
                    } else {
                         // Re-run validatePassword to show complexity error message
                         validatePassword();
                    }
                } else {
                     clearError(passwordInput, passwordError); // Clear if OK
                }

                // Show confirm password required/mismatch error if needed
                 if (!isConfirmPasswordValidForSubmit) {
                    if (confirmPasswordInput.value === '') {
                        showError(confirmPasswordInput, confirmPasswordError, 'Please confirm your password.');
                    } else {
                        // Mismatch error should already be showing from real-time check, but re-run just in case
                         validateConfirmPassword();
                    }
                 } else {
                     clearError(confirmPasswordInput, confirmPasswordError); // Clear if OK
                 }
                 // --- End submit error showing ---


                // Re-enable button and hide spinner if validation fails client-side
                const button = document.getElementById('registerButton');
                button.disabled = false;
                button.classList.remove('opacity-75', 'cursor-not-allowed');
                button.querySelector('.button-text').classList.remove('opacity-0');
                button.querySelector('.loading').classList.add('hidden');

                // Focus the first invalid field (optional, good UX)
                if (!isNameValid) nameInput.focus();
                else if (!isEmailValid) emailInput.focus();
                else if (!isPasswordValidForSubmit) passwordInput.focus();
                else if (!isConfirmPasswordValidForSubmit) confirmPasswordInput.focus();

            } else {
                // If client-side validation passes, the spinner logic (already present) will run
                const button = document.getElementById('registerButton');
                // This submit listener logic might need adjustment if it's duplicated
                 button.disabled = true;
                 button.classList.add('opacity-75', 'cursor-not-allowed');
                 button.querySelector('.button-text').classList.add('opacity-0');
                 button.querySelector('.loading').classList.remove('hidden');
            }
        });

        // --- END: Input Validation Logic ---

         document.getElementById('registerForm').addEventListener('submit', function(e) {
            const button = document.getElementById('registerButton');
             // Disable button and show spinner
            button.disabled = true;
            button.classList.add('opacity-75', 'cursor-not-allowed');
            button.querySelector('.button-text').classList.add('opacity-0');
            button.querySelector('.loading').classList.remove('hidden');
         });

        // Password Strength Logic
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const strengthBars = document.querySelectorAll('.strength-bar');
            const strengthText = document.getElementById('password-strength-text');
            const criteria = {
                length: false,
                uppercase: false,
                lowercase: false,
                number: false,
                special: false
            };

            if(passwordInput) {
                passwordInput.addEventListener('input', function() {
                    const password = this.value;
                    criteria.length = password.length >= 8;
                    criteria.uppercase = /[A-Z]/.test(password);
                    criteria.lowercase = /[a-z]/.test(password);
                    criteria.number = /[0-9]/.test(password);
                    criteria.special = /[\W_]/.test(password); // \W includes underscore

                    let strengthScore = 0;
                    let feedback = [];
                    if(criteria.length) strengthScore++; // Keep scoring based on length
                    // else feedback.push("8+ characters"); // <-- REMOVED this part
                    if(criteria.uppercase) strengthScore++; else feedback.push("uppercase letter");
                    if(criteria.lowercase) strengthScore++; else feedback.push("lowercase letter");
                    if(criteria.number) strengthScore++; else feedback.push("number");
                    if(criteria.special) strengthScore++; else feedback.push("special character");

                    // Map score (0-5) to bars (0-4)
                    let barsToShow = Math.max(0, Math.min(4, Math.floor(strengthScore / 1.25))); // Simple mapping

                     updateStrengthIndicator(barsToShow, feedback);
                });
            }

            function updateStrengthIndicator(barsToShow, feedback) {
                const colors = ['bg-red-500', 'bg-red-500', 'bg-yellow-500', 'bg-green-500', 'bg-green-500']; // Colors for 0, 1, 2, 3, 4 bars
                const textFeedback = [
                    'Very Weak', 'Weak', 'Okay', 'Good', 'Strong'
                ];

                strengthBars.forEach((bar, index) => {
                    bar.classList.remove('bg-red-500', 'bg-yellow-500', 'bg-green-500', 'bg-gray-200');
                    if (index < barsToShow) {
                         bar.classList.add(colors[barsToShow] || 'bg-green-500');
                    } else {
                        bar.classList.add('bg-gray-200');
                    }
                });

                if (passwordInput.value.length === 0) {
                     strengthText.textContent = ''; // Clear the dynamic feedback text
                     strengthText.className = 'text-xs text-gray-500 mt-2'; // Reset class
                     strengthBars.forEach(bar => bar.className = 'strength-bar flex-1 bg-gray-200 transition-all duration-300 ml-1 first:ml-0'); // Reset bars
                } else if (feedback.length > 0) {
                    // This part remains the same: Shows "Needs: ..."
                    strengthText.textContent = `Needs: ${feedback.slice(0, 2).join(', ')}${feedback.length > 2 ? '...' : ''}`;
                     strengthText.className = 'text-xs text-red-500 mt-2';
                } else {
                     // This part remains the same: Shows strength level ("Okay", "Good", "Strong")
                     strengthText.textContent = textFeedback[barsToShow] || 'Strong';
                     strengthText.className = `text-xs mt-2 ${barsToShow >= 3 ? 'text-green-600' : 'text-yellow-600'}`;
                }
             }
        });

         // Re-enable button if the page is reloaded via back button
        window.addEventListener('pageshow', function(event) {
             if (event.persisted) {
                 const button = document.getElementById('registerButton');
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