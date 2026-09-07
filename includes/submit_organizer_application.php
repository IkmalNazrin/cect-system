<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once '../config/db.php';
require_once '../lib/functions.php';

// --- Security Checks ---
// 1. Check Login
if (!isset($_SESSION['user_id'])) {
    // Maybe store form data in session before redirecting?
    header('Location: /pages/login.php');
    exit();
}

// 2. Check CSRF Token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['errors'] = ['security' => 'Invalid request. Please try submitting the form again.'];
    $_SESSION['old'] = $_POST; // Keep submitted data
    header('Location: /pages/organizer_application.php');
    exit();
}
// Consider unsetting the token after successful validation to prevent reuse
// unset($_SESSION['csrf_token']);

// 3. Check Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     header('HTTP/1.1 405 Method Not Allowed');
     echo 'Invalid request method.';
     exit();
}

$user_id = $_SESSION['user_id'];
$errors = [];
$old = $_POST; // Store submitted data to repopulate form on error

// --- Eligibility Check ---
try {
    $stmt_check = $pdo->prepare("SELECT organizer_status FROM users WHERE id = ?");
    $stmt_check->execute([$user_id]);
    $existing_status = $stmt_check->fetchColumn();

    // Allow re-application ONLY if rejected or null
    if ($existing_status !== null && $existing_status !== 'rejected') {
        $_SESSION['info_message'] = 'You have already submitted an application or are an approved organizer.';
        header('Location: /pages/account_info.php');
        exit();
    }
} catch (PDOException $e) {
     error_log("Eligibility Check Error (submit_organizer_application): " . $e->getMessage());
     $_SESSION['errors'] = ['database' => 'Could not verify application eligibility. Please try again.'];
     header('Location: /pages/organizer_application.php');
     exit();
}


// --- Input Validation ---
$organization = trim($_POST['organization'] ?? '');
$org_type = trim($_POST['org_type'] ?? '');
$contact_person = trim($_POST['contact_person'] ?? '');
$website = trim($_POST['website'] ?? '');
$description = trim($_POST['description'] ?? '');
$payout_bank_name = trim($_POST['payout_bank_name'] ?? '');
$payout_account_number = trim($_POST['payout_account_number'] ?? '');

// --- Payout Validation ---
if (empty($payout_bank_name)) $errors['payout_bank_name'] = "Bank Name for payouts is required.";
if (empty($payout_account_number)) {
    $errors['payout_account_number'] = "Bank Account Number for payouts is required.";
} elseif (!preg_match('/^[0-9- ]+$/', $payout_account_number)) {
     $errors['payout_account_number'] = "Account number should only contain digits, spaces, or hyphens.";
}
// --- Payout Validation ---

if (empty($organization)) $errors['organization'] = "Organization Name is required.";
if (empty($org_type)) $errors['org_type'] = "Organization Type is required.";

$allowed_org_types = ['NGO', 'Business', 'Community', 'Educational'];
if (!in_array($org_type, $allowed_org_types)) $errors['org_type'] = "Invalid organization type selected.";

if (empty($contact_person)) $errors['contact_person'] = "Contact Person is required.";
if (!empty($website) && !filter_var($website, FILTER_VALIDATE_URL)) $errors['website'] = "Website URL format is invalid.";
if (empty($description)) {
    $errors['description'] = "Organization Description is required.";
} elseif (mb_strlen($description) < 150) { // Use mb_strlen for multi-byte characters
     $errors['description'] = "Organization Description must be at least 150 characters long.";
}


// --- File Upload Validation and Handling ---
$verification_doc_filename = null; // Store only the filename in DB
$upload_dir_relative = '/assets/uploads/verification_docs/'; // Relative to web root
$upload_dir_absolute = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $upload_dir_relative);

