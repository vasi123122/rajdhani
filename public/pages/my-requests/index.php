<?php

session_start();

require_once __DIR__ . '/../../../database/database.php';

/*
|--------------------------------------------------------------------------
| CUSTOMER LOGIN CHECK
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    (int) $_SESSION['user_id'] <= 0
) {
    header('Location: /kmm-aut/public/pages/login/');
    exit;
}

$customerId = (int) $_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| GET CURRENT CUSTOMER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        email,
        phone,
        whatsapp,
        role,
        status
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$customerId]);

$customer = $stmt->fetch();

if (!$customer) {
    $_SESSION = [];
    session_destroy();

    header('Location: /kmm-aut/public/pages/login/');
    exit;
}


/*
|--------------------------------------------------------------------------
| CUSTOMER ONLY
|--------------------------------------------------------------------------
*/

if ($customer['role'] !== 'customer') {

    $_SESSION = [];
    session_destroy();

    header('Location: /kmm-aut/public/pages/login/');
    exit;
}

if ($customer['status'] !== 'active') {

    $_SESSION = [];
    session_destroy();

    header('Location: /kmm-aut/public/pages/login/');
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

    return $labels[$status]
        ?? ucfirst(str_replace('_', ' ', $status));
}


function statusClass($status)
{
    $classes = [

        'new' => 'new',

        'contacted' => 'contacted',

        'quotation_sent' => 'quotation',

        'accepted' => 'accepted',

        'payment_pending' => 'payment',

        'paid' => 'paid',

        'completed' => 'completed',

        'cancelled' => 'cancelled',

        'rejected' => 'rejected'

    ];

    return $classes[$status] ?? 'default';
}


