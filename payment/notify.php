<?php
// Get the absolute path relative to this file's directory
$base_path = dirname(dirname(__FILE__)); 
require_once $base_path . '/config/db.php';

// Set response headers
header('Content-Type: application/json');

// Enable logging with absolute path
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/debug.log');
error_log("\n\n=== ToyyibPay Callback (Notify) START: " . date('Y-m-d H:i:s') . " ===");

// Debug path information
error_log("Base path: " . $base_path);
error_log("File path: " . __FILE__);
error_log("Directory: " . dirname(__FILE__));

// Explicitly log request method
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
error_log("Request Method: " . $request_method);

// Get raw POST data first if it's a POST request
$raw_post_data = '';
if ($request_method === 'POST') {
    $raw_post_data = file_get_contents('php://input');
    error_log("Raw POST data received: " . $raw_post_data);
}
error_log("POST array: " . print_r($_POST, true));
error_log("GET array: " . print_r($_GET, true));

// Prioritize POST data for parameters, then GET
$billcode = $_POST['billcode'] ?? $_POST['billCode'] ?? $_GET['billcode'] ?? null;
$status = $_POST['status'] ?? $_POST['status_id'] ?? $_GET['status'] ?? null;

error_log("Extracted parameters after checking POST then GET:");
error_log("Billcode: " . ($billcode ?? 'NULL'));
error_log("Status: " . ($status ?? 'NULL'));

if (!$billcode) {
    error_log("Error: Missing billcode");
    http_response_code(200);
    echo json_encode(['status' => 'error', 'message' => 'Missing billcode']);
    exit;
}

// Map ToyyibPay status codes to our status
$status_map = [
    '1' => 'paid',
    '2' => 'pending',
    '3' => 'failed',
    '4' => 'failed'
];

$payment_status = $status_map[$status] ?? 'unknown';
error_log("Mapped status '$status' to '$payment_status'");

try {
    // Assume $pdo is available after including config/db.php
    global $pdo; 
    if (!isset($pdo)) {
        error_log("Error: PDO connection not found after including db.php");
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database configuration error']);
        exit;
    }
    
    // First check if registration exists and get current status
    $checkStmt = $pdo->prepare("
        SELECT r.*, e.title as event_title 
        FROM registrations r
        LEFT JOIN events e ON r.event_id = e.id
        WHERE r.toyyibpay_bill_code = ?
    ");
    $checkStmt->execute([$billcode]);
    $registration = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$registration) {
        error_log("Error: No registration found for billcode: $billcode");
        http_response_code(200);
        echo json_encode(['status' => 'error', 'message' => 'Registration not found']);
        exit;
    }

    error_log("Found registration: " . print_r($registration, true));

    // ALWAYS UPDATE if status is 1 (paid)
    if ($status === '1') {
        $stmt = $pdo->prepare("
            UPDATE registrations 
            SET payment_status = 'paid'
            WHERE toyyibpay_bill_code = :billcode
        ");
        
        $params = [
            ':billcode' => $billcode
        ];
        
        error_log("Executing update with params: " . print_r($params, true));
        
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            error_log("Success: Updated payment status to 'paid' for registration ID: {$registration['id']}");
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => 'Payment status updated',
                'data' => [
                    'registration_id' => $registration['id'],
                    'new_status' => 'paid',
                    'billcode' => $billcode
                ]
            ]);
        } else {
            error_log("Notice: No changes made - status might be the same");
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => 'No changes needed',
                'data' => [
                    'current_status' => $registration['payment_status']
                ]
            ]);
        }
    } else {
        error_log("Notice: Ignoring non-paid status update. Status received: " . $status);
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Ignored non-paid status',
            'data' => [
                'received_status' => $status
            ]
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(200);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
} 