<?php
session_start();
require_once '../config/db.php'; // Includes APP_BASE_URL & $pdo

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$reg_id = isset($_GET['reg_id']) ? (int)$_GET['reg_id'] : 0;

// ToyyibPay redirect parameters (for logging)
$toyyib_status_id_param = $_GET['status_id'] ?? null;
$toyyib_billcode_param = $_GET['billcode'] ?? null;
$toyyib_order_id_param = $_GET['order_id'] ?? null;
$toyyib_msg_param = $_GET['msg'] ?? null;
$toyyib_transaction_id_param = $_GET['transaction_id'] ?? null;

if ($reg_id > 0) {
     error_log("Payment Status Page: Accessed for Reg ID {$reg_id}. Toyyib RETURN Params: status_id={$toyyib_status_id_param}, billcode={$toyyib_billcode_param}, order_id={$toyyib_order_id_param}, msg={$toyyib_msg_param}, trans_id={$toyyib_transaction_id_param}");
} else {
    error_log("Payment Status Page: Accessed with invalid or missing reg_id in URL.");
}

// --- Default Values ---
$message = 'An unknown error occurred determining payment status.';
$status_class = 'bg-red-100 border-red-400 text-red-700';
$status_icon = 'fas fa-times-circle';
$status_title = 'Payment Error';
$event_id = null;
$event_title = 'the event';
$final_display_status = 'error'; // Status used for final display logic
$db_status = null;
$billCode = null;

