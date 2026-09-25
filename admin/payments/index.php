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

$adminId = (int) $_SESSION['user_id'];


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


function money($amount)
{
    return '₹' . number_format(
        (float) $amount,
        2
    );
}


function statusLabel($status)
{
    $labels = [
        'pending'   => 'Pending',
        'paid'      => 'Paid',
        'failed'    => 'Failed',
        'refunded'  => 'Refunded',
        'cancelled' => 'Cancelled'
    ];

    return $labels[$status]
        ?? ucfirst(str_replace('_', ' ', $status));
}


function statusClass($status)
{
    return match ($status) {
        'pending'   => 'pending',
        'paid'      => 'paid',
        'failed'    => 'failed',
        'refunded'  => 'refunded',
        'cancelled' => 'cancelled',
        default     => 'pending'
    };
}


function formatDate($date)
{
    if (!$date) {
        return '-';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return '-';
    }

    return date(
        'd M Y, h:i A',
        $timestamp
    );
}


/*
|--------------------------------------------------------------------------
| ACTIONS
|--------------------------------------------------------------------------
*/

$successMessage = '';
$errorMessage = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $paymentId = isset($_POST['payment_id'])
        ? (int) $_POST['payment_id']
        : 0;


    if ($paymentId <= 0) {

        $errorMessage = 'Invalid payment ID.';

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | GET PAYMENT
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    p.id,
                    p.request_id,
                    p.quotation_id,
                    p.amount,
                    p.method,
                    p.status,

                    r.request_no,
                    r.status AS request_status,
                    r.customer_id,

                    u.name AS customer_name,
                    u.phone AS customer_phone,
                    u.email AS customer_email

                FROM payments p

                INNER JOIN spare_part_requests r
                    ON r.id = p.request_id

                INNER JOIN users u
                    ON u.id = r.customer_id

                WHERE p.id = ?

                LIMIT 1
            ");

            $stmt->execute([
                $paymentId
            ]);

            $paymentRecord = $stmt->fetch();


            if (!$paymentRecord) {

                $errorMessage = 'Payment record not found.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | MARK AS PAID
                |--------------------------------------------------------------------------
                */

                if ($action === 'mark_paid') {

                    if ($paymentRecord['status'] === 'paid') {

                        $successMessage =
                            'This payment is already marked as paid.';

                    } else {

                        $pdo->beginTransaction();


                        /*
                        |--------------------------------------------------------------------------
                        | UPDATE PAYMENT
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            UPDATE payments

                            SET
                                status = 'paid',
                                method = 'upi'

                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $paymentId
                        ]);


                        /*
                        |--------------------------------------------------------------------------
                        | UPDATE REQUEST STATUS
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            UPDATE spare_part_requests

                            SET
                                status = 'paid'

                            WHERE id = ?
                        ");

                        $stmt->execute([
                            (int) $paymentRecord['request_id']
                        ]);


                        /*
                        |--------------------------------------------------------------------------
                        | STATUS HISTORY
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO request_status_history
                            (
                                request_id,
                                old_status,
                                new_status,
                                changed_by,
                                note
                            )

                            VALUES
                            (
                                ?,
                                ?,
                                'paid',
                                ?,
                                ?
                            )
                        ");

                        $stmt->execute([
                            (int) $paymentRecord['request_id'],
                            $paymentRecord['request_status'],
                            $adminId,
                            'Payment verified and marked as paid by admin.'
                        ]);


                        $pdo->commit();


                        $successMessage =
                            'Payment marked as paid successfully.';
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | MARK REQUEST AS COMPLETED
                |--------------------------------------------------------------------------
                */

                elseif ($action === 'mark_completed') {

                    if ($paymentRecord['status'] !== 'paid') {

                        $errorMessage =
                            'The payment must be marked as paid before completing the request.';

                    } elseif ($paymentRecord['request_status'] !== 'paid') {

                        $errorMessage =
                            'This request is not ready to be completed.';

                    } else {

                        $pdo->beginTransaction();

                        /*
                        |--------------------------------------------------------------------------
                        | UPDATE REQUEST STATUS
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            UPDATE spare_part_requests
                            SET
                                status = 'completed'
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            (int) $paymentRecord['request_id']
                        ]);

                        /*
                        |--------------------------------------------------------------------------
                        | STATUS HISTORY
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO request_status_history
                            (
                                request_id,
                                old_status,
                                new_status,
                                changed_by,
                                note
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                'completed',
                                ?,
                                ?
                            )
                        ");

                        $stmt->execute([
                            (int) $paymentRecord['request_id'],
                            $paymentRecord['request_status'],
                            $adminId,
                            'Payment verified and request marked as completed by admin.'
                        ]);

                        $pdo->commit();

                        $successMessage =
                            'Request marked as completed successfully.';
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | REJECT PAYMENT
                |--------------------------------------------------------------------------
                */

                elseif ($action === 'reject_payment') {

                    if ($paymentRecord['status'] === 'paid') {

                        $errorMessage =
                            'A paid payment cannot be rejected.';

                    } else {

                        $pdo->beginTransaction();


                        /*
                        |--------------------------------------------------------------------------
                        | UPDATE PAYMENT
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            UPDATE payments

                            SET
                                status = 'failed',
                                method = 'upi'

                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $paymentId
                        ]);


                        /*
                        |--------------------------------------------------------------------------
                        | KEEP REQUEST AT PAYMENT PENDING
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            UPDATE spare_part_requests

                            SET
                                status = 'payment_pending'

                            WHERE id = ?
                        ");

                        $stmt->execute([
                            (int) $paymentRecord['request_id']
                        ]);


                        /*
                        |--------------------------------------------------------------------------
                        | STATUS HISTORY
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO request_status_history
                            (
                                request_id,
                                old_status,
                                new_status,
                                changed_by,
                                note
                            )

                            VALUES
                            (
                                ?,
                                ?,
                                'payment_pending',
                                ?,
                                ?
                            )
                        ");

                        $stmt->execute([
                            (int) $paymentRecord['request_id'],
                            $paymentRecord['request_status'],
                            $adminId,
                            'Customer payment confirmation rejected by admin.'
                        ]);


                        $pdo->commit();


                        $successMessage =
                            'Payment rejected. Customer can make the payment again.';
                    }
                }
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errorMessage =
                'Unable to process payment. ' .
                $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim(
    $_GET['search'] ?? ''
);

$statusFilter = $_GET['status'] ?? '';


$allowedStatuses = [
    '',
    'pending',
    'paid',
    'failed',
    'refunded',
    'cancelled'
];


if (!in_array(
    $statusFilter,
    $allowedStatuses,
    true
)) {

    $statusFilter = '';

}


/*
|--------------------------------------------------------------------------
| PAYMENT QUERY
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT

        p.id AS payment_id,
        p.request_id,
        p.quotation_id,
        p.amount,
        p.currency,
        p.method,
        p.status AS payment_status,
        p.payment_link,
        p.created_at AS payment_created_at,

        r.request_no,
        r.part_required,
        r.quantity,
        r.status AS request_status,

        u.name AS customer_name,
        u.phone AS customer_phone,
        u.email AS customer_email,

        q.version_no,
        q.total_amount AS quotation_total

    FROM payments p

    INNER JOIN spare_part_requests r
        ON r.id = p.request_id

    INNER JOIN users u
        ON u.id = r.customer_id

    LEFT JOIN quotations q
        ON q.id = p.quotation_id

    WHERE 1 = 1
";


$params = [];


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $sql .= "
        AND (
            r.request_no LIKE ?
            OR u.name LIKE ?
            OR u.phone LIKE ?
            OR u.email LIKE ?
            OR r.part_required LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}


/*
|--------------------------------------------------------------------------
| STATUS FILTER
|--------------------------------------------------------------------------
*/

if ($statusFilter !== '') {

    $sql .= "
        AND p.status = ?
    ";

    $params[] = $statusFilter;
}


/*
|--------------------------------------------------------------------------
| ORDER
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY
        CASE
            WHEN p.status = 'pending' THEN 0
            ELSE 1
        END,
        p.id DESC
";


$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$payments = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$totalPayments = 0;
$pendingPayments = 0;
$paidPayments = 0;
$failedPayments = 0;


$stmt = $pdo->query("
    SELECT
        COUNT(*) AS total_payments,

        SUM(
            CASE
                WHEN status = 'pending'
                THEN 1
                ELSE 0
            END
        ) AS pending_payments,

        SUM(
            CASE
                WHEN status = 'paid'
                THEN 1
                ELSE 0
            END
        ) AS paid_payments,

        SUM(
            CASE
                WHEN status = 'failed'
                THEN 1
                ELSE 0
            END
        ) AS failed_payments

    FROM payments
");

$counts = $stmt->fetch();


if ($counts) {

    $totalPayments =
        (int) ($counts['total_payments'] ?? 0);

    $pendingPayments =
        (int) ($counts['pending_payments'] ?? 0);

    $paidPayments =
        (int) ($counts['paid_payments'] ?? 0);

    $failedPayments =
        (int) ($counts['failed_payments'] ?? 0);
}


/*
|--------------------------------------------------------------------------
| ADMIN NAME
|--------------------------------------------------------------------------
*/

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

    <title>
        Payments - Khammam Auto Admin
    </title>


    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }


        body {

            font-family:
                Inter,
                Arial,
                Helvetica,
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
            font-family: inherit;
        }


        .app {

            min-height: 100vh;

            display: flex;

        }


        /* =========================================================
           SIDEBAR
        ========================================================= */

        .sidebar {

            width: 280px;

            background: #111827;

            color: #ffffff;

            position: fixed;

            left: 0;

            top: 0;

            bottom: 0;

            z-index: 100;

        }


        .brand {

            height: 130px;

            padding: 30px;

            border-bottom:
                1px solid #263043;

            display: flex;

            align-items: center;

            gap: 14px;

        }


        .brand-logo {

            width: 50px;

            height: 50px;

            background: #ffffff;

            color: #111827;

            border-radius: 14px;

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: 900;

            font-size: 18px;

        }


        .brand-text strong {

            display: block;

            font-size: 20px;

        }


        .brand-text span {

            display: block;

            margin-top: 7px;

            color: #8d99ad;

            font-size: 13px;

        }


        .menu-title {

            padding:
                30px 30px 15px;

            color: #71809a;

            font-size: 12px;

            font-weight: 800;

            letter-spacing: 1px;

        }


        .nav {

            padding: 0 18px;

        }


        .nav a {

            display: flex;

            align-items: center;

            gap: 15px;

            padding: 15px 20px;

            margin-bottom: 5px;

            border-radius: 12px;

            color: #d0d7e4;

            font-size: 15px;

            font-weight: 700;

        }


        .nav a:hover {

            background: #1c2535;

        }


        .nav a.active {

            background: #ffffff;

            color: #111827;

        }


        .nav-icon {

            width: 22px;

            text-align: center;

        }


        .logout {

            position: absolute;

            bottom: 25px;

            left: 18px;

            right: 18px;

        }


        .logout a {

            color: #ff7b7b;

        }


        /* =========================================================
           MAIN
        ========================================================= */

        .main {

            margin-left: 280px;

            width:
                calc(100% - 280px);

            min-height: 100vh;

        }


        .topbar {

            height: 82px;

            background: #ffffff;

            border-bottom:
                1px solid #e4e8ef;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 36px;

        }


        .topbar-title {

            font-size: 22px;

            font-weight: 800;

        }


        .user {

            display: flex;

            align-items: center;

            gap: 12px;

        }


        .user-name {

            color: #68758b;

            font-weight: 700;

            font-size: 14px;

        }


        .avatar {

            width: 42px;

            height: 42px;

            border-radius: 50%;

            background: #111827;

            color: #ffffff;

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: 800;

        }


        .content {

            padding: 40px;

        }


        /* =========================================================
           HEADER
        ========================================================= */

        .page-heading {

            margin-bottom: 30px;

        }


        .page-heading h1 {

            font-size: 36px;

            margin-bottom: 8px;

        }


        .page-heading p {

            color: #758197;

            font-size: 15px;

        }


        /* =========================================================
           ALERTS
        ========================================================= */

        .alert {

            padding: 15px 18px;

            border-radius: 12px;

            margin-bottom: 22px;

            font-weight: 700;

        }


        .alert-success {

            background: #e9f9ef;

            color: #157347;

            border:
                1px solid #b9e8ca;

        }


        .alert-error {

            background: #fff0f0;

            color: #c62828;

            border:
                1px solid #f2bcbc;

        }


        /* =========================================================
           STATS
        ========================================================= */

        .stats {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 18px;

            margin-bottom: 28px;

        }


        .stat {

            background: #ffffff;

            border:
                1px solid #e3e8ef;

            border-radius: 16px;

            padding: 25px;

        }


        .stat-label {

            color: #748198;

            font-size: 14px;

            font-weight: 700;

        }


        .stat-value {

            display: block;

            font-size: 32px;

            font-weight: 900;

            margin-top: 12px;

        }


        /* =========================================================
           FILTER
        ========================================================= */

        .filter-card {

            background: #ffffff;

            border:
                1px solid #e3e8ef;

            border-radius: 16px;

            padding: 22px;

            margin-bottom: 25px;

        }


        .filter-form {

            display: grid;

            grid-template-columns:
                1fr 240px 120px;

            gap: 12px;

        }


        .filter-form input,
        .filter-form select {

            width: 100%;

            height: 50px;

            border:
                1px solid #d8dee8;

            border-radius: 10px;

            padding:
                0 15px;

            font-size: 14px;

            outline: none;

            background: #ffffff;

        }


        .filter-form input:focus,
        .filter-form select:focus {

            border-color: #111827;

        }


        .filter-button {

            border: 0;

            background: #111827;

            color: #ffffff;

            border-radius: 10px;

            font-weight: 800;

            cursor: pointer;

        }


        /* =========================================================
           TABLE
        ========================================================= */

        .card {

            background: #ffffff;

            border:
                1px solid #e3e8ef;

            border-radius: 18px;

            overflow: hidden;

        }


        .card-header {

            padding: 24px 28px;

            border-bottom:
                1px solid #e8ebf0;

        }


        .card-header h2 {

            font-size: 20px;

        }


        .card-header p {

            margin-top: 5px;

            color: #7b879d;

            font-size: 13px;

        }


        .table-wrap {

            width: 100%;

            overflow-x: auto;

        }


        table {

            width: 100%;

            min-width: 1050px;

            border-collapse: collapse;

        }


        th {

            background: #fafbfc;

            color: #78859a;

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: .5px;

            text-align: left;

            padding: 16px 20px;

            border-bottom:
                1px solid #e8ebf0;

        }


        td {

            padding: 20px;

            border-bottom:
                1px solid #edf0f4;

            vertical-align: middle;

        }


        tr:last-child td {

            border-bottom: 0;

        }


        .payment-id {

            font-weight: 900;

        }


        .request-number {

            font-weight: 800;

            color: #111827;

        }


        .customer-name {

            font-weight: 800;

        }


        .customer-phone {

            color: #7b879d;

            font-size: 12px;

            margin-top: 4px;

        }


        .part {

            font-weight: 700;

        }


        .amount {

            font-weight: 900;

            font-size: 16px;

        }


        .method {

            font-size: 12px;

            font-weight: 800;

            text-transform: uppercase;

        }


        /* =========================================================
           STATUS
        ========================================================= */

        .status {

            display: inline-block;

            padding:
                8px 13px;

            border-radius: 30px;

            font-size: 11px;

            font-weight: 900;

        }


        .status.pending {

            background: #fff4d6;

            color: #a56b00;

        }


        .status.paid {

            background: #e7f8ee;

            color: #16834b;

        }


        .status.failed {

            background: #ffeded;

            color: #d32f2f;

        }


        .status.refunded {

            background: #eeeaff;

            color: #6644d8;

        }


        .status.cancelled {

            background: #f1f2f4;

            color: #697386;

        }

        .status.completed {

            background: #e7f8ee;

            color: #16834b;

        }


        /* =========================================================
           ACTIONS
        ========================================================= */

        .actions {

            display: flex;

            align-items: center;

            gap: 8px;

            flex-wrap: wrap;

        }


        .action-btn {

            border: 0;

            border-radius: 9px;

            padding:
                10px 13px;

            font-size: 12px;

            font-weight: 800;

            cursor: pointer;

        }


        .view-btn {

            background: #111827;

            color: #ffffff;

        }


        .paid-btn {

            background: #16834b;

            color: #ffffff;

        }


        .reject-btn {

            background: #fff0f0;

            color: #d32f2f;

            border:
                1px solid #f0caca;

        }


        .disabled-btn {

            background: #f1f3f5;

            color: #8b95a5;

            cursor: not-allowed;

        }


        /* =========================================================
           EMPTY
        ========================================================= */

        .empty {

            padding: 80px 30px;

            text-align: center;

        }


        .empty-icon {

            font-size: 45px;

            margin-bottom: 15px;

        }


        .empty h3 {

            font-size: 20px;

            margin-bottom: 8px;

        }


        .empty p {

            color: #7b879d;

        }


        /* =========================================================
           MOBILE
        ========================================================= */

        @media (max-width: 1000px) {

            .sidebar {

                width: 230px;

            }


            .main {

                margin-left: 230px;

                width:
                    calc(100% - 230px);

            }


            .stats {

                grid-template-columns:
                    repeat(2, 1fr);

            }


            .filter-form {

                grid-template-columns: 1fr;

            }

        }


        @media (max-width: 700px) {

            .app {

                display: block;

            }


            .sidebar {

                position: static;

                width: 100%;

                height: auto;

            }


            .main {

                margin-left: 0;

                width: 100%;

            }


            .logout {

                position: static;

                margin:
                    10px 18px 20px;

            }


            .topbar {

                padding:
                    0 20px;

            }


            .content {

                padding:
                    25px 18px;

            }


            .stats {

                grid-template-columns:
                    1fr 1fr;

            }

        }


        @media (max-width: 450px) {

            .stats {

                grid-template-columns: 1fr;

            }


            .page-heading h1 {

                font-size: 28px;

            }

        }

    </style>

</head>


<body>


<div class="app">


    <!-- =========================================================
         SIDEBAR
    ========================================================== -->

    <aside class="sidebar">


        <div class="brand">

            <div class="brand-logo">
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


        <div class="menu-title">
            MAIN MENU
        </div>


        <nav class="nav">

            <a
                href="/kmm-aut/admin/dashboard/"
            >
                <span class="nav-icon">
                    ⌂
                </span>

                Dashboard
            </a>


            <a
                href="/kmm-aut/admin/requests/"
            >
                <span class="nav-icon">
                    ▣
                </span>

                Requests
            </a>


            <a
                href="/kmm-aut/admin/customers/"
            >
                <span class="nav-icon">
                    ♟
                </span>

                Customers
            </a>


            <a
                href="/kmm-aut/admin/quotations/"
            >
                <span class="nav-icon">
                    ₹
                </span>

                Quotations
            </a>


            <a
                href="/kmm-aut/admin/payments/"
                class="active"
            >
                <span class="nav-icon">
                    ▤
                </span>

                Payments
            </a>


            <a
                href="/kmm-aut/admin/notifications/"
            >
                <span class="nav-icon">
                    ♧
                </span>

                Notifications
            </a>


            <a
                href="/kmm-aut/admin/settings/"
            >
                <span class="nav-icon">
                    ⚙
                </span>

                Settings
            </a>

        </nav>


        <div class="logout">

            <a
                href="/kmm-aut/admin/auth/logout/"
            >

                <span class="nav-icon">
                    ↪
                </span>

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

            <div class="topbar-title">
                Payments
            </div>


            <div class="user">

                <div class="user-name">

                    <?= e(
                        strtoupper($adminName)
                    ) ?>

                </div>


                <div class="avatar">

                    <?= e(
                        strtoupper(
                            substr(
                                $adminName,
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


            <!-- PAGE HEADING -->

            <div class="page-heading">

                <h1>
                    Payments
                </h1>

                <p>
                    Manage customer payments and verify UPI payment confirmations.
                </p>

            </div>


            <!-- =================================================
                 ALERTS
            ================================================== -->

            <?php if ($successMessage): ?>

                <div class="alert alert-success">

                    ✓
                    <?= e($successMessage) ?>

                </div>

            <?php endif; ?>


            <?php if ($errorMessage): ?>

                <div class="alert alert-error">

                    ⚠
                    <?= e($errorMessage) ?>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 STATS
            ================================================== -->

            <div class="stats">


                <div class="stat">

                    <div class="stat-label">
                        Total Payments
                    </div>

                    <span class="stat-value">
                        <?= $totalPayments ?>
                    </span>

                </div>


                <div class="stat">

                    <div class="stat-label">
                        Pending Payments
                    </div>

                    <span class="stat-value">
                        <?= $pendingPayments ?>
                    </span>

                </div>


                <div class="stat">

                    <div class="stat-label">
                        Paid
                    </div>

                    <span class="stat-value">
                        <?= $paidPayments ?>
                    </span>

                </div>


                <div class="stat">

                    <div class="stat-label">
                        Failed
                    </div>

                    <span class="stat-value">
                        <?= $failedPayments ?>
                    </span>

                </div>


            </div>


            <!-- =================================================
                 FILTER
            ================================================== -->

            <div class="filter-card">

                <form
                    method="get"
                    class="filter-form"
                >


                    <input
                        type="text"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="Search request, customer, phone or part..."
                    >


                    <select name="status">

                        <option value="">
                            All Payment Statuses
                        </option>


                        <option
                            value="pending"
                            <?= $statusFilter === 'pending'
                                ? 'selected'
                                : '' ?>
                        >
                            Pending
                        </option>


                        <option
                            value="paid"
                            <?= $statusFilter === 'paid'
                                ? 'selected'
                                : '' ?>
                        >
                            Paid
                        </option>


                        <option
                            value="failed"
                            <?= $statusFilter === 'failed'
                                ? 'selected'
                                : '' ?>
                        >
                            Failed
                        </option>


                        <option
                            value="refunded"
                            <?= $statusFilter === 'refunded'
                                ? 'selected'
                                : '' ?>
                        >
                            Refunded
                        </option>


                        <option
                            value="cancelled"
                            <?= $statusFilter === 'cancelled'
                                ? 'selected'
                                : '' ?>
                        >
                            Cancelled
                        </option>

                    </select>


                    <button
                        type="submit"
                        class="filter-button"
                    >
                        Search
                    </button>


                </form>

            </div>


            <!-- =================================================
                 PAYMENT RECORDS
            ================================================== -->

            <div class="card">


                <div class="card-header">

                    <h2>
                        Payment Records
                    </h2>

                    <p>
                        <?= count($payments) ?>
                        payment record(s) found.
                    </p>

                </div>


                <?php if (!empty($payments)): ?>


                    <div class="table-wrap">

                        <table>


                            <thead>

                                <tr>

                                    <th>
                                        Payment
                                    </th>

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
                                        Amount
                                    </th>

                                    <th>
                                        Method
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th>
                                        Created
                                    </th>

                                    <th>
                                        Action
                                    </th>

                                </tr>

                            </thead>


                            <tbody>


                                <?php foreach (
                                    $payments
                                    as $payment
                                ): ?>


                                    <tr>


                                        <!-- PAYMENT -->

                                        <td>

                                            <div class="payment-id">

                                                #<?= e(
                                                    $payment['payment_id']
                                                ) ?>

                                            </div>

                                        </td>


                                        <!-- REQUEST -->

                                        <td>

                                            <div class="request-number">

                                                <?= e(
                                                    $payment['request_no']
                                                ) ?>

                                            </div>


                                            <?php if (
                                                !empty(
                                                    $payment['version_no']
                                                )
                                            ): ?>

                                                <div
                                                    style="
                                                        color:#8994a7;
                                                        font-size:11px;
                                                        margin-top:4px;
                                                    "
                                                >

                                                    Quotation V
                                                    <?= e(
                                                        $payment['version_no']
                                                    ) ?>

                                                </div>

                                            <?php endif; ?>

                                        </td>


                                        <!-- CUSTOMER -->

                                        <td>

                                            <div class="customer-name">

                                                <?= e(
                                                    $payment['customer_name']
                                                ) ?>

                                            </div>


                                            <div class="customer-phone">

                                                <?= e(
                                                    $payment['customer_phone']
                                                ) ?>

                                            </div>

                                        </td>


                                        <!-- PART -->

                                        <td>

                                            <div class="part">

                                                <?= e(
                                                    $payment['part_required']
                                                ) ?>

                                            </div>


                                            <div
                                                style="
                                                    color:#8994a7;
                                                    font-size:11px;
                                                    margin-top:4px;
                                                "
                                            >

                                                Qty:
                                                <?= e(
                                                    $payment['quantity']
                                                ) ?>

                                            </div>

                                        </td>


                                        <!-- AMOUNT -->

                                        <td>

                                            <div class="amount">

                                                <?= money(
                                                    $payment['amount']
                                                ) ?>

                                            </div>

                                        </td>


                                        <!-- METHOD -->

                                        <td>

                                            <div class="method">

                                                <?= e(
                                                    $payment['method']
                                                        ?: 'UPI'
                                                ) ?>

                                            </div>

                                        </td>


                                        <!-- STATUS -->

                                        <td>

                                            <span
                                                class="status <?= e(
                                                    statusClass(
                                                        $payment[
                                                            'payment_status'
                                                        ]
                                                    )
                                                ) ?>"
                                            >

                                                <?= e(
                                                    statusLabel(
                                                        $payment[
                                                            'payment_status'
                                                        ]
                                                    )
                                                ) ?>

                                            </span>

                                        </td>


                                        <!-- CREATED -->

                                        <td>

                                            <div
                                                style="
                                                    font-size:12px;
                                                    color:#697586;
                                                    white-space:nowrap;
                                                "
                                            >

                                                <?= e(
                                                    formatDate(
                                                        $payment[
                                                            'payment_created_at'
                                                        ]
                                                    )
                                                ) ?>

                                            </div>

                                        </td>


                                        <!-- ACTION -->

                                        <td>


                                            <div class="actions">


                                                <a
                                                    href="/kmm-aut/admin/requests/view.php?id=<?= (int) $payment['request_id'] ?>"
                                                    class="action-btn view-btn"
                                                >
                                                    View Request
                                                </a>


                                                <?php if (
                                                    $payment[
                                                        'payment_status'
                                                    ] === 'pending'
                                                ): ?>


                                                    <form
                                                        method="post"
                                                        style="display:inline;"
                                                        onsubmit="
                                                            return confirm(
                                                                'Are you sure you verified this UPI payment and want to mark it as PAID?'
                                                            );
                                                        "
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="mark_paid"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="payment_id"
                                                            value="<?= (int) $payment['payment_id'] ?>"
                                                        >


                                                        <button
                                                            type="submit"
                                                            class="action-btn paid-btn"
                                                        >
                                                            ✓ Mark as Paid
                                                        </button>

                                                    </form>


                                                    <form
                                                        method="post"
                                                        style="display:inline;"
                                                        onsubmit="
                                                            return confirm(
                                                                'Reject this payment confirmation?'
                                                            );
                                                        "
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="reject_payment"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="payment_id"
                                                            value="<?= (int) $payment['payment_id'] ?>"
                                                        >


                                                        <button
                                                            type="submit"
                                                            class="action-btn reject-btn"
                                                        >
                                                            Reject
                                                        </button>

                                                    </form>


                                                <?php elseif (
                                                    $payment[
                                                        'payment_status'
                                                    ] === 'paid'
                                                ): ?>


                                                    <?php if (
                                                        $payment['request_status'] === 'paid'
                                                    ): ?>

                                                        <form
                                                            method="post"
                                                            style="display:inline;"
                                                            onsubmit="
                                                                return confirm(
                                                                    'Are you sure the payment is verified and you want to mark this request as COMPLETED?'
                                                                );
                                                            "
                                                        >

                                                            <input
                                                                type="hidden"
                                                                name="action"
                                                                value="mark_completed"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="payment_id"
                                                                value="<?= (int) $payment['payment_id'] ?>"
                                                            >

                                                            <button
                                                                type="submit"
                                                                class="action-btn paid-btn"
                                                            >
                                                                ✓ Mark Completed
                                                            </button>

                                                        </form>

                                                    <?php else: ?>

                                                        <span
                                                            class="action-btn disabled-btn"
                                                        >
                                                            ✓ Completed
                                                        </span>

                                                    <?php endif; ?>


                                                <?php elseif (
                                                    $payment[
                                                        'payment_status'
                                                    ] === 'failed'
                                                ): ?>


                                                    <span
                                                        class="action-btn disabled-btn"
                                                    >
                                                        Rejected
                                                    </span>


                                                <?php endif; ?>


                                            </div>


                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                            </tbody>


                        </table>

                    </div>


                <?php else: ?>


                    <div class="empty">

                        <div class="empty-icon">
                            💳
                        </div>

                        <h3>
                            No payment records found
                        </h3>

                        <p>
                            There are no payments matching your search.
                        </p>

                    </div>


                <?php endif; ?>


            </div>


        </section>


    </main>


</div>


</body>

</html>