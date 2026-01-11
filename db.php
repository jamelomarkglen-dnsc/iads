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
?>
