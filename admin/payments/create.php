<?php

session_start();

require_once __DIR__ . '/../../database/database.php';

/*
|--------------------------------------------------------------------------
| ADMIN LOGIN CHECK
|--------------------------------------------------------------------------
*/

$adminId = $_SESSION['admin_id']
    ?? $_SESSION['admin_user_id']
    ?? $_SESSION['user_id']
    ?? null;

if (!$adminId) {
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
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount)
{
    return '₹ ' . number_format((float)$amount, 2);
}

/*
|--------------------------------------------------------------------------
| REQUEST ID
|--------------------------------------------------------------------------
*/

$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);

if (!$requestId) {
    header('Location: /kmm-aut/admin/requests/');
    exit;
}

/*
|--------------------------------------------------------------------------
| LOAD REQUEST + CUSTOMER
|--------------------------------------------------------------------------
*/

$requestStmt = $pdo->prepare("
    SELECT
        r.*,
        u.name AS customer_name,
        u.email AS customer_email,
        u.phone AS customer_phone,
        u.whatsapp AS customer_whatsapp,
        sb.name AS brand_name,
        sm.name AS model_name
    FROM spare_part_requests r
    INNER JOIN users u
        ON u.id = r.customer_id
    LEFT JOIN scooter_brands sb
        ON sb.id = r.brand_id
    LEFT JOIN scooter_models sm
        ON sm.id = r.model_id
    WHERE r.id = ?
    LIMIT 1
");

$requestStmt->execute([$requestId]);

$request = $requestStmt->fetch();

if (!$request) {
    die('Request not found.');
}

/*
|--------------------------------------------------------------------------
| ONLY ACCEPTED REQUESTS
|--------------------------------------------------------------------------
*/

if ($request['status'] !== 'accepted') {

    $message = 'Payment can be created only after the quotation is accepted.';

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment | Khammam Auto</title>

        <style>
            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                font-family: Arial, Helvetica, sans-serif;
                background: #f4f6fa;
                color: #182033;
            }

            .wrap {
                max-width: 700px;
                margin: 80px auto;
                padding: 20px;
            }

            .card {
                background: #fff;
                border-radius: 16px;
                padding: 35px;
                box-shadow: 0 10px 30px rgba(0,0,0,.06);
            }

            h1 {
                margin-top: 0;
            }

            .error {
                background: #fff1f1;
                border: 1px solid #ffcaca;
                color: #c62828;
                padding: 15px;
                border-radius: 10px;
                margin: 20px 0;
            }

            .btn {
                display: inline-block;
                background: #111827;
                color: #fff;
                padding: 12px 20px;
                border-radius: 9px;
                text-decoration: none;
                font-weight: 700;
            }
        </style>
    </head>

    <body>

    <div class="wrap">

        <div class="card">

            <h1>Payment</h1>

            <div class="error">
                <?= e($message) ?>
            </div>

            <p>
                Request:
                <strong><?= e($request['request_no']) ?></strong>
            </p>

            <p>
                Current Status:
                <strong><?= e(ucwords(str_replace('_', ' ', $request['status']))) ?></strong>
            </p>

            <a
                class="btn"
                href="/kmm-aut/admin/requests/view.php?id=<?= (int)$requestId ?>"
            >
                ← Back to Request
            </a>

        </div>

    </div>

    </body>
    </html>
    <?php

    exit;
}

/*
|--------------------------------------------------------------------------
| GET LATEST ACCEPTED QUOTATION
|--------------------------------------------------------------------------
*/