if ($reg_id > 0) {
    try {
        // --- 1. Fetch registration details ---
        $stmt = $pdo->prepare("SELECT r.payment_status, r.toyyibpay_bill_code, e.id as event_id, e.title
                               FROM registrations r
                               JOIN events e ON r.event_id = e.id
                               WHERE r.id = ?");
        $stmt->execute([$reg_id]);
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($registration) {
            $event_id = $registration['event_id'];
            $event_title = $registration['title'];
            $db_status = $registration['payment_status'];
            $billCode = $registration['toyyibpay_bill_code'];
            $final_display_status = $db_status; // Start with DB status

            error_log("Payment Status Page: Initial DB status for Reg ID {$reg_id} is '{$db_status}'. Bill Code: {$billCode}. Redirect status_id: {$toyyib_status_id_param}");

            // --- SPECIAL CASE: Handle successful retry redirect before DB/API updates ---
            if ($db_status === 'failed' && $toyyib_status_id_param === '1') {
                error_log("Payment Status Page: Detected successful redirect (status_id=1) but DB is still 'failed' for Reg ID {$reg_id}. Forcing display to 'pending' and enabling refresh.");
                $final_display_status = 'pending'; // Override to pending
                // Skip API check for now, rely on refresh/callback
                $should_check_api = false;
            } else {
                // Determine if API check is needed based on DB status (pending or failed)
                 $should_check_api = ($db_status === 'pending' || $db_status === 'failed');
            }

            // --- 2. Verify with ToyyibPay API if needed ---
            if ($should_check_api && !empty($billCode)) {
                error_log("Payment Status Page: Status is '{$db_status}'. Checking ToyyibPay API for BillCode {$billCode}...");

                $toyyibpay_check_data = ['billCode' => $billCode];
                $toyyibpayApiUrl = defined('TOYYIBPAY_API_URL') ? rtrim(TOYYIBPAY_API_URL, '/') : 'https://dev.toyyibpay.com';
                $apiUrl = $toyyibpayApiUrl . '/index.php/api/getBillTransactions';

                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $apiUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($toyyibpay_check_data),
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_CONNECTTIMEOUT => 5
                ]);
                $response = curl_exec($curl);
                $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $err = curl_error($curl);
                curl_close($curl);

                // Initialize display status based on current DB status before API check
                // $final_display_status = $db_status; // Already set above

                if ($err || $httpcode >= 400) {
                    error_log("Payment Status Page: cURL Error or HTTP {$httpcode} checking ToyyibPay status for BillCode {$billCode}: " . $err);
                    // Keep status as 'pending' if API check fails, rely on refresh/callback
                    $final_display_status = 'pending';
                    error_log("Payment Status Page: API check failed. Setting display status to 'pending'.");

                } else {
                    error_log("Payment Status Page: ToyyibPay API Response for BillCode {$billCode}: " . $response);
                    $result = json_decode($response, true);

                    if (is_array($result) && !empty($result)) {
                        // Look for ANY successful transaction ('1') in the list
                        $found_paid_status = false;
                        foreach ($result as $transaction) {
                            if (isset($transaction['billpaymentStatus']) && $transaction['billpaymentStatus'] === '1') {
                                $final_display_status = 'paid';
                                $found_paid_status = true;
                                error_log("Payment Status Page: Found successful transaction (status 1) via API for BillCode {$billCode}.");
                                break; // Found success, no need to check further
                            }
                        }

                        // If no transaction with status '1' was found, use the status of the first transaction (assumed latest)
                        if (!$found_paid_status) {
                            $transaction = $result[0]; // Get the first/latest transaction details
                            if (isset($transaction['billpaymentStatus'])) {
                                $api_status_code = $transaction['billpaymentStatus'];
                                error_log("Payment Status Page: No paid transaction found. Using status '{$api_status_code}' from latest API transaction for BillCode {$billCode}.");

                                // Determine Display Status based on the latest transaction status
                                if ($api_status_code === '3') { // 3 = Failed
                                    $final_display_status = 'failed';
                                } elseif ($api_status_code === '2' || $api_status_code === '4') { // 2 = Pending, 4 = Maybe Cancelled
                                    $final_display_status = 'pending';
                                } else {
                                    error_log("Payment Status Page: Unknown/Unexpected latest API status code '{$api_status_code}'. Treating as 'pending'.");
                                    $final_display_status = 'pending'; // Treat other statuses (like 0?) as pending
                                }
                            } else {
                                error_log("Payment Status Page: Unexpected API response format (latest transaction missing 'billpaymentStatus') for BillCode {$billCode}.");
                                $final_display_status = 'pending'; // Treat format error as pending
                            }
                        } // end if (!$found_paid_status)

                        // If we determined a final status from the API ('paid', 'failed', or 'pending')
                        if ($final_display_status === 'paid' || $final_display_status === 'failed' || $final_display_status === 'pending') {
                             error_log("Payment Status Page: Set final_display_status to '{$final_display_status}' based on API check for BillCode {$billCode}.");

                            // --- Attempt to Update DB (best effort, callback is primary) ---
                            // Only update if the API gives a *terminal* status ('paid' or 'failed')
                            // AND that status is different from the current DB status we started with.
                            if (($final_display_status === 'paid' || $final_display_status === 'failed') && $final_display_status !== $db_status)
                            {
                                error_log("Payment Status Page: Attempting to update DB for Reg ID {$reg_id} from '{$db_status}' to '{$final_display_status}'...");
                                try {
                                    $pdo->beginTransaction();
                                    // Update only if the current status matches what we started with (i.e., 'pending' or 'failed')
                                    $updateStmt = $pdo->prepare("UPDATE registrations SET payment_status = :new_status WHERE id = :reg_id AND payment_status = :expected_current_status");
                                    $updateStmt->execute([':new_status' => $final_display_status, ':reg_id' => $reg_id, ':expected_current_status' => $db_status]); // Use original $db_status
                                    $rowCount = $updateStmt->rowCount();
                                    $pdo->commit();
                                    error_log("Payment Status Page: DB Update executed for Reg ID {$reg_id}. Rows affected: {$rowCount}.");
                                    // If update succeeded, update $db_status in memory for consistency in this script run
                                    if ($rowCount > 0) {
                                        $db_status = $final_display_status;
                                    }
                                } catch (PDOException $e) {
                                    if ($pdo->inTransaction()) $pdo->rollBack();
                                    error_log("Payment Status Page: DB Error during background update for Reg ID {$reg_id}: " . $e->getMessage());
                                    // Do not change $final_display_status here, display is based on API result
                                }
                            } else {
                                 error_log("Payment Status Page: No immediate DB update needed for Reg ID {$reg_id} (DB: '{$db_status}', API implies: '{$final_display_status}').");
                            }
                        }
                    } else {
                         error_log("Payment Status Page: Empty or invalid JSON response from API check for BillCode {$billCode}.");
                         // Keep status as 'pending' if API response bad
                         $final_display_status = 'pending';
                         error_log("Payment Status Page: API JSON error. Setting display status to 'pending'.");
                    }
                }
            } else if ($final_display_status !== 'pending') { // Log only if API wasn't skipped due to the special case above
                 // If DB status was not 'pending' or 'failed', we didn't check API.
                 // Just use the initial DB status for display.
                 $final_display_status = $db_status; // Ensure it's set correctly
                 if (!empty($billCode)) { // Add log only if not checking API is intentional
                    error_log("Payment Status Page: Initial DB status '{$db_status}' for Reg ID {$reg_id} does not require API check (it's 'paid' or 'free', or handled by redirect status). Using DB status for display.");
                 }
            } // End API Check

            // --- 3. Determine final message and appearance based on $final_display_status ---
            switch ($final_display_status) {
                case 'paid':
                    $status_title = 'Payment Successful';
                    $message = "Thank you! Your payment was successful. You are now registered for <strong>" . htmlspecialchars($event_title) . "</strong>.";
                    $status_class = 'bg-green-100 border-green-400 text-green-700';
                    $status_icon = 'fas fa-check-circle';
                    break;
                case 'failed':
                    $status_title = 'Payment Failed';
                    $message = "Unfortunately, the payment process wasn't completed successfully (status: failed). Your registration for <strong>" . htmlspecialchars($event_title) . "</strong> is not confirmed. You may be able to <a href='/pages/event.php?id={$event_id}' class='font-medium underline hover:text-yellow-800'>try again</a> from the event page.";
                    $status_class = 'bg-yellow-100 border-yellow-400 text-yellow-700';
                    $status_icon = 'fas fa-exclamation-triangle';
                    break;
                 case 'pending':
                    $status_title = 'Payment Pending Confirmation';
                    $message = "We are confirming your payment status. This page will refresh automatically. If the status doesn't update to 'Successful' soon, please check the 'My Events' page or contact support.";
                    $status_class = 'bg-blue-100 border-blue-400 text-blue-700';
                    $status_icon = 'fas fa-hourglass-half';
                    break;
                 case 'free':
                    $status_title = 'Registration Confirmed';
                    $message = "This is a free event. Your registration for <strong>" . htmlspecialchars($event_title) . "</strong> is confirmed.";
                    $status_class = 'bg-green-100 border-green-400 text-green-700';
                    $status_icon = 'fas fa-check-circle';
                     break;
                default: // Includes 'error' or any unexpected initial DB status we didn't handle above
                    // If it somehow ended up here with a status other than the main ones, log it and show a generic pending/error message.
                    error_log("Payment Status Page: Reached default case in switch with unexpected final_display_status '{$final_display_status}' for Reg ID {$reg_id}. Displaying as pending.");
                    $status_title = 'Payment Status Update Pending';
                    $message = "We are checking the latest status of your registration for <strong>" . htmlspecialchars($event_title) . "</strong>. This page may refresh automatically. Please check 'My Events' or contact support if the status doesn't update shortly.";
                    $status_class = 'bg-blue-100 border-blue-400 text-blue-700'; // Use pending style
                    $status_icon = 'fas fa-hourglass-half'; // Use pending icon
                    $final_display_status = 'pending'; // Force status to pending for refresh logic
                    break;
            }

        } else { // Registration not found
            $message = 'Could not find the registration record associated with this transaction.';
            $status_title = 'Registration Not Found';
            error_log("Payment Status Page: Registration record not found in DB for Reg ID {$reg_id}.");
            $final_display_status = 'error';
        }
    } catch (PDOException $e) { // DB error fetching initial registration
        error_log("Payment Status Page: Initial DB Error for Reg ID {$reg_id}: " . $e->getMessage());
        $message = 'A database error occurred while retrieving the registration status. Please contact support.';
        $status_title = 'Database Error';
        $final_display_status = 'error';
    }
} else { // Invalid reg_id
    $message = 'Invalid transaction reference. Unable to determine payment status.';
    $status_title = 'Invalid Request';
    $final_display_status = 'error';
}

