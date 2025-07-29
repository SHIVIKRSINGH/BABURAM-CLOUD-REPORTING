<?php
require_once "../includes/config.php"; // MySQLi config
include "../includes/header.php";

// Error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Default date range
$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$branch = $_GET['branch'] ?? 'SHASHI-ND'; // ✅ Default Branch
$role_name       = $_SESSION['role_name'];
$session_branch  = $_SESSION['branch_id'] ?? '';
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $session_branch);

// Fetch items
$items = [];
$total = 0;

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


if ($stmt = $branch_db->prepare("SELECT ent_by,date(invoice_dt) as invoice_dt, bill_time,item_id, bar_code, qty, mrp,sale_price,disc_per FROM t_invoice_temp_det WHERE invoice_dt BETWEEN ? AND ? ORDER BY invoice_no desc")) {
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
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

// // Fetch total amount
// if ($stmt = $branch_db->prepare("SELECT SUM(net_amt_after_disc) as total FROM t_invoice_hdr WHERE invoice_dt BETWEEN ? AND ?")) {
//     $stmt->bind_param("ss", $from, $to);
//     $stmt->execute();
//     $result = $stmt->get_result();
//     $totalRow = $result->fetch_assoc();
//     $total = $totalRow['total'] ?? 0;
//     $stmt->close();
// }
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
        <h2 class="mb-4">Bill Skiping Report</h2>

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
                    <th>Enterrd By</th>
                    <th>Invoice Date</th>
                    <th>Invoice Time</th>
                    <th>Item Id</th>
                    <th>Barcode</th>
                    <th>Qty</th>
                    <th>MRP</th>
                    <th>Sale Price</th>
                    <th>Disc. Per(%)</th>

                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['ent_by']) ?></td>
                        <td><?= htmlspecialchars($row['invoice_dt'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['bill_time']) ?></td>
                        <td><?= htmlspecialchars($row['item_id']) ?></td>
                        <td><?= htmlspecialchars($row['bar_code']) ?></td>
                        <td><?= number_format($row['qty'], 2) ?></td>
                        <td><?= number_format($row['mrp'], 2) ?></td>
                        <td><?= number_format($row['sale_price'], 2) ?></td>
                        <td><?= number_format($row['disc_per'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- <div class="mt-3">
            <h5>Total Sale: ₹ <?= number_format($total, 2) ?></h5>
        </div> -->
    </div>

    <!-- Scripts -->
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