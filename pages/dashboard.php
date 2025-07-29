<?php
require_once __DIR__ . '/../includes/config.php';
include "../includes/header.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$role_name       = $_SESSION['role_name'];
$session_branch  = $_SESSION['branch_id'] ?? '';
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $session_branch);

// Dates
$from        = $_GET['from'] ?? '';
$to          = $_GET['to'] ?? '';
$today       = date('Y-m-d');
$month_start = date('Y-m-01');
$summary_from = $from ?: $today;
$summary_to   = $to ?: $today;
$chart_from   = $from ?: $month_start;
$chart_to     = $to ?: $today;

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

// 🔸 Sales
$stmt = $branch_db->prepare("
    SELECT DATE(invoice_dt) AS date, SUM(net_amt_after_disc) AS total_sale
    FROM t_invoice_hdr
    WHERE DATE(invoice_dt) BETWEEN ? AND ?
    GROUP BY DATE(invoice_dt)
");
$stmt->bind_param("ss", $chart_from, $chart_to);
$stmt->execute();
$res_sales = $stmt->get_result();

// 🔸 Returns
$stmt = $branch_db->prepare("
    SELECT DATE(sr_dt) AS date, SUM(net_amt) AS total_return
    FROM t_sr_hdr
    WHERE DATE(sr_dt) BETWEEN ? AND ?
    GROUP BY DATE(sr_dt)
");
$stmt->bind_param("ss", $chart_from, $chart_to);
$stmt->execute();
$res_returns = $stmt->get_result();

// 🧮 Merge chart data
$data = [];
while ($row = $res_sales->fetch_assoc()) {
    $data[$row['date']]['sales'] = $row['total_sale'];
}
while ($row = $res_returns->fetch_assoc()) {
    $data[$row['date']]['returns'] = $row['total_return'];
}

$labels = [];
$salesData = [];
$returnsData = [];

$start = new DateTime($chart_from);
$end = new DateTime($chart_to);
$end->modify('+1 day');

for ($d = $start; $d < $end; $d->modify('+1 day')) {
    $date = $d->format('Y-m-d');
    $labels[] = $date;
    $salesData[] = $data[$date]['sales'] ?? 0;
    $returnsData[] = $data[$date]['returns'] ?? 0;
}

// 🧾 Summary
$stmt = $branch_db->prepare("
    SELECT 
        (SELECT COALESCE(SUM(net_amt_after_disc), 0) FROM t_invoice_hdr WHERE DATE(invoice_dt) BETWEEN ? AND ?) AS total_sales,
        (SELECT COALESCE(SUM(net_amt), 0) FROM t_sr_hdr WHERE DATE(sr_dt) BETWEEN ? AND ?) AS total_returns,
        (SELECT COUNT(*) FROM t_invoice_hdr WHERE DATE(invoice_dt) BETWEEN ? AND ?) AS invoice_count
");
$stmt->bind_param("ssssss", $summary_from, $summary_to, $summary_from, $summary_to, $summary_from, $summary_to);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

$total_sales = $summary['total_sales'];
$total_returns = $summary['total_returns'];
$invoice_count = $summary['invoice_count'];
$net_total = $total_sales - $total_returns;

// PAYMENT MODE DATA

// Prepare and execute the updated payment mode-wise sale vs return query
$stmt = $branch_db->prepare("
    SELECT 
        CAST(x.pay_mode_id AS CHAR) AS pay_mode_id,
        SUM(x.total_sale) AS total_sale,
        SUM(x.total_return) AS total_return,
        SUM(x.total_sale) - SUM(x.total_return) AS net_total
    FROM (
        SELECT 
            a.pay_mode_id, 
            SUM(a.pay_amt) AS total_sale, 
            0 AS total_return
        FROM t_invoice_pay_det a
        JOIN t_invoice_hdr b ON a.invoice_no = b.invoice_no
        WHERE DATE(b.invoice_dt) BETWEEN ? AND ?
        GROUP BY a.pay_mode_id

        UNION ALL

        SELECT 
            a.pay_mode_id, 
            0 AS total_sale, 
            SUM(a.pay_amt) AS total_return
        FROM t_sr_pay_det a
        JOIN t_sr_hdr b ON a.sr_no = b.sr_no
        WHERE DATE(b.sr_dt) BETWEEN ? AND ?
        GROUP BY a.pay_mode_id
    ) x
    GROUP BY x.pay_mode_id

    UNION ALL

    SELECT 
        'TOTAL' AS pay_mode_id,
        SUM(x.total_sale),
        SUM(x.total_return),
        SUM(x.total_sale) - SUM(x.total_return)
    FROM (
        SELECT 
            a.pay_mode_id, 
            SUM(a.pay_amt) AS total_sale, 
            0 AS total_return
        FROM t_invoice_pay_det a
        JOIN t_invoice_hdr b ON a.invoice_no = b.invoice_no
        WHERE DATE(b.invoice_dt) BETWEEN ? AND ?
        GROUP BY a.pay_mode_id

        UNION ALL

        SELECT 
            a.pay_mode_id, 
            0 AS total_sale, 
            SUM(a.pay_amt) AS total_return
        FROM t_sr_pay_det a
        JOIN t_sr_hdr b ON a.sr_no = b.sr_no
        WHERE DATE(b.sr_dt) BETWEEN ? AND ?
        GROUP BY a.pay_mode_id
    ) x
");

$stmt->bind_param("ssssssss", $summary_from, $summary_to, $summary_from, $summary_to, $summary_from, $summary_to, $summary_from, $summary_to);
$stmt->execute();
$result = $stmt->get_result();

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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="bg-light">
    <div class="container py-4">
        <h2 class="mb-4">Sales & Return Dashboard</h2>

        <form method="get" class="row g-3 mb-4">
            <div class="col-md-3">
                <label>Branch</label>
                <select name="branch" class="form-select" <?= strtolower($role_name) !== 'admin' ? 'disabled' : '' ?>>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= $b ?>" <?= $b === $selected_branch ? 'selected' : '' ?>><?= $b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label>From</label>
                <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control">
            </div>
            <div class="col-md-3">
                <label>To</label>
                <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control">
            </div>
            <div class="col-md-3 align-self-end">
                <button class="btn btn-primary w-100">Filter</button>
            </div>
        </form>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="p-3 bg-white shadow-sm rounded text-center">
                    <div class="text-muted small">Total Sales</div>
                    <h5 class="text-success">₹ <?= number_format($total_sales, 2) ?></h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-white shadow-sm rounded text-center">
                    <div class="text-muted small">Sale Returns</div>
                    <h5 class="text-danger">₹ <?= number_format($total_returns, 2) ?></h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-white shadow-sm rounded text-center">
                    <div class="text-muted small">Net Total</div>
                    <h5 class="text-primary">₹ <?= number_format($net_total, 2) ?></h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-white shadow-sm rounded text-center">
                    <div class="text-muted small">Invoices</div>
                    <h5><?= $invoice_count ?></h5>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <canvas id="chart"></canvas>
            </div>
        </div>
        <div></div>
        <!-- PAYMENT MODE WISE SALE SUMMARY UI -->
        <!-- <div class="row g-3 mb-4"> -->
        <div class="card shadow-sm mt-4">
            <div class="p-3 bg-white shadow-sm rounded text-center">
                <div class="row g-3 mb-4 justify-content-center">
                    <div class="col-auto text-center">
                        <h5 class="m-0">PAYMENT MODE WISE SALE SUMMARY</h5>
                    </div>
                </div>
                <div class="table-responsive mt-3">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Payment Mode</th>
                                <th>Total Sale</th>
                                <th>Total Sale Return</th>
                                <th>Net Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()) {
                                $isTotalRow = $row['pay_mode_id'] === 'TOTAL';
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['pay_mode_id']); ?></strong></td>
                                    <td style="<?php echo $isTotalRow ? 'background-color: #d4edda; color: #155724; font-weight: bold;' : ''; ?>">
                                        <?php echo number_format($row['total_sale'], 2); ?>
                                    </td>
                                    <td style="<?php echo $isTotalRow ? 'background-color: #f8d7da; color: #721c24; font-weight: bold;' : ''; ?>">
                                        <?php echo number_format($row['total_return'], 2); ?>
                                    </td>
                                    <td style="<?php echo $isTotalRow ? 'background-color: #e6f4ea; color: #1e4620; font-weight: bold;' : ''; ?>">
                                        <?php echo number_format($row['net_total'], 2); ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        const ctx = document.getElementById('chart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels) ?>,
                datasets: [{
                        label: 'Net Sales (₹)',
                        data: <?= json_encode($salesData) ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.8)'
                    },
                    {
                        label: 'Sale Returns (₹)',
                        data: <?= json_encode($returnsData) ?>,
                        backgroundColor: 'rgba(255, 99, 132, 0.8)'
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: ctx => '₹ ' + ctx.formattedValue
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: value => '₹ ' + value
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>