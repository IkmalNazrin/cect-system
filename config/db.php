<?php
require_once __DIR__ . '/load_env.php';

$host = cect_env('DB_HOST', 'localhost');
$dbname = cect_env('DB_NAME', 'cect_db');
$username = cect_env('DB_USER', 'root');
$password = cect_env('DB_PASS', '');

try {
    // First create the PDO connection
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    error_log("Database connection successful");

    // Then verify the table structure
    $tableCheck = $pdo->query("
        SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = '$dbname' 
        AND TABLE_NAME = 'users'
    ");
    $columns = $tableCheck->fetchAll(PDO::FETCH_ASSOC);
    error_log('Database table structure verification:');
    error_log('Table columns: ' . print_r($columns, true));

} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die('Database connection failed: ' . $e->getMessage());
}

// ToyyibPay Configuration
define('TOYYIBPAY_SECRET_KEY', cect_env('TOYYIBPAY_SECRET_KEY', ''));
define('TOYYIBPAY_CATEGORY_CODE', cect_env('TOYYIBPAY_CATEGORY_CODE', ''));
define('TOYYIBPAY_API_URL', cect_env('TOYYIBPAY_API_URL', 'https://dev.toyyibpay.com/'));

// Base URL for Callbacks/Redirects
$configuredBaseUrl = cect_env('APP_BASE_URL', '');
if (!empty($configuredBaseUrl)) {
    define('APP_BASE_URL', rtrim($configuredBaseUrl, '/'));
} else {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('APP_BASE_URL', $protocol . $domainName);
}
?>
