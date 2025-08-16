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
    <style>
        .action-buttons {
            position: sticky;
            top: 0;
            background-color: #f8f9fa;
            z-index: 10;
            padding: 10px 0;
        }

        .table-responsive {
            max-height: 500px;
            overflow-y: auto;
        }

        @media print {
            .no-print {
                display: none;
            }

            .table-responsive {
                max-height: none !important;
                overflow: visible !important;
            }
        }
    </style>
</head>

<body class="bg-light">
    <div class="container my-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">🧾 Invoice #<?= htmlspecialchars($invoice_no) ?></h3>
            <div class="action-buttons no-print">
                <button class="btn btn-primary btn-sm" onclick="printFull()">🖨️ Print</button>
                <button class="btn btn-danger btn-sm" onclick="downloadPDF()">📄 PDF</button>
                <button class="btn btn-success btn-sm" onclick="exportToExcel()">📊 Excel</button>
            </div>
        </div>

        <!-- ==================== Invoice Items Table ==================== -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title">📦 Invoice Item Details</h5>
                <div class="table-responsive" id="items-wrapper">
                    <table class="table table-bordered table-striped table-hover" id="items-table">
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
                            <?php if (count($invoice_det) === 0): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">No Items Found</td>
                                </tr>
                            <?php else: ?>
                                <?php $i = 1;
                                foreach ($invoice_det as $row): ?>
                                    <tr class="text-center">
                                        <td><?= $i++ ?></td>
                                        <td><?= htmlspecialchars($row['item_id']) ?></td>
                                        <td class="text-start"><?= htmlspecialchars($row['item_name']) ?></td>
                                        <td><?= $row['qty'] ?></td>
                                        <td><?= number_format($row['mrp'], 2) ?></td>
                                        <td><?= number_format($row['sale_price'], 2) ?></td>
                                        <td><?= number_format($row['disc_per'], 2) ?></td>
                                        <td><?= number_format($row['disc_amt'], 2) ?></td>
                                        <td><?= number_format($row['sale_tax_per'], 2) ?></td>
                                        <td><?= number_format($row['sale_tax_amt'], 2) ?></td>
                                        <td><?= number_format($row['net_amt'], 2) ?></td>
                                        <td><?= number_format($row['pur_rate'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
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
                <div class="table-responsive" id="pay-wrapper">
                    <table class="table table-bordered table-striped table-hover" id="pay-table">
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
                                        <td><?= htmlspecialchars($row['ref_no'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['bank_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['card_no'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- JS Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <script>
        function disableScrollWrapper(wrapperId) {
            const wrapper = document.getElementById(wrapperId);
            wrapper.style.maxHeight = 'none';
            wrapper.style.overflow = 'visible';
        }

        function enableScrollWrapper(wrapperId) {
            const wrapper = document.getElementById(wrapperId);
            wrapper.style.maxHeight = '500px';
            wrapper.style.overflow = 'auto';
        }

        function downloadPDF() {
            disableScrollWrapper('items-wrapper');
            disableScrollWrapper('pay-wrapper');
            const element = document.body;
            html2pdf().set({
                margin: 0.5,
                filename: 'Invoice_<?= $invoice_no ?>.pdf',
                image: {
                    type: 'jpeg',
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2
                },
                jsPDF: {
                    unit: 'in',
                    format: 'a4',
                    orientation: 'portrait'
                }
            }).from(element).save().then(() => {
                enableScrollWrapper('items-wrapper');
                enableScrollWrapper('pay-wrapper');
            });
        }

        function exportToExcel() {
            disableScrollWrapper('items-wrapper');
            disableScrollWrapper('pay-wrapper');
            setTimeout(() => {
                const wb = XLSX.utils.book_new();
                const itemsTable = document.getElementById("items-table");
                const payTable = document.getElementById("pay-table");
                XLSX.utils.book_append_sheet(wb, XLSX.utils.table_to_sheet(itemsTable), "Invoice Items");
                XLSX.utils.book_append_sheet(wb, XLSX.utils.table_to_sheet(payTable), "Payments");
                XLSX.writeFile(wb, 'Invoice_<?= $invoice_no ?>.xlsx');
                enableScrollWrapper('items-wrapper');
                enableScrollWrapper('pay-wrapper');
            }, 100);
        }

        function printFull() {
            disableScrollWrapper('items-wrapper');
            disableScrollWrapper('pay-wrapper');
            setTimeout(() => {
                window.print();
                enableScrollWrapper('items-wrapper');
                enableScrollWrapper('pay-wrapper');
            }, 200);
        }
    </script>
</body>

</html>