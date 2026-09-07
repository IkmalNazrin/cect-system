<?php
session_start();
require_once '../config/db.php';
require_once '../lib/functions.php';

// Generate CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI']; // Store intended page
    header('Location: /pages/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if user can apply (status is null or rejected)
try {
    $stmt = $pdo->prepare("SELECT organizer_status FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Redirect if already pending or approved
    if ($user && ($user['organizer_status'] === 'pending' || $user['organizer_status'] === 'approved')) {
        $_SESSION['info_message'] = 'You already have an active or pending organizer application.';
        header('Location: /pages/account_info.php');
        exit();
    }
    // Allow application if status is null or 'rejected'
} catch (PDOException $e) {
    error_log("Error checking user organizer status: " . $e->getMessage());
    // Handle error appropriately, maybe redirect with an error message
    $_SESSION['errors'] = ['database' => 'Could not verify your application eligibility. Please try again later.']; // Use key for errors
    header('Location: /pages/account_info.php');
    exit();
}

// ***** START PROFILE COMPLETION CHECK *****
if (!isUserProfileComplete($pdo, $user_id)) {
    // Store intended destination
    $_SESSION['redirect_after_profile_update'] = $_SERVER['REQUEST_URI']; // Store this page URL
    $_SESSION['profile_error'] = 'Please complete your profile (First Name, Last Name, Phone Number) before applying to become an organizer.';
    header('Location: /pages/account_info.php?reason=incomplete_profile');
    exit;
}
// ***** END PROFILE COMPLETION CHECK *****

// Get errors and old input from session, then clear them
$errors = $_SESSION['errors'] ?? [];
$old = $_SESSION['old'] ?? [];
unset($_SESSION['errors'], $_SESSION['old']);

// Include navigation
require_once '../includes/nav.php'; // Make sure nav.php is included only once
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Become an Organizer - CECT</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-slide-up { animation: slideUp 0.3s ease-out; }

        /* Basic focus styles */
        input:focus, select:focus, textarea:focus {
            outline: 2px solid transparent;
            outline-offset: 2px;
            box-shadow: 0 0 0 2px rgba(167, 139, 250, 0.5); /* Tailwind violet-300 */
            border-color: #a78bfa; /* Tailwind violet-400 */
        }
        .input-error {
             border-color: #f87171 !important; /* Tailwind red-400 */
             box-shadow: 0 0 0 2px rgba(248, 113, 113, 0.3) !important; /* Tailwind red-300 */
        }
         /* Add pointer cursor to select */
        select { cursor: pointer; }
        /* Ensure select arrow shows */
         select.appearance-none {
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
             background-position: right 0.5rem center;
             background-repeat: no-repeat;
             background-size: 1.5em 1.5em;
             padding-right: 2.5rem; /* Make space for arrow */
         }
         /* Loading state for button */
         button.is-loading .submit-text { opacity: 0; }
         button.is-loading .loading-spinner { opacity: 1; }
         button .loading-spinner { transition: opacity 0.2s ease-in-out; }

         /* Alpine cloak */
         [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gradient-to-br from-purple-50 via-white to-blue-50 min-h-screen">

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-xl border border-gray-100 overflow-hidden animate-slide-up">
            <!-- Form Header -->
            <div class="bg-gradient-to-r from-purple-500 to-indigo-500 p-6 sm:p-8 border-b border-purple-200/30">
                <div class="flex items-center gap-4">
                    <div class="bg-white/20 p-3 rounded-xl shadow-sm ring-1 ring-white/30">
                        <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold text-white tracking-tight">Organizer Application</h1>
                        <p class="text-sm sm:text-base text-purple-100 mt-1">Tell us about your organization to start hosting events.</p>
                    </div>
                </div>
            </div>

            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border-l-4 border-red-400 p-4 mx-6 sm:mx-8 mt-6 rounded-lg shadow-sm">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                        <div>
                             <h3 class="text-sm font-semibold text-red-800">Please correct the errors below:</h3>
                            <ul class="mt-1 list-disc list-inside text-sm text-red-700 space-y-0.5">
                                <?php foreach ($errors as $field => $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Application Form -->
            <!-- Ensure the action points to the correct processing script location -->
            <form action="/includes/submit_organizer_application.php" method="POST" enctype="multipart/form-data" class="p-6 sm:p-8 space-y-8">
                 <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                <!-- Organization Details Section -->
                <fieldset class="space-y-6 border border-gray-200 rounded-xl p-6 pt-4 shadow-sm bg-gray-50/50">
                     <legend class="text-lg font-semibold text-gray-800 px-3 -mt-1 mb-4 flex items-center gap-2 bg-gray-50 w-fit">
                         <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18" /></svg>
                         Organization Information
                     </legend>

                    <!-- Organization Name -->
                    <div>
                        <label for="organization" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Organization Name <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="organization" name="organization" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg transition-all duration-200 bg-white shadow-sm text-sm <?= isset($errors['organization']) ? 'input-error' : '' ?>"
                            value="<?= htmlspecialchars($old['organization'] ?? '') ?>"
                            placeholder="e.g., Community Arts Council">
                         <?php if(isset($errors['organization'])): ?><p class="text-xs text-red-600 mt-1"><?= $errors['organization'] ?></p><?php endif; ?>
                    </div>

                    <!-- Organization Type -->
                    <div class="relative">
                        <label for="org_type" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Organization Type <span class="text-red-500">*</span>
                        </label>
                        <select id="org_type" name="org_type" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white transition-all duration-200 appearance-none shadow-sm text-sm <?= isset($errors['org_type']) ? 'input-error' : '' ?>">
                            <option value="">Select organization type...</option>
                            <option value="NGO" <?= isset($old['org_type']) && $old['org_type'] === 'NGO' ? 'selected' : '' ?>>Non-Profit / NGO</option>
                            <option value="Business" <?= isset($old['org_type']) && $old['org_type'] === 'Business' ? 'selected' : '' ?>>Business / Company</option>
                            <option value="Community" <?= isset($old['org_type']) && $old['org_type'] === 'Community' ? 'selected' : '' ?>>Community Group</option>
                            <option value="Educational" <?= isset($old['org_type']) && $old['org_type'] === 'Educational' ? 'selected' : '' ?>>Educational Institution</option>
                        </select>
                        <!-- Select Arrow SVG -->
                         <div class="pointer-events-none absolute inset-y-0 right-0 top-7 flex items-center px-3 text-gray-400">
                             <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                              </svg>
                         </div>
                         <?php if(isset($errors['org_type'])): ?><p class="text-xs text-red-600 mt-1"><?= $errors['org_type'] ?></p><?php endif; ?>
                    </div>

                    <!-- Contact Person -->
                    <div>
                        <label for="contact_person" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Contact Person <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="contact_person" name="contact_person" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg transition-all duration-200 bg-white shadow-sm text-sm <?= isset($errors['contact_person']) ? 'input-error' : '' ?>"
                            value="<?= htmlspecialchars($old['contact_person'] ?? '') ?>"
                            placeholder="e.g., Jane Smith">
                         <?php if(isset($errors['contact_person'])): ?><p class="text-xs text-red-600 mt-1"><?= $errors['contact_person'] ?></p><?php endif; ?>
                    </div>

                    <!-- Website -->
                    <div>
                        <label for="website" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Organization Website <span class="text-gray-500 text-xs">(optional)</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                </svg>
                            </div>
                            <input type="url" id="website" name="website"
                                class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg transition-all duration-200 bg-white shadow-sm text-sm <?= isset($errors['website']) ? 'input-error' : '' ?>"
                                placeholder="https://your-organization.com"
                                value="<?= htmlspecialchars($old['website'] ?? '') ?>">
                        </div>
                         <?php if(isset($errors['website'])): ?><p class="text-xs text-red-600 mt-1"><?= $errors['website'] ?></p><?php endif; ?>
                    </div>
                </fieldset>

                <!-- Verification Section -->
                <fieldset class="space-y-4 border border-gray-200 rounded-xl p-6 pt-4 shadow-sm bg-gray-50/50" x-data="{ fileName: '' }">
                     <legend class="text-lg font-semibold text-gray-800 px-3 -mt-1 mb-4 flex items-center gap-2 bg-gray-50 w-fit">
                         <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                         Verification Document
                     </legend>

                    <div>
                        <label for="verification_doc" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Registration Certificate / Proof <span class="text-red-500">*</span>
                            <span class="block text-xs text-gray-500"> (PDF, JPG, PNG - Max 5MB)</span>
                        </label>
                        <div class="mt-1 flex items-center p-4 border-2 border-dashed border-gray-300 rounded-lg hover:border-purple-400 transition-colors duration-200 bg-white <?= isset($errors['verification_doc']) ? 'input-error !border-red-400' : '' ?>">
                             <label class="relative cursor-pointer rounded-md font-medium text-purple-600 hover:text-purple-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-purple-500">
                                 <span class="px-4 py-2 bg-purple-50 border border-purple-200 rounded-lg text-sm hover:bg-purple-100 transition shadow-sm">Choose File</span>
                                <input type="file" id="verification_doc" name="verification_doc" required
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    class="sr-only"
                                    @change="fileName = $event.target.files.length > 0 ? $event.target.files[0].name : ''">
                            </label>
                            <span x-text="fileName || 'No file selected'" class="ml-4 text-sm text-gray-600 truncate" :class="{ 'text-red-600': <?= isset($errors['verification_doc']) ? 'true' : 'false' ?> }"></span>
                        </div>
                         <template x-if="fileName">
                             <div class="mt-2 text-xs text-green-600 flex items-center">
                                 <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                 Selected: <span x-text="fileName" class="font-medium ml-1"></span>
                             </div>
                         </template>
                          <?php if(isset($errors['verification_doc'])): ?><p class="text-xs text-red-600 mt-1"><?= $errors['verification_doc'] ?></p><?php endif; ?>
                    </div>
                </fieldset>

                <!-- Payout Information Section -->
                <fieldset class="space-y-6 border border-gray-200 rounded-xl p-6 pt-4 shadow-sm bg-gray-50/50">
                    <legend class="text-lg font-semibold text-gray-800 px-3 -mt-1 mb-4 flex items-center gap-2 bg-gray-50 w-fit">
                        <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" /></svg>
                        Payout Information (For Event Revenue)
                    </legend>

                    <!-- Bank Name -->
                    <div>
                        <label for="payout_bank_name" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Bank Name <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="payout_bank_name" name="payout_bank_name" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg transition-all duration-200 bg-white shadow-sm text-sm <?= isset($errors['payout_bank_name']) ? 'input-error' : '' ?>"
                            value="<?= htmlspecialchars($old['payout_bank_name'] ?? '') ?>"
                            placeholder="e.g., Maybank, CIMB Bank">
                         <?php if(isset($errors['payout_bank_name'])): ?><p class="text-xs text-red-600 mt-1"><?= $errors['payout_bank_name'] ?></p><?php endif; ?>
                    </div>

                    <!-- Account Number -->
                    <div>
                        <label for="payout_account_number" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Bank Account Number <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="payout_account_number" name="payout_account_number" required pattern="[0-9- ]+" title="Please enter numbers, spaces, or hyphens only."
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg transition-all duration-200 bg-white shadow-sm text-sm <?= isset($errors['payout_account_number']) ? 'input-error' : '' ?>"
                            value="<?= htmlspecialchars($old['payout_account_number'] ?? '') ?>"
                            placeholder="Enter account number accurately">
                         <?php if(isset($errors['payout_account_number'])): ?><p class="text-xs text-red-600 mt-1"><?= $errors['payout_account_number'] ?></p><?php endif; ?>
                    </div>

                     <p class="text-xs text-gray-500 mt-1">
                        This information is required for processing payouts for any revenue generated from your paid events hosted on CECT. Please ensure accuracy.
                        <br><strong class="text-gray-600">Note:</strong> For this project, data is stored simply. A real application would require enhanced security and encryption for financial details.
                     </p>
                </fieldset>
                <!-- Payout Information Section -->

                <!-- Description Section -->
                <fieldset class="space-y-4 border border-gray-200 rounded-xl p-6 pt-4 shadow-sm bg-gray-50/50">
                    <legend class="text-lg font-semibold text-gray-800 px-3 -mt-1 mb-4 flex items-center gap-2 bg-gray-50 w-fit">
                         <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-2.138a1.126 1.126 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" /></svg>
                        About Your Organization
                    </legend>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Organization Description <span class="text-red-500">*</span>
                        </label>
                        <textarea id="description" name="description" rows="5" required minlength="150"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg transition-all duration-200 bg-white shadow-sm text-sm resize-y min-h-[120px] <?= isset($errors['description']) ? 'input-error' : '' ?>"
                            placeholder="Describe your organization's mission, typical events, and why you want to use CECT (min. 150 characters)"><?= htmlspecialchars($old['description'] ?? '') ?></textarea>
                        <p class="mt-2 text-xs text-gray-500">Please provide sufficient detail for review. Minimum 150 characters required.</p>
                         <?php if(isset($errors['description'])): ?><p class="text-xs text-red-600 mt-1"><?= $errors['description'] ?></p><?php endif; ?>
                    </div>
                </fieldset>

                <!-- Form Footer -->
                <div class="pt-8 border-t border-gray-200 flex flex-col sm:flex-row justify-end gap-3">
                    <a href="/pages/account_info.php" class="px-5 py-2.5 text-center text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 rounded-lg transition-colors duration-200 font-medium text-sm shadow-sm">
                        Cancel
                    </a>
                    <button type="submit" id="submitButton"
                            class="px-6 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-lg hover:from-purple-700 hover:to-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all duration-200 font-semibold text-sm shadow-md hover:shadow-lg relative group flex items-center justify-center disabled:opacity-70 disabled:cursor-not-allowed">
                        <span class="submit-text transition-opacity group-[.is-loading]:opacity-0">Submit Application</span>
                        <svg class="w-5 h-5 ml-2 group-hover:translate-x-1 transition-transform duration-200 group-[.is-loading]:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path></svg>
                        <span class="loading-spinner absolute inset-0 flex items-center justify-center opacity-0 group-[.is-loading]:opacity-100 transition-opacity duration-200 pointer-events-none">
                             <svg class="w-5 h-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                             </svg>
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php require_once '../includes/footer.php'; ?>

    <script>
    // Form submission loading state
    document.querySelector('form[action="/includes/submit_organizer_application.php"]').addEventListener('submit', (e) => {
        const btn = e.target.querySelector('#submitButton');
        // Very basic client-side check - server validation is primary
        const requiredFields = e.target.querySelectorAll('[required]');
        let formIsValid = true;
        requiredFields.forEach(field => {
             field.classList.remove('input-error'); // Clear previous errors
             let fieldValid = true;
             if (field.type === 'file') {
                 if(field.files.length === 0) fieldValid = false;
             } else if (field.type === 'textarea') {
                 if (field.value.trim().length < (field.minLength || 0)) fieldValid = false;
             } else if (!field.value.trim()) {
                 fieldValid = false;
             }

             if (!fieldValid) {
                 formIsValid = false;
                 field.classList.add('input-error');
                 // Find the label associated with the field to potentially highlight it too
                 const label = e.target.querySelector(`label[for='${field.id}']`);
                 if(label) label.classList.add('text-red-600'); // Example highlight
             } else {
                  const label = e.target.querySelector(`label[for='${field.id}']`);
                  if(label) label.classList.remove('text-red-600');
             }
        });

        if(formIsValid) {
             btn.disabled = true;
             btn.classList.add('is-loading');
        } else {
            e.preventDefault(); // Prevent submission if client validation fails
            // Scroll to the first error? (Optional enhancement)
            const firstError = e.target.querySelector('.input-error');
            if(firstError) {
                firstError.focus();
                // Optionally show a general message
            }
            console.warn("Please fill all required fields correctly.");
        }
    });

     // Re-enable button if the page is navigated back to (bfcache)
     window.addEventListener('pageshow', function(event) {
         const form = document.querySelector('form[action="/includes/submit_organizer_application.php"]');
         const btn = form ? form.querySelector('#submitButton') : null;
         if (event.persisted && btn) {
             btn.disabled = false;
             btn.classList.remove('is-loading');
         }
     });
    </script>
</body>
</html>