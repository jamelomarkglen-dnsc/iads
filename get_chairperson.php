<?php
include 'db.php';
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $sql = $conn->prepare("SELECT firstname, lastname, email, department, position, contact FROM users WHERE id=? AND role='program_chairperson'");
    $sql->bind_param("i", $id);
    $sql->execute();
    $result = $sql->get_result();
    if ($row = $result->fetch_assoc()) {
        echo json_encode($row);
    } else {
        echo json_encode(["error" => "Not found"]);
    }
}
?>
