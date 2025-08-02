<?php
require_once "../includes/config.php";

$role_name = $_SESSION['role_name'];
$session_branch = $_SESSION['branch_id'] ?? '';
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $session_branch);

// Connect to central DB to get branch config
$stmt = $con->prepare("SELECT * FROM m_branch_sync_config WHERE branch_id = ?");
$stmt->bind_param("s", $selected_branch);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) die("❌ Branch config not found.");

$config = $res->fetch_assoc();
$branch_db = new mysqli($config['db_host'], $config['db_user'], $config['db_password'], $config['db_name']);
if ($branch_db->connect_error) die("❌ DB Error: " . $branch_db->connect_error);
$branch_db->set_charset('utf8mb4');
$branch_db->query("SET time_zone = '+05:30'");

$term = $_GET['term'] ?? '';
if (strlen($term) < 2) {
    echo json_encode([]);
    exit;
}

$stmt = $branch_db->prepare("SELECT user_id, user_name FROM m_user WHERE user_name LIKE CONCAT('%', ?, '%') ORDER BY user_name LIMIT 10");
$stmt->bind_param("s", $term);
$stmt->execute();
$result = $stmt->get_result();

$suggestions = [];
while ($row = $result->fetch_assoc()) {
    $suggestions[] = [
        'label' => $row['user_name'],
        'value' => $row['user_id']
    ];
}

echo json_encode($suggestions);
