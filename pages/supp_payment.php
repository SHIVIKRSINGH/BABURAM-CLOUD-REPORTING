<?php
require_once "../includes/config.php";
include "../includes/header.php";

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Inputs
$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$supp_id = $_GET['supp_id'] ?? '';
$role_name = $_SESSION['role_name'];
$session_branch = $_SESSION['branch_id'] ?? '';
$selected_branch = $_GET['branch'] ?? ($_SESSION['selected_branch_id'] ?? $session_branch);

// Connect to central DB to get branch config
$stmt = $con->prepare("SELECT * FROM m_branch_sync_config WHERE branch_id = ?");
$stmt->bind_param("s", $selected_branch);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) die("❌ Branch config not found.");

$config = $res->fetch_assoc();
$branch_db = new mysqli($config['db_host'], $config['db_user'], $config['db_password'], $config['db_name']);
if ($branch_db->connect_error) die("❌ DB Error: " . $branch_db->connect_error);
$branch_db->set_charset('utf8mb4');
$branch_db->query("SET time_zone = '+05:30'");

// Load supplier list
$suppliers = [];
$supp_stmt = $branch_db->query("SELECT supp_id, supp_name FROM m_supplier ORDER BY supp_name");
while ($row = $supp_stmt->fetch_assoc()) {
    $suppliers[] = $row;
}

// Fetch payments
$params = [$from, $to];
$query = "
    SELECT 
        A.v_no, A.v_date, A.supp_id, B.supp_name, A.amount, A.pay_type, A.v_remarks 
    FROM t_payment_made A
    LEFT JOIN m_supplier B ON A.supp_id = B.supp_id
    WHERE A.v_date BETWEEN ? AND ?
";

if ($supp_id !== '') {
    $query .= " AND A.supp_id = ?";
    $params[] = $supp_id;
}

$query .= " ORDER BY A.v_no DESC";

$stmt = $branch_db->prepare($query);
$types = str_repeat('s', count($params));
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$payments = [];
$total_amount = 0;

while ($row = $result->fetch_assoc()) {
    $payments[] = $row;
    $total_amount += $row['amount'];
}

// Branch dropdown list
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
    <title>Payment Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">

</head>

<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.js"></script>
<script>
    $(function() {
        $("#supplier_search").autocomplete({
            source: "supplier_search.php",
            minLength: 2,
            select: function(event, ui) {
                $("#supplier_search").val(ui.item.label);
                $("#supp_id").val(ui.item.value);
                return false;
            }
        });
    });
</script>


<body class="bg-light">
    <div class="container py-5">
        <h2 class="mb-4">Supplier-wise Payment Report</h2>

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
                <label>From Date</label>
                <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div class="col-md-3">
                <label>To Date</label>
                <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($to) ?>">
            </div>
            <div class="col-md-3">
                <label>Supplier</label>
                <input type="text" id="supplier_search" class="form-control" placeholder="Search supplier...">
                <input type="hidden" name="supp_id" id="supp_id" value="<?= htmlspecialchars($supp_id) ?>">
            </div>

            <div class="col-md-3 align-self-end">
                <button type="submit" class="btn btn-primary">Search</button>
            </div>
        </form>

        <table id="paymentTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Voucher No</th>
                    <th>Voucher Date</th>
                    <th>Supplier ID</th>
                    <th>Supplier Name</th>
                    <th>Amount</th>
                    <th>Payment Type</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['v_no']) ?></td>
                        <td><?= htmlspecialchars($p['v_date']) ?></td>
                        <td><?= htmlspecialchars($p['supp_id']) ?></td>
                        <td><?= htmlspecialchars($p['supp_name']) ?></td>
                        <td><?= number_format($p['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($p['pay_type']) ?></td>
                        <td><?= htmlspecialchars($p['v_remarks']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="mt-3">
            <strong>Total Paid: ₹<?= number_format($total_amount, 2) ?></strong>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#paymentTable').DataTable();
        });
    </script>
</body>

</html>