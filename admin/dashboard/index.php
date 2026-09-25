<?php

session_start();

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../database/database.php';


/*
|--------------------------------------------------------------------------
| Admin Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    !in_array($_SESSION['user_role'], ['admin', 'staff'], true)
) {
    header('Location: /kmm-aut/admin/auth/login/');
    exit;
}


$userName = $_SESSION['user_name'] ?? 'Admin';
$userRole = $_SESSION['user_role'] ?? 'admin';


/*
|--------------------------------------------------------------------------
| Dashboard Statistics
|--------------------------------------------------------------------------
*/

$totalRequests = 0;
$newRequests = 0;
$inProgress = 0;
$completed = 0;
$cancelled = 0;
$totalCustomers = 0;

try {

    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM spare_part_requests
    ");

    $totalRequests = (int) $stmt->fetchColumn();


    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM spare_part_requests
        WHERE status = 'new'
    ");

    $newRequests = (int) $stmt->fetchColumn();


    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM spare_part_requests
        WHERE status IN (
            'contacted',
            'quotation_sent',
            'accepted',
            'payment_pending',
            'paid'
        )
    ");

    $inProgress = (int) $stmt->fetchColumn();


    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM spare_part_requests
        WHERE status = 'completed'
    ");

    $completed = (int) $stmt->fetchColumn();


    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM spare_part_requests
        WHERE status IN ('cancelled', 'rejected')
    ");

    $cancelled = (int) $stmt->fetchColumn();


    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'customer'
    ");

    $totalCustomers = (int) $stmt->fetchColumn();


    /*
    |--------------------------------------------------------------------------
    | Recent Requests
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query("
        SELECT
            r.id,
            r.request_no,
            r.part_required,
            r.quantity,
            r.status,
            r.created_at,
            u.name AS customer_name,
            u.phone AS customer_phone
        FROM spare_part_requests r
        LEFT JOIN users u
            ON u.id = r.customer_id
        ORDER BY r.id DESC
        LIMIT 10
    ");

    $recentRequests = $stmt->fetchAll();


} catch (Throwable $e) {

    error_log($e->getMessage());

    $recentRequests = [];

}


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function statusLabel(string $status): string
{
    $labels = [

        'new' => 'New',

        'contacted' => 'Contacted',

        'quotation_sent' => 'Quotation Sent',

        'accepted' => 'Accepted',

        'payment_pending' => 'Payment Pending',

        'paid' => 'Paid',

        'completed' => 'Completed',

        'cancelled' => 'Cancelled',

        'rejected' => 'Rejected'

    ];

    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}


function statusClass(string $status): string
{
    return match ($status) {

        'new' => 'new',

        'contacted' => 'contacted',

        'quotation_sent' => 'quotation',

        'accepted' => 'accepted',

        'payment_pending' => 'payment',

        'paid' => 'paid',

        'completed' => 'completed',

        'cancelled',
        'rejected' => 'cancelled',

        default => 'new'

    };
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Admin Dashboard | Khammam Auto
    </title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {
            margin: 0;

            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                Arial,
                sans-serif;

            background: #f5f7fb;

            color: #172033;
        }


        a {
            text-decoration: none;
            color: inherit;
        }


        .layout {
            min-height: 100vh;

            display: flex;
        }


        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            width: 250px;

            min-height: 100vh;

            background: #111827;

            color: #fff;

            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;

            padding: 25px 18px;

            z-index: 20;
        }


        .brand {
            padding: 8px 12px 28px;

            border-bottom: 1px solid
                rgba(255,255,255,.08);
        }


        .brand-name {
            font-size: 23px;
            font-weight: 900;

            letter-spacing: -.5px;
        }


        .brand-name span {
            color: #ef4444;
        }


        .brand-subtitle {
            margin-top: 5px;

            color: #9ca3af;

            font-size: 12px;
        }


        .menu-title {
            margin: 28px 12px 10px;

            color: #6b7280;

            font-size: 10px;

            font-weight: 800;

            letter-spacing: 1.2px;

            text-transform: uppercase;
        }


        .nav {
            display: flex;

            flex-direction: column;

            gap: 5px;
        }


        .nav a {
            display: flex;

            align-items: center;

            gap: 12px;

            padding: 12px 13px;

            border-radius: 9px;

            color: #cbd5e1;

            font-size: 13px;

            font-weight: 600;
        }


        .nav a:hover,
        .nav a.active {
            background: #ef2b2d;

            color: #fff;
        }


        .nav-icon {
            width: 20px;

            text-align: center;

            font-size: 15px;
        }


        .logout {
            position: absolute;

            left: 18px;
            right: 18px;
            bottom: 20px;
        }


        .logout a {
            color: #f87171;
        }


        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {
            width: calc(100% - 250px);

            margin-left: 250px;

            min-height: 100vh;
        }


        .topbar {
            height: 76px;

            background: #fff;

            border-bottom: 1px solid #e5e7eb;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 32px;
        }


        .topbar-title {
            font-size: 20px;

            font-weight: 800;
        }


        .topbar-subtitle {
            margin-top: 3px;

            color: #8a94a6;

            font-size: 12px;
        }


        .admin-user {
            display: flex;

            align-items: center;

            gap: 11px;
        }


        .avatar {
            width: 40px;
            height: 40px;

            border-radius: 50%;

            background: #111827;

            color: #fff;

            display: flex;

            align-items: center;
            justify-content: center;

            font-weight: 800;

            font-size: 14px;
        }


        .admin-name {
            font-size: 13px;

            font-weight: 800;
        }


        .admin-role {
            color: #8a94a6;

            font-size: 11px;

            margin-top: 2px;

            text-transform: capitalize;
        }


        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 30px 32px;
        }


        .welcome {
            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 25px;
        }


        .welcome h1 {
            margin: 0;

            font-size: 27px;

            font-weight: 850;
        }


        .welcome p {
            margin: 7px 0 0;

            color: #7c8799;

            font-size: 13px;
        }


        .request-btn {
            background: #ef2b2d;

            color: #fff;

            padding: 12px 18px;

            border-radius: 8px;

            font-size: 13px;

            font-weight: 800;
        }


        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        .stats {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 18px;

            margin-bottom: 25px;
        }


        .stat-card {
            background: #fff;

            border: 1px solid #e5e7eb;

            border-radius: 13px;

            padding: 20px;
        }


        .stat-title {
            color: #7c8799;

            font-size: 12px;

            font-weight: 700;
        }


        .stat-number {
            margin-top: 10px;

            font-size: 29px;

            font-weight: 850;
        }


        .stat-description {
            margin-top: 6px;

            color: #9aa3b2;

            font-size: 11px;
        }


        /*
        |--------------------------------------------------------------------------
        | Grid
        |--------------------------------------------------------------------------
        */

        .dashboard-grid {
            display: grid;

            grid-template-columns:
                minmax(0, 2fr)
                minmax(280px, 1fr);

            gap: 20px;
        }


        .panel {
            background: #fff;

            border: 1px solid #e5e7eb;

            border-radius: 13px;

            overflow: hidden;
        }


        .panel-header {
            padding: 18px 20px;

            border-bottom: 1px solid #edf0f4;

            display: flex;

            align-items: center;

            justify-content: space-between;
        }


        .panel-title {
            font-size: 15px;

            font-weight: 800;
        }


        .view-all {
            color: #ef2b2d;

            font-size: 12px;

            font-weight: 800;
        }


        /*
        |--------------------------------------------------------------------------
        | Table
        |--------------------------------------------------------------------------
        */

        .table-wrap {
            overflow-x: auto;
        }


        table {
            width: 100%;

            border-collapse: collapse;
        }


        th {
            background: #fafbfc;

            color: #8b95a7;

            font-size: 10px;

            font-weight: 800;

            text-align: left;

            padding: 12px 18px;

            text-transform: uppercase;
        }


        td {
            padding: 15px 18px;

            border-top: 1px solid #edf0f4;

            font-size: 12px;
        }


        .request-no {
            font-weight: 800;

            color: #172033;
        }


        .customer {
            font-weight: 700;
        }


        .part {
            font-weight: 700;
        }


        .status {
            display: inline-flex;

            padding: 5px 9px;

            border-radius: 20px;

            font-size: 10px;

            font-weight: 800;
        }


        .status.new {
            background: #eaf2ff;
            color: #2563eb;
        }


        .status.contacted {
            background: #fff7e6;
            color: #c47a00;
        }


        .status.quotation {
            background: #f1eaff;
            color: #7c3aed;
        }


        .status.accepted {
            background: #e9f8ef;
            color: #159447;
        }


        .status.payment,
        .status.paid {
            background: #e8f7f6;
            color: #087f78;
        }


        .status.completed {
            background: #e9f8ef;
            color: #159447;
        }


        .status.cancelled {
            background: #fff0f1;
            color: #d52f3f;
        }


        .view-btn {
            color: #ef2b2d;

            font-size: 11px;

            font-weight: 800;
        }


        /*
        |--------------------------------------------------------------------------
        | Quick Actions
        |--------------------------------------------------------------------------
        */

        .actions {
            padding: 18px;
        }


        .action {
            display: block;

            padding: 15px;

            border: 1px solid #e7eaf0;

            border-radius: 10px;

            margin-bottom: 10px;

            transition: .15s;
        }


        .action:last-child {
            margin-bottom: 0;
        }


        .action:hover {
            border-color: #ef2b2d;

            background: #fff8f8;
        }


        .action-title {
            font-size: 13px;

            font-weight: 800;
        }


        .action-description {
            margin-top: 4px;

            color: #8b95a7;

            font-size: 11px;
        }


        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1000px) {

            .stats {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }


            .dashboard-grid {
                grid-template-columns: 1fr;
            }

        }


        @media (max-width: 700px) {

            .sidebar {
                width: 210px;
            }


            .main {
                width: calc(100% - 210px);

                margin-left: 210px;
            }


            .topbar {
                padding: 0 18px;
            }


            .content {
                padding: 20px 18px;
            }


            .welcome {
                align-items: flex-start;

                flex-direction: column;

                gap: 15px;
            }


            .stats {
                grid-template-columns: 1fr;
            }

        }


        @media (max-width: 520px) {

            .sidebar {
                width: 190px;
            }


            .main {
                width: calc(100% - 190px);

                margin-left: 190px;
            }


            .brand-name {
                font-size: 18px;
            }


            .nav a {
                font-size: 11px;

                padding: 10px;
            }


            .topbar-title {
                font-size: 16px;
            }


            .admin-name,
            .admin-role {
                display: none;
            }

        }

    </style>

