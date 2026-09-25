<?php

session_start();

require_once __DIR__ . '/../../database/database.php';

/*
|--------------------------------------------------------------------------
| ADMIN ACCESS
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    (int) $_SESSION['user_id'] <= 0 ||
    !isset($_SESSION['user_role']) ||
    !in_array($_SESSION['user_role'], ['admin', 'staff'], true)
) {
    header('Location: /kmm-aut/admin/auth/login/');
    exit;
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function statusLabel($status)
{
    $labels = [
        'new'              => 'New',
        'contacted'        => 'Contacted',
        'quotation_sent'   => 'Quotation Sent',
        'accepted'         => 'Accepted',
        'payment_pending'  => 'Payment Pending',
        'paid'             => 'Paid',
        'completed'        => 'Completed',
        'cancelled'        => 'Cancelled',
        'rejected'         => 'Rejected'
    ];

    return $labels[$status]
        ?? ucfirst(str_replace('_', ' ', $status));
}

function statusClass($status)
{
    $classes = [
        'new'              => 'new',
        'contacted'        => 'contacted',
        'quotation_sent'   => 'quotation',
        'accepted'         => 'accepted',
        'payment_pending'  => 'payment',
        'paid'             => 'paid',
        'completed'        => 'completed',
        'cancelled'        => 'cancelled',
        'rejected'         => 'rejected'
    ];

    return $classes[$status] ?? 'default';
}

function formatDate($date)
{
    if (!$date) {
        return '-';
    }

    $time = strtotime($date);

    if (!$time) {
        return $date;
    }

    return date('d M Y, h:i A', $time);
}


/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/

$allowedFilters = [
    'all',
    'new',
    'contacted',
    'quotation_sent',
    'accepted',
    'payment_pending',
    'paid',
    'completed',
    'cancelled',
    'rejected'
];

$filter = $_GET['status'] ?? 'all';

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');


/*
|--------------------------------------------------------------------------
| REQUEST QUERY
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        r.id,
        r.request_no,
        r.part_required,
        r.quantity,
        r.quality_preference,
        r.status,
        r.created_at,

        u.name AS customer_name,
        u.phone AS customer_phone,
        u.email AS customer_email,

        sb.name AS brand_name,
        sm.name AS model_name,

        GROUP_CONCAT(
            DISTINCT pc.name
            ORDER BY pc.name
            SEPARATOR ', '
        ) AS category_names

    FROM spare_part_requests r

    INNER JOIN users u
        ON u.id = r.customer_id

    LEFT JOIN scooter_brands sb
        ON sb.id = r.brand_id

    LEFT JOIN scooter_models sm
        ON sm.id = r.model_id

    LEFT JOIN request_categories rc
        ON rc.request_id = r.id

    LEFT JOIN part_categories pc
        ON pc.id = rc.category_id

    WHERE 1 = 1
";

$params = [];


/*
|--------------------------------------------------------------------------
| STATUS FILTER
|--------------------------------------------------------------------------
*/

if ($filter !== 'all') {

    $sql .= "
        AND r.status = ?
    ";

    $params[] = $filter;
}