if (!is_dir($upload_dir_absolute)) {
    if (!mkdir($upload_dir_absolute, 0755, true)) {
        $errors['verification_doc'] = "Failed to create upload directory. Please contact support.";
        error_log("Failed to create directory: " . $upload_dir_absolute);
        // Don't proceed if directory can't be made
        $_SESSION['errors'] = $errors;
        $_SESSION['old'] = $old;
        header('Location: /pages/organizer_application.php');
        exit();
    }
    // Optional: Add .htaccess to deny direct access right after creating the dir
    file_put_contents($upload_dir_absolute . DIRECTORY_SEPARATOR . '.htaccess', "Deny from all");
}


if (isset($_FILES['verification_doc']) && $_FILES['verification_doc']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['verification_doc'];
    $max_size = 5 * 1024 * 1024; // 5MB
    // More robust MIME type checking if possible
    $allowed_mime_types = ['application/pdf', 'image/jpeg', 'image/png'];
    $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];

    // Basic checks first
    if ($file['size'] > $max_size) {
        $errors['verification_doc'] = "File is too large (Max 5MB allowed).";
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($mime_type, $allowed_mime_types) || !in_array($file_ext, $allowed_exts)) {
            $errors['verification_doc'] = "Invalid file type. Only PDF, JPG, PNG allowed.";
        } else {
            // Generate unique filename (prevents overwrites and sanitizes)
            $safe_filename_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
            $unique_filename = 'doc_' . $user_id . '_' . time() . '_' . $safe_filename_base . '.' . $file_ext;
            $destination = $upload_dir_absolute . $unique_filename;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                $errors['verification_doc'] = "Failed to save uploaded document. Please try again.";
                error_log("Failed to move uploaded file to: " . $destination);
            } else {
                // Success: store only the filename
                $verification_doc_filename = $unique_filename;
            }
        }
    }
} else {
    // Handle upload errors or missing file more specifically
    $upload_error_code = $_FILES['verification_doc']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($upload_error_code !== UPLOAD_ERR_NO_FILE && $upload_error_code !== UPLOAD_ERR_OK) {
        // Log specific error code for debugging
         error_log("File upload error for user $user_id: Code $upload_error_code");
         $errors['verification_doc'] = "An error occurred during file upload (Code: $upload_error_code). Please try again.";
    } elseif ($upload_error_code === UPLOAD_ERR_NO_FILE) {
        // Only mark as required if it's truly missing and there are no other errors yet
        // (prevents duplicate required messages if other fields also fail)
        if (empty($errors)) {
             $errors['verification_doc'] = "Verification document is required.";
        }
    }
}