$quotationStmt = $pdo->prepare("
    SELECT *
    FROM quotations
    WHERE request_id = ?
      AND status = 'accepted'
    ORDER BY version_no DESC
    LIMIT 1
");

$quotationStmt->execute([$requestId]);

$quotation = $quotationStmt->fetch();

if (!$quotation) {
    die('Accepted quotation not found.');
}

/*
|--------------------------------------------------------------------------
| CHECK EXISTING PAYMENT
|--------------------------------------------------------------------------
*/

$paymentStmt = $pdo->prepare("
    SELECT *
    FROM payments
    WHERE request_id = ?
      AND quotation_id = ?
    ORDER BY id DESC
    LIMIT 1
");

$paymentStmt->execute([
    $requestId,
    $quotation['id']
]);

$existingPayment = $paymentStmt->fetch();

/*
|--------------------------------------------------------------------------
| CREATE PAYMENT
|--------------------------------------------------------------------------
*/

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate payment
        |--------------------------------------------------------------------------
        */

        if (
            $existingPayment &&
            in_array(
                $existingPayment['status'],
                ['pending', 'paid'],
                true
            )
        ) {

            throw new Exception(
                'A payment already exists for this accepted quotation.'
            );
        }

        $pdo->beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | Create payment
        |--------------------------------------------------------------------------
        */

        $insertPayment = $pdo->prepare("
            INSERT INTO payments
            (
                request_id,
                quotation_id,
                amount,
                currency,
                method,
                status,
                created_at,
                updated_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                'INR',
                'other',
                'pending',
                NOW(),
                NOW()
            )
        ");

        $insertPayment->execute([
            $requestId,
            $quotation['id'],
            $quotation['total_amount']
        ]);

        $paymentId = $pdo->lastInsertId();

        /*
        |--------------------------------------------------------------------------
        | Update request status
        |--------------------------------------------------------------------------
        */

        $updateRequest = $pdo->prepare("
            UPDATE spare_part_requests
            SET status = 'payment_pending',
                updated_at = NOW()
            WHERE id = ?
        ");

        $updateRequest->execute([
            $requestId
        ]);

        /*
        |--------------------------------------------------------------------------
        | Status History
        |--------------------------------------------------------------------------
        */

        $historyStmt = $pdo->prepare("
            INSERT INTO request_status_history
            (
                request_id,
                old_status,
                new_status,
                changed_by,
                note,
                created_at
            )
            VALUES
            (
                ?,
                'accepted',
                'payment_pending',
                ?,
                ?,
                NOW()
            )
        ");

        $historyStmt->execute([
            $requestId,
            $adminId,
            'Payment created for accepted quotation.'
        ]);

        /*
        |--------------------------------------------------------------------------
        | Activity Log
        |--------------------------------------------------------------------------
        */

        try {

            $activityStmt = $pdo->prepare("
                INSERT INTO activity_logs
                (
                    user_id,
                    action,
                    entity_type,
                    entity_id,
                    description,
                    created_at
                )
                VALUES
                (
                    ?,
                    'payment_created',
                    'payment',
                    ?,
                    ?,
                    NOW()
                )
            ");

            $activityStmt->execute([
                $adminId,
                $paymentId,
                'Payment created for request ' . $request['request_no']
            ]);

        } catch (Throwable $activityError) {
            /*
             * Activity logging should not stop payment creation
             * if the table structure differs.
             */
        }

        $pdo->commit();

        /*
        |--------------------------------------------------------------------------
        | Redirect back to request
        |--------------------------------------------------------------------------
        */

        header(
            'Location: /kmm-aut/admin/requests/view.php?id='
            . $requestId
            . '&payment_created=1'
        );

        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| PAGE
|--------------------------------------------------------------------------
*/

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Create Payment | Khammam Auto</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family:
                Inter,
                Arial,
                Helvetica,
                sans-serif;
            background: #f4f6fa;
            color: #172033;
        }

        .layout {
            min-height: 100vh;
            display: flex;
        }

        /*
        |--------------------------------------------------------------------------
        | SIDEBAR
        |--------------------------------------------------------------------------
        */

        .sidebar {
            width: 315px;
            background: #101827;
            color: #fff;
            min-height: 100vh;
            padding: 32px 20px;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 15px 28px;
            border-bottom: 1px solid rgba(255,255,255,.12);
        }

        .brand-logo {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: #fff;
            color: #101827;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 900;
        }

        .brand-name {
            font-size: 20px;
            font-weight: 800;
        }

        .brand-sub {
            margin-top: 5px;
            color: #9ba7bd;
            font-size: 14px;
        }

        .menu-title {
            margin: 28px 15px 14px;
            color: #71809a;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .nav a {
            display: flex;
            align-items: center;
            gap: 15px;
            color: #dce3ef;
            text-decoration: none;
            padding: 17px 15px;
            border-radius: 12px;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .nav a:hover {
            background: rgba(255,255,255,.08);
        }

        .nav a.active {
            background: #fff;
            color: #101827;
        }

        .logout {
            position: absolute;
            left: 20px;
            right: 20px;
            bottom: 25px;
        }

        .logout a {
            color: #ff8f8f;
        }

        /*
        |--------------------------------------------------------------------------
        | MAIN
        |--------------------------------------------------------------------------
        */

        .main {
            margin-left: 315px;
            width: calc(100% - 315px);
            min-height: 100vh;
        }

        .topbar {
            height: 85px;
            background: #fff;
            border-bottom: 1px solid #e5e8ee;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 40px;
        }

        .topbar h2 {
            margin: 0;
            font-size: 20px;
        }

        .admin-name {
            color: #64718a;
            font-size: 14px;
        }

        .content {
            max-width: 1100px;
            margin: 0 auto;
            padding: 45px 35px 70px;
        }

        .back {
            color: #63728c;
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 25px;
        }

        .page-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 30px;
        }

        .page-title h1 {
            margin: 0;
            font-size: 34px;
        }

        .page-title p {
            margin: 8px 0 0;
            color: #71809a;
        }

        /*
        |--------------------------------------------------------------------------
        | GRID
        |--------------------------------------------------------------------------
        */

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        .card {
            background: #fff;
            border: 1px solid #e3e7ee;
            border-radius: 18px;
            overflow: hidden;
        }

        .card-header {
            padding: 24px 28px;
            border-bottom: 1px solid #e8ebf0;
        }

        .card-header h3 {
            margin: 0;
            font-size: 20px;
        }

        .card-body {
            padding: 28px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 15px 0;
            border-bottom: 1px solid #edf0f4;
        }

        .info-row:last-child {
            border-bottom: 0;
        }

        .label {
            color: #7a879d;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .value {
            font-weight: 700;
            text-align: right;
        }

        /*
        |--------------------------------------------------------------------------
        | PAYMENT BOX
        |--------------------------------------------------------------------------
        */

        .payment-box {
            background: #f7f9fc;
            border: 1px solid #e0e5ec;
            border-radius: 14px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .amount-label {
            color: #71809a;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .amount {
            font-size: 38px;
            font-weight: 900;
            color: #111827;
        }

        .status {
            display: inline-flex;
            padding: 8px 13px;
            border-radius: 999px;
            background: #e9defe;
            color: #6840d8;
            font-size: 13px;
            font-weight: 800;
            margin-top: 12px;
        }

        .notice {
            background: #fff8e7;
            border: 1px solid #f1dc9b;
            color: #7b5d08;
            padding: 15px;
            border-radius: 10px;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        /*
        |--------------------------------------------------------------------------
        | BUTTONS
        |--------------------------------------------------------------------------
        */

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            border: 0;
            border-radius: 10px;
            padding: 14px 22px;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background: #101827;
            color: #fff;
        }

        .btn-secondary {
            background: #fff;
            color: #172033;
            border: 1px solid #d7dde6;
        }

        .btn-primary:hover {
            background: #1b2638;
        }

        /*
        |--------------------------------------------------------------------------
        | ERROR
        |--------------------------------------------------------------------------
        */

        .error {
            background: #fff0f0;
            border: 1px solid #ffc7c7;
            color: #c62828;
            padding: 16px 18px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 900px) {

            .sidebar {
                width: 240px;
            }

            .main {
                margin-left: 240px;
                width: calc(100% - 240px);
            }

            .grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 700px) {

            .sidebar {
                position: relative;
                width: 100%;
                min-height: auto;
            }

            .main {
                margin-left: 0;
                width: 100%;
            }

            .layout {
                display: block;
            }

            .logout {
                position: static;
                margin-top: 20px;
            }

            .topbar {
                padding: 0 20px;
            }

            .content {
                padding: 30px 18px 50px;
            }

            .page-title {
                display: block;
            }

            .page-title h1 {
                font-size: 28px;
            }

        }

    </style>

</head>

<body>

<div class="layout">

    <!-- SIDEBAR -->

    <aside class="sidebar">

        <div class="brand">

            <div class="brand-logo">
                KA
            </div>

            <div>
                <div class="brand-name">
                    Khammam Auto
                </div>

                <div class="brand-sub">
                    Admin Panel
                </div>
            </div>

        </div>


        <div class="menu-title">
            MAIN MENU
        </div>


        <nav class="nav">

            <a href="/kmm-aut/admin/dashboard/">
                ◇ Dashboard
            </a>

            <a href="/kmm-aut/admin/requests/">
                ▣ Requests
            </a>

            <a href="#">
                ♟ Customers
            </a>

            <a href="/kmm-aut/admin/quotations/create.php?request_id=<?= (int)$requestId ?>">
                ₹ Quotations
            </a>

            <a href="#" class="active">
                ▤ Payments
            </a>

            <a href="#">
                ♟ Notifications
            </a>

            <a href="#">
                ⚙ Settings
            </a>

        </nav>


        <div class="logout">

            <nav class="nav">

                <a href="/kmm-aut/admin/auth/logout/">
                    ↪ Logout
                </a>

            </nav>

        </div>

    </aside>


    <!-- MAIN -->

    <main class="main">

        <header class="topbar">

            <h2>
                Payment Management
            </h2>

            <div class="admin-name">
                Khammam Auto Admin
            </div>

        </header>


        <section class="content">

            <a
                class="back"
                href="/kmm-aut/admin/requests/view.php?id=<?= (int)$requestId ?>"
            >
                ← Back to Request
            </a>


            <div class="page-title">

                <div>

                    <h1>
                        Create Payment
                    </h1>

                    <p>
                        Create payment for the accepted quotation.
                    </p>

                </div>

            </div>


            <?php if ($error): ?>

                <div class="error">
                    <?= e($error) ?>
                </div>

            <?php endif; ?>


            <div class="grid">

                <!-- REQUEST INFORMATION -->

                <div class="card">

                    <div class="card-header">

                        <h3>
                            Request Information
                        </h3>

                    </div>

                    <div class="card-body">

                        <div class="info-row">

                            <div class="label">
                                Request ID
                            </div>

                            <div class="value">
                                <?= e($request['request_no']) ?>
                            </div>

                        </div>


                        <div class="info-row">

                            <div class="label">
                                Customer
                            </div>

                            <div class="value">
                                <?= e($request['customer_name']) ?>
                            </div>

                        </div>


                        <div class="info-row">

                            <div class="label">
                                Phone
                            </div>

                            <div class="value">
                                <?= e($request['customer_phone']) ?>
                            </div>

                        </div>


                        <div class="info-row">

                            <div class="label">
                                Part
                            </div>

                            <div class="value">
                                <?= e($request['part_required']) ?>
                            </div>

                        </div>


                        <div class="info-row">

                            <div class="label">
                                Quantity
                            </div>

                            <div class="value">
                                <?= e($request['quantity']) ?>
                            </div>

                        </div>


                        <div class="info-row">

                            <div class="label">
                                Scooter
                            </div>

                            <div class="value">

                                <?php

                                $brand = $request['brand_name']
                                    ?: $request['brand_other']
                                    ?: 'Not specified';

                                $model = $request['model_name']
                                    ?: $request['model_other']
                                    ?: 'Not specified';

                                ?>

                                <?= e($brand) ?>
                                /
                                <?= e($model) ?>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- PAYMENT -->

                <div class="card">

                    <div class="card-header">

                        <h3>
                            Payment Details
                        </h3>

                    </div>

                    <div class="card-body">

                        <div class="payment-box">

                            <div class="amount-label">
                                Accepted Quotation Amount
                            </div>

                            <div class="amount">
                                <?= money($quotation['total_amount']) ?>
                            </div>

                            <div class="status">
                                Quotation Accepted
                            </div>

                        </div>


                        <div class="info-row">

                            <div class="label">
                                Quotation
                            </div>

                            <div class="value">
                                Version <?= e($quotation['version_no']) ?>
                            </div>

                        </div>


                        <div class="info-row">

                            <div class="label">
                                Payment Amount
                            </div>

                            <div class="value">
                                <?= money($quotation['total_amount']) ?>
                            </div>

                        </div>


                        <div class="info-row">

                            <div class="label">
                                Currency
                            </div>

                            <div class="value">
                                INR
                            </div>

                        </div>


                        <div class="notice">

                            This will create a payment record with
                            <strong>Pending</strong> status and move the
                            request to <strong>Payment Pending</strong>.

                            Razorpay payment integration can be connected
                            after this payment flow is confirmed.

                        </div>


                        <?php if ($existingPayment): ?>

                            <div class="notice">

                                A payment record already exists for this
                                quotation.

                                <br><br>

                                Payment ID:
                                <strong>
                                    #<?= e($existingPayment['id']) ?>
                                </strong>

                                <br>

                                Status:
                                <strong>
                                    <?= e(ucwords(str_replace(
                                        '_',
                                        ' ',
                                        $existingPayment['status']
                                    ))) ?>
                                </strong>

                            </div>


                            <div class="actions">

                                <a
                                    href="/kmm-aut/admin/requests/view.php?id=<?= (int)$requestId ?>"
                                    class="btn btn-secondary"
                                >
                                    Back to Request
                                </a>

                            </div>

                        <?php else: ?>

                            <form
                                method="POST"
                                onsubmit="return confirmPaymentCreation();"
                            >

                                <div class="actions">

                                    <a
                                        href="/kmm-aut/admin/requests/view.php?id=<?= (int)$requestId ?>"
                                        class="btn btn-secondary"
                                    >
                                        Cancel
                                    </a>

                                    <button
                                        type="submit"
                                        class="btn btn-primary"
                                    >
                                        Create Payment
                                    </button>

                                </div>

                            </form>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </section>

    </main>

</div>


<script>

function confirmPaymentCreation()
{
    return confirm(
        'Create a payment of ₹<?= number_format((float)$quotation['total_amount'], 2) ?> for this accepted quotation?'
    );
}

</script>

</body>

</html>