<?php
// FILE: actions/create_toyyibpay_bill.php (CORRECT CODE)
session_start();
require_once '../config/db.php'; // Includes ToyyibPay defines
require_once '../lib/functions.php';

header('Content-Type: application/json');

// Ensure BILL_EXPIRY_DAYS is defined (fallback)
if (!defined('BILL_EXPIRY_DAYS')) {
    define('BILL_EXPIRY_DAYS', 3);
}

// --- Authentication & CSRF ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required. Please log in.']);
    exit;
}
// Check $_POST for data sent via FormData from JS
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// --- Profile Check ---
if (!isUserProfileComplete($pdo, $user_id)) {
    $_SESSION['redirect_after_profile_update'] = $_SERVER['HTTP_REFERER'] ?? '/pages/events.php';
    $_SESSION['profile_error'] = 'Please complete your profile (First Name, Last Name, Phone Number) before registering for paid events.';
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $_SESSION['profile_error'], 'redirect' => '/pages/account_info.php?reason=incomplete_profile']);
    exit;
}

// --- Event ID Validation ---
// Get event_id from $_POST as sent by FormData
$event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
if (!$event_id || $event_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid event specified.']);
    exit;
}

// --- Logging Start ---
error_log("[CreateBill] Request received for User {$user_id}, Event {$event_id}");

