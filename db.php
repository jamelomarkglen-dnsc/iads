<?php
$servername = "localhost";

// Live
$username = "u645049065_iads2";
$password = "Dnsc01606";
$database = "u645049065_iads2";

// Local
// $username = "root";
// $password = ""; 
// $database = "advance_studies";


$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset and collation to prevent collation mismatch errors
$conn->set_charset('utf8mb4');
$conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
?>
