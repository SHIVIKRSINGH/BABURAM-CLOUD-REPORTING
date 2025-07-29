<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// 🔒 Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.php");
    exit;
}

// 🔹 Session Shortcuts
$user_id   = $_SESSION['user_id'];
$role_id   = $_SESSION['role_id'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';
$branch_id = $_SESSION['branch_id'] ?? '';
$username  = $_SESSION['username'] ?? '';
$db_selected = $_SESSION['db_selected'] ?? '';

// 🔹 Branch List (for admin only)
$branchList = [];
$default_branch_id = '';

if (strtolower($role_name) === 'admin') {
    $branchRes = $con->query("SELECT branch_id, db_name FROM m_branch_sync_config ORDER BY branch_id");
    while ($row = $branchRes->fetch_assoc()) {
        $branchList[] = $row;
    }

    // Use session branch_id or first available
    if (!isset($_SESSION['selected_branch_id']) && count($branchList)) {
        $_SESSION['selected_branch_id'] = $branchList[0]['branch_id'];
    }

    $selected_branch_id = $_SESSION['selected_branch_id'] ?? '';
} else {
    $selected_branch_id = $branch_id;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>ShashiFashion</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            display: flex;
            min-height: 100vh;
            margin: 0;
        }

        .sidebar {
            width: 250px;
            background: #343a40;
            color: white;
            padding-top: 20px;
        }

        .sidebar a {
            color: #adb5bd;
            text-decoration: none;
            display: block;
            padding: 10px 20px;
        }

        .sidebar a:hover {
            background: #495057;
            color: white;
        }

        .sidebar .menu-title {
            font-size: 14px;
            text-transform: uppercase;
            padding: 10px 20px;
            color: #ced4da;
        }

        .content {
            flex-grow: 1;
            padding: 20px;
            background: #f8f9fa;
        }

        .topbar {
            background: #fff;
            padding: 10px 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .branch-select {
            font-size: 14px;
            margin-left: 10px;
            padding: 3px 6px;
        }
    </style>
</head>

<body>

    <!-- Sidebar -->
    <div class="sidebar">
        <a class="navbar-brand text-white fw-bold mb-4 d-block" href="dashboard.php">Shashi Fashion</a>
        <?php
        // 🔹 Fetch Menus
        $sql = "
        SELECT m.*
        FROM m_menu m
        JOIN m_permission p ON m.menu_id = p.menu_id
        WHERE p.role_id = ?
        AND m.is_active = 1
        ORDER BY m.parent_id, m.sort_order
    ";
        $stmt = $con->prepare($sql);
        $stmt->bind_param("i", $role_id);
        $stmt->execute();
        $menus = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // 🔹 Build Menu Tree
        $tree = [];
        foreach ($menus as $menu) {
            if (is_null($menu['parent_id']) || $menu['parent_id'] === '') {
                $tree[$menu['menu_id']] = $menu + ['children' => []];
            }
        }
        foreach ($menus as $menu) {
            if (!is_null($menu['parent_id']) && isset($tree[$menu['parent_id']])) {
                $tree[$menu['parent_id']]['children'][] = $menu;
            }
        }
        ?>

        <?php foreach ($tree as $parent): ?>
            <?php if (empty($parent['children'])): ?>
                <a href="<?= htmlspecialchars($parent['menu_url']) ?>">
                    <i class="<?= $parent['menu_icon'] ?>"></i> <?= htmlspecialchars($parent['menu_name']) ?>
                </a>
            <?php else: ?>
                <div class="menu-title">
                    <i class="<?= $parent['menu_icon'] ?>"></i> <?= htmlspecialchars($parent['menu_name']) ?>
                </div>
                <?php foreach ($parent['children'] as $child): ?>
                    <a class="ms-3" href="<?= htmlspecialchars($child['menu_url']) ?>">
                        <i class="<?= $child['menu_icon'] ?>"></i> <?= htmlspecialchars($child['menu_name']) ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Main Content -->
    <div class="content">
        <div class="topbar">
            <div>
                Welcome, <strong><?= htmlspecialchars($username) ?></strong> (<?= $role_name ?>) |
                Branch:
                <?php if (strtolower($role_name) === 'admin'): ?>
                    <form method="POST" action="" style="display:inline;">
                        <select name="selected_branch_id" class="branch-select" onchange="this.form.submit()">
                            <?php foreach ($branchList as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= $b['branch_id'] === $selected_branch_id ? 'selected' : '' ?>>
                                    <?= $b['branch_id'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php else: ?>
                    <span class="text-muted"><?= $branch_id ?></span>
                <?php endif; ?>
            </div>
            <a class="nav-link text-danger" href="../logout.php">Logout</a>
        </div>