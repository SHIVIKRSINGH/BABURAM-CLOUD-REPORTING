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
    A.receipt_id,
    date(A.receipt_date) as receipt_dt,
    A.supp_id,
    C.supp_name,
    B.item_id,
    M.item_desc AS item_name,
    B.qty,
    B.pur_rate,
    B.disc_per,
    B.vat_per,
    B.vat_amt,
    B.net_rate,
    B.net_amt,
    B.mrp,
    B.sales_price
FROM t_receipt_hdr A
JOIN t_receipt_det B ON A.receipt_id = B.receipt_id
LEFT JOIN m_supplier C ON A.supp_id = C.supp_id
LEFT JOIN m_item_hdr M ON B.item_id = M.item_id
WHERE A.receipt_date BETWEEN STR_TO_DATE(?, '%Y-%m-%d') AND STR_TO_DATE(?, '%Y-%m-%d') and M.item_id=?
ORDER BY A.receipt_id desc;

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
    <title>ITEM WISE PURCHASE HISTORY View</title>
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
            <h3 class="mb-0">📦 Item Wise Purchase History</h3>
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
                            <th>Receipt No.</th>
                            <th>Receipt Date</th>
                            <th>Supp Id</th>
                            <th>Supp Name</th>
                            <th>Item Id</th>
                            <th>Item Name</th>
                            <th>Qty</th>
                            <th>Pur. Rate</th>
                            <th>Disc Per</th>
                            <th>Vat Per</th>
                            <th>Vat Amt</th>
                            <th>Net Rate</th>
                            <th>Net Amt</th>
                            <th>MRP</th>
                            <th>Sales Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1;
                        foreach ($items as $row): ?>
                            <tr class="text-center">
                                <td><?= $i++ ?></td>
                                <td class="text-start"><?= $row['receipt_id'] ?></td>
                                <td><?= $row['receipt_dt'] ?></td>
                                <td><?= $row['supp_id'] ?></td>
                                <td><?= $row['supp_name'] ?></td>
                                <td><?= $row['item_id'] ?></td>
                                <td><?= $row['item_name'] ?></td>
                                <td><?= $row['qty'] ?></td>
                                <td><?= number_format($row['pur_rate'], 2) ?></td>
                                <td><?= number_format($row['disc_per'], 2) ?></td>
                                <td><?= number_format($row['vat_per'], 2) ?></td>
                                <td><?= number_format($row['vat_amt'], 2) ?></td>
                                <td><?= number_format($row['net_rate'], 2) ?></td>
                                <td><?= number_format($row['net_amt'], 2) ?></td>
                                <td><?= number_format($row['mrp'], 2) ?></td>
                                <td><?= $row['sale_price'] ?></td>
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