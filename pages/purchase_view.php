<?php
require_once "../includes/config.php";
include "../includes/header.php";

ini_set('display_errors', 1);
error_reporting(E_ALL);

$receipt_id = $_GET['receipt_id'] ?? '';
$branch_id = $_GET['branch_id'] ?? '';

if (!$receipt_id) {
    echo "<div class='alert alert-danger'>Missing receipt ID.</div>";
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

// Get GRN header
$stmt = $branch_db->prepare("
    SELECT 
        H.receipt_id, H.receipt_date, H.branch_id, H.pur_type,
        H.order_no, H.bill_no, H.bill_date,
        H.supp_id, S.supp_name,
        H.gross_amt AS hdr_gross_amt, H.net_amt AS hdr_net_amt,
        H.status, H.ent_by, H.ent_on
    FROM t_receipt_hdr H
    JOIN m_supplier S ON H.supp_id = S.supp_id
    WHERE H.receipt_id = ?
");
$stmt->bind_param("s", $receipt_id);
$stmt->execute();
$result = $stmt->get_result();
$receipt = $result->fetch_assoc();
$stmt->close();

// Get and group GRN items
$stmt = $branch_db->prepare("
    SELECT 
        B.sl_no, B.bar_code, B.item_id, C.hsn_code,
        C.item_desc AS item_name,
        B.qty, B.free_item_yn, B.pur_rate, B.item_amt,
        B.disc_per, B.disc_amt, B.vat_per, B.vat_amt,
        B.net_rate, B.net_amt AS d_net_amt,
        B.mrp, B.sales_price, B.cess_perc, B.cess_amt,
        CASE WHEN B.net_rate > 0 THEN ROUND(((B.mrp - B.net_rate) * 100) / B.net_rate, 2) ELSE 0 END AS margin,
        CASE WHEN B.net_rate > 0 THEN ROUND(((B.sales_price - B.net_rate) * 100) / B.net_rate, 2) ELSE 0 END AS margin_on_sp,
        CASE WHEN B.mrp > 0 THEN ROUND(((B.mrp - B.net_rate) * 100) / B.mrp, 2) ELSE 0 END AS mark_down_margin
    FROM t_receipt_det B
    JOIN m_item_hdr C ON TRIM(B.item_id) = TRIM(C.item_id)
    WHERE TRIM(B.receipt_id) = ?
    ORDER BY CAST(B.sl_no AS SIGNED)
");
$stmt->bind_param("s", $receipt_id);
$stmt->execute();
$result = $stmt->get_result();

$grouped_items = [];
while ($row = $result->fetch_assoc()) {
    $item_id = $row['item_id'];
    if (!isset($grouped_items[$item_id])) {
        $grouped_items[$item_id] = $row;
    } else {
        $grouped_items[$item_id]['qty'] += $row['qty'];
        $grouped_items[$item_id]['item_amt'] += $row['item_amt'];
        $grouped_items[$item_id]['disc_amt'] += $row['disc_amt'];
        $grouped_items[$item_id]['vat_amt'] += $row['vat_amt'];
        $grouped_items[$item_id]['d_net_amt'] += $row['d_net_amt'];
        $grouped_items[$item_id]['cess_amt'] += $row['cess_amt'];
    }
}
$items = array_values($grouped_items);
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
            <h3 class="mb-0">📦 Purchase Details (GRN)</h3>
            <div class="action-buttons no-print">
                <button class="btn btn-primary btn-sm" onclick="printFull()">🖨️ Print</button>
                <button class="btn btn-danger btn-sm" onclick="downloadPDF()">📄 PDF</button>
                <button class="btn btn-success btn-sm" onclick="exportToExcel()">📊 Excel</button>
            </div>
        </div>

        <?php if (!$receipt): ?>
            <div class="alert alert-warning">No GRN found for this receipt ID.</div>
        <?php else: ?>
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="card-title">Receipt #<?= htmlspecialchars($receipt['receipt_id']) ?></h5>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>🧾 Supplier:</strong> <?= htmlspecialchars($receipt['supp_name']) ?><br>
                                <strong>📅 Receipt Date:</strong> <?= $receipt['receipt_date'] ?><br>
                                <strong>📄 Bill No:</strong> <?= $receipt['bill_no'] ?> (<?= $receipt['bill_date'] ?>)
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>👤 Entered By:</strong> <?= $receipt['ent_by'] ?><br>
                                <strong>💰 Gross Amount:</strong> ₹<?= number_format($receipt['hdr_gross_amt'], 2) ?><br>
                                <strong>💵 Net Amount:</strong> ₹<?= number_format($receipt['hdr_net_amt'], 2) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive" id="table-wrapper">
                <table class="table table-bordered table-hover table-striped" id="grn-table">
                    <thead class="table-dark text-center">
                        <tr>
                            <th>Sl</th>
                            <th>Item Name</th>
                            <th>HSN</th>
                            <th>Qty</th>
                            <th>Pur Rate</th>
                            <th>Disc%</th>
                            <th>GST%</th>
                            <th>Net Rate</th>
                            <th>Net Amt</th>
                            <th>MRP</th>
                            <th>Sales Price</th>
                            <th>Cess%</th>
                            <th>Margin on MRP</th>
                            <th>Margin on SP</th>
                            <th>Mark Down Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1;
                        foreach ($items as $row): ?>
                            <tr class="text-center">
                                <td><?= $i++ ?></td>
                                <td class="text-start"><?= htmlspecialchars($row['item_name']) ?></td>
                                <td><?= $row['hsn_code'] ?></td>
                                <td><?= $row['qty'] ?></td>
                                <td><?= $row['pur_rate'] ?></td>
                                <td><?= $row['disc_per'] ?></td>
                                <td><?= $row['vat_per'] ?></td>
                                <td><?= $row['net_rate'] ?></td>
                                <td><?= number_format($row['d_net_amt'], 2) ?></td>
                                <td><?= $row['mrp'] ?></td>
                                <td><?= $row['sales_price'] ?></td>
                                <td><?= $row['cess_perc'] ?></td>
                                <td><?= $row['margin'] ?>%</td>
                                <td><?= $row['margin_on_sp'] ?>%</td>
                                <td><?= $row['mark_down_margin'] ?>%</td>
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