// --- Determine if auto-refresh is needed ---
$autoRefreshMeta = '';
// Refresh only if the final determined status is 'pending'
if ($final_display_status === 'pending') {
    $refreshUrl = APP_BASE_URL . '/pages/payment_status.php?reg_id=' . $reg_id;
    $autoRefreshMeta = '<meta http-equiv="refresh" content="10;url=' . htmlspecialchars($refreshUrl) . '">'; // Refresh every 10 seconds
    error_log("Payment Status Page: Adding auto-refresh meta tag for Reg ID {$reg_id} (Status: pending)");
}

?>

<!DOCTYPE html>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($status_title) ?> - CECT</title>
    <?= $autoRefreshMeta // Add the refresh tag here ?>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <?php include '../includes/nav.php'; ?>

<main class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="p-6 md:p-8 border-l-4 <?= $status_class ?>">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="<?= $status_icon ?> text-3xl mr-4 <?= explode(' ', $status_class)[2] ?>"></i>
                </div>
                <div>
                    <h1 class="text-xl md:text-2xl font-semibold <?= explode(' ', $status_class)[2] ?>"><?= htmlspecialchars($status_title) ?></h1>
                    <p class="mt-2 text-gray-600"><?= $message ?></p>
                </div>
            </div>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex flex-col sm:flex-row gap-3 justify-end items-center">
             <?php if ($final_display_status === 'pending'): ?>
                <span class="text-sm text-gray-500 flex items-center">
                    <svg class="animate-spin h-4 w-4 mr-1.5 text-blue-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    Checking status... Auto-refreshing.
                </span>
             <?php endif; ?>
            <?php if ($event_id): ?>
            <a href="/pages/event.php?id=<?= $event_id ?>"
               class="inline-flex justify-center items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
               <i class="fas fa-arrow-left mr-2 text-xs"></i> Back to Event
            </a>
            <?php endif; ?>
            <a href="/pages/registered_events.php"
               class="inline-flex justify-center items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500">
               Go to My Events <i class="fas fa-calendar-check ml-2 text-xs"></i>
            </a>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
</body>
</html>