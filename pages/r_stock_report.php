<?php
require_once "../includes/config.php";
include "../includes/header.php";

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Default date range
$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to'] ?? date('Y-m-d');

$role_name       = $_SESSION['role_name'];
$session_branch  = $_SESSION['branch_id'] ?? '';
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $session_branch);

$invoices = [];
$totals = [
    'op_bal' => 0,
    'pur_qty' => 0,
    'pur_ret_qty' => 0,
    'tran_in_qty' => 0,
    'tran_out_qty' => 0,
    'sale_qty' => 0,
    'sale_ret_qty' => 0,
    'prod_qty' => 0,
    'rm_consum_qty' => 0,
    'cl_bal' => 0
];

// 🔌 Branch connection
$stmt = $con->prepare("SELECT * FROM m_branch_sync_config WHERE branch_id = ?");
$stmt->bind_param("s", $selected_branch);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) die("❌ Branch config not found for '$selected_branch'");
$config = $res->fetch_assoc();

$branch_db = new mysqli(
    $config['db_host'],
    $config['db_user'],
    $config['db_password'],
    $config['db_name']
);
if ($branch_db->connect_error) die("❌ Branch DB connection failed: " . $branch_db->connect_error);
$branch_db->set_charset('utf8mb4');
$branch_db->query("SET time_zone = '+05:30'");

$from = $branch_db->real_escape_string($from);
$to   = $branch_db->real_escape_string($to);

// ========================
// STOCK REPORT QUERY
// ========================
$query = "
CREATE TEMPORARY TABLE tmpStocks (
    item_id VARCHAR(30),
    item_desc VARCHAR(255),
    op_bal DECIMAL(18,4) DEFAULT 0,
    pur_qty DECIMAL(18,4) DEFAULT 0,
    pur_ret_qty DECIMAL(18,4) DEFAULT 0,
    tran_in_qty DECIMAL(18,4) DEFAULT 0,
    tran_out_qty DECIMAL(18,4) DEFAULT 0,
    sale_qty DECIMAL(18,4) DEFAULT 0,
    sale_ret_qty DECIMAL(18,4) DEFAULT 0,
    prod_qty DECIMAL(18,4) DEFAULT 0,
    rm_consum_qty DECIMAL(18,4) DEFAULT 0,
    cl_bal DECIMAL(18,4) DEFAULT 0,
    cost_price DECIMAL(18,4) DEFAULT 0,
    sale_price DECIMAL(18,4) DEFAULT 0
);

INSERT INTO tmpStocks (item_id, item_desc, op_bal, cost_price, sale_price)
SELECT 
    item_id, item_desc, IFNULL(op_bal_unit,0), IFNULL(cost_price,0), IFNULL(sale_price,0)
FROM m_item_hdr;


-- ✅ Purchase
CREATE TEMPORARY TABLE tmpPur AS
SELECT B.item_id, SUM(IFNULL(B.qty,0)) AS pur_qty
FROM t_receipt_hdr A
JOIN t_receipt_det B ON A.receipt_id = B.receipt_id
WHERE A.receipt_date BETWEEN STR_TO_DATE('$from','%Y-%m-%d') AND STR_TO_DATE('$to','%Y-%m-%d')
GROUP BY B.item_id;

UPDATE tmpStocks S
JOIN tmpPur P ON S.item_id = P.item_id
SET S.pur_qty = P.pur_qty;

-- ✅ Purchase Return
CREATE TEMPORARY TABLE tmpPurRet AS
SELECT B.item_id, SUM(IFNULL(B.qty,0)) AS pur_ret_qty
FROM t_pur_ret_hdr A
JOIN t_pur_ret_det B ON A.ret_no = B.ret_no
WHERE A.ret_dt BETWEEN STR_TO_DATE('$from','%Y-%m-%d') AND STR_TO_DATE('$to','%Y-%m-%d')
GROUP BY B.item_id;

UPDATE tmpStocks S
JOIN tmpPurRet P ON S.item_id = P.item_id
SET S.pur_ret_qty = P.pur_ret_qty;

-- ✅ Sales
CREATE TEMPORARY TABLE tmpSale AS
SELECT B.item_id, SUM(IFNULL(B.qty,0)) AS sale_qty
FROM t_invoice_hdr A
JOIN t_invoice_det B ON A.invoice_no = B.invoice_no
WHERE A.invoice_dt BETWEEN STR_TO_DATE('$from','%Y-%m-%d') AND STR_TO_DATE('$to','%Y-%m-%d')
GROUP BY B.item_id;

UPDATE tmpStocks S
JOIN tmpSale P ON S.item_id = P.item_id
SET S.sale_qty = P.sale_qty;

-- ✅ Sale Return
CREATE TEMPORARY TABLE tmpSaleRet AS
SELECT B.item_id, SUM(IFNULL(B.qty,0)) AS sale_ret_qty
FROM t_sr_hdr A
JOIN t_sr_det B ON A.sr_no = B.sr_no
WHERE A.sr_dt BETWEEN STR_TO_DATE('$from','%Y-%m-%d') AND STR_TO_DATE('$to','%Y-%m-%d')
GROUP BY B.item_id;

UPDATE tmpStocks S
JOIN tmpSaleRet P ON S.item_id = P.item_id
SET S.sale_ret_qty = P.sale_ret_qty;

-- ✅ Transfer In
CREATE TEMPORARY TABLE tmpTransIn AS
SELECT B.item_id, SUM(IFNULL(B.qty_in,0)) AS tran_in_qty
FROM t_trans_in_hdr A
JOIN t_trans_in_det B ON A.trans_in_no = B.trans_in_no
WHERE A.trans_in_dt BETWEEN STR_TO_DATE('$from','%Y-%m-%d') AND STR_TO_DATE('$to','%Y-%m-%d')
GROUP BY B.item_id;

