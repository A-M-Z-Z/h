<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    header("Location: expired");
    exit();
}

// Database Connection
$host = 'localhost';
$user = 'root';
$pass = 'root';
$dbname = 'cloudbox';
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$userid = $_SESSION['user_id'];

// Check if folder_id is specified
if (!isset($_GET['folder_id']) || !is_numeric($_GET['folder_id'])) {
    die("Folder ID is required");
}

$folder_id = intval($_GET['folder_id']);

// Check if folder belongs to the user
$folder_check = $conn->query("SELECT folder_name FROM folders WHERE id = $folder_id AND user_id = $userid");
if ($folder_check->num_rows === 0) {
    die("Folder not found or access denied");
}

$folder_name = $folder_check->fetch_assoc()['folder_name'];

// Create a temporary directory for the files
$temp_dir = sys_get_temp_dir() . '/cloudbox_' . time();
if (!file_exists($temp_dir)) {
    mkdir($temp_dir, 0777, true);
}

// Function to recursively get all files in a folder
function getFilesInFolder($conn, $folder_id, $userid, $base_path, $parent_path = '') {
    $files_collected = 0;
    
    // Get files directly in this folder
    $files_query = $conn->query("SELECT id, filename, file_type FROM files WHERE folder_id = $folder_id AND user_id = $userid");
    
    while ($file = $files_query->fetch_assoc()) {
        // Get file content
        $content_query = $conn->query("SELECT content FROM file_content WHERE file_id = {$file['id']}");
        if ($content_query->num_rows > 0) {
            $content = $content_query->fetch_assoc()['content'];
            
            // Create path if it doesn't exist
            $current_path = $base_path . '/' . $parent_path;
            if (!file_exists($current_path)) {
                mkdir($current_path, 0777, true);
            }
            
            // Save file to disk
            file_put_contents($current_path . '/' . $file['filename'], $content);
            $files_collected++;
        }
    }
    
    // Get subfolders and process them
    $subfolders_query = $conn->query("SELECT id, folder_name FROM folders WHERE parent_folder_id = $folder_id AND user_id = $userid");
    
    while ($subfolder = $subfolders_query->fetch_assoc()) {
        $new_parent_path = $parent_path . ($parent_path ? '/' : '') . $subfolder['folder_name'];
        $files_collected += getFilesInFolder($conn, $subfolder['id'], $userid, $base_path, $new_parent_path);
    }
    
    return $files_collected;
}

// Collect all files
$total_files = getFilesInFolder($conn, $folder_id, $userid, $temp_dir);

if ($total_files === 0) {
    // Clean up temp directory
    if (file_exists($temp_dir)) {
        array_map('unlink', glob("$temp_dir/*.*"));
        rmdir($temp_dir);
    }
    
    die("No files found in this folder");
}

// Create ZIP archive
$zip_file = $temp_dir . '.zip';
$zip = new ZipArchive();

if ($zip->open($zip_file, ZipArchive::CREATE) !== TRUE) {
    die("Cannot create ZIP archive");
}

// Add files to ZIP recursively
function addFilesToZip($zip, $directory, $zip_path = '') {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            
            // Calculate relative path for ZIP
            $relativePath = $zip_path;
            if ($relativePath !== '') {
                $relativePath .= '/';
            }
            
            $relativePath .= substr($filePath, strlen($directory) + 1);
            
            $zip->addFile($filePath, $relativePath);
        }
    }
}

addFilesToZip($zip, $temp_dir);
$zip->close();

// Clean up temp directory
function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    
    return rmdir($dir);
}

// Send ZIP file to user
header("Content-Type: application/zip");
header("Content-Disposition: attachment; filename=\"" . $folder_name . ".zip\"");
header("Content-Length: " . filesize($zip_file));
readfile($zip_file);

// Clean up after sending the file
unlink($zip_file);
deleteDirectory($temp_dir);
