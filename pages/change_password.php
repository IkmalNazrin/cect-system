<?php
session_start();
$success = $_SESSION['success'] ?? null;
$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['success'], $_SESSION['errors']);
require_once '../config/db.php';
require_once '../includes/nav.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - CECT</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/zxcvbn@4.4.2/dist/zxcvbn.js"></script>
    <style>
        .password-strength-meter {
            height: 3px;
            transition: all 0.3s ease;
        }
        .password-strength-0 { width: 20%; background: #ef4444; }
        .password-strength-1 { width: 40%; background: #f59e0b; }
        .password-strength-2 { width: 60%; background: #eab308; }
        .password-strength-3 { width: 80%; background: #84cc16; }
        .password-strength-4 { width: 100%; background: #22c55e; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen pb-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="flex flex-col lg:flex-row gap-8">
                <!-- Include sidebar navigation -->
                <?php require_once '../includes/nav.php'; ?>

                <!-- Main Content -->
                <div class="flex-1 bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="p-6 border-b border-gray-100">
                        <h2 class="text-2xl font-semibold text-gray-800 flex items-center">
                            <svg class="w-6 h-6 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                            </svg>
                            Change Password
                        </h2>
                        <p class="mt-2 text-gray-600">Update your account password</p>
                    </div>

                    <!-- Messages -->
                    <div class="px-6">
                        <?php if ($success): ?>
                            <div class="fade-in bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 mt-4 flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <?= $success ?>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($errors as $error): ?>
                            <div class="fade-in bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 mt-4 flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                </svg>
                                <?= $error ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <form action="update_password.php" method="POST" class="p-6">
                        <div class="space-y-6">
                            <!-- Current Password -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                    <svg class="w-4 h-4 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                    Current Password
                                </label>
                                <div class="relative">
                                    <input type="password" name="current_password" required
                                        class="w-full px-4 py-2.5 border rounded-lg input-focus-style pr-10"
                                        id="current-password">
                                    <button type="button" 
                                            class="absolute right-3 top-3 text-gray-500 hover:text-purple-600"
                                            onclick="togglePasswordVisibility('current-password', this)">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- New Password -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                    <svg class="w-4 h-4 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                                    </svg>
                                    New Password
                                </label>
                                <div class="relative">
                                    <input type="password" name="new_password" required
                                        class="w-full px-4 py-2.5 border rounded-lg input-focus-style pr-10"
                                        id="new-password"
                                        oninput="checkPasswordStrength(this.value)">
                                    <button type="button" 
                                            class="absolute right-3 top-3 text-gray-500 hover:text-purple-600"
                                            onclick="togglePasswordVisibility('new-password', this)">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </button>
                                </div>
                                <div class="password-strength-meter mt-2 rounded-full"></div>
                                <div class="text-sm text-gray-500 mt-2" id="password-strength-text"></div>
                            </div>

                            <!-- Confirm Password -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                    <svg class="w-4 h-4 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    Confirm New Password
                                </label>
                                <div class="relative">
                                    <input type="password" name="confirm_password" required
                                        class="w-full px-4 py-2.5 border rounded-lg input-focus-style pr-10"
                                        id="confirm-password"
                                        oninput="checkPasswordMatch()">
                                    <button type="button" 
                                            class="absolute right-3 top-3 text-gray-500 hover:text-purple-600"
                                            onclick="togglePasswordVisibility('confirm-password', this)">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </button>
                                </div>
                                <div class="text-sm text-red-600 mt-1" id="password-match-error"></div>
                            </div>
                        </div>

                        <div class="mt-8 pt-6 border-t border-gray-100 flex justify-between items-center">
                            <a href="account_info.php" class="text-gray-600 hover:text-purple-600 flex items-center">
                                <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                                </svg>
                                Back to Profile
                            </a>
                            <button type="submit" class="relative px-6 py-2.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-medium transition-colors">
                                Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Password strength checker
    function checkPasswordStrength(password) {
        const strength = zxcvbn(password).score;
        const meter = document.querySelector('.password-strength-meter');
        const text = document.getElementById('password-strength-text');
        
        meter.className = `password-strength-meter password-strength-${strength}`;
        
        const strengthText = [
            'Very Weak',
            'Weak',
            'Fair',
            'Strong',
            'Very Strong'
        ][strength];
        
        text.textContent = `Password Strength: ${strengthText}`;
        text.className = `text-sm mt-2 ${
            strength < 2 ? 'text-red-600' : 
            strength < 3 ? 'text-yellow-600' : 'text-green-600'
        }`;
    }

    // Password match checker
    function checkPasswordMatch() {
        const password = document.getElementById('new-password').value;
        const confirmPassword = document.getElementById('confirm-password').value;
        const errorDiv = document.getElementById('password-match-error');
        
        if (password !== confirmPassword && confirmPassword.length > 0) {
            errorDiv.textContent = 'Passwords do not match';
            document.getElementById('confirm-password').classList.add('border-red-500');
        } else {
            errorDiv.textContent = '';
            document.getElementById('confirm-password').classList.remove('border-red-500');
        }
    }

    // Password visibility toggle
    function togglePasswordVisibility(inputId, button) {
        const input = document.getElementById(inputId);
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        button.innerHTML = isPassword ? 
            `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M6.358 6.353L4 4m0 0l2.358-2.353M4 4l2.358 2.353"/>
            </svg>` :
            `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>`;
    }
    </script>
</body>
</html>