UPDATE tmpStocks S
JOIN tmpTransIn P ON S.item_id = P.item_id
SET S.tran_in_qty = P.tran_in_qty;

-- ✅ Transfer Out
CREATE TEMPORARY TABLE tmpTransOut AS
SELECT B.item_id, SUM(IFNULL(B.qty_out,0)) AS tran_out_qty
FROM t_trans_out_hdr A
JOIN t_trans_out_det B ON A.trans_out_no = B.trans_out_no
WHERE A.trans_out_dt BETWEEN STR_TO_DATE('$from','%Y-%m-%d') AND STR_TO_DATE('$to','%Y-%m-%d')
GROUP BY B.item_id;

UPDATE tmpStocks S
JOIN tmpTransOut P ON S.item_id = P.item_id
SET S.tran_out_qty = P.tran_out_qty;

-- ✅ Production
CREATE TEMPORARY TABLE tmpProd AS
SELECT A.item_id, SUM(IFNULL(A.qty,0)) AS prod_qty
FROM t_prod_entry_det A
WHERE A.prod_entry_date BETWEEN STR_TO_DATE('$from','%Y-%m-%d') AND STR_TO_DATE('$to','%Y-%m-%d')
GROUP BY A.item_id;

UPDATE tmpStocks S
JOIN tmpProd P ON S.item_id = P.item_id
SET S.prod_qty = P.prod_qty;

-- ✅ Raw Material Consumption
CREATE TEMPORARY TABLE tmpRM AS
SELECT B.item_id, SUM(IFNULL(A.qty,0) * IFNULL(B.qty,0)) AS rm_consum_qty
FROM t_prod_entry_det A
JOIN m_gift_item_det B ON A.item_id = B.gitem_id
WHERE A.prod_entry_date BETWEEN STR_TO_DATE('$from','%Y-%m-%d') AND STR_TO_DATE('$to','%Y-%m-%d')
GROUP BY B.item_id;

UPDATE tmpStocks S
JOIN tmpRM P ON S.item_id = P.item_id
SET S.rm_consum_qty = P.rm_consum_qty;

-- ✅ Final stock balance
UPDATE tmpStocks SET cl_bal = IFNULL(op_bal,0)
    + IFNULL(pur_qty,0) - IFNULL(pur_ret_qty,0)
    - IFNULL(sale_qty,0) + IFNULL(sale_ret_qty,0)
    + IFNULL(tran_in_qty,0) - IFNULL(tran_out_qty,0)
    + IFNULL(prod_qty,0) - IFNULL(rm_consum_qty,0);

-- ✅ Final report
SELECT 
    item_id, item_desc, op_bal, pur_qty, pur_ret_qty, tran_in_qty, tran_out_qty,
    sale_qty, sale_ret_qty, prod_qty, rm_consum_qty, cl_bal,
    cost_price, sale_price,
    ROUND(cl_bal * cost_price, 2) AS cost_value,
    ROUND(cl_bal * sale_price, 2) AS sale_value
FROM tmpStocks
WHERE cl_bal <> 0
ORDER BY item_id;

DROP TEMPORARY TABLE IF EXISTS tmpStocks, tmpPur, tmpPurRet, tmpSale, tmpSaleRet, tmpTransIn, tmpTransOut, tmpProd, tmpRM;
";

if ($branch_db->multi_query($query)) {
    do {
        if ($result = $branch_db->store_result()) {
            while ($row = $result->fetch_assoc()) {
                $invoices[] = $row;
                foreach (['op_bal', 'pur_qty', 'pur_ret_qty', 'tran_in_qty', 'tran_out_qty', 'sale_qty', 'sale_ret_qty', 'prod_qty', 'rm_consum_qty', 'cl_bal'] as $field) {
                    $totals[$field] += $row[$field];
                }
            }
            $result->free();
        }
    } while ($branch_db->more_results() && $branch_db->next_result());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Stock Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container py-5">
        <h2 class="mb-4">Stock Report</h2>

        <form method="get" class="row g-3 mb-4">
            <div class="col-md-3">
                <label>From Date</label>
                <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div class="col-md-3">
                <label>To Date</label>
                <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($to) ?>">
            </div>
            <div class="col-md-3 align-self-end">
                <button type="submit" class="btn btn-primary">Search</button>
            </div>
        </form>

        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Item ID</th>
                    <th>Item Name</th>
                    <th>Opening</th>
                    <th>Purchase</th>
                    <th>Purchase Return</th>
                    <th>Sale</th>
                    <th>Sale Return</th>
                    <th>Transfer In</th>
                    <th>Transfer Out</th>
                    <th>Production</th>
                    <th>Consumption</th>
                    <th>Closing</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['item_id']) ?></td>
                        <td><?= htmlspecialchars($row['item_desc']) ?></td>
                        <td><?= number_format($row['op_bal'], 2) ?></td>
                        <td><?= number_format($row['pur_qty'], 2) ?></td>
                        <td><?= number_format($row['pur_ret_qty'], 2) ?></td>
                        <td><?= number_format($row['sale_qty'], 2) ?></td>
                        <td><?= number_format($row['sale_ret_qty'], 2) ?></td>
                        <td><?= number_format($row['tran_in_qty'], 2) ?></td>
                        <td><?= number_format($row['tran_out_qty'], 2) ?></td>
                        <td><?= number_format($row['prod_qty'], 2) ?></td>
                        <td><?= number_format($row['rm_consum_qty'], 2) ?></td>
                        <td><?= number_format($row['cl_bal'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h5 class="mt-3">Totals:</h5>
        <p>Closing Balance Total: <?= number_format($totals['cl_bal'], 2) ?></p>
    </div>
</body>

</html>