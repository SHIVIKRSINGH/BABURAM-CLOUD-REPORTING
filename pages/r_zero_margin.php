<?php
require_once "../includes/config.php"; // MySQLi config
include "../includes/header.php";

// Error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Default date range
$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$branch = $_GET['branch'] ?? 'IND'; // ✅ Default Branch
$role_name       = $_SESSION['role_name'];
$session_branch  = $_SESSION['branch_id'] ?? '';
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $session_branch);

// Fetch invoices
$invoices = [];
$total = 0;
$totals = [
    'item_qty' => 0,
    'item_amt' => 0,
    'item_ret_qty' => 0,
    'item_ret_amt' => 0,
    'net_qty' => 0,
    'net_amt' => 0,
    'pur_amt' => 0,
    'margin_sum' => 0,
    'margin_count' => 0
];

// 🔌 Connect to branch DB dynamically
$branch_db = null;
$stmt = $con->prepare("SELECT * FROM m_branch_sync_config WHERE branch_id = ?");
$stmt->bind_param("s", $selected_branch);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    die("❌ Branch config not found for '$selected_branch'");
}
$config = $res->fetch_assoc();

$branch_db = new mysqli(
    $config['db_host'],
    $config['db_user'],
    $config['db_password'],
    $config['db_name']
);
if ($branch_db->connect_error) {
    die("❌ Branch DB connection failed: " . $branch_db->connect_error);
}
$branch_db->set_charset('utf8mb4');
$branch_db->query("SET time_zone = '+05:30'");

$from = $branch_db->real_escape_string($from);
$to   = $branch_db->real_escape_string($to);

$query = "
   CREATE TEMPORARY TABLE tmpSaleIt AS
SELECT 
    A.item_id, A.bar_code,
    SUM(IFNULL(A.qty, 0)) AS item_qty,
    SUM(
        (IFNULL(A.net_amt, 0) - ((IFNULL(A.net_amt, 0) * IFNULL(B.disc_per, 0)) / 100))
    ) AS item_amt,
    SUM(IFNULL(A.qty, 0) * IFNULL(A.pur_rate, 0)) AS pur_amt
FROM t_invoice_det A
JOIN t_invoice_hdr B ON A.invoice_no = B.invoice_no
WHERE B.invoice_dt BETWEEN STR_TO_DATE('$from', '%Y-%m-%d') 
                      AND STR_TO_DATE('$to', '%Y-%m-%d')
GROUP BY A.item_id, A.bar_code;

CREATE TEMPORARY TABLE tmpSaleRetIt AS
SELECT 
    A.item_id, A.bar_code,
    SUM(IFNULL(A.qty, 0)) AS item_ret_qty,
    SUM(
        (IFNULL(A.net_amt, 0) - ((IFNULL(A.net_amt, 0) * IFNULL(B.disc_per, 0)) / 100))
    ) AS item_ret_amt,
    SUM(IFNULL(A.qty, 0) * IFNULL(A.pur_rate, 0)) AS pur_ret_amt
FROM t_sr_det A
JOIN t_sr_hdr B ON A.sr_no = B.sr_no
WHERE B.sr_dt BETWEEN STR_TO_DATE('$from', '%Y-%m-%d') 
                 AND STR_TO_DATE('$to', '%Y-%m-%d')
GROUP BY A.item_id, A.bar_code;

SELECT 
    I.item_id,
    M.item_desc AS item_name,
    IFNULL(S.item_qty, 0) AS item_qty,
    IFNULL(S.item_amt, 0) AS item_amt,
    IFNULL(R.item_ret_qty, 0) AS item_ret_qty,
    IFNULL(R.item_ret_amt, 0) AS item_ret_amt,
    (IFNULL(S.item_qty, 0) - IFNULL(R.item_ret_qty, 0)) AS net_qty,
    (IFNULL(S.item_amt, 0) - IFNULL(R.item_ret_amt, 0)) AS net_amt,
    IFNULL(M.sale_tax_paid, '') AS sale_tax_paid,
    IFNULL(T.tax_per, 0) AS vat_per,
    IFNULL(M.cost_price, 0) AS cost_price,
    (IFNULL(S.pur_amt, 0) - IFNULL(R.pur_ret_amt, 0)) AS pur_amt,
    CASE
        WHEN (IFNULL(S.pur_amt, 0) - IFNULL(R.pur_ret_amt, 0)) > 0 THEN
            ROUND(
                ((IFNULL(S.item_amt, 0) - IFNULL(R.item_ret_amt, 0) 
                  - (IFNULL(S.pur_amt, 0) - IFNULL(R.pur_ret_amt, 0))) * 100) 
                / (IFNULL(S.pur_amt, 0) - IFNULL(R.pur_ret_amt, 0)), 
            2)
        ELSE NULL
    END AS margin_percent