try {
    $pdo->beginTransaction();
    error_log("[CreateBill] DB Transaction started.");

    // 1. Fetch Event Details (Ensure it's paid)
    $stmtEvent = $pdo->prepare("SELECT title, price FROM events WHERE id = ? FOR UPDATE");
    $stmtEvent->execute([$event_id]);
    $event = $stmtEvent->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        error_log("[CreateBill] Error: Event ID {$event_id} not found.");
        $pdo->rollBack(); http_response_code(404); echo json_encode(['success' => false, 'message' => 'Event not found.']); exit;
    }
    $eventPrice = (float)$event['price'];
    if ($eventPrice <= 0) {
        error_log("[CreateBill] Error: Event ID {$event_id} is free (Price: {$eventPrice}).");
        $pdo->rollBack(); http_response_code(400); echo json_encode(['success' => false, 'message' => 'This is a free event.']); exit;
    }
    error_log("[CreateBill] Event fetched: ID {$event_id}, Price {$eventPrice}");

    // 2. Fetch User Details
    $stmtUser = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if (!$user || empty($user['name']) || empty($user['email']) || empty($user['phone'])) {
        error_log("[CreateBill] Error: User ID {$user_id} profile incomplete.");
        $pdo->rollBack(); http_response_code(400); echo json_encode(['success' => false, 'message' => 'Your profile is incomplete (Name, Email, Phone required).']); exit;
    }
    error_log("[CreateBill] User fetched: ID {$user_id}, Email {$user['email']}");

    // 3. Check for Existing Registration (Paid, Pending, Failed)
    $stmtCheckReg = $pdo->prepare("
        SELECT id, payment_status, toyyibpay_bill_code, registration_date
        FROM registrations
        WHERE user_id = ? AND event_id = ?
        ORDER BY id DESC LIMIT 1");
    $stmtCheckReg->execute([$user_id, $event_id]);
    $existingReg = $stmtCheckReg->fetch(PDO::FETCH_ASSOC);

    $registration_id = null;
    $billCode = null;
    $create_new_bill = true; // Assume we need a new bill

    if ($existingReg) {
        error_log("[CreateBill] Existing registration found: ID {$existingReg['id']}, Status {$existingReg['payment_status']}, BillCode {$existingReg['toyyibpay_bill_code']}");
        $regStatus = $existingReg['payment_status'];
        $existingBillCode = $existingReg['toyyibpay_bill_code'];
        $regDate = null;
         try {
             if ($existingReg['registration_date']) {
                $regDate = new DateTime($existingReg['registration_date']);
                error_log("[CreateBill] Existing registration date: " . $regDate->format('Y-m-d H:i:s'));
             }
         } catch (Exception $e) {
             error_log("[CreateBill] Warning: Could not parse existing registration date '{$existingReg['registration_date']}'. Error: " . $e->getMessage());
         }

        if ($regStatus === 'paid') {
            error_log("[CreateBill] Error: User already paid (Reg ID {$existingReg['id']}).");
            $pdo->rollBack(); http_response_code(409); echo json_encode(['success' => false, 'message' => 'You are already registered and paid for this event.']); exit;
        }

        // Check if pending or failed, has a bill code, and has a valid date
        if (($regStatus === 'pending' || $regStatus === 'failed') && !empty($existingBillCode) && $regDate) {
            $now = new DateTime();
            $interval = $now->diff($regDate);
            $daysOld = (int)$interval->format('%a'); // Use integer cast
            error_log("[CreateBill] Checking expiry: Status {$regStatus}, BillCode exists, Date exists, Age {$daysOld} days (Expiry: " . BILL_EXPIRY_DAYS . " days).");

            if ($daysOld < BILL_EXPIRY_DAYS) {
                // *** REUSE EXISTING BILL ***
                $billCode = $existingBillCode;
                $registration_id = $existingReg['id'];
                $create_new_bill = false; // We found a valid, non-expired bill to reuse
                error_log("[CreateBill] Decision: REUSE Bill. BillCode {$billCode}, RegID {$registration_id}.");
            } else {
                // *** EXISTING BILL EXPIRED ***
                error_log("[CreateBill] Decision: Create NEW Bill. Reason: Existing bill expired (Age {$daysOld} >= " . BILL_EXPIRY_DAYS . ").");
                // Consider deleting or marking the old record as 'expired' here if needed
                // Example:
                // $stmtMarkExpired = $pdo->prepare("UPDATE registrations SET payment_status = 'expired' WHERE id = ?");
                // $stmtMarkExpired->execute([$existingReg['id']]);
                // error_log("[CreateBill] Marked expired Reg ID {$existingReg['id']} as 'expired'.");
            }
        } elseif (($regStatus === 'pending' || $regStatus === 'failed')) {
             // Handle inconsistent state (pending/failed without bill code or date) - treat as needing new bill
             error_log("[CreateBill] Decision: Create NEW Bill. Reason: Inconsistent state (Status: {$regStatus}, BillCode Empty: ".(empty($existingBillCode) ? 'Yes':'No').", Date Invalid: ".($regDate ? 'No':'Yes').").");
             // Consider deleting or marking the old record as 'error' here if needed
             // Example:
             // $stmtMarkError = $pdo->prepare("UPDATE registrations SET payment_status = 'error' WHERE id = ?");
             // $stmtMarkError->execute([$existingReg['id']]);
             // error_log("[CreateBill] Marked inconsistent Reg ID {$existingReg['id']} as 'error'.");
        } else {
             error_log("[CreateBill] Decision: Create NEW Bill. Reason: Existing registration status ('{$regStatus}') is not reusable.");
        }
    } else {
         error_log("[CreateBill] Decision: Create NEW Bill. Reason: No existing registration found for User {$user_id}, Event {$event_id}.");
    }

    // 4. Proceed: Either Reuse URL or Create New Bill
    if (!$create_new_bill && $billCode) {
        // --- REUSE FLOW ---
        $paymentUrl = TOYYIBPAY_API_URL . $billCode; // Construct the full URL to the bill page
        $pdo->commit(); // Commit transaction (no DB changes needed here)
        error_log("[CreateBill] REUSE: Committed. Redirecting User {$user_id} to {$paymentUrl}");
        echo json_encode(['success' => true, 'message' => 'Redirecting to your existing payment attempt...', 'paymentUrl' => $paymentUrl]);
        exit;
    } else {
        // --- CREATE NEW BILL FLOW ---
        error_log("[CreateBill] NEW: Proceeding to create new registration record.");
        // 5. Create a new pending registration record
        $stmtInsertReg = $pdo->prepare("INSERT INTO registrations (user_id, event_id, registration_date, payment_status) VALUES (?, ?, NOW(), 'pending')");
        if (!$stmtInsertReg->execute([$user_id, $event_id])) {
             $errorInfo = $stmtInsertReg->errorInfo();
             error_log("[CreateBill] Error: Failed to insert new pending registration. SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
             $pdo->rollBack();
             http_response_code(500); echo json_encode(['success' => false, 'message' => 'Failed to initiate registration record.']); exit;
        }
        $registration_id = $pdo->lastInsertId();
        error_log("[CreateBill] NEW: Registration record created: ID {$registration_id}");

        // 6. Prepare data for ToyyibPay createBill API
        $toyyibpay_data = [
            'userSecretKey' => TOYYIBPAY_SECRET_KEY,
            'categoryCode' => TOYYIBPAY_CATEGORY_CODE,
            'billName' => substr($event['title'], 0, 100), // Ensure within limits
            'billDescription' => substr("Payment for: " . $event['title'] . " (Reg ID: " . $registration_id . ")", 0, 255), // Ensure within limits
            'billPriceSetting' => 1, // 1 = Full Amount
            'billPayorInfo' => 1, // 1 = Compulsory
            'billAmount' => $eventPrice * 100, // Amount in cents
            'billReturnUrl' => APP_BASE_URL . '/pages/payment_status.php?reg_id=' . $registration_id,
            'billCallbackUrl' => rtrim(APP_BASE_URL, '/') . '/payment/notify.php',
            'billExternalReferenceNo' => (string)$registration_id, // Use our registration ID
            'billTo' => $user['name'],
            'billEmail' => $user['email'],
            'billPhone' => $user['phone'],
            'billSplitPayment' => 0, // 0 = No Split Payment
            'billSplitPaymentArgs' => '',
            'billPaymentChannel' => '0', // 0 = FPX, 1 = Credit Card, 2 = Both
            'billChargeToCustomer' => 1 // 1 = Pass FPX charge to customer, 2 = Absorb by merchant
            // 'billContentEmail' => 'Thank you for your payment!', // Optional email content
            // 'billPaymentAmount' => '', // Optional: if billPriceSetting is 0 (Open Amount)
            // 'billPaymentTax' => '', // Optional: Tax amount
        ];
        error_log("[CreateBill] NEW: Prepared data for ToyyibPay API: " . print_r($toyyibpay_data, true));


        // 7. Call ToyyibPay API using cURL
        error_log("[CreateBill] NEW: Calling ToyyibPay createBill API...");
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => TOYYIBPAY_API_URL . 'index.php/api/createBill', // Check this endpoint structure
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query($toyyibpay_data), // Send as form-urlencoded
            CURLOPT_FAILONERROR => false, // Set to false to get response body even on 4xx/5xx
            CURLOPT_TIMEOUT => 30, // Connection timeout
            CURLOPT_CONNECTTIMEOUT => 10 // Shorter connection timeout
        ]);
        $result = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);
        curl_close($curl);

        error_log("[CreateBill] NEW: ToyyibPay API Response - HTTP Code: {$httpcode}, cURL Error: {$err}, Response Body: {$result}");

        // Check HTTP code first
        if ($httpcode >= 400) {
            $pdo->rollBack();
            error_log("[CreateBill] Error: ToyyibPay API returned HTTP status {$httpcode}.");
            http_response_code(502); // Bad Gateway
            echo json_encode(['success' => false, 'message' => 'Payment gateway communication error (HTTP ' . $httpcode . '). Please try again later.']); exit;
        }
         // Check curl error after checking HTTP code
        if ($err) {
            $pdo->rollBack();
            error_log("[CreateBill] Error: cURL error calling ToyyibPay API: {$err}");
            http_response_code(502); // Bad Gateway
            echo json_encode(['success' => false, 'message' => 'Payment gateway communication error. Please try again later.']); exit;
        }


        // 8. Process ToyyibPay Response Body
        $bill_response = json_decode($result);

        // ToyyibPay returns an array [{ "BillCode": "..." }] on success
        if (is_array($bill_response) && isset($bill_response[0]->BillCode)) {
            $newBillCode = $bill_response[0]->BillCode;
            error_log("[CreateBill] NEW: ToyyibPay returned success. BillCode: {$newBillCode}");

            // 9. Update the NEW registration record with the BillCode
            $stmtUpdateReg = $pdo->prepare("UPDATE registrations SET toyyibpay_bill_code = ? WHERE id = ?");
            if (!$stmtUpdateReg->execute([$newBillCode, $registration_id])) {
                 $errorInfo = $stmtUpdateReg->errorInfo();
                 error_log("[CreateBill] Error: Failed to update new registration {$registration_id} with BillCode {$newBillCode}. SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
                 $pdo->rollBack();
                 http_response_code(500); echo json_encode(['success' => false, 'message' => 'Failed to store payment reference.']); exit;
            }
            error_log("[CreateBill] NEW: Updated registration {$registration_id} with BillCode {$newBillCode}.");

            // 10. Commit transaction and return payment URL
            $pdo->commit();
            $paymentUrl = TOYYIBPAY_API_URL . $newBillCode; // Construct the full URL
            error_log("[CreateBill] NEW: Committed. Redirecting User {$user_id} to {$paymentUrl}");
            echo json_encode(['success' => true, 'message' => 'Redirecting to payment gateway...', 'paymentUrl' => $paymentUrl]);
            exit;
        } else {
            // ToyyibPay returned an error JSON or unexpected format
            $pdo->rollBack();
            // Try to get a specific error message from the API response
            $apiErrorMsg = 'Unexpected response format';
            if (is_array($bill_response) && isset($bill_response[0]->msg)) {
                $apiErrorMsg = $bill_response[0]->msg;
            } elseif (is_object($bill_response) && isset($bill_response->msg)) {
                 $apiErrorMsg = $bill_response->msg;
            } elseif (is_string($result) && strlen($result) < 200) { // Show short non-JSON responses
                 $apiErrorMsg = $result;
            }
            error_log("[CreateBill] Error: ToyyibPay API did not return expected BillCode. Gateway Reason: {$apiErrorMsg}. Full Response: {$result}");
            http_response_code(502); echo json_encode(['success' => false, 'message' => "Failed to create payment bill. Gateway Reason: " . htmlspecialchars($apiErrorMsg)]); exit;
        }
    }

} catch (PDOException $e) {
    error_log("[CreateBill] CRITICAL PDOException: " . $e->getMessage());
    if ($pdo->inTransaction()) {
        error_log("[CreateBill] Rolling back transaction due to PDOException.");
        $pdo->rollBack();
    }
    http_response_code(500); echo json_encode(['success' => false, 'message' => 'An internal database error occurred.']); exit;
} catch (Exception $e) {
     error_log("[CreateBill] CRITICAL General Exception: " . $e->getMessage());
     if (isset($pdo) && $pdo->inTransaction()) {
        error_log("[CreateBill] Rolling back transaction due to Exception.");
         $pdo->rollBack();
     }
     http_response_code(500); echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']); exit;
}
?>