/*
|--------------------------------------------------------------------------
| SEARCH FILTER
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $sql .= "
        AND (
            r.request_no LIKE ?
            OR r.part_required LIKE ?
            OR u.name LIKE ?
            OR u.phone LIKE ?
            OR u.email LIKE ?
            OR sb.name LIKE ?
            OR sm.name LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}


$sql .= "
    GROUP BY
        r.id,
        r.request_no,
        r.part_required,
        r.quantity,
        r.quality_preference,
        r.status,
        r.created_at,
        u.name,
        u.phone,
        u.email,
        sb.name,
        sm.name

    ORDER BY
        r.created_at DESC,
        r.id DESC
";


$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$requests = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$countStmt = $pdo->query("
    SELECT
        status,
        COUNT(*) AS total
    FROM spare_part_requests
    GROUP BY status
");

$statusCounts = [];

foreach ($countStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}

$totalRequests = array_sum($statusCounts);

$adminName = $_SESSION['user_name'] ?? 'Admin';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Requests - Admin | Khammam Auto</title>

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

        button,
        input,
        select {
            font: inherit;
        }


        /* SIDEBAR */

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: 250px;
            background: #111827;
            color: #fff;
            padding: 22px 16px;
            z-index: 100;
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 5px 9px 27px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            background: #fff;
            color: #111827;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 900;
        }

        .brand-text strong {
            display: block;
            font-size: 17px;
        }

        .brand-text span {
            display: block;
            margin-top: 3px;
            color: #9ca3af;
            font-size: 11px;
        }

        .nav-title {
            margin: 25px 10px 9px;
            color: #6b7280;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .1em;
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
            gap: 11px;
            padding: 12px;
            border-radius: 9px;
            color: #c5cad4;
            font-size: 13px;
            font-weight: 650;
        }

        .nav a:hover {
            background: rgba(255,255,255,.06);
            color: #fff;
        }

        .nav a.active {
            background: #fff;
            color: #111827;
        }

        .nav-icon {
            width: 21px;
            text-align: center;
            font-size: 15px;
        }


        /* MAIN */

        .main {
            margin-left: 250px;
            min-height: 100vh;
        }

        .topbar {
            height: 70px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        .topbar-title {
            font-size: 18px;
            font-weight: 800;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .admin-name {
            color: #667085;
            font-size: 13px;
        }

        .avatar {
            width: 37px;
            height: 37px;
            border-radius: 50%;
            background: #111827;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }


        /* CONTENT */

        .content {
            max-width: 1500px;
            margin: auto;
            padding: 30px;
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-header h1 {
            margin: 0 0 7px;
            font-size: 27px;
        }

        .page-header p {
            margin: 0;
            color: #7b8495;
            font-size: 13px;
        }


        /* STATS */

        .stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 13px;
            padding: 18px;
        }

        .stat-label {
            color: #7b8495;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .stat-number {
            margin-top: 8px;
            font-size: 27px;
            font-weight: 850;
        }


        /* FILTERS */

        .filter-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 13px;
            padding: 18px;
            margin-bottom: 18px;
        }

        .filter-row {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 260px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            height: 42px;
            padding: 0 14px;
            border: 1px solid #dfe3e8;
            border-radius: 9px;
            outline: none;
            color: #1f2937;
            background: #fff;
        }

        .search-box input:focus {
            border-color: #111827;
        }

        .search-btn {
            height: 42px;
            border: 0;
            border-radius: 9px;
            background: #111827;
            color: #fff;
            padding: 0 18px;
            font-size: 13px;
            font-weight: 750;
            cursor: pointer;
        }

        .clear-btn {
            height: 42px;
            padding: 0 14px;
            border: 1px solid #dfe3e8;
            border-radius: 9px;
            background: #fff;
            color: #667085;
            font-size: 13px;
            font-weight: 700;
        }

        .status-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 15px;
        }

        .filter {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 12px;
            border: 1px solid #dfe3e8;
            border-radius: 8px;
            background: #fff;
            color: #667085;
            font-size: 11px;
            font-weight: 700;
        }

        .filter:hover {
            background: #f7f8fa;
        }

        .filter.active {
            background: #111827;
            border-color: #111827;
            color: #fff;
        }

        .filter-count {
            opacity: .7;
        }


        /* TABLE */

        .table-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 17px 19px;
            border-bottom: 1px solid #e5e7eb;
        }

        .table-header h2 {
            margin: 0;
            font-size: 16px;
        }

        .result-count {
            color: #7b8495;
            font-size: 12px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1150px;
            border-collapse: collapse;
        }

        th {
            background: #fafbfc;
            color: #8b93a2;
            font-size: 10px;
            font-weight: 800;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: 13px 15px;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }

        td {
            padding: 14px 15px;
            border-bottom: 1px solid #edf0f4;
            color: #344054;
            font-size: 12px;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #fafbfc;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        .request-no {
            font-family: monospace;
            font-size: 11px;
            font-weight: 800;
            color: #111827;
            white-space: nowrap;
        }

        .customer-name-cell {
            font-weight: 750;
            color: #202939;
        }

        .customer-phone {
            margin-top: 3px;
            color: #8a93a3;
            font-size: 11px;
        }

        .part-name {
            color: #202939;
            font-weight: 750;
        }

        .category {
            color: #667085;
            font-size: 11px;
        }

        .scooter {
            color: #667085;
            font-size: 11px;
            line-height: 1.5;
        }

        .quality {
            font-size: 11px;
            color: #667085;
        }

        .status {
            display: inline-flex;
            padding: 6px 9px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
        }

        .status.new {
            background: #eaf2ff;
            color: #1769d2;
        }

        .status.contacted {
            background: #fff4d8;
            color: #9b6400;
        }

        .status.quotation {
            background: #f0eaff;
            color: #6941c6;
        }

        .status.accepted {
            background: #e8f8ef;
            color: #16834b;
        }

        .status.payment {
            background: #fff0e5;
            color: #bd4e0b;
        }

        .status.paid,
        .status.completed {
            background: #e7f8ee;
            color: #157347;
        }

        .status.cancelled,
        .status.rejected {
            background: #ffebed;
            color: #c92a3b;
        }

        .status.default {
            background: #eef0f4;
            color: #596273;
        }

        .view-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 8px;
            background: #111827;
            color: #fff;
            font-size: 11px;
            font-weight: 750;
            white-space: nowrap;
        }

        .view-btn:hover {
            background: #273244;
        }


        /* EMPTY */

        .empty {
            text-align: center;
            padding: 70px 20px;
        }

        .empty-icon {
            font-size: 36px;
            margin-bottom: 12px;
        }

        .empty h3 {
            margin: 0 0 7px;
            font-size: 17px;
        }

        .empty p {
            margin: 0;
            color: #7b8495;
            font-size: 13px;
        }


        /* MOBILE */

        .mobile-header {
            display: none;
        }

        @media (max-width: 1100px) {

            .stats {
                grid-template-columns: repeat(3, 1fr);
            }

        }

        @media (max-width: 850px) {

            .sidebar {
                transform: translateX(-100%);
                transition: .25s ease;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .mobile-header {
                height: 62px;
                background: #111827;
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0 18px;
            }

            .menu-btn {
                border: 0;
                background: transparent;
                color: #fff;
                font-size: 23px;
                cursor: pointer;
            }

            .topbar {
                display: none;
            }

            .content {
                padding: 22px 16px 45px;
            }

        }

        @media (max-width: 600px) {

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .page-header {
                flex-direction: column;
            }

            .page-header h1 {
                font-size: 23px;
            }

            .search-box {
                min-width: 100%;
            }

        }

    </style>

</head>


<body>


<!-- MOBILE HEADER -->

<div class="mobile-header">

    <button
        type="button"
        class="menu-btn"
        onclick="toggleSidebar()"
    >
        ☰
    </button>

    <strong>
        Khammam Auto Admin
    </strong>

    <span></span>

</div>


<!-- SIDEBAR -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="brand">

        <div class="brand-icon">
            KA
        </div>

        <div class="brand-text">

            <strong>
                Khammam Auto
            </strong>

            <span>
                Admin Panel
            </span>

        </div>

    </div>


    <div class="nav-title">
        Main Menu
    </div>


    <nav class="nav">

        <a href="/kmm-aut/admin/dashboard/">

            <span class="nav-icon">
                ⌂
            </span>

            Dashboard

        </a>


        <a
            href="/kmm-aut/admin/requests/"
            class="active"
        >

            <span class="nav-icon">
                ▤
            </span>

            Requests

        </a>


        <a href="#">

            <span class="nav-icon">
                👥
            </span>

            Customers

        </a>


        <a href="#">

            <span class="nav-icon">
                💰
            </span>

            Quotations

        </a>


        <a href="#">

            <span class="nav-icon">
                💳
            </span>

            Payments

        </a>


        <a href="#">

            <span class="nav-icon">
                🔔
            </span>

            Notifications

        </a>


        <a href="#">

            <span class="nav-icon">
                ⚙
            </span>

            Settings

        </a>


        <a href="/kmm-aut/admin/auth/logout/">

            <span class="nav-icon">
                ↪
            </span>

            Logout

        </a>

    </nav>

</aside>


<!-- MAIN -->

<main class="main">


    <!-- TOPBAR -->

    <header class="topbar">

        <div class="topbar-title">
            Request Management
        </div>


        <div class="topbar-right">

            <span class="admin-name">
                <?= e($adminName) ?>
            </span>

            <div class="avatar">

                <?= e(
                    strtoupper(
                        substr(
                            trim($adminName),
                            0,
                            1
                        )
                    )
                ) ?>

            </div>

        </div>

    </header>


    <!-- CONTENT -->

    <section class="content">


        <div class="page-header">

            <div>

                <h1>
                    Customer Requests
                </h1>

                <p>
                    Review and manage spare-part requests submitted by customers.
                </p>

            </div>

        </div>


        <!-- STATS -->

        <div class="stats">


            <div class="stat-card">

                <div class="stat-label">
                    Total Requests
                </div>

                <div class="stat-number">
                    <?= $totalRequests ?>
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-label">
                    New
                </div>

                <div class="stat-number">
                    <?= $statusCounts['new'] ?? 0 ?>
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-label">
                    Contacted
                </div>

                <div class="stat-number">
                    <?= $statusCounts['contacted'] ?? 0 ?>
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-label">
                    Quotation Sent
                </div>

                <div class="stat-number">
                    <?= $statusCounts['quotation_sent'] ?? 0 ?>
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-label">
                    Completed
                </div>

                <div class="stat-number">
                    <?= $statusCounts['completed'] ?? 0 ?>
                </div>

            </div>


        </div>


        <!-- FILTER CARD -->

        <div class="filter-card">


            <form
                method="get"
                action=""
            >

                <div class="filter-row">


                    <div class="search-box">

                        <input
                            type="text"
                            name="search"
                            value="<?= e($search) ?>"
                            placeholder="Search request number, customer, phone, part..."
                        >

                    </div>


                    <input
                        type="hidden"
                        name="status"
                        value="<?= e($filter) ?>"
                    >


                    <button
                        type="submit"
                        class="search-btn"
                    >
                        Search
                    </button>


                    <?php if ($search !== '' || $filter !== 'all'): ?>

                        <a
                            href="/kmm-aut/admin/requests/"
                            class="clear-btn"
                        >
                            Clear
                        </a>

                    <?php endif; ?>


                </div>

            </form>


            <div class="status-filters">


                <a
                    href="?status=all<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                    class="filter <?= $filter === 'all' ? 'active' : '' ?>"
                >
                    All

                    <span class="filter-count">
                        (<?= $totalRequests ?>)
                    </span>

                </a>


                <a
                    href="?status=new<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                    class="filter <?= $filter === 'new' ? 'active' : '' ?>"
                >
                    New

                    <span class="filter-count">
                        (<?= $statusCounts['new'] ?? 0 ?>)
                    </span>

                </a>


                <a
                    href="?status=contacted<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                    class="filter <?= $filter === 'contacted' ? 'active' : '' ?>"
                >
                    Contacted

                    <span class="filter-count">
                        (<?= $statusCounts['contacted'] ?? 0 ?>)
                    </span>

                </a>


                <a
                    href="?status=quotation_sent<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                    class="filter <?= $filter === 'quotation_sent' ? 'active' : '' ?>"
                >
                    Quotation

                    <span class="filter-count">
                        (<?= $statusCounts['quotation_sent'] ?? 0 ?>)
                    </span>

                </a>


                <a
                    href="?status=accepted<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                    class="filter <?= $filter === 'accepted' ? 'active' : '' ?>"
                >
                    Accepted

                    <span class="filter-count">
                        (<?= $statusCounts['accepted'] ?? 0 ?>)
                    </span>

                </a>


                <a
                    href="?status=payment_pending<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                    class="filter <?= $filter === 'payment_pending' ? 'active' : '' ?>"
                >
                    Payment Pending

                    <span class="filter-count">
                        (<?= $statusCounts['payment_pending'] ?? 0 ?>)
                    </span>

                </a>


                <a
                    href="?status=paid<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                    class="filter <?= $filter === 'paid' ? 'active' : '' ?>"
                >
                    Paid

                    <span class="filter-count">
                        (<?= $statusCounts['paid'] ?? 0 ?>)
                    </span>

                </a>


                <a
                    href="?status=completed<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                    class="filter <?= $filter === 'completed' ? 'active' : '' ?>"
                >
                    Completed

                    <span class="filter-count">
                        (<?= $statusCounts['completed'] ?? 0 ?>)
                    </span>

                </a>


                <a
                    href="?status=cancelled<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                    class="filter <?= $filter === 'cancelled' ? 'active' : '' ?>"
                >
                    Cancelled

                    <span class="filter-count">
                        (<?= $statusCounts['cancelled'] ?? 0 ?>)
                    </span>

                </a>


                <a
                    href="?status=rejected<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                    class="filter <?= $filter === 'rejected' ? 'active' : '' ?>"
                >
                    Rejected

                    <span class="filter-count">
                        (<?= $statusCounts['rejected'] ?? 0 ?>)
                    </span>

                </a>


            </div>

        </div>


        <!-- TABLE -->

        <div class="table-card">


            <div class="table-header">

                <h2>
                    Requests
                </h2>

                <span class="result-count">
                    <?= count($requests) ?> result(s)
                </span>

            </div>


            <?php if (!empty($requests)): ?>


                <div class="table-wrap">

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
                                    Category
                                </th>

                                <th>
                                    Scooter
                                </th>

                                <th>
                                    Qty
                                </th>

                                <th>
                                    Quality
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Submitted
                                </th>

                                <th>
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php foreach ($requests as $request): ?>


                            <?php

                            $brand = trim(
                                (string) ($request['brand_name'] ?? '')
                            );

                            $model = trim(
                                (string) ($request['model_name'] ?? '')
                            );

                            $scooter = trim(
                                $brand .
                                ($brand !== '' && $model !== '' ? ' ' : '') .
                                $model
                            );

                            if ($scooter === '') {
                                $scooter = 'Not specified';
                            }


                            $qualityLabels = [
                                'genuine' => 'Genuine',
                                'first_quality' => 'First Quality',
                                'either' => 'Either'
                            ];

                            $quality = $qualityLabels[
                                $request['quality_preference'] ?? ''
                            ] ?? '-';

                            ?>


                            <tr>


                                <td>

                                    <div class="request-no">
                                        <?= e($request['request_no']) ?>
                                    </div>

                                </td>


                                <td>

                                    <div class="customer-name-cell">
                                        <?= e($request['customer_name']) ?>
                                    </div>

                                    <div class="customer-phone">
                                        <?= e($request['customer_phone']) ?>
                                    </div>

                                </td>


                                <td>

                                    <div class="part-name">
                                        <?= e($request['part_required']) ?>
                                    </div>

                                </td>


                                <td>

                                    <div class="category">

                                        <?= e(
                                            $request['category_names']
                                                ?: 'Not specified'
                                        ) ?>

                                    </div>

                                </td>


                                <td>

                                    <div class="scooter">
                                        <?= e($scooter) ?>
                                    </div>

                                </td>


                                <td>
                                    <?= e($request['quantity']) ?>
                                </td>


                                <td>

                                    <div class="quality">
                                        <?= e($quality) ?>
                                    </div>

                                </td>


                                <td>

                                    <span
                                        class="status <?= e(
                                            statusClass(
                                                $request['status']
                                            )
                                        ) ?>"
                                    >

                                        <?= e(
                                            statusLabel(
                                                $request['status']
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <?= e(
                                        formatDate(
                                            $request['created_at']
                                        )
                                    ) ?>

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

                </div>


            <?php else: ?>


                <div class="empty">

                    <div class="empty-icon">
                        📋
                    </div>

                    <h3>
                        No requests found
                    </h3>

                    <p>
                        There are no customer requests matching the selected filter.
                    </p>

                </div>


            <?php endif; ?>


        </div>


    </section>

</main>


<script>

function toggleSidebar()
{
    const sidebar = document.getElementById('sidebar');

    sidebar.classList.toggle('open');
}

</script>


</body>

</html>