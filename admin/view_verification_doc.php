<?php
session_start();
require_once '../config/db.php'; // Adjust path as needed

// --- Admin Authentication Check ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403); // Forbidden
    exit('<!DOCTYPE html><html><head><title>Access Denied</title></head><body><h1>Access Denied</h1><p>You do not have permission to view this document.</p></body></html>');
}

// --- Get User ID ---
$user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
if (!$user_id) {
    http_response_code(400); // Bad Request
    exit('<!DOCTYPE html><html><head><title>Bad Request</title></head><body><h1>Bad Request</h1><p>Invalid user specified.</p></body></html>');
}

// --- Fetch Document Filename from DB ---
try {
    $stmt = $pdo->prepare("SELECT verification_doc FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $filename = $stmt->fetchColumn();

    if (!$filename) {
        http_response_code(404); // Not Found
        exit('<!DOCTYPE html><html><head><title>Not Found</title></head><body><h1>Not Found</h1><p>Verification document not found for this user or user does not exist.</p></body></html>');
    }

    // --- Construct Absolute Path ---
    // IMPORTANT: Ensure this matches the path used in submit_organizer_application.php
    $upload_dir_relative = '/assets/uploads/verification_docs/'; // Path relative to web root
    $upload_dir_absolute = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $upload_dir_relative);
    $file_path = $upload_dir_absolute . $filename;

    // Basic security check: Prevent directory traversal
    if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
         http_response_code(400);
         exit('Invalid filename.');
    }


    // --- Check File Existence ---
    if (!file_exists($file_path) || !is_readable($file_path)) {
         http_response_code(404);
         error_log("Verification file not found or not readable: " . $file_path);
         exit('<!DOCTYPE html><html><head><title>Not Found</title></head><body><h1>Not Found</h1><p>Document file cannot be accessed. It may have been removed or permissions changed.</p></body></html>');
    }

    // --- Determine MIME Type ---
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_path);
    finfo_close($finfo);

    // Default MIME type if detection fails
    if (!$mime_type) {
        $mime_type = 'application/octet-stream';
    }

    // --- Serve the File ---
    header('Content-Type: ' . $mime_type);
    // Use 'inline' for PDFs/images if possible, 'attachment' forces download
    $disposition = (strpos($mime_type, 'image/') === 0 || $mime_type === 'application/pdf') ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public'); // For IE compatibility

    // Ensure no output before this point
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Read the file and send its content to the output buffer
    readfile($file_path);
    exit; // Important to stop script execution

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    error_log("Database Error (view_verification_doc): " . $e->getMessage());
     exit('<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Server Error</h1><p>A database error occurred while retrieving document information.</p></body></html>');
} catch (Exception $e) {
     http_response_code(500);
     error_log("General Error (view_verification_doc): " . $e->getMessage());
      exit('<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Server Error</h1><p>An unexpected error occurred.</p></body></html>');
}
?>