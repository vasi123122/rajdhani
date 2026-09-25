<?php

session_start();

require_once __DIR__ . '/../../../database/database.php';

/*
|--------------------------------------------------------------------------
| LOGIN CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
    header('Location: /kmm-aut/public/pages/login/');
    exit;
}

$customerId = (int) $_SESSION['user_id'];


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


/*
|--------------------------------------------------------------------------
| CUSTOMER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        email,
        phone
    FROM users
    WHERE id = ?
      AND role = 'customer'
    LIMIT 1
");

$stmt->execute([
    $customerId
]);

$customer = $stmt->fetch();

if (!$customer) {

    session_destroy();

    header(
        'Location: /kmm-aut/public/pages/login/'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| REQUEST ID
|--------------------------------------------------------------------------
*/

$requestId = isset($_GET['request_id'])
    ? (int) $_GET['request_id']
    : 0;

if ($requestId <= 0) {

    header(
        'Location: /kmm-aut/public/pages/my-requests/'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| LOAD PAYMENT
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        p.id AS payment_id,
        p.request_id,
        p.quotation_id,
        p.amount,
        p.currency,
        p.method,
        p.status AS payment_status,
        p.gateway_order_id,
        p.gateway_payment_id,
        p.payment_link,
        p.paid_at,
        p.notes,
        p.created_at AS payment_created_at,

        r.request_no,
        r.part_required,
        r.quantity,
        r.status AS request_status,

        q.version_no,
        q.total_amount AS quotation_total,
        q.status AS quotation_status,
        q.valid_until

    FROM payments p

    INNER JOIN spare_part_requests r
        ON r.id = p.request_id

    LEFT JOIN quotations q
        ON q.id = p.quotation_id

    WHERE p.request_id = ?
      AND r.customer_id = ?

    ORDER BY p.id DESC

    LIMIT 1
");

$stmt->execute([
    $requestId,
    $customerId
]);

$payment = $stmt->fetch();

if (!$payment) {

    header(
        'Location: /kmm-aut/public/pages/my-requests/'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| UPI DETAILS
|--------------------------------------------------------------------------
*/

$upiId = 'saiteja061636@ybl';

$upiName = 'Khammam Auto';


/*
|--------------------------------------------------------------------------
| CUSTOMER "I HAVE PAID"
|--------------------------------------------------------------------------
|
| This DOES NOT mark payment as paid.
|
| It only informs admin that customer says payment
| has been completed.
|
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'payment_made') {

        /*
        |--------------------------------------------------------------
        | Only pending payments can be reported
        |--------------------------------------------------------------
        */

        if (
            in_array(
                $payment['payment_status'],
                ['created', 'pending'],
                true
            )
        ) {

            $currentNotes = trim(
                (string) ($payment['notes'] ?? '')
            );

            $alreadyReported = stripos(
                $currentNotes,
                'Customer reported that payment was made'
            ) !== false;


            /*
            |----------------------------------------------------------
            | Update payment note
            |----------------------------------------------------------
            */

            if (!$alreadyReported) {

                $newNote =
                    'Customer reported that payment was made via PhonePe/UPI. ' .
                    'UPI ID: ' . $upiId .
                    '. Waiting for admin verification.';

                if ($currentNotes !== '') {

                    $newNote =
                        $currentNotes .
                        ' | ' .
                        $newNote;
                }

                $updatePayment = $pdo->prepare("
                    UPDATE payments

                    SET
                        method = 'upi',
                        status = 'pending',
                        notes = ?

                    WHERE id = ?
                ");

                $updatePayment->execute([
                    $newNote,
                    $payment['payment_id']
                ]);


                /*
                |------------------------------------------------------
                | Notify all Admin / Staff users
                |------------------------------------------------------
                */

                $adminStmt = $pdo->query("
                    SELECT id
                    FROM users
                    WHERE role IN ('admin', 'staff')
                      AND status = 'active'
                ");

                $admins = $adminStmt->fetchAll();


                foreach ($admins as $admin) {

                    $notificationStmt = $pdo->prepare("
                        INSERT INTO notifications
                        (
                            user_id,
                            type,
                            title,
                            message,
                            link,
                            is_read,
                            created_at
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            0,
                            NOW()
                        )
                    ");

                    $notificationStmt->execute([
                        (int) $admin['id'],

                        'payment_confirmation',

                        'Payment Confirmation Received',

                        'Customer ' .
                        $customer['name'] .
                        ' reported a PhonePe/UPI payment of ' .
                        money($payment['amount']) .
                        ' for request ' .
                        $payment['request_no'] .
                        '. Please verify the payment.',

                        '/kmm-aut/admin/requests/view.php?id=' .
                        $requestId
                    ]);
                }
            }


            /*
            |----------------------------------------------------------
            | Redirect with success message
            |----------------------------------------------------------
            */

            header(
                'Location: /kmm-aut/public/pages/payment/?request_id=' .
                $requestId .
                '&reported=1'
            );

            exit;
        }
    }
}


/*
|--------------------------------------------------------------------------
| REFRESH PAYMENT DATA AFTER POST
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        p.id AS payment_id,
        p.request_id,
        p.quotation_id,
        p.amount,
        p.currency,
        p.method,
        p.status AS payment_status,
        p.gateway_order_id,
        p.gateway_payment_id,
        p.payment_link,
        p.paid_at,
        p.notes,
        p.created_at AS payment_created_at,

        r.request_no,
        r.part_required,
        r.quantity,
        r.status AS request_status,

        q.version_no,
        q.total_amount AS quotation_total,
        q.status AS quotation_status,
        q.valid_until

    FROM payments p

    INNER JOIN spare_part_requests r
        ON r.id = p.request_id

    LEFT JOIN quotations q
        ON q.id = p.quotation_id

    WHERE p.request_id = ?
      AND r.customer_id = ?

    ORDER BY p.id DESC

    LIMIT 1
");

$stmt->execute([
    $requestId,
    $customerId
]);

$payment = $stmt->fetch();


/*
|--------------------------------------------------------------------------
| PAYMENT STATUS
|--------------------------------------------------------------------------
*/

$isPaid =
    $payment['payment_status'] === 'paid';

$isPending =
    in_array(
        $payment['payment_status'],
        ['created', 'pending'],
        true
    );


/*
|--------------------------------------------------------------------------
| ALREADY REPORTED
|--------------------------------------------------------------------------
*/

$alreadyReported =
    stripos(
        (string) ($payment['notes'] ?? ''),
        'Customer reported that payment was made'
    ) !== false;


/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGE
|--------------------------------------------------------------------------
*/

$reportedSuccessfully =
    isset($_GET['reported']) &&
    $_GET['reported'] === '1';


/*
|--------------------------------------------------------------------------
| CUSTOMER INITIAL
|--------------------------------------------------------------------------
*/

$customerName =
    $customer['name'];

$initial =
    strtoupper(
        substr(
            trim($customerName),
            0,
            1
        )
    );

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
        Payment - Khammam Auto
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
            color: inherit;

            text-decoration: none;
        }


        button {
            font-family: inherit;
        }


        /* ======================================================
           SIDEBAR
        ====================================================== */

        .sidebar {

            position: fixed;

            left: 0;
            top: 0;
            bottom: 0;

            width: 250px;

            background: #ffffff;

            border-right: 1px solid #e7eaf0;

            padding: 24px 16px;

            z-index: 100;
        }


        .brand {

            display: flex;

            align-items: center;

            gap: 11px;

            padding:
                4px 10px 28px;
        }


        .brand-icon {

            width: 40px;
            height: 40px;

            border-radius: 12px;

            display: flex;

            align-items: center;
            justify-content: center;

            background: #111827;

            color: #ffffff;

            font-size: 18px;

            font-weight: 800;
        }


        .brand-text strong {

            display: block;

            font-size: 17px;

            font-weight: 800;
        }


        .brand-text span {

            display: block;

            margin-top: 3px;

            font-size: 11px;

            color: #7b8495;
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

            transition: .2s ease;
        }


        .nav a:hover {

            background: #f4f6f9;
        }


        .nav a.active {

            background: #111827;

            color: #ffffff;
        }


        .nav-icon {

            width: 22px;

            text-align: center;
        }


        /* ======================================================
           MAIN
        ====================================================== */

        .main {

            margin-left: 250px;

            min-height: 100vh;
        }


        .topbar {

            height: 72px;

            background: #ffffff;

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

            color: #626b7c;
        }


        .avatar {

            width: 38px;
            height: 38px;

            border-radius: 50%;

            background: #111827;

            color: #ffffff;

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: 700;
        }


        .content {

            max-width: 1120px;

            margin: 0 auto;

            padding:
                30px 32px 60px;
        }


        .back {

            display: inline-block;

            margin-bottom: 20px;

            color: #667085;

            font-size: 13px;

            font-weight: 600;
        }


        .back:hover {

            color: #111827;
        }


        .page-title {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 20px;

            margin-bottom: 25px;
        }


        .page-title h1 {

            margin: 0 0 7px;

            font-size: 28px;

            font-weight: 800;
        }


        .page-title p {

            margin: 0;

            color: #7a8394;

            font-size: 13px;
        }


        .status {

            display: inline-flex;

            padding:
                8px 14px;

            border-radius: 999px;

            font-size: 12px;

            font-weight: 750;
        }


        .status-pending {

            background: #fff1e8;

            color: #c45111;
        }


        .status-paid {

            background: #e7f8ee;

            color: #157347;
        }


        /* ======================================================
           CARD
        ====================================================== */

        .card {

            background: #ffffff;

            border: 1px solid #e7eaf0;

            border-radius: 16px;

            overflow: hidden;
        }


        .card-header {

            padding: 20px;

            border-bottom:
                1px solid #edf0f4;
        }


        .card-header h2 {

            margin: 0;

            font-size: 17px;
        }


        .card-body {

            padding: 24px;
        }


        .payment-layout {

            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 20px;
        }


        /* ======================================================
           AMOUNT
        ====================================================== */

        .amount-box {

            padding: 25px;

            border-radius: 14px;

            background: #111827;

            color: #ffffff;
        }


        .amount-label {

            font-size: 12px;

            opacity: .7;

            margin-bottom: 8px;
        }


        .amount {

            font-size: 38px;

            font-weight: 800;

            letter-spacing: -.5px;
        }


        /* ======================================================
           DETAILS
        ====================================================== */

        .details {

            margin-top: 20px;

            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 15px;
        }


        .detail {

            padding: 15px;

            border:
                1px solid #e7eaf0;

            border-radius: 10px;

            background: #ffffff;
        }


        .detail-label {

            color: #929aaa;

            font-size: 10px;

            font-weight: 700;

            text-transform: uppercase;

            margin-bottom: 5px;
        }


        .detail-value {

            font-size: 13px;

            font-weight: 650;

            word-break: break-word;
        }


        /* ======================================================
           PAY AREA
        ====================================================== */

        .pay-area {

            border:
                1px solid #e5e9ef;

            border-radius: 13px;

            padding: 25px;

            background: #ffffff;
        }


        .pay-area h3 {

            margin:
                0 0 8px;

            font-size: 18px;
        }


        .pay-area > p {

            color: #667085;

            font-size: 13px;

            line-height: 1.6;

            margin:
                0 0 18px;
        }


        /* ======================================================
           METHOD
        ====================================================== */

        .method-box {

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 14px;

            border:
                1px solid #e7eaf0;

            border-radius: 10px;

            margin-top: 16px;
        }


        .method-icon {

            width: 40px;
            height: 40px;

            border-radius: 10px;

            background: #f3f4f6;

            display: flex;

            align-items: center;
            justify-content: center;

            font-weight: 800;

            color: #111827;
        }


        .method-text strong {

            display: block;

            font-size: 13px;

            margin-bottom: 3px;
        }


        .method-text span {

            display: block;

            color: #7a8394;

            font-size: 11px;
        }


        /* ======================================================
           UPI BOX
        ====================================================== */

        .upi-box {

            border:
                1px solid #e4e8ee;

            background: #fafbfc;

            border-radius: 14px;

            padding: 18px;

            margin-top: 18px;
        }


        .upi-title {

            font-size: 12px;

            color: #7b8495;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: .05em;

            margin-bottom: 8px;
        }


        .upi-id-row {

            display: flex;

            align-items: center;

            gap: 10px;
        }


        .upi-id {

            flex: 1;

            min-width: 0;

            padding:
                13px 14px;

            border:
                1px solid #dfe4eb;

            background: #ffffff;

            border-radius: 10px;

            font-size: 15px;

            font-weight: 750;

            color: #111827;

            word-break: break-all;
        }


        .copy-button {

            border:
                1px solid #dfe4eb;

            background: #ffffff;

            color: #111827;

            border-radius: 10px;

            padding:
                12px 14px;

            font-size: 12px;

            font-weight: 750;

            cursor: pointer;

            white-space: nowrap;
        }


        .copy-button:hover {

            background: #f3f4f6;
        }


        .upi-note {

            margin:
                13px 0 0;

            color: #667085;

            font-size: 12px;

            line-height: 1.6;
        }


        /* ======================================================
           I HAVE PAID BUTTON
        ====================================================== */

        .paid-button {

            width: 100%;

            border: 0;

            border-radius: 10px;

            background: #111827;

            color: #ffffff;

            padding:
                15px 18px;

            font-size: 14px;

            font-weight: 750;

            cursor: pointer;

            margin-top: 18px;

            transition: .2s ease;
        }


        .paid-button:hover {

            background: #000000;
        }


        .paid-button:disabled {

            opacity: .6;

            cursor: not-allowed;
        }


        /* ======================================================
           INSTRUCTIONS
        ====================================================== */

        .instructions {

            margin-top: 18px;

            padding: 18px;

            border-radius: 12px;

            background: #f7f8fa;

            border:
                1px solid #e6e9ee;
        }


        .instructions h4 {

            margin:
                0 0 10px;

            font-size: 13px;
        }


        .instructions ol {

            margin: 0;

            padding-left: 20px;

            color: #667085;

            font-size: 12px;

            line-height: 1.8;
        }


        /* ======================================================
           WARNING / SUCCESS
        ====================================================== */

        .payment-info {

            margin-top: 18px;

            padding:
                15px;

            background: #fff8e8;

            border:
                1px solid #f1dc9d;

            border-radius: 11px;

            color: #805f00;

            font-size: 12px;

            line-height: 1.7;
        }


        .success-box {

            padding: 18px;

            border-radius: 11px;

            background: #eaf8ef;

            border:
                1px solid #c8ecd5;

            color: #187542;

            font-size: 13px;

            font-weight: 650;

            line-height: 1.7;
        }


        .already-box {

            margin-top: 18px;

            padding: 16px;

            border-radius: 11px;

            background: #eef5ff;

            border:
                1px solid #d4e5ff;

            color: #315b91;

            font-size: 12px;

            line-height: 1.7;
        }


        /* ======================================================
           MOBILE
        ====================================================== */

        .mobile-header {

            display: none;
        }


        @media (max-width: 900px) {

            .sidebar {

                transform:
                    translateX(-100%);

                transition: .25s;

                z-index: 1000;
            }


            .sidebar.open {

                transform:
                    translateX(0);
            }


            .main {

                margin-left: 0;
            }


            .mobile-header {

                display: flex;

                height: 62px;

                background: #ffffff;

                border-bottom:
                    1px solid #e7eaf0;

                align-items: center;

                justify-content:
                    space-between;

                padding:
                    0 18px;

                position: sticky;

                top: 0;

                z-index: 900;
            }


            .mobile-header button {

                border: 0;

                background: transparent;

                font-size: 22px;

                cursor: pointer;
            }


            .topbar {

                display: none;
            }


            .content {

                padding:
                    22px 18px 45px;
            }


            .payment-layout {

                grid-template-columns: 1fr;
            }
        }


        @media (max-width: 600px) {

            .content {

                padding:
                    20px 14px 40px;
            }


            .page-title {

                flex-direction: column;

                align-items:
                    flex-start;
            }


            .page-title h1 {

                font-size: 25px;
            }


            .details {

                grid-template-columns: 1fr;
            }


            .amount {

                font-size: 32px;
            }


            .upi-id-row {

                flex-direction: column;

                align-items: stretch;
            }


            .copy-button {

                width: 100%;
            }


            .pay-area {

                padding: 18px;
            }


            .card-body {

                padding: 16px;
            }
        }

    </style>

</head>


<body>


<div class="app">


    <!-- ======================================================
         MOBILE HEADER
    ======================================================= -->

    <div class="mobile-header">

        <button
            type="button"
            onclick="toggleSidebar()"
        >
            ☰
        </button>

        <strong>
            Khammam Auto
        </strong>

        <span></span>

    </div>


    <!-- ======================================================
         SIDEBAR
    ======================================================= -->

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
                <span class="nav-icon">⌂</span>
                Dashboard
            </a>


            <a
                href="/kmm-aut/public/pages/request/"
            >
                <span class="nav-icon">＋</span>
                Request a Part
            </a>


            <a
                href="/kmm-aut/public/pages/my-requests/"
                class="active"
            >
                <span class="nav-icon">▤</span>
                My Requests
            </a>


            <a href="#">
                <span class="nav-icon">▣</span>
                My Scooters
            </a>


            <a href="#">
                <span class="nav-icon">◉</span>
                My Profile
            </a>


            <a href="#">
                <span class="nav-icon">♧</span>
                Notifications
            </a>


            <a
                href="/kmm-aut/public/pages/logout/"
            >
                <span class="nav-icon">↪</span>
                Logout
            </a>

        </nav>

    </aside>


    <!-- ======================================================
         MAIN
    ======================================================= -->

    <main class="main">


        <!-- TOPBAR -->

        <header class="topbar">

            <div class="topbar-title">
                Payment
            </div>


            <div class="topbar-right">

                <span class="customer-name">
                    <?= e($customerName) ?>
                </span>


                <div class="avatar">
                    <?= e($initial) ?>
                </div>

            </div>

        </header>


        <!-- ==================================================
             CONTENT
        =================================================== -->

        <section class="content">


            <a
                href="/kmm-aut/public/pages/request-details/?id=<?= (int) $requestId ?>"
                class="back"
            >
                ← Back to Request
            </a>


            <!-- PAGE TITLE -->

            <div class="page-title">

                <div>

                    <h1>
                        Payment
                    </h1>

                    <p>
                        Complete payment for your accepted quotation.
                    </p>

                </div>


                <?php if ($isPaid): ?>

                    <span class="status status-paid">
                        Paid
                    </span>

                <?php else: ?>

                    <span class="status status-pending">
                        Payment Pending
                    </span>

                <?php endif; ?>

            </div>


            <!-- =================================================
                 MAIN CARD
            ================================================== -->

            <div class="card">


                <div class="card-header">

                    <h2>
                        Payment Details
                    </h2>

                </div>


                <div class="card-body">


                    <div class="payment-layout">


                        <!-- =================================================
                             LEFT SIDE
                        ================================================== -->

                        <div>


                            <div class="amount-box">

                                <div class="amount-label">
                                    Amount Payable
                                </div>


                                <div class="amount">
                                    <?= money($payment['amount']) ?>
                                </div>

                            </div>


                            <div class="details">


                                <div class="detail">

                                    <div class="detail-label">
                                        Request
                                    </div>

                                    <div class="detail-value">
                                        <?= e($payment['request_no']) ?>
                                    </div>

                                </div>


                                <div class="detail">

                                    <div class="detail-label">
                                        Quotation
                                    </div>

                                    <div class="detail-value">
                                        Version
                                        <?= e($payment['version_no']) ?>
                                    </div>

                                </div>


                                <div class="detail">

                                    <div class="detail-label">
                                        Part
                                    </div>

                                    <div class="detail-value">
                                        <?= e($payment['part_required']) ?>
                                    </div>

                                </div>


                                <div class="detail">

                                    <div class="detail-label">
                                        Quantity
                                    </div>

                                    <div class="detail-value">
                                        <?= e($payment['quantity']) ?>
                                    </div>

                                </div>


                            </div>


                        </div>


                        <!-- =================================================
                             RIGHT SIDE
                        ================================================== -->

                        <div class="pay-area">


                            <?php if ($isPaid): ?>


                                <h3>
                                    Payment Completed
                                </h3>


                                <p>
                                    Your payment has been verified
                                    and marked as paid.
                                </p>


                                <div class="success-box">

                                    ✓ Payment received successfully.

                                    <br><br>

                                    Your spare-part request can now
                                    continue to the next step.

                                </div>


                            <?php elseif ($isPending): ?>


                                <h3>
                                    Pay Using PhonePe / UPI
                                </h3>


                                <p>

                                    Please make the payment manually
                                    using PhonePe or any UPI application.

                                </p>


                                <!-- PAYMENT METHOD -->

                                <div class="method-box">

                                    <div class="method-icon">
                                        UPI
                                    </div>


                                    <div class="method-text">

                                        <strong>
                                            PhonePe / UPI
                                        </strong>

                                        <span>
                                            Manual UPI payment
                                        </span>

                                    </div>

                                </div>


                                <!-- UPI ID -->

                                <div class="upi-box">

                                    <div class="upi-title">
                                        UPI ID
                                    </div>


                                    <div class="upi-id-row">

                                        <div
                                            class="upi-id"
                                            id="upiId"
                                        >
                                            <?= e($upiId) ?>
                                        </div>


                                        <button
                                            type="button"
                                            class="copy-button"
                                            onclick="copyUpi()"
                                        >
                                            Copy UPI ID
                                        </button>

                                    </div>


                                    <p class="upi-note">

                                        Send exactly

                                        <strong>
                                            <?= money($payment['amount']) ?>
                                        </strong>

                                        to this UPI ID.

                                    </p>

                                </div>


                                <!-- =================================================
                                     I HAVE PAID
                                ================================================== -->

                                <?php if ($reportedSuccessfully): ?>


                                    <div
                                        class="success-box"
                                        style="margin-top:18px;"
                                    >

                                        ✓ Payment confirmation sent.

                                        <br><br>

                                        We have notified Khammam Auto
                                        that you made the payment.

                                        <br><br>

                                        Your payment will remain
                                        <strong>
                                            Pending
                                        </strong>
                                        until the admin verifies it.

                                    </div>


                                <?php elseif ($alreadyReported): ?>


                                    <div class="already-box">

                                        <strong>
                                            Payment confirmation already submitted.
                                        </strong>

                                        <br><br>

                                        Khammam Auto has been notified.
                                        Please wait while the payment is
                                        verified.

                                    </div>


                                <?php else: ?>


                                    <form
                                        method="POST"
                                        onsubmit="return confirmPaymentSubmitted();"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="payment_made"
                                        >


                                        <button
                                            type="submit"
                                            class="paid-button"
                                        >
                                            I Have Paid
                                        </button>

                                    </form>


                                <?php endif; ?>


                                <!-- =================================================
                                     HOW TO PAY
                                ================================================== -->

                                <div class="instructions">

                                    <h4>
                                        How to Pay
                                    </h4>


                                    <ol>

                                        <li>
                                            Open
                                            <strong>
                                                PhonePe
                                            </strong>
                                            or another UPI app.
                                        </li>


                                        <li>
                                            Select
                                            <strong>
                                                Send Money / UPI
                                            </strong>.
                                        </li>


                                        <li>
                                            Enter this UPI ID:

                                            <strong>
                                                <?= e($upiId) ?>
                                            </strong>
                                        </li>


                                        <li>
                                            Enter amount:

                                            <strong>
                                                <?= money($payment['amount']) ?>
                                            </strong>
                                        </li>


                                        <li>
                                            Complete the payment.
                                        </li>


                                        <li>
                                            Return here and click
                                            <strong>
                                                I Have Paid
                                            </strong>.
                                        </li>

                                    </ol>

                                </div>


                                <!-- =================================================
                                     VERIFICATION NOTICE
                                ================================================== -->

                                <div class="payment-info">

                                    <strong>
                                        Important
                                    </strong>

                                    <br><br>

                                    Clicking
                                    <strong>
                                        I Have Paid
                                    </strong>
                                    does not automatically mark the
                                    payment as Paid.

                                    <br><br>

                                    Khammam Auto will verify the UPI
                                    payment first.

                                    <br><br>

                                    Please do not make the payment twice.

                                </div>


                            <?php else: ?>


                                <h3>
                                    Payment Status
                                </h3>


                                <p>

                                    Current payment status:

                                    <strong>
                                        <?= e(
                                            ucfirst(
                                                $payment['payment_status']
                                            )
                                        ) ?>
                                    </strong>

                                </p>


                            <?php endif; ?>


                        </div>


                    </div>


                </div>


            </div>


        </section>


    </main>


</div>


<script>

/*
|--------------------------------------------------------------------------
| MOBILE SIDEBAR
|--------------------------------------------------------------------------
*/

function toggleSidebar()
{
    const sidebar =
        document.getElementById('sidebar');

    if (sidebar) {

        sidebar.classList.toggle('open');

    }
}


/*
|--------------------------------------------------------------------------
| COPY UPI
|--------------------------------------------------------------------------
*/

function copyUpi()
{
    const upiElement =
        document.getElementById('upiId');

    if (!upiElement) {
        return;
    }

    const upiId =
        upiElement.innerText.trim();


    if (navigator.clipboard) {

        navigator.clipboard.writeText(upiId)

            .then(function () {

                showCopySuccess();

            })

            .catch(function () {

                fallbackCopy(upiId);

            });

    } else {

        fallbackCopy(upiId);

    }
}


/*
|--------------------------------------------------------------------------
| FALLBACK COPY
|--------------------------------------------------------------------------
*/

function fallbackCopy(text)
{
    const textarea =
        document.createElement('textarea');

    textarea.value = text;

    textarea.style.position = 'fixed';

    textarea.style.left = '-9999px';

    document.body.appendChild(textarea);

    textarea.select();


    try {

        document.execCommand('copy');

        showCopySuccess();

    } catch (error) {

        alert(
            'Please copy the UPI ID manually: ' +
            text
        );

    }


    document.body.removeChild(textarea);
}


/*
|--------------------------------------------------------------------------
| COPY SUCCESS
|--------------------------------------------------------------------------
*/

function showCopySuccess()
{
    const button =
        document.querySelector('.copy-button');

    if (!button) {
        return;
    }


    const originalText =
        button.innerText;


    button.innerText =
        'Copied ✓';


    setTimeout(function () {

        button.innerText =
            originalText;

    }, 1800);
}


/*
|--------------------------------------------------------------------------
| PAYMENT CONFIRMATION
|--------------------------------------------------------------------------
*/

function confirmPaymentSubmitted()
{
    return confirm(
        'Have you completed the UPI payment of the displayed amount?'
    );
}

</script>


</body>

</html>