</head>


<body>


<div class="layout">


    <!-- SIDEBAR -->

    <aside class="sidebar">


        <div class="brand">

            <div class="brand-name">
                KHAMMAM <span>AUTO</span>
            </div>

            <div class="brand-subtitle">
                Admin Portal
            </div>

        </div>


        <div class="menu-title">
            Main Menu
        </div>


        <nav class="nav">

            <a
                href="/kmm-aut/admin/dashboard/"
                class="active"
            >
                <span class="nav-icon">⌂</span>
                Dashboard
            </a>


            <a
                href="/kmm-aut/admin/requests/"
            >
                <span class="nav-icon">▣</span>
                Requests
            </a>


            <a href="#">
                <span class="nav-icon">◉</span>
                Customers
            </a>


            <a href="#">
                <span class="nav-icon">◈</span>
                Quotations
            </a>


            <a href="#">
                <span class="nav-icon">₹</span>
                Payments
            </a>


            <a href="#">
                <span class="nav-icon">⚙</span>
                Settings
            </a>

        </nav>


        <div class="logout">

            <nav class="nav">

                <a
                    href="/kmm-aut/admin/auth/logout/"
                >
                    <span class="nav-icon">↪</span>
                    Logout
                </a>

            </nav>

        </div>


    </aside>


    <!-- MAIN -->

    <main class="main">


        <!-- TOP BAR -->

        <header class="topbar">


            <div>

                <div class="topbar-title">
                    Admin Dashboard
                </div>

                <div class="topbar-subtitle">
                    Manage Khammam Auto spare-part requests
                </div>

            </div>


            <div class="admin-user">

                <div>

                    <div class="admin-name">
                        <?= e($userName) ?>
                    </div>

                    <div class="admin-role">
                        <?= e($userRole) ?>
                    </div>

                </div>


                <div class="avatar">

                    <?= e(strtoupper(substr($userName, 0, 1))) ?>

                </div>

            </div>


        </header>


        <!-- CONTENT -->

        <section class="content">


            <div class="welcome">


                <div>

                    <h1>
                        Welcome, <?= e($userName) ?>
                    </h1>

                    <p>
                        Here's what's happening with your spare-part requests.
                    </p>

                </div>


                <a
                    href="/kmm-aut/admin/requests/"
                    class="request-btn"
                >
                    View Requests
                </a>


            </div>


            <!-- STATISTICS -->

            <div class="stats">


                <div class="stat-card">

                    <div class="stat-title">
                        Total Requests
                    </div>

                    <div class="stat-number">
                        <?= $totalRequests ?>
                    </div>

                    <div class="stat-description">
                        All customer requests
                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-title">
                        New Requests
                    </div>

                    <div class="stat-number">
                        <?= $newRequests ?>
                    </div>

                    <div class="stat-description">
                        Waiting for review
                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-title">
                        In Progress
                    </div>

                    <div class="stat-number">
                        <?= $inProgress ?>
                    </div>

                    <div class="stat-description">
                        Active requests
                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-title">
                        Completed
                    </div>

                    <div class="stat-number">
                        <?= $completed ?>
                    </div>

                    <div class="stat-description">
                        Successfully completed
                    </div>

                </div>


            </div>


            <!-- MAIN DASHBOARD -->

            <div class="dashboard-grid">


                <!-- RECENT REQUESTS -->

                <div class="panel">


                    <div class="panel-header">

                        <div class="panel-title">
                            Recent Requests
                        </div>

                        <a
                            href="/kmm-aut/admin/requests/"
                            class="view-all"
                        >
                            View All
                        </a>

                    </div>


                    <div class="table-wrap">


                        <?php if (!empty($recentRequests)): ?>


                            <table>

                                <thead>

                                    <tr>

                                        <th>
                                            Request
                                        </th>

                                        <th>
                                            Customer
                                        </th>

                                        <th>
                                            Part
                                        </th>

                                        <th>
                                            Status
                                        </th>

                                        <th>
                                            Action
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>


                                <?php foreach ($recentRequests as $request): ?>


                                    <tr>


                                        <td>

                                            <div class="request-no">

                                                <?= e($request['request_no']) ?>

                                            </div>

                                        </td>


                                        <td>

                                            <div class="customer">

                                                <?= e($request['customer_name'] ?? 'Unknown') ?>

                                            </div>

                                            <?php if (!empty($request['customer_phone'])): ?>

                                                <div
                                                    style="
                                                        color:#9aa3b2;
                                                        font-size:10px;
                                                        margin-top:3px;
                                                    "
                                                >
                                                    <?= e($request['customer_phone']) ?>
                                                </div>

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            <div class="part">

                                                <?= e($request['part_required']) ?>

                                            </div>

                                            <div
                                                style="
                                                    color:#9aa3b2;
                                                    font-size:10px;
                                                    margin-top:3px;
                                                "
                                            >
                                                Qty:
                                                <?= (int) $request['quantity'] ?>
                                            </div>

                                        </td>


                                        <td>

                                            <span
                                                class="status <?= e(statusClass($request['status'])) ?>"
                                            >
                                                <?= e(statusLabel($request['status'])) ?>
                                            </span>

                                        </td>


                                        <td>

                                            <a
                                                href="/kmm-aut/admin/requests/view.php?id=<?= (int) $request['id'] ?>"
                                                class="view-btn"
                                            >
                                                View
                                            </a>

                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                                </tbody>

                            </table>


                        <?php else: ?>


                            <div
                                style="
                                    padding:55px 20px;
                                    text-align:center;
                                    color:#8b95a7;
                                    font-size:13px;
                                "
                            >

                                No requests found.

                            </div>


                        <?php endif; ?>


                    </div>


                </div>


                <!-- QUICK ACTIONS -->

                <div class="panel">


                    <div class="panel-header">

                        <div class="panel-title">
                            Quick Actions
                        </div>

                    </div>


                    <div class="actions">


                        <a
                            href="/kmm-aut/admin/requests/"
                            class="action"
                        >

                            <div class="action-title">
                                Manage Requests
                            </div>

                            <div class="action-description">
                                Review and update customer requests
                            </div>

                        </a>


                        <a
                            href="/kmm-aut/admin/requests/?status=new"
                            class="action"
                        >

                            <div class="action-title">
                                New Requests
                            </div>

                            <div class="action-description">
                                <?= $newRequests ?>
                                requests waiting for review
                            </div>

                        </a>


                        <a
                            href="#"
                            class="action"
                        >

                            <div class="action-title">
                                Customers
                            </div>

                            <div class="action-description">
                                <?= $totalCustomers ?>
                                registered customers
                            </div>

                        </a>


                    </div>


                </div>


            </div>


        </section>


    </main>


</div>


</body>

</html>