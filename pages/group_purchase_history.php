<?php
require_once "../includes/config.php";
ini_set('display_errors',1); error_reporting(E_ALL);

// params
$group_id = $_GET['group_id'] ?? '';
$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to'] ?? date('Y-m-d');
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $_SESSION['branch_id'] ?? '');

if ($group_id === '') {
    echo "<div class='alert alert-warning'>Group not specified.</div>";
    exit;
}

// connect to branch DB dynamically
$stmt = $con->prepare("SELECT * FROM m_branch_sync_config WHERE branch_id = ?");
$stmt->bind_param("s", $selected_branch);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    echo "<div class='alert alert-danger'>Branch config not found for '$selected_branch'</div>";
    exit;
}
$config = $res->fetch_assoc();
$branch_db = new mysqli($config['db_host'], $config['db_user'], $config['db_password'], $config['db_name']);
if ($branch_db->connect_error) { echo "<div class='alert alert-danger'>Branch DB connection failed.</div>"; exit; }
$branch_db->set_charset('utf8mb4');
$branch_db->query("SET time_zone = '+05:30'");

$from = $branch_db->real_escape_string($from);
$to   = $branch_db->real_escape_string($to);
$group_id_esc = $branch_db->real_escape_string($group_id);

// Build query: per item within group, sum purchase qty & amt
$query = "
CREATE TEMPORARY TABLE tmpPurIt AS
SELECT A.item_id,
       SUM(IFNULL(A.qty,0)) AS pur_qty,
       SUM(IFNULL(A.net_amt,0)) AS pur_amt
FROM t_receipt_det A
JOIN t_receipt_hdr B ON A.receipt_id = B.receipt_id
WHERE B.receipt_date BETWEEN STR_TO_DATE('$from', '%Y-%m-%d') AND STR_TO_DATE('$to', '%Y-%m-%d')
GROUP BY A.item_id;

/* select items in this group and aggregate */
SELECT I.item_id, I.item_desc,
       IFNULL(P.pur_qty,0) AS pur_qty,
       IFNULL(P.pur_amt,0) AS pur_amt
FROM m_item_hdr I
LEFT JOIN m_group G ON I.group_id = G.group_id
LEFT JOIN tmpPurIt P ON I.item_id = P.item_id
WHERE IFNULL(G.group_id, '') = '$group_id_esc'
  AND IFNULL(P.pur_qty,0) > 0
ORDER BY I.item_id;

/* cleanup */
DROP TEMPORARY TABLE IF EXISTS tmpPurIt;
";

$rows = [];
if ($branch_db->multi_query($query)) {
    do {
        if ($result = $branch_db->store_result()) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
    } while ($branch_db->more_results() && $branch_db->next_result());
} else {
    echo "<div class='alert alert-danger'>Query failed: {$branch_db->error}</div>";
    exit;
}

// Output table
?>
<div class="table-responsive">
<table class="table table-bordered">
    <thead>
        <tr>
            <th>Item ID</th>
            <th>Item Name</th>
            <th>Purchase Qty</th>
            <th>Purchase Amt</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $tot_pur_qty = $tot_pur_amt = 0;
        foreach ($rows as $r):
            $tot_pur_qty += floatval($r['pur_qty']);
            $tot_pur_amt += floatval($r['pur_amt']);
        ?>
        <tr>
            <td><?= htmlspecialchars($r['item_id']) ?></td>
            <td><?= htmlspecialchars($r['item_desc']) ?></td>
            <td><?= number_format($r['pur_qty'],3) ?></td>
            <td>₹<?= number_format($r['pur_amt'],2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="table-secondary">
            <th colspan="2">Totals</th>
            <th><?= number_format($tot_pur_qty,3) ?></th>
            <th>₹<?= number_format($tot_pur_amt,2) ?></th>
        </tr>
    </tfoot>
</table>
</div>
