<?php
require_once "../includes/config.php"; // MySQLi config
include "../includes/header.php"; // session + role
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Default date range & branch
$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');
$branch = $_GET['branch'] ?? 'IND';

$role_name = $_SESSION['role_name'] ?? '';
$session_branch = $_SESSION['branch_id'] ?? '';
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $session_branch);

// Totals
$groups = [];
$totals = [
    'item_qty' => 0,
    'item_amt' => 0,
    'item_ret_qty' => 0,
    'item_ret_amt' => 0,
    'net_qty' => 0,
    'net_amt' => 0,
    'pur_amt' => 0
];

// connect to branch DB dynamically
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

// Build multi-statement query to prepare temp tables and final grouped totals by group
$query = "
CREATE TEMPORARY TABLE tmpSaleIt AS
SELECT A.item_id,
       SUM(IFNULL(A.qty,0)) AS item_qty,
       SUM( (IFNULL(A.net_amt,0) - ((IFNULL(A.net_amt,0) * IFNULL(B.disc_per,0)) / 100)) ) AS item_amt,
       SUM(IFNULL(A.qty,0) * IFNULL(A.pur_rate,0)) AS pur_amt
FROM t_invoice_det A
JOIN t_invoice_hdr B ON A.invoice_no = B.invoice_no
WHERE date(B.invoice_dt) BETWEEN STR_TO_DATE('$from', '%Y-%m-%d') AND STR_TO_DATE('$to', '%Y-%m-%d')
GROUP BY A.item_id;

CREATE TEMPORARY TABLE tmpSaleRetIt AS
SELECT A.item_id,
       SUM(IFNULL(A.qty,0)) AS item_ret_qty,
       SUM( (IFNULL(A.net_amt,0) - ((IFNULL(A.net_amt,0) * IFNULL(B.disc_per,0)) / 100)) ) AS item_ret_amt,
       SUM(IFNULL(A.qty,0) * IFNULL(A.pur_rate,0)) AS pur_ret_amt
FROM t_sr_det A
JOIN t_sr_hdr B ON A.sr_no = B.sr_no
WHERE B.sr_dt BETWEEN STR_TO_DATE('$from', '%Y-%m-%d') AND STR_TO_DATE('$to', '%Y-%m-%d')
GROUP BY A.item_id;

CREATE TEMPORARY TABLE tmpPurIt AS
SELECT A.item_id,
       SUM(IFNULL(A.qty,0)) AS pur_qty,
       SUM(IFNULL(A.net_amt,0)) AS pur_amt
FROM t_receipt_det A
JOIN t_receipt_hdr B ON A.receipt_id = B.receipt_id
WHERE Date(B.receipt_date) BETWEEN STR_TO_DATE('$from', '%Y-%m-%d') AND STR_TO_DATE('$to', '%Y-%m-%d')
GROUP BY A.item_id;

/* Now aggregate item-level results up to group level */
CREATE TEMPORARY TABLE tmpGroupAgg AS
SELECT
    IFNULL(G.group_id, 'UNGROUPED') AS group_id,
    IFNULL(G.group_desc, 'UN-GROUPED') AS group_desc,
    SUM(IFNULL(S.item_qty,0)) AS total_item_qty,
    SUM(IFNULL(S.item_amt,0)) AS total_item_amt,
    SUM(IFNULL(R.item_ret_qty,0)) AS total_item_ret_qty,
    SUM(IFNULL(R.item_ret_amt,0)) AS total_item_ret_amt,
    SUM(IFNULL(P.pur_amt,0)) AS total_pur_amt
FROM m_item_hdr I
LEFT JOIN m_group G ON I.group_id = G.group_id
LEFT JOIN tmpSaleIt S ON I.item_id = S.item_id
LEFT JOIN tmpSaleRetIt R ON I.item_id = R.item_id
LEFT JOIN tmpPurIt P ON I.item_id = P.item_id
/* only groups where something happened: sale or purchase */
GROUP BY IFNULL(G.group_id, 'UNGROUPED'), IFNULL(G.group_desc, 'UN-GROUPED');

SELECT * FROM tmpGroupAgg ORDER BY group_desc;

/* cleanup */
DROP TEMPORARY TABLE IF EXISTS tmpSaleIt;
DROP TEMPORARY TABLE IF EXISTS tmpSaleRetIt;
DROP TEMPORARY TABLE IF EXISTS tmpPurIt;
DROP TEMPORARY TABLE IF EXISTS tmpGroupAgg;
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
    die("Query failed: " . $branch_db->error);
}

