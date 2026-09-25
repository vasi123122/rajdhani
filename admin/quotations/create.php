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

function money($value)
{
    return number_format((float)$value, 2);
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
| LOAD REQUEST
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
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

$stmt->execute([$requestId]);

$request = $stmt->fetch();

if (!$request) {
    die('Request not found.');
}

/*
|--------------------------------------------------------------------------
| CUSTOM BRAND / MODEL
|--------------------------------------------------------------------------
*/

$brandName = $request['brand_name'] ?: $request['brand_other'];
$modelName = $request['model_name'] ?: $request['model_other'];

if (!$brandName) {
    $brandName = 'Not specified';
}

if (!$modelName) {
    $modelName = 'Not specified';
}

/*
|--------------------------------------------------------------------------
| LOAD CATEGORIES
|--------------------------------------------------------------------------
*/

$categoryStmt = $pdo->prepare("
    SELECT pc.name
    FROM request_categories rc
    INNER JOIN part_categories pc
        ON pc.id = rc.category_id
    WHERE rc.request_id = ?
    ORDER BY pc.name ASC
");

$categoryStmt->execute([$requestId]);

$categories = $categoryStmt->fetchAll();

$categoryNames = [];

foreach ($categories as $category) {
    $categoryNames[] = $category['name'];
}

$categoryText = $categoryNames
    ? implode(', ', $categoryNames)
    : 'Not specified';

/*
|--------------------------------------------------------------------------
| EXISTING QUOTATION VERSION
|--------------------------------------------------------------------------
*/

$versionStmt = $pdo->prepare("
    SELECT COALESCE(MAX(version_no), 0)
    FROM quotations
    WHERE request_id = ?
");

$versionStmt->execute([$requestId]);

$nextVersion = ((int)$versionStmt->fetchColumn()) + 1;

/*
|--------------------------------------------------------------------------
| FORM DEFAULTS
|--------------------------------------------------------------------------
*/

$errors = [];

$partPrice = $_POST['part_price'] ?? '';
$labourCharges = $_POST['labour_charges'] ?? '0';
$otherCharges = $_POST['other_charges'] ?? '0';
$discount = $_POST['discount'] ?? '0';
$validUntil = $_POST['valid_until'] ?? '';
$notes = $_POST['notes'] ?? '';

/*
|--------------------------------------------------------------------------
| CREATE QUOTATION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $partPriceFloat = (float)$partPrice;
    $labourFloat = (float)$labourCharges;
    $otherFloat = (float)$otherCharges;
    $discountFloat = (float)$discount;

    /*
    | Basic validation
    */

    if ($partPrice === '' || $partPriceFloat < 0) {
        $errors[] = 'Please enter the part price.';
    }

    if ($labourFloat < 0) {
        $errors[] = 'Labour charges cannot be negative.';
    }

    if ($otherFloat < 0) {
        $errors[] = 'Other charges cannot be negative.';
    }

    if ($discountFloat < 0) {
        $errors[] = 'Discount cannot be negative.';
    }

    /*
    | Calculate
    */

    $subtotal = $partPriceFloat + $labourFloat + $otherFloat;

    $totalAmount = $subtotal - $discountFloat;

    if ($totalAmount < 0) {
        $errors[] = 'Discount cannot be greater than the subtotal.';
    }

    /*
    | Valid until
    */

    if ($validUntil !== '') {

        $validDate = DateTime::createFromFormat('Y-m-d', $validUntil);

        if (!$validDate || $validDate->format('Y-m-d') !== $validUntil) {
            $errors[] = 'Please enter a valid quotation expiry date.';
        }
    }

    /*
    | Create quotation
    */

    if (!$errors) {

        try {

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | Insert quotation
            |--------------------------------------------------------------------------
            */

            $quotationStmt = $pdo->prepare("
                INSERT INTO quotations (
                    request_id,
                    version_no,
                    status,
                    labour_charges,
                    other_charges,
                    discount,
                    subtotal,
                    total_amount,
                    valid_until,
                    notes,
                    created_by
                )
                VALUES (
                    ?,
                    ?,
                    'sent',
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            $quotationStmt->execute([
                $requestId,
                $nextVersion,
                $labourFloat,
                $otherFloat,
                $discountFloat,
                $subtotal,
                $totalAmount,
                $validUntil !== '' ? $validUntil : null,
                trim($notes) !== '' ? trim($notes) : null,
                $adminId
            ]);

            $quotationId = (int)$pdo->lastInsertId();

            /*
            |--------------------------------------------------------------------------
            | Insert quotation item
            |--------------------------------------------------------------------------
            */

            $itemStmt = $pdo->prepare("
                INSERT INTO quotation_items (
                    quotation_id,
                    description,
                    quantity,
                    unit_price,
                    amount
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            $itemAmount = $partPriceFloat * (int)$request['quantity'];

            $itemStmt->execute([
                $quotationId,
                $request['part_required'],
                (int)$request['quantity'],
                $partPriceFloat,
                $itemAmount
            ]);

            /*
            |--------------------------------------------------------------------------
            | Update request status
            |--------------------------------------------------------------------------
            */

            $oldStatus = $request['status'];

            $updateRequest = $pdo->prepare("
                UPDATE spare_part_requests
                SET status = 'quotation_sent',
                    updated_at = NOW()
                WHERE id = ?
            ");

            $updateRequest->execute([$requestId]);

            /*
            |--------------------------------------------------------------------------
            | Status history
            |--------------------------------------------------------------------------
            */

            $historyStmt = $pdo->prepare("
                INSERT INTO request_status_history (
                    request_id,
                    old_status,
                    new_status,
                    changed_by,
                    note
                )
                VALUES (?, ?, 'quotation_sent', ?, ?)
            ");

            $historyStmt->execute([
                $requestId,
                $oldStatus,
                $adminId,
                'Quotation #' . $quotationId . ' sent to customer.'
            ]);

            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $pdo->commit();

            /*
            | Redirect to request view
            */

            header(
                'Location: /kmm-aut/admin/requests/view.php?id=' .
                $requestId .
                '&quotation_created=1'
            );

            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Unable to create quotation. Please try again.';
        }
    }
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

    <title>Create Quotation - Khammam Auto</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family:
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

        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */

        .sidebar {
            width: 280px;
            background: #101827;
            color: #fff;
            padding: 28px 18px;
            flex-shrink: 0;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 4px 12px 30px;
            border-bottom: 1px solid rgba(255,255,255,.10);
        }

        .brand-logo {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: #fff;
            color: #101827;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 800;
        }

        .brand-title {
            font-size: 20px;
            font-weight: 800;
        }

        .brand-subtitle {
            margin-top: 4px;
            font-size: 13px;
            color: #8d9ab1;
        }

        .menu-title {
            margin: 30px 12px 14px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            color: #71809b;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 15px 14px;
            margin-bottom: 7px;
            border-radius: 10px;
            color: #d6dcea;
            font-size: 15px;
            font-weight: 600;
        }

        .menu a:hover,
        .menu a.active {
            background: #fff;
            color: #172033;
        }

        .menu-icon {
            width: 20px;
            text-align: center;
        }

        .logout {
            margin-top: 35px;
        }

        /* MAIN */

        .main {
            flex: 1;
            min-width: 0;
        }

        .topbar {
            height: 78px;
            background: #fff;
            border-bottom: 1px solid #e4e8ef;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 34px;
        }

        .topbar-title {
            font-size: 21px;
            font-weight: 800;
        }

        .admin-name {
            color: #66738a;
            font-size: 14px;
        }

        .content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 36px;
        }

        .back-link {
            display: inline-block;
            color: #5c6d88;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 28px;
        }

        .page-heading h1 {
            margin: 0;
            font-size: 32px;
        }

        .page-heading p {
            margin: 9px 0 0;
            color: #71809a;
            font-size: 15px;
        }

        .request-badge {
            background: #eef4ff;
            color: #2563eb;
            padding: 10px 15px;
            border-radius: 9px;
            font-weight: 700;
            font-size: 13px;
        }

        /* CARDS */

        .card {
            background: #fff;
            border: 1px solid #e4e8ef;
            border-radius: 15px;
            margin-bottom: 22px;
            overflow: hidden;
        }

        .card-header {
            padding: 22px 25px;
            border-bottom: 1px solid #e8ebf0;
        }

        .card-header h2 {
            margin: 0;
            font-size: 18px;
        }

        .card-body {
            padding: 25px;
        }

        .request-grid {
            display: grid;
            grid-template-columns:
                repeat(4, minmax(0, 1fr));
            gap: 22px;
        }

        .info-label {
            color: #8995a8;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .6px;
            margin-bottom: 7px;
        }

        .info-value {
            font-size: 15px;
            font-weight: 700;
            color: #1c2638;
            word-break: break-word;
        }

        /* FORM */

        .form-grid {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 22px;
        }

        .field {
            margin-bottom: 4px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 700;
            color: #334155;
        }

        .required {
            color: #ef4444;
        }

        input,
        textarea {
            width: 100%;
            border: 1px solid #cfd7e3;
            border-radius: 9px;
            padding: 13px 14px;
            font-size: 15px;
            outline: none;
            background: #fff;
            color: #172033;
        }

        input:focus,
        textarea:focus {
            border-color: #2563eb;
            box-shadow:
                0 0 0 3px rgba(37,99,235,.10);
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .input-prefix {
            position: relative;
        }

        .input-prefix span {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-weight: 700;
        }

        .input-prefix input {
            padding-left: 32px;
        }

        .calculation {
            background: #f7f9fc;
            border: 1px solid #e3e8f0;
            border-radius: 12px;
            padding: 20px;
        }

        .calc-row {
            display: flex;
            justify-content: space-between;
            padding: 9px 0;
            color: #526078;
            font-size: 14px;
        }

        .calc-row.total {
            border-top: 1px solid #dfe5ee;
            margin-top: 8px;
            padding-top: 16px;
            font-size: 19px;
            font-weight: 800;
            color: #101827;
        }

        .negative {
            color: #dc2626;
        }

        /* ERRORS */

        .alert {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            color: #be123c;
            border-radius: 10px;
            padding: 15px 18px;
            margin-bottom: 22px;
        }

        .alert ul {
            margin: 0;
            padding-left: 20px;
        }

        /* ACTIONS */

        .actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-top: 25px;
        }

        .btn {
            border: 0;
            border-radius: 10px;
            padding: 14px 22px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
        }

        .btn-secondary {
            background: #fff;
            border: 1px solid #d5dce7;
            color: #45536a;
        }

        .btn-primary {
            background: #101827;
            color: #fff;
            min-width: 220px;
        }

        .btn-primary:hover {
            background: #1d293d;
        }

        .note {
            margin-top: 8px;
            color: #8995a8;
            font-size: 12px;
        }

        /* MOBILE */

        @media (max-width: 900px) {

            .sidebar {
                width: 220px;
            }

            .request-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .content {
                padding: 25px;
            }

        }

        @media (max-width: 700px) {

            .layout {
                display: block;
            }

            .sidebar {
                width: 100%;
            }

            .menu {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 5px;
            }

            .menu-title {
                margin-top: 20px;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px 15px;
            }

            .page-heading {
                display: block;
            }

            .page-heading h1 {
                font-size: 26px;
            }

            .request-badge {
                display: inline-block;
                margin-top: 15px;
            }

            .request-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .actions {
                flex-direction: column-reverse;
                align-items: stretch;
            }

            .btn {
                width: 100%;
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
                <div class="brand-title">
                    Khammam Auto
                </div>

                <div class="brand-subtitle">
                    Admin Panel
                </div>
            </div>

        </div>

        <div class="menu-title">
            MAIN MENU
        </div>

        <nav class="menu">

            <a href="/kmm-aut/admin/dashboard/">
                <span class="menu-icon">⌂</span>
                Dashboard
            </a>

            <a
                href="/kmm-aut/admin/requests/"
                class="active"
            >
                <span class="menu-icon">▣</span>
                Requests
            </a>

            <a href="#">
                <span class="menu-icon">♟</span>
                Customers
            </a>

            <a href="/kmm-aut/admin/quotations/">
                <span class="menu-icon">₹</span>
                Quotations
            </a>

            <a href="#">
                <span class="menu-icon">▤</span>
                Payments
            </a>

            <a href="#">
                <span class="menu-icon">♟</span>
                Notifications
            </a>

            <a href="#">
                <span class="menu-icon">⚙</span>
                Settings
            </a>

            <a
                href="/kmm-aut/admin/auth/logout/"
                class="logout"
            >
                <span class="menu-icon">↪</span>
                Logout
            </a>

        </nav>

    </aside>


    <!-- MAIN -->

    <main class="main">

        <header class="topbar">

            <div class="topbar-title">
                Create Quotation
            </div>

            <div class="admin-name">
                Khammam Auto Admin
            </div>

        </header>


        <section class="content">

            <a
                href="/kmm-aut/admin/requests/view.php?id=<?= (int)$requestId ?>"
                class="back-link"
            >
                ← Back to Request
            </a>


            <div class="page-heading">

                <div>

                    <h1>
                        Create Quotation
                    </h1>

                    <p>
                        Prepare a quotation for this spare-part request.
                    </p>

                </div>

                <div class="request-badge">
                    <?= e($request['request_no']) ?>
                </div>

            </div>


            <?php if ($errors): ?>

                <div class="alert">

                    <ul>

                        <?php foreach ($errors as $error): ?>

                            <li>
                                <?= e($error) ?>
                            </li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>


            <!-- REQUEST SUMMARY -->

            <div class="card">

                <div class="card-header">

                    <h2>
                        Request Summary
                    </h2>

                </div>

                <div class="card-body">

                    <div class="request-grid">

                        <div>
                            <div class="info-label">
                                Customer
                            </div>

                            <div class="info-value">
                                <?= e($request['customer_name']) ?>
                            </div>
                        </div>

                        <div>
                            <div class="info-label">
                                Phone
                            </div>

                            <div class="info-value">
                                <?= e($request['customer_phone'] ?: 'Not provided') ?>
                            </div>
                        </div>

                        <div>
                            <div class="info-label">
                                Part Required
                            </div>

                            <div class="info-value">
                                <?= e($request['part_required']) ?>
                            </div>
                        </div>

                        <div>
                            <div class="info-label">
                                Quantity
                            </div>

                            <div class="info-value">
                                <?= (int)$request['quantity'] ?>
                            </div>
                        </div>

                        <div>
                            <div class="info-label">
                                Category
                            </div>

                            <div class="info-value">
                                <?= e($categoryText) ?>
                            </div>
                        </div>

                        <div>
                            <div class="info-label">
                                Scooter Brand
                            </div>

                            <div class="info-value">
                                <?= e($brandName) ?>
                            </div>
                        </div>

                        <div>
                            <div class="info-label">
                                Scooter Model
                            </div>

                            <div class="info-value">
                                <?= e($modelName) ?>
                            </div>
                        </div>

                        <div>
                            <div class="info-label">
                                Quality
                            </div>

                            <div class="info-value">

                                <?php

                                $qualityLabels = [
                                    'genuine' => 'Company / Genuine',
                                    'first_quality' => 'First Quality / Premium',
                                    'either' => 'Either is Fine'
                                ];

                                echo e(
                                    $qualityLabels[$request['quality_preference']]
                                    ?? 'Not specified'
                                );

                                ?>

                            </div>
                        </div>

                    </div>

                </div>

            </div>


            <!-- QUOTATION FORM -->

            <form method="POST">

                <div class="card">

                    <div class="card-header">

                        <h2>
                            Quotation Amount
                        </h2>

                    </div>

                    <div class="card-body">

                        <div class="form-grid">

                            <div class="field">

                                <label>
                                    Part Price
                                    <span class="required">*</span>
                                </label>

                                <div class="input-prefix">

                                    <span>₹</span>

                                    <input
                                        type="number"
                                        name="part_price"
                                        id="part_price"
                                        min="0"
                                        step="0.01"
                                        value="<?= e($partPrice) ?>"
                                        placeholder="0.00"
                                        required
                                    >

                                </div>

                                <div class="note">
                                    Enter the unit price of the requested part.
                                </div>

                            </div>


                            <div class="field">

                                <label>
                                    Labour Charges
                                </label>

                                <div class="input-prefix">

                                    <span>₹</span>

                                    <input
                                        type="number"
                                        name="labour_charges"
                                        id="labour_charges"
                                        min="0"
                                        step="0.01"
                                        value="<?= e($labourCharges) ?>"
                                        placeholder="0.00"
                                    >

                                </div>

                            </div>


                            <div class="field">

                                <label>
                                    Other Charges
                                </label>

                                <div class="input-prefix">

                                    <span>₹</span>

                                    <input
                                        type="number"
                                        name="other_charges"
                                        id="other_charges"
                                        min="0"
                                        step="0.01"
                                        value="<?= e($otherCharges) ?>"
                                        placeholder="0.00"
                                    >

                                </div>

                            </div>


                            <div class="field">

                                <label>
                                    Discount
                                </label>

                                <div class="input-prefix">

                                    <span>₹</span>

                                    <input
                                        type="number"
                                        name="discount"
                                        id="discount"
                                        min="0"
                                        step="0.01"
                                        value="<?= e($discount) ?>"
                                        placeholder="0.00"
                                    >

                                </div>

                            </div>


                            <div class="field">

                                <label>
                                    Quotation Valid Until
                                </label>

                                <input
                                    type="date"
                                    name="valid_until"
                                    value="<?= e($validUntil) ?>"
                                >

                                <div class="note">
                                    Optional expiry date for this quotation.
                                </div>

                            </div>


                            <div class="field">

                                <label>
                                    Quotation Version
                                </label>

                                <input
                                    type="text"
                                    value="Version <?= (int)$nextVersion ?>"
                                    readonly
                                >

                            </div>


                            <div class="field full">

                                <label>
                                    Notes for Customer
                                </label>

                                <textarea
                                    name="notes"
                                    placeholder="Example: Part available. Installation charges included."><?= e($notes) ?></textarea>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- TOTAL -->

                <div class="card">

                    <div class="card-header">

                        <h2>
                            Quotation Summary
                        </h2>

                    </div>

                    <div class="card-body">

                        <div class="calculation">

                            <div class="calc-row">

                                <span>
                                    Part Amount
                                </span>

                                <strong id="displayPart">
                                    ₹0.00
                                </strong>

                            </div>

                            <div class="calc-row">

                                <span>
                                    Labour Charges
                                </span>

                                <strong id="displayLabour">
                                    ₹0.00
                                </strong>

                            </div>

                            <div class="calc-row">

                                <span>
                                    Other Charges
                                </span>

                                <strong id="displayOther">
                                    ₹0.00
                                </strong>

                            </div>

                            <div class="calc-row">

                                <span>
                                    Discount
                                </span>

                                <strong
                                    id="displayDiscount"
                                    class="negative"
                                >
                                    - ₹0.00
                                </strong>

                            </div>

                            <div class="calc-row">

                                <span>
                                    Subtotal
                                </span>

                                <strong id="displaySubtotal">
                                    ₹0.00
                                </strong>

                            </div>

                            <div class="calc-row total">

                                <span>
                                    Total Amount
                                </span>

                                <strong id="displayTotal">
                                    ₹0.00
                                </strong>

                            </div>

                        </div>


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
                                ₹ Create & Send Quotation
                            </button>

                        </div>

                    </div>

                </div>

            </form>

        </section>

    </main>

</div>


<script>

function numberValue(id) {

    const element = document.getElementById(id);

    if (!element) {
        return 0;
    }

    const value = parseFloat(element.value);

    return Number.isFinite(value) ? value : 0;
}

function rupees(value) {

    return '₹' + value.toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

}

function calculateQuotation() {

    const quantity =
        <?= (int)$request['quantity'] ?>;

    const unitPrice =
        numberValue('part_price');

    const labour =
        numberValue('labour_charges');

    const other =
        numberValue('other_charges');

    const discount =
        numberValue('discount');

    const partAmount =
        unitPrice * quantity;

    const subtotal =
        partAmount + labour + other;

    const total =
        Math.max(0, subtotal - discount);

    document.getElementById('displayPart').textContent =
        rupees(partAmount);

    document.getElementById('displayLabour').textContent =
        rupees(labour);

    document.getElementById('displayOther').textContent =
        rupees(other);

    document.getElementById('displayDiscount').textContent =
        '- ' + rupees(discount);

    document.getElementById('displaySubtotal').textContent =
        rupees(subtotal);

    document.getElementById('displayTotal').textContent =
        rupees(total);
}


[
    'part_price',
    'labour_charges',
    'other_charges',
    'discount'
].forEach(function(id) {

    const element =
        document.getElementById(id);

    if (element) {

        element.addEventListener(
            'input',
            calculateQuotation
        );

    }

});


calculateQuotation();

</script>

</body>

</html>