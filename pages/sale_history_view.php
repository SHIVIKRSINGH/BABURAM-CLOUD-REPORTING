<?php
require_once "../includes/config.php";
include "../includes/header.php";

ini_set('display_errors', 1);
error_reporting(E_ALL);

$item_id = $_GET['item_id'] ?? '';
$branch_id = $_GET['branch_id'] ?? '';

$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');



if (!$item_id) {
    echo "<div class='alert alert-danger'>Missing Item ID.</div>";
    exit;
}

$receipt = [];
$items = [];
$role_name       = $_SESSION['role_name'];
$session_branch  = $_SESSION['branch_id'] ?? '';
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $session_branch);

// Branch DB connection
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
// Get GRN header
$stmt = $branch_db->prepare("
    SELECT 
    A.invoice_no,
    A.invoice_dt,
    A.cust_id,
    C.cust_name,
    B.item_id,
    M.item_desc AS item_name,
    B.qty,
    B.sale_price,
    B.disc_per,
    B.sale_tax_per AS vat_per,
    B.sale_tax_amt AS vat_amt,
    B.net_amt,
    B.mrp
FROM t_invoice_hdr A
JOIN t_invoice_det B ON A.invoice_no = B.invoice_no
LEFT JOIN m_customer C ON A.cust_id = C.cust_id
LEFT JOIN m_item_hdr M ON B.item_id = M.item_id
WHERE A.invoice_dt BETWEEN STR_TO_DATE(?, '%Y-%m-%d') AND STR_TO_DATE(?, '%Y-%m-%d') and M.item_id=?
ORDER BY B.item_id;

");
$stmt->bind_param("sss", $from, $to, $item_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

?>

<!DOCTYPE html>
<html>

<head>
    <title>GRN View</title>
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
            max-height: 600px;
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
    <div class="container my-5" id="grn-section">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">📦 Item Wise Sale History (GRN)</h3>
            <div class="action-buttons no-print">
                <button class="btn btn-primary btn-sm" onclick="printFull()">🖨️ Print</button>
                <button class="btn btn-danger btn-sm" onclick="downloadPDF()">📄 PDF</button>
                <button class="btn btn-success btn-sm" onclick="exportToExcel()">📊 Excel</button>
            </div>
        </div>

        <?php if (!$item_id): ?>
            <div class="alert alert-warning">No History found for this Item Id.</div>
        <?php else: ?>
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="card-title">Item Id #<?= htmlspecialchars($item_id) ?></h5>
                </div>
            </div>

            <div class="table-responsive" id="table-wrapper">
                <table class="table table-bordered table-hover table-striped" id="grn-table">
                    <thead class="table-dark text-center">
                        <tr>
                            <th>Sl No.</th>
                            <th>Invoice No.</th>
                            <th>Invoice Date</th>
                            <th>Cust Id</th>
                            <th>Cust Name</th>
                            <th>Item Id</th>
                            <th>Item Name</th>
                            <th>Qty</th>
                            <th>MRP</th>
                            <th>Sales Price</th>
                            <th>Disc Per</th>
                            <th>Vat Per</th>
                            <th>Vat Amt</th>
                            <th>Net Amt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1;
                        foreach ($items as $row): ?>
                            <tr class="text-center">
                                <td><?= $i++ ?></td>
                                <td class="text-start"><?= htmlspecialchars($row['invoice_no']) ?></td>
                                <td><?= $row['invoice_dt'] ?></td>
                                <td><?= $row['cust_id'] ?></td>
                                <td><?= $row['cust_name'] ?></td>
                                <td><?= $row['item_id'] ?></td>
                                <td><?= $row['item_name'] ?></td>
                                <td><?= $row['qty'] ?></td>
                                <td><?= number_format($row['mrp'], 2) ?></td>
                                <td><?= number_format($row['sale_price'], 2) ?></td>
                                <td><?= number_format($row['disc_per'], 2) ?></td>
                                <td><?= number_format($row['vat_per'], 2) ?></td>
                                <td><?= number_format($row['vat_amt'], 2) ?></td>
                                <td><?= number_format($row['net_amt'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- JS Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <script>
        function disableScrollWrapper() {
            const wrapper = document.getElementById('table-wrapper');
            wrapper.style.maxHeight = 'none';
            wrapper.style.overflow = 'visible';
        }

        function enableScrollWrapper() {
            const wrapper = document.getElementById('table-wrapper');
            wrapper.style.maxHeight = '600px';
            wrapper.style.overflow = 'auto';
        }

        function downloadPDF() {
            disableScrollWrapper();
            const element = document.getElementById('grn-section');
            html2pdf().set({
                margin: 0.5,
                filename: 'GRN_<?= $receipt['receipt_id'] ?? "Receipt" ?>.pdf',
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
                enableScrollWrapper();
            });
        }

        function exportToExcel() {
            disableScrollWrapper();
            setTimeout(() => {
                const table = document.getElementById("grn-table");
                const wb = XLSX.utils.table_to_book(table, {
                    sheet: "GRN"
                });
                XLSX.writeFile(wb, 'GRN_<?= $receipt['receipt_id'] ?? "Receipt" ?>.xlsx');
                enableScrollWrapper();
            }, 100);
        }

        function printFull() {
            disableScrollWrapper();
            setTimeout(() => {
                window.print();
                enableScrollWrapper();
            }, 200);
        }
    </script>
</body>

</html>