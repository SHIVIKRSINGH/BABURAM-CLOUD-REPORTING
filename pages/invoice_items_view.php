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

// ==================== Fetch Invoice Header ====================
$stmt = $branch_db->prepare(
    "SELECT invoice_no, net_amt_after_disc FROM t_invoice_hdr WHERE invoice_no = ?"
);
$stmt->bind_param("s", $invoice_no);
$stmt->execute();
$invoice_hdr = $stmt->get_result()->fetch_assoc();
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

// Group items
$grouped_items = [];
foreach ($invoice_det as $row) {
    $id = $row['item_id'];
    if (!isset($grouped_items[$id])) {
        $grouped_items[$id] = [
            'item_id' => $row['item_id'],
            'item_name' => $row['item_name'],
            'qty' => (float)$row['qty'],
            'mrp' => (float)$row['mrp'],
            'sale_price' => (float)$row['sale_price'],
            'disc_per' => (float)$row['disc_per'],
            'disc_amt' => (float)$row['disc_amt'],
            'sale_tax_per' => (float)$row['sale_tax_per'],
            'sale_tax_amt' => (float)$row['sale_tax_amt'],
            'net_amt_total' => (float)$row['net_amt'],
            'pur_rate' => (float)$row['pur_rate'],
        ];
    } else {
        $grouped_items[$id]['qty'] += (float)$row['qty'];
        $grouped_items[$id]['disc_amt'] += (float)$row['disc_amt'];
        $grouped_items[$id]['sale_tax_amt'] += (float)$row['sale_tax_amt'];
    }
}
$invoice_det_grouped = array_values($grouped_items);

// ==================== Fetch Invoice Payment Details ====================
$invoice_pay = [];
$stmt = $branch_db->prepare("
    SELECT pay_mode_id, pay_amt, ref_amt, bank_name, cc_no
    FROM t_invoice_pay_det
    WHERE invoice_no = ?
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
    <title>Invoice #<?= htmlspecialchars($invoice_no) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        @media print {
            .no-print {
                display: none !important;
            }

            .table-responsive {
                max-height: none !important;
                overflow: visible !important;
            }
        }
    </style>
</head>

<body class="bg-light">

    <div class="container my-5" id="invoice-section">

        <!-- Header & Buttons -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>🧾 Invoice #<?= htmlspecialchars($invoice_no) ?></h3>
            <div class="no-print">
                <button class="btn btn-primary btn-sm" onclick="printInvoice()">🖨️ Print</button>
                <button class="btn btn-danger btn-sm" onclick="downloadInvoicePDF()">📄 PDF</button>
                <button class="btn btn-success btn-sm" onclick="exportInvoiceExcel()">📊 Excel</button>
            </div>
        </div>

        <!-- ==================== Items ==================== -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5>📦 Invoice Item Details</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead class="table-dark text-center">
                            <tr>
                                <th>#</th>
                                <th>Item Id</th>
                                <th>Item Name</th>
                                <th>Qty</th>
                                <th>MRP</th>
                                <th>Sale</th>
                                <th>Disc%</th>
                                <th>Disc Amt</th>
                                <th>Tax%</th>
                                <th>Tax Amt</th>
                                <th>Net Amt</th>
                                <th>Pur Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1;
                            foreach ($invoice_det_grouped as $r): ?>
                                <tr class="text-center">
                                    <td><?= $i++ ?></td>
                                    <td><?= $r['item_id'] ?></td>
                                    <td class="text-start"><?= $r['item_name'] ?></td>
                                    <td><?= $r['qty'] ?></td>
                                    <td><?= number_format($r['mrp'], 2) ?></td>
                                    <td><?= number_format($r['sale_price'], 2) ?></td>
                                    <td><?= number_format($r['disc_per'], 2) ?></td>
                                    <td><?= number_format($r['disc_amt'], 2) ?></td>
                                    <td><?= number_format($r['sale_tax_per'], 2) ?></td>
                                    <td><?= number_format($r['sale_tax_amt'], 2) ?></td>
                                    <td><?= number_format($r['net_amt_total'], 2) ?></td>
                                    <td><?= number_format($r['pur_rate'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="fw-bold text-end">
                                <td colspan="10">Grand Total</td>
                                <td><?= number_format($invoice_hdr['net_amt_after_disc'], 2) ?></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================== Payments ==================== -->
        <div class="card shadow-sm">
            <div class="card-body">
                <h5>💳 Payment Details</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead class="table-dark text-center">
                            <tr>
                                <th>#</th>
                                <th>Mode</th>
                                <th>Amount</th>
                                <th>Refund</th>
                                <th>Bank</th>
                                <th>Card No</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $j = 1;
                            foreach ($invoice_pay as $p): ?>
                                <tr class="text-center">
                                    <td><?= $j++ ?></td>
                                    <td><?= $p['pay_mode_id'] ?></td>
                                    <td><?= number_format($p['pay_amt'], 2) ?></td>
                                    <td><?= $p['ref_amt'] ?></td>
                                    <td><?= $p['bank_name'] ?></td>
                                    <td><?= $p['cc_no'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <script>
        function printInvoice() {
            window.print();
        }

        function downloadInvoicePDF() {
            html2pdf().set({
                filename: 'Invoice_<?= $invoice_no ?>.pdf',
                margin: 0.5,
                html2canvas: {
                    scale: 2
                },
                jsPDF: {
                    unit: 'in',
                    format: 'a4'
                }
            }).from(document.getElementById('invoice-section')).save();
        }

        function exportInvoiceExcel() {
            const wb = XLSX.utils.book_new();
            document.querySelectorAll("table").forEach((t, i) => {
                XLSX.utils.book_append_sheet(wb, XLSX.utils.table_to_sheet(t), "Sheet" + (i + 1));
            });
            XLSX.writeFile(wb, 'Invoice_<?= $invoice_no ?>.xlsx');
        }
    </script>

</body>

</html>