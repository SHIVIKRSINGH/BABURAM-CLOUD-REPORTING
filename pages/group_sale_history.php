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

// Build query: per item within group, sum sale qty, amount (and returns as negative adjustment)
$query = "
CREATE TEMPORARY TABLE tmpSaleIt AS
SELECT A.item_id,
       SUM(IFNULL(A.qty,0)) AS sale_qty,
       SUM( (IFNULL(A.net_amt,0) - ((IFNULL(A.net_amt,0) * IFNULL(B.disc_per,0)) / 100)) ) AS sale_amt
FROM t_invoice_det A
JOIN t_invoice_hdr B ON A.invoice_no = B.invoice_no
WHERE B.invoice_dt BETWEEN STR_TO_DATE('$from', '%Y-%m-%d') AND STR_TO_DATE('$to', '%Y-%m-%d')
GROUP BY A.item_id;

CREATE TEMPORARY TABLE tmpSaleRetIt AS
SELECT A.item_id,
       SUM(IFNULL(A.qty,0)) AS ret_qty,
       SUM( (IFNULL(A.net_amt,0) - ((IFNULL(A.net_amt,0) * IFNULL(B.disc_per,0)) / 100)) ) AS ret_amt
FROM t_sr_det A
JOIN t_sr_hdr B ON A.sr_no = B.sr_no
WHERE B.sr_dt BETWEEN STR_TO_DATE('$from', '%Y-%m-%d') AND STR_TO_DATE('$to', '%Y-%m-%d')
GROUP BY A.item_id;

/* select items in this group and aggregate */
SELECT I.item_id, I.item_desc,
       IFNULL(S.sale_qty,0) AS sale_qty,
       IFNULL(S.sale_amt,0) AS sale_amt,
       IFNULL(R.ret_qty,0) AS ret_qty,
       IFNULL(R.ret_amt,0) AS ret_amt,
       (IFNULL(S.sale_qty,0) - IFNULL(R.ret_qty,0)) AS net_qty,
       (IFNULL(S.sale_amt,0) - IFNULL(R.ret_amt,0)) AS net_amt
FROM m_item_hdr I
LEFT JOIN m_group G ON I.group_id = G.group_id
LEFT JOIN tmpSaleIt S ON I.item_id = S.item_id
LEFT JOIN tmpSaleRetIt R ON I.item_id = R.item_id
WHERE IFNULL(G.group_id, '') = '$group_id_esc'
  AND (IFNULL(S.sale_qty,0) > 0 OR IFNULL(R.ret_qty,0) > 0)
ORDER BY I.item_id;

/* cleanup */
DROP TEMPORARY TABLE IF EXISTS tmpSaleIt;
DROP TEMPORARY TABLE IF EXISTS tmpSaleRetIt;
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

// Output table (this can be returned to modal or as a full page)
?>
<div class="table-responsive">
<table class="table table-bordered">
    <thead>
        <tr>
            <th>Item ID</th>
            <th>Item Name</th>
            <th>Sale Qty</th>
            <th>Sale Amt</th>
            <th>Return Qty</th>
            <th>Return Amt</th>
            <th>Net Qty</th>
            <th>Net Amt</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $tot_sale_qty = $tot_sale_amt = $tot_ret_qty = $tot_ret_amt = $tot_net_qty = $tot_net_amt = 0;
        foreach ($rows as $r):
            $tot_sale_qty += floatval($r['sale_qty']);
            $tot_sale_amt += floatval($r['sale_amt']);
            $tot_ret_qty += floatval($r['ret_qty']);
            $tot_ret_amt += floatval($r['ret_amt']);
            $tot_net_qty += floatval($r['net_qty']);
            $tot_net_amt += floatval($r['net_amt']);
        ?>
        <tr>
            <td><?= htmlspecialchars($r['item_id']) ?></td>
            <td><?= htmlspecialchars($r['item_desc']) ?></td>
            <td><?= number_format($r['sale_qty'],3) ?></td>
            <td>₹<?= number_format($r['sale_amt'],2) ?></td>
            <td><?= number_format($r['ret_qty'],3) ?></td>
            <td>₹<?= number_format($r['ret_amt'],2) ?></td>
            <td><?= number_format($r['net_qty'],3) ?></td>
            <td>₹<?= number_format($r['net_amt'],2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="table-secondary">
            <th colspan="2">Totals</th>
            <th><?= number_format($tot_sale_qty,3) ?></th>
            <th>₹<?= number_format($tot_sale_amt,2) ?></th>
            <th><?= number_format($tot_ret_qty,3) ?></th>
            <th>₹<?= number_format($tot_ret_amt,2) ?></th>
            <th><?= number_format($tot_net_qty,3) ?></th>
            <th>₹<?= number_format($tot_net_amt,2) ?></th>
        </tr>
    </tfoot>
</table>
</div>
