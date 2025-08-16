<?php
require_once "../includes/config.php";
include "../includes/header.php";

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Get invoice_no from query string
$invoice_no = $_GET['invoice_no'] ?? '';

if (!$invoice_no) {
    echo "<div class='alert alert-danger'>❌ Missing Invoice No.</div>";
    exit;
}

$role_name       = $_SESSION['role_name'] ?? '';
$session_branch  = $_SESSION['branch_id'] ?? '';
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $session_branch);

// Get branch DB connection details
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

// ==================== Fetch Invoice Header (for total) ====================
$invoice_hdr = [];
$stmt = $branch_db->prepare("SELECT invoice_no, net_amt_after_disc FROM t_invoice_hdr WHERE invoice_no = ?");
$stmt->bind_param("s", $invoice_no);
$stmt->execute();
$result = $stmt->get_result();
$invoice_hdr = $result->fetch_assoc();
$stmt->close();

// ==================== Fetch Invoice Item Details ====================
$invoice_det = [];
$stmt = $branch_db->prepare("
    SELECT 
        d.invoice_no,
        d.item_id,
        i.item_desc AS item_name,
        d.qty,
        d.mrp,
        d.sale_price,
        d.disc_per,
        d.disc_amt,
        d.sale_tax_per,
        d.sale_tax_amt,
        d.net_amt,
        d.pur_rate
    FROM t_invoice_det d
    LEFT JOIN m_item_hdr i ON d.item_id = i.item_id
    WHERE d.invoice_no = ?
");
$stmt->bind_param("s", $invoice_no);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $invoice_det[] = $row;
}
$stmt->close();

// Group items by item_id
$grouped_items = [];
foreach ($invoice_det as $row) {
    $item_id = $row['item_id'];
    if (!isset($grouped_items[$item_id])) {
        $grouped_items[$item_id] = [
            'item_id'    => $row['item_id'],
            'item_name'  => $row['item_name'],
            'qty'        => (float)$row['qty'],
            'mrp'        => (float)$row['mrp'],
            'sale_price' => (float)$row['sale_price'],
            'disc_per'   => (float)$row['disc_per'],
            'disc_amt'   => (float)$row['disc_amt'],
            'sale_tax_per' => (float)$row['sale_tax_per'],
            'sale_tax_amt' => (float)$row['sale_tax_amt'],
            'net_amt_total' => (float)$row['net_amt'],
            'pur_rate'   => (float)$row['pur_rate'],
        ];
    } else {
        $grouped_items[$item_id]['qty'] += (float)$row['qty'];
        $grouped_items[$item_id]['disc_amt'] += (float)$row['disc_amt'];
        $grouped_items[$item_id]['sale_tax_amt'] += (float)$row['sale_tax_amt'];
        // keep net_amt_total as-is (do not sum)
    }
}
$invoice_det_grouped = array_values($grouped_items);

// ==================== Fetch Invoice Payment Details ====================
$invoice_pay = [];
$stmt = $branch_db->prepare("
    SELECT 
        p.invoice_no,
        p.pay_mode_id,
        p.pay_amt,
        p.ref_amt,
        p.bank_name,
        p.cc_no
    FROM t_invoice_pay_det p
    WHERE p.invoice_no = ?
");
$stmt->bind_param("s", $invoice_no);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $invoice_pay[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html>

<head>
    <title>Invoice #<?= htmlspecialchars($invoice_no) ?> - Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container my-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">🧾 Invoice #<?= htmlspecialchars($invoice_no) ?></h3>
        </div>

        <!-- ==================== Invoice Items Table ==================== -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title">📦 Invoice Item Details</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover">
                        <thead class="table-dark text-center">
                            <tr>
                                <th>Sl No.</th>
                                <th>Item Id</th>
                                <th>Item Name</th>
                                <th>Qty</th>
                                <th>Mrp</th>
                                <th>Sale Price</th>
                                <th>Disc %</th>
                                <th>Disc Amt</th>
                                <th>Tax %</th>
                                <th>Tax Amt</th>
                                <th>Net Amt</th>
                                <th>Pur. Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($invoice_det_grouped) === 0): ?>
                                <tr>
                                    <td colspan="12" class="text-center text-muted">No Items Found</td>
                                </tr>
                            <?php else: ?>
                                <?php $i = 1;
                                foreach ($invoice_det_grouped as $row): ?>
                                    <tr class="text-center">
                                        <td><?= $i++ ?></td>
                                        <td><?= htmlspecialchars($row['item_id']) ?></td>
                                        <td class="text-start">
                                            <?= htmlspecialchars($row['item_name']) ?><br>
                                            <small class="text-muted">(per unit: <?= number_format($row['net_amt_total'] / max(1, $row['qty']), 2) ?>)</small>
                                        </td>
                                        <td><?= $row['qty'] ?></td>
                                        <td><?= number_format($row['mrp'], 2) ?></td>
                                        <td><?= number_format($row['sale_price'], 2) ?></td>
                                        <td><?= number_format($row['disc_per'], 2) ?></td>
                                        <td><?= number_format($row['disc_amt'], 2) ?></td>
                                        <td><?= number_format($row['sale_tax_per'], 2) ?></td>
                                        <td><?= number_format($row['sale_tax_amt'], 2) ?></td>
                                        <td><?= number_format($row['net_amt_total'], 2) ?></td>
                                        <td><?= number_format($row['pur_rate'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="fw-bold text-end">
                                    <td colspan="10">Grand Total</td>
                                    <td><?= number_format($invoice_hdr['net_amt_after_disc'] ?? 0, 2) ?></td>
                                    <td></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================== Invoice Payments Table ==================== -->
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title">💳 Payment Details</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover">
                        <thead class="table-dark text-center">
                            <tr>
                                <th>Sl No.</th>
                                <th>Pay Mode</th>
                                <th>Pay Amount</th>
                                <th>Refund Amt</th>
                                <th>Bank Name</th>
                                <th>Card No.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($invoice_pay) === 0): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No Payments Found</td>
                                </tr>
                            <?php else: ?>
                                <?php $j = 1;
                                foreach ($invoice_pay as $row): ?>
                                    <tr class="text-center">
                                        <td><?= $j++ ?></td>
                                        <td><?= htmlspecialchars($row['pay_mode_id'] ?? '-') ?></td>
                                        <td><?= number_format((float)($row['pay_amt'] ?? 0), 2) ?></td>
                                        <td><?= htmlspecialchars($row['ref_amt'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['bank_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['cc_no'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>

</html>