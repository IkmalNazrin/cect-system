<?php

// --- Ensure script is run from CLI (Command Line Interface) ---
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: This script can only be run from the command line.\n");
}

// --- Set timezone consistently ---
date_default_timezone_set('Asia/Kuala_Lumpur'); // IMPORTANT: Match the timezone in functions.php

// --- Include necessary files ---
// Use absolute paths for reliability in cron
require_once __DIR__ . '/../config/db.php';       // Connects to DB ($pdo is created here)
require_once __DIR__ . '/../lib/functions.php'; // Contains checkAndSendEventReminders

error_log("Cron Job: send_reminders_cron.php started at " . date('Y-m-d H:i:s'));

// --- Execute the Reminder Function ---
try {
    // Call the function with the database connection
    // Send reminders for events happening between 24 and 25 hours from now.
    $results = checkAndSendEventReminders($pdo, 24, 1);

    $summary = sprintf(
        "Reminder Job Summary: Processed=%d, Sent=%d, Failed=%d",
        $results['processed'],
        $results['sent'],
        $results['failed']
    );
    echo $summary . "\n"; // Output summary to cron log
    error_log("Cron Job: " . $summary);

} catch (Exception $e) {
    $errorMsg = "Cron Job Error: Exception caught in send_reminders_cron.php: " . $e->getMessage() . " on line " . $e->getLine();
    echo $errorMsg . "\n"; // Output error to cron log
    error_log($errorMsg);
    exit(1); // Exit with an error code
}

error_log("Cron Job: send_reminders_cron.php finished successfully at " . date('Y-m-d H:i:s'));
exit(0); // Exit successfully
?>