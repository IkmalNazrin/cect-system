<?php
// Set headers (keep as is)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$base_path = dirname(dirname(__FILE__));

// Logging Setup (keep as is)
error_reporting(E_ALL);
ini_set('log_errors', 1);
$log_file = dirname(__FILE__) . '/debug.log'; // Define log file path
ini_set('error_log', $log_file);
error_log("\n\n=== ToyyibPay Callback START: " . date('Y-m-d H:i:s') . " ===");

require_once $base_path . '/config/db.php'; // Includes $pdo

// Function to send response (keep as is)
function send_response($status_code, $message, $data = []) {
    http_response_code($status_code);
    // Set content type AFTER setting http_response_code
    header('Content-Type: application/json');
    $response = ['status' => $status_code, 'message' => $message];
    if (!empty($data)) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    // Log the response being sent
    error_log("[Callback] Response sent: $status_code - $message. Data: " . json_encode($data));
    exit();
}

// Log request details (keep as is, good for debugging)
error_log("[Callback] Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
error_log("[Callback] Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN'));
$raw_post = file_get_contents('php://input');
error_log("[Callback] Raw POST data: " . $raw_post);
error_log("[Callback] POST array: " . print_r($_POST, true));
error_log("[Callback] GET array: " . print_r($_GET, true));

// Combine GET and POST data (ToyyibPay typically uses POST for callbacks)
$data = $_POST; // Prioritize POST for callbacks

// --- Extract Parameters ---
// Use null coalescing operator for cleaner extraction
$billcode = $data['billcode'] ?? $data['billCode'] ?? null;
$status_id = $data['status_id'] ?? $data['status'] ?? null; // ToyyibPay status: 1=paid, 2=pending, 3=failed
$order_id = $data['order_id'] ?? null; // Should match your registration ID / billExternalReferenceNo
$msg = $data['msg'] ?? null; // Optional message from ToyyibPay
$transaction_id = $data['billpaymentInvoiceNo'] ?? $data['refno'] ?? $data['billpaymentRefNo'] ?? null; // Try different possible keys for transaction ID

error_log("[Callback] Extracted Data - Billcode: $billcode, Status ID: $status_id, Order ID: $order_id, Msg: $msg, Transaction ID: $transaction_id");

// --- Input Validation ---
if (empty($billcode)) {
    error_log("[Callback] ERROR: Missing 'billcode'. Cannot process.");
    send_response(400, "Missing billcode parameter.");
}
if (empty($status_id)) {
    error_log("[Callback] ERROR: Missing 'status_id' for Billcode: $billcode. Cannot process status update.");
    // Send 200 OK so ToyyibPay doesn't retry indefinitely, but log the error clearly.
    send_response(200, "Missing status_id parameter, processing skipped.");
}

// --- Process Callback ---
try {
    // 1. Fetch current registration details based on billcode
    $stmtCheck = $pdo->prepare("SELECT id, payment_status FROM registrations WHERE toyyibpay_bill_code = ?");
    $stmtCheck->execute([$billcode]);
    $registration = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$registration) {
        error_log("[Callback] ERROR: No registration found for Billcode: $billcode. Ignoring callback.");
        // Send 200 OK - we can't process it if we don't know the billcode.
        send_response(200, "Registration not found for the provided billcode.");
    }

    $reg_id = $registration['id'];
    $current_db_status = $registration['payment_status'];
    error_log("[Callback] Found Registration ID: {$reg_id}. Current DB Status: '{$current_db_status}' for Billcode: $billcode");

    // 2. Determine the new status based on ToyyibPay's status_id
    $new_status = null;
    switch ($status_id) {
        case '1': // Success
            $new_status = 'paid';
            break;
        case '3': // Failed / Cancelled by user
            $new_status = 'failed';
            break;
        case '2': // Pending payment (rarely used in callbacks, more common in API checks)
            // Decide if you want to update to 'pending' from callback. Usually not necessary.
            // $new_status = 'pending';
            error_log("[Callback] Received 'pending' (status_id 2) for Billcode $billcode. Usually no DB update needed from callback for this.");
             send_response(200, "Pending status received, no update action taken."); // Exit here if no action for pending
             break; // Keep break just in case
        default:
            error_log("[Callback] Received unhandled status_id: '{$status_id}' for Billcode: $billcode. No action taken.");
            send_response(200, "Unhandled status_id: {$status_id}.");
            break; // Exit switch
    }

    // 3. Update the database IF the status needs changing
    if ($new_status !== null && $current_db_status !== $new_status) {
        error_log("[Callback] Attempting DB update for Reg ID: {$reg_id} (Billcode: $billcode). From '{$current_db_status}' To '{$new_status}'");

        // Add transaction ID and maybe message if available and you have columns for them
        $sql = "UPDATE registrations SET payment_status = :new_status";
        $params = [
            ':new_status' => $new_status,
            ':reg_id' => $reg_id // Use ID for WHERE clause - more reliable
        ];

        // Optional: Add transaction ID if you have a column like 'transaction_ref'
        // if (!empty($transaction_id)) {
        //     $sql .= ", transaction_ref = :trans_id";
        //     $params[':trans_id'] = $transaction_id;
        // }
        // Optional: Add payment message if you have a column like 'payment_notes'
        // if (!empty($msg)) {
        //     $sql .= ", payment_notes = :notes";
        //     $params[':notes'] = $msg;
        // }

        $sql .= " WHERE id = :reg_id AND payment_status != :new_status"; // Final WHERE clause prevents redundant updates

        $pdo->beginTransaction();
        try {
            $update_stmt = $pdo->prepare($sql);
            $update_stmt->execute($params);
            $rowCount = $update_stmt->rowCount();
            $pdo->commit();

            if ($rowCount > 0) {
                error_log("[Callback] SUCCESS: DB updated for Reg ID: {$reg_id} to '{$new_status}'. Rows affected: {$rowCount}.");
                // Respond 200 OK to ToyyibPay
                send_response(200, "Callback processed successfully. Status updated to {$new_status}.");
            } else {
                error_log("[Callback] INFO: No DB rows updated for Reg ID: {$reg_id}. Status might already be '{$new_status}' or WHERE condition mismatch.");
                send_response(200, "Callback received, but no database update was performed (status likely already correct).");
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("[Callback] CRITICAL: PDOException during DB update for Reg ID: {$reg_id}. Error: " . $e->getMessage());
            // Respond with 500 to signal an internal error, ToyyibPay might retry.
            send_response(500, "Internal server error during database update.");
        }

    } else {
        // Status hasn't changed or no action needed for the received status_id
        error_log("[Callback] No DB update required for Reg ID: {$reg_id}. Reason: New status ('{$new_status}') is same as current ('{$current_db_status}') or null.");
        send_response(200, "No status update required.");
    }

} catch (PDOException $e) {
    error_log("[Callback] CRITICAL: PDOException accessing database for Billcode: $billcode. Error: " . $e->getMessage());
    send_response(500, "Database connection or query error.");
} catch (Exception $e) {
    error_log("[Callback] CRITICAL: General Exception processing Billcode: $billcode. Error: " . $e->getMessage());
    send_response(500, "An unexpected error occurred.");
}

error_log("=== ToyyibPay Callback END ===");
?>