// --- Database Update ---
if (empty($errors)) {
    // Fetch applicant email and name BEFORE the transaction
    $applicant_email = null;
    $applicant_name = null;
    try {
        $stmt_user = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $applicant_info = $stmt_user->fetch(PDO::FETCH_ASSOC);
        if ($applicant_info) {
            $applicant_email = $applicant_info['email'];
            $applicant_name = $applicant_info['name'];
        } else {
            // Should not happen if user is logged in, but good to handle
            error_log("Could not fetch applicant info for user ID: $user_id in submit_organizer_application.php");
            // Decide if you want to proceed without email or throw an error
        }
    } catch (PDOException $e) {
         error_log("Error fetching applicant email: " . $e->getMessage());
         // Decide if you want to proceed without email or throw an error
    }


    try {
        $pdo->beginTransaction(); // Start transaction

        $sql = "UPDATE users SET
                    organization = :organization,
                    org_type = :org_type,
                    contact_person = :contact_person,
                    website = :website,
                    organizer_description = :description,
                    verification_doc = :verification_doc, -- Store filename only
                    payout_bank_name = :payout_bank_name,
                    payout_account_number = :payout_account_number,
                    organizer_status = 'pending',         -- Set status
                    approval_notes = NULL                 -- Clear previous rejection notes
                WHERE id = :user_id";

        $stmt = $pdo->prepare($sql);

        // Bind parameters
        $stmt->bindParam(':organization', $organization);
        $stmt->bindParam(':org_type', $org_type);
        $stmt->bindParam(':contact_person', $contact_person);
        $stmt->bindParam(':website', $website, PDO::PARAM_STR); // Explicitly STR
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':verification_doc', $verification_doc_filename); // Store filename
        $stmt->bindParam(':payout_bank_name', $payout_bank_name);
        $stmt->bindParam(':payout_account_number', $payout_account_number);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            $pdo->commit(); // Commit transaction

            // Success
            $_SESSION['success_message'] = "Your organizer application has been submitted successfully and is pending review by an administrator.";
            unset($_SESSION['old']); // Clear form data on success

            // --- *** NEW: Notify Admin *** ---
            $admin_email = 'admin@cect.localhost'; // !!! IMPORTANT: Change this to your actual admin email(s)
                                                 // For multiple admins, you might query the DB or loop through a list
            $admin_subject = "New Organizer Application Submitted - CECT";

            // Construct email bodies
            $applicant_name_display = htmlspecialchars($contact_person ?: ($applicant_name ?: 'N/A'));
            $org_name_display = htmlspecialchars($organization ?: 'N/A');
            $dashboard_link = "http://{$_SERVER['HTTP_HOST']}/pages/admin_dashboard.php?tab=review#review-section"; // Adjust URL if needed

            $adminHtmlBody = "
                <html><body style='font-family: sans-serif;'>
                <h2>New Organizer Application Received</h2>
                <p>A new application requires your review:</p>
                <ul>
                    <li><strong>Applicant Name:</strong> {$applicant_name_display}</li>
                    <li><strong>Applicant Email:</strong> " . htmlspecialchars($applicant_email ?: 'N/A') . "</li>
                    <li><strong>Organization:</strong> {$org_name_display}</li>
                    <li><strong>Organization Type:</strong> " . htmlspecialchars($org_type ?: 'N/A') . "</li>
                </ul>
                <p>Please review the application in the admin dashboard:</p>
                <p><a href='{$dashboard_link}' style='padding: 10px 15px; background-color: #6366F1; color: white; text-decoration: none; border-radius: 5px;'>Review Application</a></p>
                <p>Thank you,</p>
                <p>CECT System Bot</p>
                </body></html>";

            $adminPlainBody = "
                New Organizer Application Received\n
                A new application requires your review:\n
                - Applicant Name: {$applicant_name_display}\n
                - Applicant Email: " . ($applicant_email ?: 'N/A') . "\n
                - Organization: {$org_name_display}\n
                - Organization Type: " . ($org_type ?: 'N/A') . "\n\n
                Please review the application in the admin dashboard:\n
                {$dashboard_link}\n\n
                Thank you,\n
                CECT System Bot\n";

            // Send the email using the function from functions.php
            if (!sendEmail($admin_email, $admin_subject, $adminHtmlBody, $adminPlainBody, 'ikmalnazrin256@gmail.com', 'CECT System Bot')) {
                 error_log("Failed to send admin notification email for new application from user ID: {$user_id}");
                 // Don't stop the user flow, but maybe log more prominently or add a session warning for admin?
            }
            // --- *** END Notify Admin *** ---

            header('Location: /pages/account_info.php'); // Redirect to account page
            exit();
        } else {
            $pdo->rollBack(); // Rollback on failure
            $errors['database'] = "Failed to submit application due to a database error. Please try again.";
            error_log("Failed to execute user update for organizer application. User ID: $user_id");
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { // Check if transaction started before rolling back
             $pdo->rollBack();
        }
        error_log("Database Error (submit_organizer_application): " . $e->getMessage()); // Log the actual error
        $errors['database'] = "An unexpected database error occurred. Please try again later or contact support.";
    }
}

// --- Handle Errors (Redirect Back) ---
if (!empty($errors)) {
    $_SESSION['errors'] = $errors;
    $_SESSION['old'] = $old; // Send back submitted data
    header('Location: /pages/organizer_application.php');
    exit();
}
?>