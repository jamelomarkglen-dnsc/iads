<?php
session_start();

// Check if user is logged in
if (isset($_SESSION["user_id"]) && isset($_SESSION["role"])) {
    // Redirect to appropriate dashboard based on role
    switch ($_SESSION["role"]) {
        case "dean":
            header("Location: dean.php");
            break;
        case "program_chairperson":
            header("Location: program_chairperson.php");
            break;
        case "faculty":
            header("Location: faculty.php");
            break;
        case "adviser":
            header("Location: adviser.php");
            break;
        case "panel":
            header("Location: panel.php");
            break;
        case "committee_chair":
            header("Location: my_committee_defense.php");
            break;
        case "committee_chairperson":
            header("Location: my_committee_defense.php");
            break;
        case "student":
            header("Location: student_dashboard.php");
            break;
        default:
            header("Location: login.php");
            break;
    }
    exit;
} else {
    // User not logged in, redirect to login page
    header("Location: login.php");
    exit;
}
?>