function qualityLabel($quality)
{
    $labels = [

        'genuine' => 'Genuine',

        'first_quality' => 'First Quality',

        'either' => 'Either'

    ];

    return $labels[$quality] ?? '-';
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
| STATUS FILTER
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
| REQUEST QUERY
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Only requests belonging to the logged-in customer are loaded.
|
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

        r.brand_other,

        r.model_other,

        sb.name AS brand_name,

        sm.name AS model_name

    FROM spare_part_requests r

    LEFT JOIN scooter_brands sb
        ON sb.id = r.brand_id

    LEFT JOIN scooter_models sm
        ON sm.id = r.model_id

    WHERE r.customer_id = ?
";

$params = [$customerId];


/*
|--------------------------------------------------------------------------
| APPLY STATUS FILTER
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
| ORDER
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY
        r.created_at DESC,
        r.id DESC
";


/*
|--------------------------------------------------------------------------
| EXECUTE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$requests = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| USER NAME
|--------------------------------------------------------------------------
*/

$userName = $customer['name'] ?: 'Customer';


/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$countStmt = $pdo->prepare("
    SELECT
        status,
        COUNT(*) AS total
    FROM spare_part_requests
    WHERE customer_id = ?
    GROUP BY status
");

$countStmt->execute([$customerId]);

$statusCounts = [];

foreach ($countStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}

$totalRequests = array_sum($statusCounts);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>My Requests - Khammam Auto</title>

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


        /* SIDEBAR */

        .sidebar {

            position: fixed;

            left: 0;
            top: 0;
            bottom: 0;

            width: 250px;

            background: #fff;

            border-right: 1px solid #e7eaf0;

            padding: 24px 16px;

            z-index: 100;
        }


        .brand {

            display: flex;

            align-items: center;

            gap: 11px;

            padding: 4px 10px 28px;
        }


        .brand-icon {

            width: 40px;
            height: 40px;

            border-radius: 12px;

            background: #111827;

            color: #fff;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 14px;

            font-weight: 800;
        }


        .brand-text strong {

            display: block;

            font-size: 17px;
        }


        .brand-text span {

            display: block;

            font-size: 11px;

            color: #7b8495;

            margin-top: 3px;
        }


        .nav-title {

            padding: 0 11px;

            margin-bottom: 9px;

            color: #9aa2b1;

            font-size: 11px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: .08em;
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

            border-radius: 10px;

            color: #60697a;

            font-size: 14px;

            font-weight: 600;
        }


        .nav a:hover {

            background: #f4f6f9;

            color: #111827;
        }


        .nav a.active {

            background: #111827;

            color: #fff;
        }


        .nav-icon {

            width: 22px;

            text-align: center;
        }


        /* MAIN */

        .main {

            margin-left: 250px;

            min-height: 100vh;
        }


        /* TOPBAR */

        .topbar {

            height: 72px;

            background: #fff;

            border-bottom: 1px solid #e7eaf0;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 32px;
        }


        .topbar-title {

            font-size: 18px;

            font-weight: 750;
        }


        .topbar-right {

            display: flex;

            align-items: center;

            gap: 12px;
        }


        .customer-name {

            font-size: 13px;

            color: #667085;
        }


        .avatar {

            width: 38px;
            height: 38px;

            border-radius: 50%;

            background: #111827;

            color: #fff;

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: 700;
        }


        /* CONTENT */

        .content {

            max-width: 1250px;

            margin: auto;

            padding: 30px 32px 60px;
        }


        .page-header {

            display: flex;

            align-items: flex-start;

            justify-content: space-between;

            gap: 20px;

            margin-bottom: 22px;
        }


        .page-header h1 {

            margin: 0 0 6px;

            font-size: 27px;
        }


        .page-header p {

            margin: 0;

            color: #7b8495;

            font-size: 13px;
        }


        .request-btn {

            display: inline-flex;

            align-items: center;

            gap: 7px;

            padding: 11px 16px;

            background: #111827;

            color: #fff;

            border-radius: 9px;

            font-size: 13px;

            font-weight: 700;
        }


        .request-btn:hover {
            background: #000;
        }


        /* FILTERS */

        .filters {

            display: flex;

            gap: 7px;

            flex-wrap: wrap;

            margin-bottom: 20px;
        }


        .filter {

            padding: 8px 13px;

            border: 1px solid #e1e5eb;

            border-radius: 8px;

            background: #fff;

            color: #667085;

            font-size: 12px;

            font-weight: 650;
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

            opacity: .65;

            margin-left: 3px;
        }


        /* CARD */

        .card {

            background: #fff;

            border: 1px solid #e7eaf0;

            border-radius: 14px;

            overflow: hidden;
        }


        .table-wrap {

            overflow-x: auto;
        }


        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 950px;
        }


        th {

            background: #fafbfc;

            color: #8b93a2;

            font-size: 10px;

            text-transform: uppercase;

            letter-spacing: .05em;

            text-align: left;

            padding: 14px 16px;

            border-bottom: 1px solid #e7eaf0;
        }


        td {

            padding: 15px 16px;

            border-bottom: 1px solid #edf0f4;

            font-size: 13px;

            color: #344054;

            vertical-align: middle;
        }


        tbody tr:last-child td {

            border-bottom: 0;
        }


        tbody tr:hover {

            background: #fafbfc;
        }


        .request-no {

            font-family: monospace;

            font-weight: 700;

            color: #111827;

            white-space: nowrap;
        }


        .part-name {

            font-weight: 700;

            color: #202939;
        }


        .scooter {

            color: #667085;

            font-size: 12px;

            line-height: 1.5;
        }


        .status {

            display: inline-flex;

            padding: 6px 9px;

            border-radius: 999px;

            font-size: 11px;

            font-weight: 750;

            white-space: nowrap;
        }


        .status.new {

            background: #eaf2ff;

            color: #1769d2;
        }


        .status.contacted {

            background: #fff5dc;

            color: #a46700;
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

            background: #fff1e8;

            color: #c45111;
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

            padding: 8px 12px;

            border-radius: 8px;

            background: #111827;

            color: #fff;

            font-size: 12px;

            font-weight: 700;

            white-space: nowrap;
        }


        .view-btn:hover {

            background: #000;
        }


        /* EMPTY */

        .empty {

            text-align: center;

            padding: 70px 20px;

            color: #7b8495;
        }


        .empty-icon {

            font-size: 38px;

            margin-bottom: 10px;
        }


        .empty h3 {

            margin: 0 0 6px;

            color: #344054;

            font-size: 16px;
        }


        .empty p {

            margin: 0 0 18px;

            font-size: 13px;
        }


        /* MOBILE */

        .mobile-header {

            display: none;
        }


        @media (max-width: 900px) {

            .sidebar {

                transform: translateX(-100%);

                transition: .25s;
            }


            .sidebar.open {

                transform: translateX(0);
            }


            .main {

                margin-left: 0;
            }


            .mobile-header {

                display: flex;

                height: 62px;

                background: #fff;

                border-bottom: 1px solid #e7eaf0;

                align-items: center;

                justify-content: space-between;

                padding: 0 18px;
            }


            .menu-btn {

                border: 0;

                background: transparent;

                font-size: 22px;

                cursor: pointer;
            }


            .topbar {

                display: none;
            }


            .content {

                padding: 22px 18px 45px;
            }
        }


        @media (max-width: 600px) {

            .page-header {

                flex-direction: column;
            }


            .page-header h1 {

                font-size: 23px;
            }


            .request-btn {

                width: 100%;

                justify-content: center;
            }

        }

    </style>

</head>


<body>


<!-- MOBILE HEADER -->

<div class="mobile-header">

    <button
        class="menu-btn"
        type="button"
        onclick="toggleSidebar()"
    >
        ☰
    </button>

    <strong>Khammam Auto</strong>

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
                Spare Part Requests
            </span>

        </div>

    </div>


    <div class="nav-title">
        Menu
    </div>


    <nav class="nav">

        <a
            href="/kmm-aut/public/pages/dashboard/"
        >

            <span class="nav-icon">
                ⌂
            </span>

            Dashboard

        </a>


        <a
            href="/kmm-aut/public/pages/request/"
        >

            <span class="nav-icon">
                ＋
            </span>

            Request a Part

        </a>


        <a
            href="/kmm-aut/public/pages/my-requests/"
            class="active"
        >

            <span class="nav-icon">
                ▤
            </span>

            My Requests

        </a>


        <a href="#">

            <span class="nav-icon">
                ▣
            </span>

            My Scooters

        </a>


        <a href="#">

            <span class="nav-icon">
                ◉
            </span>

            My Profile

        </a>


        <a href="#">

            <span class="nav-icon">
                ♧
            </span>

            Notifications

        </a>


        <a
            href="/kmm-aut/public/pages/logout/"
        >

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
            My Requests
        </div>


        <div class="topbar-right">

            <span class="customer-name">

                <?= e($userName) ?>

            </span>


            <div class="avatar">

                <?= e(
                    strtoupper(
                        substr(
                            trim($userName),
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


        <!-- PAGE HEADER -->

        <div class="page-header">

            <div>

                <h1>
                    My Requests
                </h1>

                <p>
                    Track all your spare-part requests in one place.
                </p>

            </div>


            <a
                href="/kmm-aut/public/pages/request/"
                class="request-btn"
            >
                + Request a Part
            </a>

        </div>


        <!-- FILTERS -->

        <div class="filters">


            <a
                href="?status=all"
                class="filter <?= $filter === 'all' ? 'active' : '' ?>"
            >

                All

                <span class="filter-count">
                    (<?= $totalRequests ?>)
                </span>

            </a>


            <a
                href="?status=new"
                class="filter <?= $filter === 'new' ? 'active' : '' ?>"
            >

                New

                <span class="filter-count">
                    (<?= $statusCounts['new'] ?? 0 ?>)
                </span>

            </a>


            <a
                href="?status=contacted"
                class="filter <?= $filter === 'contacted' ? 'active' : '' ?>"
            >

                Contacted

                <span class="filter-count">
                    (<?= $statusCounts['contacted'] ?? 0 ?>)
                </span>

            </a>


            <a
                href="?status=quotation_sent"
                class="filter <?= $filter === 'quotation_sent' ? 'active' : '' ?>"
            >

                Quoted

                <span class="filter-count">
                    (<?= $statusCounts['quotation_sent'] ?? 0 ?>)
                </span>

            </a>


            <a
                href="?status=accepted"
                class="filter <?= $filter === 'accepted' ? 'active' : '' ?>"
            >

                Accepted

                <span class="filter-count">
                    (<?= $statusCounts['accepted'] ?? 0 ?>)
                </span>

            </a>


            <a
                href="?status=payment_pending"
                class="filter <?= $filter === 'payment_pending' ? 'active' : '' ?>"
            >

                Payment Pending

                <span class="filter-count">
                    (<?= $statusCounts['payment_pending'] ?? 0 ?>)
                </span>

            </a>


            <a
                href="?status=paid"
                class="filter <?= $filter === 'paid' ? 'active' : '' ?>"
            >

                Paid

                <span class="filter-count">
                    (<?= $statusCounts['paid'] ?? 0 ?>)
                </span>

            </a>


            <a
                href="?status=completed"
                class="filter <?= $filter === 'completed' ? 'active' : '' ?>"
            >

                Completed

                <span class="filter-count">
                    (<?= $statusCounts['completed'] ?? 0 ?>)
                </span>

            </a>


            <a
                href="?status=cancelled"
                class="filter <?= $filter === 'cancelled' ? 'active' : '' ?>"
            >

                Cancelled

                <span class="filter-count">
                    (<?= $statusCounts['cancelled'] ?? 0 ?>)
                </span>

            </a>


        </div>


        <!-- REQUEST TABLE -->

        <div class="card">


            <?php if (!empty($requests)): ?>


                <div class="table-wrap">

                    <table>


                        <thead>

                            <tr>

                                <th>
                                    Request
                                </th>

                                <th>
                                    Part
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
                                    Date
                                </th>

                                <th>
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php foreach ($requests as $item): ?>


                            <?php

                            /*
                            |--------------------------------------------------------------------------
                            | SCOOTER NAME
                            |--------------------------------------------------------------------------
                            */

                            $brand = trim(
                                (string) ($item['brand_name'] ?? '')
                            );

                            $model = trim(
                                (string) ($item['model_name'] ?? '')
                            );

                            $brandOther = trim(
                                (string) ($item['brand_other'] ?? '')
                            );

                            $modelOther = trim(
                                (string) ($item['model_other'] ?? '')
                            );


                            if ($brand === '' && $brandOther !== '') {
                                $brand = $brandOther;
                            }


                            if ($model === '' && $modelOther !== '') {
                                $model = $modelOther;
                            }


                            $scooter = trim(
                                $brand .
                                (
                                    $brand !== '' && $model !== ''
                                    ? ' '
                                    : ''
                                ) .
                                $model
                            );


                            if ($scooter === '') {
                                $scooter = 'Not specified';
                            }

                            ?>


                            <tr>


                                <td>

                                    <div class="request-no">

                                        <?= e(
                                            $item['request_no']
                                        ) ?>

                                    </div>

                                </td>


                                <td>

                                    <div class="part-name">

                                        <?= e(
                                            $item['part_required']
                                        ) ?>

                                    </div>

                                </td>


                                <td>

                                    <div class="scooter">

                                        <?= e($scooter) ?>

                                    </div>

                                </td>


                                <td>

                                    <?= e(
                                        $item['quantity']
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        qualityLabel(
                                            $item['quality_preference']
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <span
                                        class="status <?= e(
                                            statusClass(
                                                $item['status']
                                            )
                                        ) ?>"
                                    >

                                        <?= e(
                                            statusLabel(
                                                $item['status']
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <?= e(
                                        formatDate(
                                            $item['created_at']
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <a
                                        href="/kmm-aut/public/pages/request-details/?id=<?= (int) $item['id'] ?>"
                                        class="view-btn"
                                    >

                                        View Details

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
                        You haven't submitted any spare-part requests yet.
                    </p>


                    <a
                        href="/kmm-aut/public/pages/request/"
                        class="request-btn"
                    >
                        + Request a Part
                    </a>

                </div>


            <?php endif; ?>


        </div>


    </section>


</main>


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