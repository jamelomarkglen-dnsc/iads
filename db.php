<?php
$servername = "localhost";
$username = "root";
$password = ""; // Leave this empty if using XAMPP with no MySQL password
$database = "advance_studies";

$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