// populate groups and totals
foreach ($rows as $r) {
    // compute net qty/amt
    $net_qty = floatval($r['total_item_qty']) - floatval($r['total_item_ret_qty']);
    $net_amt = floatval($r['total_item_amt']) - floatval($r['total_item_ret_amt']);

    $groups[] = [
        'group_id' => $r['group_id'],
        'group_desc' => $r['group_desc'],
        'total_sale_qty' => $r['total_item_qty'],
        'total_sale_amt' => $r['total_item_amt'],
        'total_ret_qty' => $r['total_item_ret_qty'],
        'total_ret_amt' => $r['total_item_ret_amt'],
        'net_qty' => $net_qty,
        'net_amt' => $net_amt,
        'total_pur_amt' => $r['total_pur_amt']
    ];

    // accumulate footer totals
    $totals['item_qty'] += floatval($r['total_item_qty']);
    $totals['item_amt'] += floatval($r['total_item_amt']);
    $totals['item_ret_qty'] += floatval($r['total_item_ret_qty']);
    $totals['item_ret_amt'] += floatval($r['total_item_ret_amt']);
    $totals['net_qty'] += $net_qty;
    $totals['net_amt'] += $net_amt;
    $totals['pur_amt'] += floatval($r['total_pur_amt']);
}

// Branch list for filter
$branches = [];
if (strtolower($role_name) === 'admin') {
    $res = $con->query("SELECT branch_id FROM m_branch_sync_config");
    while ($row = $res->fetch_assoc()) { $branches[] = $row['branch_id']; }
} else {
    $branches[] = $session_branch;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Group Wise Sale & Purchase Register</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h2 class="mb-4">GROUP WISE SALE & PURCHASE REGISTER</h2>

    <form method="get" class="row g-3 mb-4">
        <div class="col-sm-6 col-md-3">
            <label>Branch</label>
            <select name="branch" class="form-select" <?= strtolower($role_name) !== 'admin' ? 'disabled' : '' ?>>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= htmlspecialchars($b) ?>" <?= $b === $selected_branch ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
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

    <table id="groupTable" class="table table-striped table-bordered">
        <thead>
            <tr>
                <th>Group ID</th>
                <th>Group Desc</th>
                <th>Total Sale (Amt)</th>
                <th>Total Purchase (Amt)</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groups as $g): ?>
            <tr>
                <td><?= htmlspecialchars($g['group_id']) ?></td>
                <td><?= htmlspecialchars($g['group_desc']) ?></td>
                <td>
                    ₹<?= number_format($g['total_sale_amt'], 2) ?>
                    <button class="btn btn-sm btn-outline-info ms-2 view-history" data-type="sale" data-group="<?= urlencode($g['group_id']) ?>" title="View Sale History">i</button>
                </td>
                <td>
                    ₹<?= number_format($g['total_pur_amt'], 2) ?>
                    <button class="btn btn-sm btn-outline-info ms-2 view-history" data-type="purchase" data-group="<?= urlencode($g['group_id']) ?>" title="View Purchase History">i</button>
                </td>
                <td>
                    <!-- You may add more actions here -->
                    <a href="#" class="btn btn-sm btn-outline-secondary" onclick="return false;">Report</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="mt-3">
        <h5>Totals:</h5>
        <p>
            Qty: <?= number_format($totals['item_qty'], 3) ?> |
            Amt: ₹<?= number_format($totals['item_amt'], 2) ?> |
            Return Qty: <?= number_format($totals['item_ret_qty'], 3) ?> |
            Return Amt: ₹<?= number_format($totals['item_ret_amt'], 2) ?> |
            Net Qty: <?= number_format($totals['net_qty'], 3) ?> |
            Purchase Amt: ₹<?= number_format($totals['pur_amt'], 2) ?>
        </p>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><span id="historyTitle">History</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="historyBody">
        <div class="text-center">Loading...</div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function(){
    $('#groupTable').DataTable();

    // handle info buttons - load history page into modal via AJAX
    $('.view-history').on('click', function(){
        var type = $(this).data('type'); // 'sale' or 'purchase'
        var group = $(this).data('group');
        var from = encodeURIComponent('<?= $from ?>');
        var to   = encodeURIComponent('<?= $to ?>');
        var branch = encodeURIComponent('<?= $selected_branch ?>');

        var url = (type === 'sale') ? 'group_sale_history.php' : 'group_purchase_history.php';
        url += '?group_id=' + group + '&from=' + from + '&to=' + to + '&branch=' + branch;

        $('#historyTitle').text((type === 'sale' ? 'Sale' : 'Purchase') + ' History - Group: ' + decodeURIComponent(group));
        $('#historyBody').html('<div class="text-center">Loading...</div>');
        var modalEl = new bootstrap.Modal(document.getElementById('historyModal'));
        modalEl.show();

        $.get(url, function(data){
            $('#historyBody').html(data);
        }).fail(function(xhr, status, err){
            $('#historyBody').html('<div class="alert alert-danger">Failed to load history: ' + err + '</div>');
        });
    });
});
</script>
</body>
</html>
