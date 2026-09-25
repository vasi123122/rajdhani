<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();

require_once __DIR__ . '/../../../database/database.php';


/*
|--------------------------------------------------------------------------
| CUSTOMER LOGIN CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header('Location: /kmm-aut/public/pages/login/');
    exit;
}

$customerId = (int) $_SESSION['user_id'];
$customerName = $_SESSION['user_name'] ?? 'Customer';


/*
|--------------------------------------------------------------------------
| DASHBOARD DATA
|--------------------------------------------------------------------------
*/

$totalRequests = 0;
$inProgress = 0;
$completed = 0;
$cancelled = 0;
$recentRequests = [];
$dbError = '';


try {

    /*
    |--------------------------------------------------------------------------
    | TOTAL REQUESTS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM spare_part_requests
        WHERE customer_id = :customer_id
    ");

    $stmt->execute([
        ':customer_id' => $customerId
    ]);

    $totalRequests = (int) $stmt->fetch()['total'];


    /*
    |--------------------------------------------------------------------------
    | IN PROGRESS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM spare_part_requests
        WHERE customer_id = :customer_id
        AND status IN (
            'new',
            'contacted',
            'quotation_sent',
            'accepted',
            'payment_pending',
            'paid'
        )
    ");

    $stmt->execute([
        ':customer_id' => $customerId
    ]);

    $inProgress = (int) $stmt->fetch()['total'];


    /*
    |--------------------------------------------------------------------------
    | COMPLETED
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM spare_part_requests
        WHERE customer_id = :customer_id
        AND status = 'completed'
    ");

    $stmt->execute([
        ':customer_id' => $customerId
    ]);

    $completed = (int) $stmt->fetch()['total'];


    /*
    |--------------------------------------------------------------------------
    | CANCELLED / REJECTED
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM spare_part_requests
        WHERE customer_id = :customer_id
        AND status IN ('cancelled', 'rejected')
    ");

    $stmt->execute([
        ':customer_id' => $customerId
    ]);

    $cancelled = (int) $stmt->fetch()['total'];


    /*
    |--------------------------------------------------------------------------
    | RECENT REQUESTS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            request_no,
            part_required,
            quantity,
            status,
            created_at
        FROM spare_part_requests
        WHERE customer_id = :customer_id
        ORDER BY created_at DESC
        LIMIT 8
    ");

    $stmt->execute([
        ':customer_id' => $customerId
    ]);

    $recentRequests = $stmt->fetchAll();


} catch (PDOException $e) {

    $dbError = $e->getMessage();

}


/*
|--------------------------------------------------------------------------
| STATUS LABEL
|--------------------------------------------------------------------------
*/

function statusLabel($status)
{
    return match ($status) {

        'new' =>
            'New',

        'contacted' =>
            'Contacted',

        'quotation_sent' =>
            'Quotation Sent',

        'accepted' =>
            'Accepted',

        'payment_pending' =>
            'Payment Pending',

        'paid' =>
            'Paid',

        'completed' =>
            'Completed',

        'cancelled' =>
            'Cancelled',

        'rejected' =>
            'Rejected',

        default =>
            ucfirst(str_replace('_', ' ', $status))
    };
}


/*
|--------------------------------------------------------------------------
| STATUS CSS CLASS
|--------------------------------------------------------------------------
*/

function statusClass($status)
{
    return match ($status) {

        'new' =>
            'status-new',

        'contacted' =>
            'status-contacted',

        'quotation_sent' =>
            'status-quotation',

        'accepted' =>
            'status-accepted',

        'payment_pending' =>
            'status-payment',

        'paid' =>
            'status-paid',

        'completed' =>
            'status-completed',

        'cancelled',
        'rejected' =>
            'status-cancelled',

        default =>
            'status-new'
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

    <title>Customer Dashboard | Khammam Auto</title>


    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }


        body {

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f5f7fb;

            color: #172033;

            min-height: 100vh;
        }


        a {
            text-decoration: none;
        }


        /*
        |--------------------------------------------------------------------------
        | LAYOUT
        |--------------------------------------------------------------------------
        */

        .app {

            display: flex;

            min-height: 100vh;
        }


        /*
        |--------------------------------------------------------------------------
        | SIDEBAR
        |--------------------------------------------------------------------------
        */

        .sidebar {

            width: 250px;

            background: #111827;

            color: #ffffff;

            position: fixed;

            top: 0;
            left: 0;
            bottom: 0;

            padding: 24px 16px;

            z-index: 100;
        }


        .logo {

            padding: 5px 12px 28px;

            border-bottom:
                1px solid
                rgba(255,255,255,0.08);

            margin-bottom: 24px;
        }


        .logo h1 {

            font-size: 21px;

            font-weight: 800;

            letter-spacing: -0.4px;
        }


        .logo h1 span {

            color: #ef2b2d;
        }


        .logo p {

            color: #9ca3af;

            font-size: 11px;

            margin-top: 5px;
        }


        .menu-title {

            color: #6b7280;

            font-size: 10px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: 1px;

            padding: 0 12px;

            margin-bottom: 8px;
        }


        .nav {

            display: flex;

            flex-direction: column;

            gap: 4px;
        }


        .nav a {

            display: flex;

            align-items: center;

            gap: 12px;

            color: #d1d5db;

            padding: 12px;

            border-radius: 8px;

            font-size: 14px;

            transition: 0.2s;
        }


        .nav a:hover {

            background: #1f2937;

            color: #ffffff;
        }


        .nav a.active {

            background: #ef2b2d;

            color: #ffffff;
        }


        .nav-icon {

            width: 22px;

            text-align: center;

            font-size: 16px;
        }


        .sidebar-bottom {

            position: absolute;

            left: 16px;
            right: 16px;

            bottom: 20px;
        }


        .logout {

            display: flex;

            align-items: center;

            gap: 12px;

            color: #fca5a5;

            padding: 12px;

            border-radius: 8px;

            font-size: 14px;
        }


        .logout:hover {

            background: #1f2937;

            color: #ffffff;
        }


        /*
        |--------------------------------------------------------------------------
        | MAIN
        |--------------------------------------------------------------------------
        */

        .main {

            margin-left: 250px;

            width: calc(100% - 250px);

            min-height: 100vh;
        }


        /*
        |--------------------------------------------------------------------------
        | TOPBAR
        |--------------------------------------------------------------------------
        */

        .topbar {

            height: 72px;

            background: #ffffff;

            border-bottom:
                1px solid #e5e7eb;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 32px;
        }


        .topbar-left h2 {

            font-size: 19px;

            color: #111827;
        }


        .topbar-left p {

            color: #6b7280;

            font-size: 12px;

            margin-top: 3px;
        }


        .customer {

            display: flex;

            align-items: center;

            gap: 10px;
        }


        .avatar {

            width: 38px;

            height: 38px;

            border-radius: 50%;

            background: #fee2e2;

            color: #dc2626;

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: 800;

            font-size: 14px;
        }


        .customer-name {

            font-size: 13px;

            font-weight: 700;

            color: #111827;
        }


        .customer-role {

            font-size: 11px;

            color: #6b7280;

            margin-top: 2px;
        }


        /*
        |--------------------------------------------------------------------------
        | CONTENT
        |--------------------------------------------------------------------------
        */

        .content {

            padding: 30px 32px;
        }


        .welcome {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 25px;
        }


        .welcome h1 {

            font-size: 26px;

            color: #111827;

            margin-bottom: 6px;
        }


        .welcome p {

            color: #6b7280;

            font-size: 14px;
        }


        .request-btn {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 8px;

            background: #ef2b2d;

            color: #ffffff;

            padding: 12px 18px;

            border-radius: 8px;

            font-size: 14px;

            font-weight: 700;

            transition: 0.2s;
        }


        .request-btn:hover {

            background: #d92325;
        }


        /*
        |--------------------------------------------------------------------------
        | STATISTICS
        |--------------------------------------------------------------------------
        */

        .stats {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 18px;

            margin-bottom: 28px;
        }


        .stat-card {

            background: #ffffff;

            border:
                1px solid #e5e7eb;

            border-radius: 12px;

            padding: 20px;
        }


        .stat-top {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 14px;
        }


        .stat-title {

            color: #6b7280;

            font-size: 13px;

            font-weight: 600;
        }


        .stat-icon {

            width: 38px;

            height: 38px;

            border-radius: 9px;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 17px;
        }


        .icon-blue {

            background: #eff6ff;

            color: #2563eb;
        }


        .icon-orange {

            background: #fff7ed;

            color: #ea580c;
        }


        .icon-green {

            background: #ecfdf5;

            color: #059669;
        }


        .icon-red {

            background: #fef2f2;

            color: #dc2626;
        }


        .stat-number {

            font-size: 27px;

            font-weight: 800;

            color: #111827;
        }


        /*
        |--------------------------------------------------------------------------
        | MAIN GRID
        |--------------------------------------------------------------------------
        */

        .dashboard-grid {

            display: grid;

            grid-template-columns:
                minmax(0, 1fr)
                300px;

            gap: 20px;
        }


        .panel {

            background: #ffffff;

            border:
                1px solid #e5e7eb;

            border-radius: 12px;

            overflow: hidden;
        }


        .panel-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 18px 20px;

            border-bottom:
                1px solid #e5e7eb;
        }


        .panel-header h3 {

            font-size: 16px;

            color: #111827;
        }


        .panel-header a {

            color: #ef2b2d;

            font-size: 12px;

            font-weight: 700;
        }


        /*
        |--------------------------------------------------------------------------
        | TABLE
        |--------------------------------------------------------------------------
        */

        .table-wrapper {

            overflow-x: auto;
        }


        table {

            width: 100%;

            border-collapse: collapse;
        }


        th {

            text-align: left;

            background: #f9fafb;

            color: #6b7280;

            font-size: 11px;

            font-weight: 700;

            padding: 12px 18px;

            white-space: nowrap;
        }


        td {

            padding: 14px 18px;

            border-top:
                1px solid #f0f1f3;

            font-size: 13px;

            color: #374151;

            white-space: nowrap;
        }


        .request-no {

            color: #111827;

            font-weight: 700;
        }


        .part-name {

            color: #111827;

            font-weight: 600;
        }


        .date {

            color: #6b7280;

            font-size: 12px;
        }


        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        .status {

            display: inline-flex;

            align-items: center;

            padding: 5px 9px;

            border-radius: 999px;

            font-size: 10px;

            font-weight: 700;
        }


        .status-new {

            background: #eff6ff;

            color: #2563eb;
        }


        .status-contacted {

            background: #fff7ed;

            color: #c2410c;
        }


        .status-quotation {

            background: #f5f3ff;

            color: #7c3aed;
        }


        .status-accepted {

            background: #ecfeff;

            color: #0891b2;
        }


        .status-payment {

            background: #fefce8;

            color: #a16207;
        }


        .status-paid {

            background: #ecfdf5;

            color: #047857;
        }


        .status-completed {

            background: #ecfdf5;

            color: #059669;
        }


        .status-cancelled {

            background: #fef2f2;

            color: #dc2626;
        }


        /*
        |--------------------------------------------------------------------------
        | EMPTY STATE
        |--------------------------------------------------------------------------
        */

        .empty {

            text-align: center;

            padding: 50px 20px;
        }


        .empty-icon {

            font-size: 35px;

            margin-bottom: 10px;
        }


        .empty h4 {

            color: #111827;

            font-size: 15px;

            margin-bottom: 6px;
        }


        .empty p {

            color: #6b7280;

            font-size: 12px;

            margin-bottom: 17px;
        }


        /*
        |--------------------------------------------------------------------------
        | QUICK ACTIONS
        |--------------------------------------------------------------------------
        */

        .quick-actions {

            padding: 16px;
        }


        .quick-action {

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 13px;

            border:
                1px solid #e5e7eb;

            border-radius: 9px;

            margin-bottom: 9px;

            color: #374151;

            transition: 0.2s;
        }


        .quick-action:last-child {

            margin-bottom: 0;
        }


        .quick-action:hover {

            border-color: #ef2b2d;

            background: #fffafa;
        }


        .quick-icon {

            width: 34px;

            height: 34px;

            border-radius: 8px;

            background: #fef2f2;

            color: #ef2b2d;

            display: flex;

            align-items: center;

            justify-content: center;
        }


        .quick-text strong {

            display: block;

            font-size: 12px;

            color: #111827;

            margin-bottom: 2px;
        }


        .quick-text span {

            font-size: 10px;

            color: #6b7280;
        }


        /*
        |--------------------------------------------------------------------------
        | INFO PANEL
        |--------------------------------------------------------------------------
        */

        .info-panel {

            margin-top: 20px;

            padding: 18px;

            background: #eff6ff;

            border:
                1px solid #dbeafe;

            border-radius: 10px;
        }


        .info-panel h4 {

            color: #1e40af;

            font-size: 13px;

            margin-bottom: 6px;
        }


        .info-panel p {

            color: #475569;

            font-size: 11px;

            line-height: 1.6;
        }


        /*
        |--------------------------------------------------------------------------
        | MOBILE MENU
        |--------------------------------------------------------------------------
        */

        .mobile-menu {

            display: none;

            border: 0;

            background: transparent;

            font-size: 22px;

            cursor: pointer;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1050px) {

            .stats {

                grid-template-columns:
                    repeat(2, 1fr);
            }


            .dashboard-grid {

                grid-template-columns: 1fr;
            }
        }


        @media (max-width: 760px) {

            .sidebar {

                transform:
                    translateX(-100%);

                transition: 0.25s;
            }


            .sidebar.open {

                transform:
                    translateX(0);
            }


            .main {

                margin-left: 0;

                width: 100%;
            }


            .mobile-menu {

                display: block;
            }


            .topbar {

                padding:
                    0 18px;
            }


            .content {

                padding:
                    22px 18px;
            }


            .welcome {

                align-items: flex-start;

                flex-direction: column;

                gap: 15px;
            }


            .stats {

                grid-template-columns: 1fr;
            }


            .customer-info {

                display: none;
            }
        }


        @media (max-width: 480px) {

            .welcome h1 {

                font-size: 22px;
            }


            .stat-card {

                padding: 16px;
            }


            .topbar-left h2 {

                font-size: 16px;
            }


            .request-btn {

                width: 100%;
            }
        }

    </style>

</head>


<body>


<div class="app">


    <!-- =========================================================
         SIDEBAR
    ========================================================== -->

    <aside class="sidebar" id="sidebar">


        <div class="logo">

            <h1>
                KHAMMAM <span>AUTO</span>
            </h1>

            <p>
                Customer Portal
            </p>

        </div>


        <div class="menu-title">
            MAIN MENU
        </div>


        <nav class="nav">


            <!-- DASHBOARD -->

            <a
                href="/kmm-aut/public/pages/dashboard/"
                class="active"
            >

                <span class="nav-icon">⌂</span>

                Dashboard

            </a>


            <!-- REQUEST A PART -->

            <a
                href="/kmm-aut/public/pages/request/"
            >

                <span class="nav-icon">＋</span>

                Request a Part

            </a>


            <!-- MY REQUESTS -->

            <a
                href="/kmm-aut/public/pages/my-requests/"
            >

                <span class="nav-icon">▣</span>

                My Requests

            </a>


            <!-- MY PROFILE -->

            <a
                href="#"
            >

                <span class="nav-icon">♙</span>

                My Profile

            </a>


            <!-- NOTIFICATIONS -->

            <a
                href="#"
            >

                <span class="nav-icon">●</span>

                Notifications

            </a>


        </nav>


        <!-- LOGOUT -->

        <div class="sidebar-bottom">

            <a
                href="/kmm-aut/public/pages/logout/"
                class="logout"
            >

                <span class="nav-icon">↪</span>

                Logout

            </a>

        </div>


    </aside>



    <!-- =========================================================
         MAIN
    ========================================================== -->

    <main class="main">


        <!-- TOPBAR -->

        <header class="topbar">


            <div class="topbar-left">

                <button
                    class="mobile-menu"
                    onclick="toggleSidebar()"
                    type="button"
                >
                    ☰
                </button>

                <h2>
                    Customer Dashboard
                </h2>

                <p>
                    Manage your spare part requests
                </p>

            </div>


            <div class="customer">


                <div class="avatar">

                    <?= htmlspecialchars(
                        strtoupper(
                            substr($customerName, 0, 1)
                        )
                    ) ?>

                </div>


                <div class="customer-info">

                    <div class="customer-name">

                        <?= htmlspecialchars(
                            $customerName
                        ) ?>

                    </div>

                    <div class="customer-role">
                        Customer
                    </div>

                </div>


            </div>


        </header>



        <!-- CONTENT -->

        <section class="content">


            <!-- =================================================
                 WELCOME
            ================================================== -->

            <div class="welcome">


                <div>

                    <h1>

                        Welcome,
                        <?= htmlspecialchars(
                            $customerName
                        ) ?>

                        👋

                    </h1>


                    <p>

                        Submit a spare part request
                        and track its progress here.

                    </p>

                </div>


                <a
                    href="/kmm-aut/public/pages/request/"
                    class="request-btn"
                >

                    ＋ Request a Spare Part

                </a>


            </div>



            <!-- =================================================
                 STAT CARDS
            ================================================== -->

            <div class="stats">


                <!-- TOTAL -->

                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-title">
                            Total Requests
                        </div>

                        <div class="stat-icon icon-blue">
                            ▣
                        </div>

                    </div>

                    <div class="stat-number">
                        <?= $totalRequests ?>
                    </div>

                </div>


                <!-- IN PROGRESS -->

                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-title">
                            In Progress
                        </div>

                        <div class="stat-icon icon-orange">
                            ◷
                        </div>

                    </div>

                    <div class="stat-number">
                        <?= $inProgress ?>
                    </div>

                </div>


                <!-- COMPLETED -->

                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-title">
                            Completed
                        </div>

                        <div class="stat-icon icon-green">
                            ✓
                        </div>

                    </div>

                    <div class="stat-number">
                        <?= $completed ?>
                    </div>

                </div>


                <!-- CANCELLED -->

                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-title">
                            Cancelled
                        </div>

                        <div class="stat-icon icon-red">
                            ×
                        </div>

                    </div>

                    <div class="stat-number">
                        <?= $cancelled ?>
                    </div>

                </div>


            </div>



            <!-- =================================================
                 DASHBOARD GRID
            ================================================== -->

            <div class="dashboard-grid">


                <!-- =================================================
                     RECENT REQUESTS
                ================================================== -->

                <div class="panel">


                    <div class="panel-header">

                        <h3>
                            Recent Requests
                        </h3>


                        <a
                            href="/kmm-aut/public/pages/my-requests/"
                        >
                            View All
                        </a>

                    </div>


                    <?php if (!empty($recentRequests)): ?>


                        <div class="table-wrapper">

                            <table>

                                <thead>

                                    <tr>

                                        <th>
                                            REQUEST ID
                                        </th>

                                        <th>
                                            PART
                                        </th>

                                        <th>
                                            QTY
                                        </th>

                                        <th>
                                            STATUS
                                        </th>

                                        <th>
                                            DATE
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>


                                <?php foreach (
                                    $recentRequests
                                    as $request
                                ): ?>


                                    <tr>


                                        <td>

                                            <span
                                                class="request-no"
                                            >

                                                <?= htmlspecialchars(
                                                    $request['request_no']
                                                ) ?>

                                            </span>

                                        </td>


                                        <td>

                                            <span
                                                class="part-name"
                                            >

                                                <?= htmlspecialchars(
                                                    $request['part_required']
                                                ) ?>

                                            </span>

                                        </td>


                                        <td>

                                            <?= (int)
                                                $request['quantity'] ?>

                                        </td>


                                        <td>

                                            <span
                                                class="status <?= statusClass(
                                                    $request['status']
                                                ) ?>"
                                            >

                                                <?= htmlspecialchars(
                                                    statusLabel(
                                                        $request['status']
                                                    )
                                                ) ?>

                                            </span>

                                        </td>


                                        <td>

                                            <span
                                                class="date"
                                            >

                                                <?= date(
                                                    'd M Y',
                                                    strtotime(
                                                        $request['created_at']
                                                    )
                                                ) ?>

                                            </span>

                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                                </tbody>

                            </table>

                        </div>


                    <?php else: ?>


                        <div class="empty">


                            <div class="empty-icon">
                                🔧
                            </div>


                            <h4>
                                No requests yet
                            </h4>


                            <p>
                                You haven't submitted
                                any spare part requests.
                            </p>


                            <a
                                href="/kmm-aut/public/pages/request/"
                                class="request-btn"
                            >
                                Request a Part
                            </a>


                        </div>


                    <?php endif; ?>


                </div>



                <!-- =================================================
                     QUICK ACTIONS
                ================================================== -->

                <div>


                    <div class="panel">


                        <div class="panel-header">

                            <h3>
                                Quick Actions
                            </h3>

                        </div>


                        <div class="quick-actions">


                            <!-- REQUEST PART -->

                            <a
                                href="/kmm-aut/public/pages/request/"
                                class="quick-action"
                            >

                                <div class="quick-icon">
                                    ＋
                                </div>


                                <div class="quick-text">

                                    <strong>
                                        Request a Part
                                    </strong>

                                    <span>
                                        Tell us what you need
                                    </span>

                                </div>

                            </a>



                            <!-- MY REQUESTS -->

                            <a
                                href="/kmm-aut/public/pages/my-requests/"
                                class="quick-action"
                            >

                                <div class="quick-icon">
                                    ▣
                                </div>


                                <div class="quick-text">

                                    <strong>
                                        My Requests
                                    </strong>

                                    <span>
                                        Track your requests
                                    </span>

                                </div>

                            </a>



                            <!-- MY PROFILE -->

                            <a
                                href="#"
                                class="quick-action"
                            >

                                <div class="quick-icon">
                                    ♙
                                </div>


                                <div class="quick-text">

                                    <strong>
                                        My Profile
                                    </strong>

                                    <span>
                                        Update your details
                                    </span>

                                </div>

                            </a>


                        </div>


                    </div>



                    <!-- INFO -->

                    <div class="info-panel">

                        <h4>
                            How Khammam Auto Works
                        </h4>

                        <p>

                            Submit your spare part request.
                            Our team reviews the requirement,
                            contacts you, sends a quotation,
                            and helps complete the request.

                        </p>

                    </div>


                </div>


            </div>


        </section>


    </main>


</div>



<script>

function toggleSidebar()
{
    const sidebar =
        document.getElementById('sidebar');

    sidebar.classList.toggle('open');
}

</script>


</body>

</html>