FROM m_item_hdr I
LEFT JOIN m_item_hdr M ON I.item_id = M.item_id
LEFT JOIN tmpSaleIt S ON I.item_id = S.item_id
LEFT JOIN tmpSaleRetIt R ON I.item_id = R.item_id
LEFT JOIN m_tax_type T ON M.sale_tax_paid = T.tax_type_id
WHERE (IFNULL(S.item_qty, 0) > 0 OR IFNULL(R.item_ret_qty, 0) > 0)
  AND (
        (IFNULL(S.pur_amt, 0) - IFNULL(R.pur_ret_amt, 0)) > 0
        AND ROUND(
            ((IFNULL(S.item_amt, 0) - IFNULL(R.item_ret_amt, 0) 
              - (IFNULL(S.pur_amt, 0) - IFNULL(R.pur_ret_amt, 0))) * 100) 
            / (IFNULL(S.pur_amt, 0) - IFNULL(R.pur_ret_amt, 0)), 
        2) = 0
      )
ORDER BY I.item_id;

DROP TEMPORARY TABLE IF EXISTS tmpSaleIt;
DROP TEMPORARY TABLE IF EXISTS tmpSaleRetIt;

";

if ($branch_db->multi_query($query)) {
    do {
        if ($result = $branch_db->store_result()) {
            while ($row = $result->fetch_assoc()) {
                $invoices[] = $row;
                foreach (['item_qty', 'item_amt', 'item_ret_qty', 'item_ret_amt', 'net_qty', 'net_amt', 'pur_amt'] as $field) {
                    $totals[$field] += $row[$field];
                }
                if (!is_null($row['margin_percent'])) {
                    $totals['margin_sum'] += $row['margin_percent'];
                    $totals['margin_count']++;
                }
            }
            $result->free();
        }
    } while ($branch_db->more_results() && $branch_db->next_result());
}

$average_margin = $totals['margin_count'] > 0 ? round($totals['margin_sum'] / $totals['margin_count'], 2) : 0;

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
    <title>Invoice Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container py-5">
        <h2 class="mb-4">ITEM WISE ZERO MARGIN REPORT</h2>

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
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($to) ?>">
            </div>
            <div class="col-md-3 align-self-end">
                <button type="submit" class="btn btn-primary">Search</button>
            </div>
        </form>

        <table id="invoiceTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Item ID</th>
                    <th>Name</th>
                    <th>Qty</th>
                    <th>Amount</th>
                    <th>Return Qty</th>
                    <th>Return Amt</th>
                    <th>Net Qty</th>
                    <th>Net Amt</th>
                    <th>Cost Price</th>
                    <th>Purchase Amt</th>
                    <th>Margin %</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['item_id']) ?></td>
                        <td><?= htmlspecialchars($row['item_name'] ?? '-') ?></td>
                        <td><?= number_format($row['item_qty']) ?></td>
                        <td><?= number_format($row['item_amt']) ?></td>
                        <td><?= number_format($row['item_ret_qty'], 2) ?></td>
                        <td><?= number_format($row['item_ret_amt'], 2) ?></td>
                        <td><?= number_format($row['net_qty'], 2) ?></td>
                        <td><?= number_format($row['net_amt'], 2) ?></td>
                        <td><?= number_format($row['cost_price'], 2) ?></td>
                        <td><?= number_format($row['pur_amt'], 2) ?></td>
                        <td><?= is_null($row['margin_percent']) ? '-' : number_format($row['margin_percent'], 2) ?>%</td>
                        <td>
                            <a href="sale_history_view.php?item_id=<?= urlencode($row['item_id']) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-sm btn-outline-primary" title="View History Details">
                                🔍 View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="mt-3">
            <h5>Totals:</h5>
            <p>Qty: <?= $totals['item_qty'] ?> | Amt: ₹<?= number_format($totals['item_amt'], 2) ?> | Return Qty: <?= $totals['item_ret_qty'] ?> | Return Amt: ₹<?= number_format($totals['item_ret_amt'], 2) ?> | Net Qty: <?= $totals['net_qty'] ?> | Purchase Amt: ₹<?= number_format($totals['pur_amt'], 2) ?> | Avg Margin: <?= $average_margin ?>%</p>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#invoiceTable').DataTable();
        });
    </script>
</body>

</html>