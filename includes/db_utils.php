<?php
function getBranchConnection($branch_id)
{
    $central = new mysqli('localhost', 'shivendra', '24199319@Shiv', 'softgen_db_central');
    if ($central->connect_error) {
        die("❌ Central DB connection failed: " . $central->connect_error);
    }

    $stmt = $central->prepare("SELECT db_host, db_user, db_password, db_name FROM m_branch_sync_config WHERE branch_id = ?");
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        die("❌ Branch config not found for '$branch_id'");
    }

    $row = $result->fetch_assoc();
    $branchCon = new mysqli($row['db_host'], $row['db_user'], $row['db_password'], $row['db_name']);
    if ($branchCon->connect_error) {
        die("❌ Branch DB connection failed: " . $branchCon->connect_error);
    }

    $branchCon->query("SET time_zone = '+05:30'");
    $branchCon->set_charset("utf8mb4");
    return $branchCon;
}
