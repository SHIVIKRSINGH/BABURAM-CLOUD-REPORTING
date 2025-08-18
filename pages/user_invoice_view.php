<?php
require_once "../includes/config.php";
include "../includes/header.php";

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Params from GET
$user_id   = $_GET['user_id'] ?? 'all';
$pay_mode  = $_GET['pay_mode_id'] ?? '';
$from_date = $_GET['from_date'] ?? date('Y-m-d');
$to_date   = $_GET['to_date'] ?? date('Y-m-d');

$role_name       = $_SESSION['role_name'] ?? '';
$session_branch  = $_SESSION['branch_id'] ?? '';
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $session_branch);

// 🔌 Get branch DB connection details
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

// ==================== Fetch All Invoices by Pay Mode ====================
$query = "
    SELECT 
        P.pay_mode_id,
        H.invoice_no,
        DATE(H.invoice_dt) AS invoice_date,
        H.bill_time,
        H.net_amt_after_disc,
        H.ent_by
    FROM t_invoice_hdr H
    LEFT JOIN t_invoice_pay_det P ON H.invoice_no = P.invoice_no
    WHERE (? = 'all' OR H.ent_by = ?)
      AND DATE(H.invoice_dt) BETWEEN ? AND ?
    ORDER BY P.pay_mode_id, H.invoice_no
";

$stmt = $branch_db->prepare($query);
$stmt->bind_param("ssss", $user_id, $user_id, $from_date, $to_date);
$stmt->execute();
$result = $stmt->get_result();

$invoices_by_mode = [];
while ($row = $result->fetch_assoc()) {
    $mode = $row['pay_mode_id'] ?: 'UNKNOWN';
    $invoices_by_mode[$mode][] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html>

<head>
    <title>User Invoice View</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>

<body class="bg-light">

    <div class="container my-5">
        <h3 class="mb-4">🧾 Invoice Details (<?= htmlspecialchars($user_id) ?>)</h3>

        <!-- Nav Tabs -->
        <ul class="nav nav-tabs" id="invoiceTabs" role="tablist">
            <?php
            $active_mode = $_GET['pay_mode_id'] ?? '';
            $first = true;
            foreach ($invoices_by_mode as $mode => $rows):
                $isActive = ($active_mode && $active_mode === $mode) || (!$active_mode && $first);
            ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $isActive ? 'active' : '' ?>" id="tab-<?= $mode ?>"
                        data-bs-toggle="tab" data-bs-target="#content-<?= $mode ?>" type="button" role="tab">
                        <?= htmlspecialchars($mode) ?> (<?= count($rows) ?>)
                    </button>
                </li>
            <?php $first = false;
            endforeach; ?>
        </ul>

        <!-- Tab Contents -->
        <div class="tab-content mt-3">
            <?php
            $active_mode = $_GET['pay_mode_id'] ?? '';
            $first = true;
            foreach ($invoices_by_mode as $mode => $rows):
                $isActive = ($active_mode && $active_mode === $mode) || (!$active_mode && $first);
            ?>
                <div class="tab-pane fade <?= $isActive ? 'show active' : '' ?>" id="content-<?= $mode ?>" role="tabpanel">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="mb-3">Payment Mode: <?= htmlspecialchars($mode) ?></h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover datatable">
                                    <thead class="table-dark text-center">
                                        <tr>
                                            <th>Invoice No</th>
                                            <th>Invoice Date</th>
                                            <th>Bill Time</th>
                                            <th>Amount (₹)</th>
                                            <th>Entered By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $total_amount = 0;
                                        foreach ($rows as $row):
                                            $total_amount += $row['net_amt_after_disc'];
                                        ?>
                                            <tr class="text-center">
                                                <td><?= htmlspecialchars($row['invoice_no']) ?></td>
                                                <td><?= htmlspecialchars($row['invoice_date']) ?></td>
                                                <td><?= htmlspecialchars($row['bill_time']) ?></td>
                                                <td><?= number_format($row['net_amt_after_disc'], 2) ?></td>
                                                <td><?= htmlspecialchars($row['ent_by']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-secondary fw-bold text-center">
                                        <tr>
                                            <td colspan="3">TOTAL</td>
                                            <td><?= number_format($total_amount, 2) ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php $first = false;
            endforeach; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            $('.datatable').DataTable({
                pageLength: 10,
                order: [
                    [0, 'desc']
                ]
            });
        });
    </script>

</body>

</html>