<?php
require_once "../includes/config.php";
include "../includes/header.php";

ini_set('display_errors', 1);
error_reporting(E_ALL);

$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$role_name       = $_SESSION['role_name'];
$session_branch  = $_SESSION['branch_id'] ?? '';
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $session_branch);

$invoices = [];
$totals = [
    'qty' => 0,
    'net_amt' => 0,
    'ret_qty' => 0,
    'ret_net_amt' => 0,
    'net_qty' => 0,
    'net_amt_final' => 0,
];

// Branch connection
$stmt = $con->prepare("SELECT * FROM m_branch_sync_config WHERE branch_id = ?");
$stmt->bind_param("s", $selected_branch);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    die("❌ Branch config not found.");
}
$config = $res->fetch_assoc();
$branch_db = new mysqli(
    $config['db_host'],
    $config['db_user'],
    $config['db_password'],
    $config['db_name']
);
if ($branch_db->connect_error) die("❌ DB Error: " . $branch_db->connect_error);
$branch_db->set_charset('utf8mb4');
$branch_db->query("SET time_zone = '+05:30'");

// Run main query
$query = "
CREATE TEMPORARY TABLE tmpPurRegIt AS
SELECT 
    B.item_id,CAST('' AS CHAR(255)) AS item_name, -- This defines the correct column type,
    SUM(IFNULL(B.qty, 0)) AS qty,
    SUM(IFNULL(B.net_amt, 0)) AS net_amt,
    0 AS ret_qty,
    0 AS ret_net_amt
FROM t_receipt_hdr A
JOIN t_receipt_det B ON B.receipt_id = A.receipt_id
WHERE A.receipt_date BETWEEN STR_TO_DATE('$from', '%Y-%m-%d') AND STR_TO_DATE('$to', '%Y-%m-%d')
GROUP BY B.item_id;

UPDATE tmpPurRegIt T
JOIN m_item_hdr M ON T.item_id = M.item_id
SET T.item_name = M.item_desc;

CREATE TEMPORARY TABLE tmpPurRet AS
SELECT 
    B.item_id,
    SUM(IFNULL(B.qty, 0)) AS ret_qty,
    SUM(IFNULL(B.net_amt, 0)) AS ret_net_amt
FROM t_pur_ret_hdr A
JOIN t_pur_ret_det B ON B.ret_no = A.ret_no
WHERE A.ret_dt BETWEEN STR_TO_DATE('$from', '%Y-%m-%d') AND STR_TO_DATE('$to', '%Y-%m-%d')
GROUP BY B.item_id;

UPDATE tmpPurRegIt T
JOIN tmpPurRet R ON T.item_id = R.item_id
SET T.ret_qty = R.ret_qty, T.ret_net_amt = R.ret_net_amt;

SELECT 
    item_id, item_name,
    qty, net_amt,
    ret_qty, ret_net_amt,
    (qty - ret_qty) AS net_qty,
    (net_amt - ret_net_amt) AS net_amt_final
FROM tmpPurRegIt
ORDER BY item_id;

DROP TEMPORARY TABLE IF EXISTS tmpPurRegIt;
DROP TEMPORARY TABLE IF EXISTS tmpPurRet;
";

if ($branch_db->multi_query($query)) {
    do {
        if ($result = $branch_db->store_result()) {
            while ($row = $result->fetch_assoc()) {
                $invoices[] = $row;
                foreach (['qty', 'net_amt', 'ret_qty', 'ret_net_amt', 'net_qty', 'net_amt_final'] as $key) {
                    $totals[$key] += $row[$key];
                }
            }
            $result->free();
        }
    } while ($branch_db->more_results() && $branch_db->next_result());
}

// 🏢 Branch list
$branches = [];
if (strtolower($role_name) === 'admin') {
    $res = $con->query("SELECT branch_id FROM m_branch_sync_config");
    while ($row = $res->fetch_assoc()) {
        $branches[] = $row['branch_id'];
    }
} else {
    $branches[] = $session_branch;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Purchase Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container py-5">
        <h2 class="mb-4">ITEM-WISE PURCHASE REGISTER</h2>

        <form method="get" class="row g-3 mb-4">
            <div class="col-sm-6 col-md-3">
                <label>Branch</label>
                <select name="branch" class="form-select" <?= strtolower($role_name) !== 'admin' ? 'disabled' : '' ?>>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= $b ?>" <?= $b === $selected_branch ? 'selected' : '' ?>><?= $b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label>From Date</label>
                <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div class="col-md-3">
                <label>To Date</label>
                <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($to) ?>">
            </div>
            <div class="col-md-3 align-self-end">
                <button class="btn btn-primary" type="submit">Search</button>
            </div>
        </form>

        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Item ID</th>
                    <th>Item Name</th>
                    <th>Purchase Qty</th>
                    <th>Purchase Amt</th>
                    <th>Return Qty</th>
                    <th>Return Amt</th>
                    <th>Net Qty</th>
                    <th>Net Amt</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['item_id']) ?></td>
                        <td><?= htmlspecialchars($row['item_name'] ?? '-') ?></td>
                        <td><?= number_format($row['qty'], 2) ?></td>
                        <td><?= number_format($row['net_amt'], 2) ?></td>
                        <td><?= number_format($row['ret_qty'], 2) ?></td>
                        <td><?= number_format($row['ret_net_amt'], 2) ?></td>
                        <td><?= number_format($row['net_qty'], 2) ?></td>
                        <td><?= number_format($row['net_amt_final'], 2) ?></td>
                        <td>
                            <a href="pur_history_view.php?item_id=<?= urlencode($row['item_id']) ?>&branch_id=<?= urlencode($branch) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-sm btn-outline-primary" title="View History Details">
                                🔍 View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="mt-3">
            <strong>Totals:</strong><br>
            Qty: <?= $totals['qty'] ?> |
            Amt: ₹<?= number_format($totals['net_amt'], 2) ?> |
            Return Qty: <?= $totals['ret_qty'] ?> |
            Return Amt: ₹<?= number_format($totals['ret_net_amt'], 2) ?> |
            Net Qty: <?= $totals['net_qty'] ?> |
            Net Amt: ₹<?= number_format($totals['net_amt_final'], 2) ?>
        </div>
    </div>
</body>

</html>