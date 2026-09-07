<?php
// Disable error reporting for production
error_reporting(0);
ini_set('display_errors', 0);

// But enable logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/payment_debug.log');

// Load database configuration
require_once '../config/db.php';

// Set headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Function to send response
function send_response($status_code, $message) {
    http_response_code($status_code);
    echo json_encode(['status' => $status_code, 'message' => $message]);
    error_log("Response sent: $status_code - $message");
    exit();
}

// Log request details
error_log("\n=== Payment Update START ===");
error_log("Time: " . date('Y-m-d H:i:s'));
error_log("IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
error_log("Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
error_log("Query: " . ($_SERVER['QUERY_STRING'] ?? 'NONE'));

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    header('HTTP/1.1 200 OK');
    exit();
}

// Collect data from all sources
$data = array_merge($_GET, $_POST);
error_log("Received data: " . print_r($data, true));

// Extract billcode and status
$billcode = $data['billcode'] ?? null;
$status = $data['status_id'] ?? $data['status'] ?? null;

// Validate minimum required data
if (empty($billcode)) {
    send_response(200, "No billcode provided - skipping update");
}

try {
    // Get registration details
    $stmt = $pdo->prepare("
        SELECT r.*, e.title as event_title, e.price as event_price
        FROM registrations r
        LEFT JOIN events e ON r.event_id = e.id
        WHERE r.toyyibpay_bill_code = ?
    ");
    $stmt->execute([$billcode]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registration) {
        send_response(200, "No registration found for billcode: $billcode");
    }

    // Determine new status
    $new_status = null;
    if ($status === '1' || $status === 1 || $status === 'success' || $status === 'Success') {
        $new_status = 'paid';
    } elseif ($status === '3' || $status === 3 || $status === 'fail' || $status === 'Failed') {
        $new_status = 'failed';
    } elseif ($registration['payment_status'] === 'pending') {
        $new_status = 'paid';
    }

    // Update if needed
    if ($new_status && $registration['payment_status'] === 'pending') {
        try {
            $pdo->beginTransaction();

            $update_stmt = $pdo->prepare("
                UPDATE registrations 
                SET payment_status = :status,
                    payment_confirmed_at = CASE WHEN :status = 'paid' THEN NOW() ELSE payment_confirmed_at END
                WHERE toyyibpay_bill_code = :billcode 
                AND payment_status = 'pending'
            ");

            $update_stmt->execute([
                ':status' => $new_status,
                ':billcode' => $billcode
            ]);

            if ($update_stmt->rowCount() > 0) {
                $pdo->commit();
                send_response(200, "Updated payment status to: $new_status");
            } else {
                $pdo->rollBack();
                send_response(200, "No update needed - status already set");
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Update error: " . $e->getMessage());
            send_response(200, "Could not update payment status");
        }
    } else {
        send_response(200, "No update needed - Current: " . $registration['payment_status']);
    }
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    send_response(200, "Error processing request");
}

// Shouldn't reach here
send_response(200, "Request processed");
?> 