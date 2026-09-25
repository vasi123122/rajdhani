<?php

session_start();

require_once __DIR__ . '/../../../database/database.php';

/*
|--------------------------------------------------------------------------
| Customer Login Check
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
    header('Location: /kmm-aut/public/pages/login/');
    exit;
}

$customerId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['request_details_csrf'])) {
    $_SESSION['request_details_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['request_details_csrf'];

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function statusLabel($status)
{
    $labels = [
        'new'             => 'New',
        'contacted'       => 'Contacted',
        'quotation_sent'  => 'Quotation Sent',
        'accepted'        => 'Accepted',
        'payment_pending' => 'Payment Pending',
        'paid'            => 'Paid',
        'completed'       => 'Completed',
        'cancelled'       => 'Cancelled',
        'rejected'        => 'Rejected'
    ];

    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function statusClass($status)
{
    $classes = [
        'new'             => 'status-new',
        'contacted'       => 'status-contacted',
        'quotation_sent'  => 'status-quotation',
        'accepted'        => 'status-accepted',
        'payment_pending' => 'status-payment',
        'paid'            => 'status-paid',
        'completed'       => 'status-completed',
        'cancelled'       => 'status-cancelled',
        'rejected'        => 'status-rejected'
    ];

    return $classes[$status] ?? 'status-default';
}

function qualityLabel($quality)
{
    $labels = [
        'genuine'      => 'Genuine',
        'first_quality'=> 'First Quality',
        'either'       => 'Either'
    ];

    return $labels[$quality] ?? 'Not specified';
}

function formatDateTime($date)
{
    if (!$date) {
        return '-';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return e($date);
    }

    return date('d M Y, h:i A', $timestamp);
}

function formatMoney($amount)
{
    return '₹' . number_format((float) $amount, 2);
}

/*
|--------------------------------------------------------------------------
| Request ID
|--------------------------------------------------------------------------
*/

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$requestId || $requestId <= 0) {
    header('Location: /kmm-aut/public/pages/my-requests/');
    exit;
}

/*
|--------------------------------------------------------------------------
| Handle Quotation Accept / Reject
|--------------------------------------------------------------------------
*/

$actionMessage = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        empty($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $actionMessage = 'Invalid request. Please refresh the page and try again.';
        $actionType = 'error';

    } else {

        $quotationAction = $_POST['quotation_action'] ?? '';

        if (
            !in_array(
                $quotationAction,
                ['accept', 'reject'],
                true
            )
        ) {
            $actionMessage = 'Invalid quotation action.';
            $actionType = 'error';

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | Verify request belongs to logged-in customer
                |--------------------------------------------------------------------------
                */

                $verifyStmt = $pdo->prepare("
                    SELECT
                        id,
                        request_no,
                        status
                    FROM spare_part_requests
                    WHERE id = ?
                      AND customer_id = ?
                    LIMIT 1
                ");

                $verifyStmt->execute([
                    $requestId,
                    $customerId
                ]);

                $verifiedRequest = $verifyStmt->fetch();

                if (!$verifiedRequest) {
                    $actionMessage = 'Request not found.';
                    $actionType = 'error';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Get Latest Quotation
                    |--------------------------------------------------------------------------
                    */

                    $quotationStmt = $pdo->prepare("
                        SELECT *
                        FROM quotations
                        WHERE request_id = ?
                        ORDER BY version_no DESC, id DESC
                        LIMIT 1
                    ");

                    $quotationStmt->execute([
                        $requestId
                    ]);

                    $latestQuotation = $quotationStmt->fetch();

                    if (!$latestQuotation) {

                        $actionMessage = 'No quotation is available for this request.';
                        $actionType = 'error';

                    } elseif ($latestQuotation['status'] !== 'sent') {

                        $actionMessage = 'This quotation is no longer available for acceptance or rejection.';
                        $actionType = 'error';

                    } elseif (
                        !empty($latestQuotation['valid_until']) &&
                        $latestQuotation['valid_until'] < date('Y-m-d')
                    ) {

                        $actionMessage = 'This quotation has expired.';
                        $actionType = 'error';

                    } else {

                        $pdo->beginTransaction();

                        if ($quotationAction === 'accept') {

                            /*
                            |--------------------------------------------------------------------------
                            | Accept quotation
                            |--------------------------------------------------------------------------
                            */

                            $updateQuotation = $pdo->prepare("
                                UPDATE quotations
                                SET
                                    status = 'accepted',
                                    accepted_at = NOW()
                                WHERE id = ?
                                  AND request_id = ?
                                  AND status = 'sent'
                            ");

                            $updateQuotation->execute([
                                $latestQuotation['id'],
                                $requestId
                            ]);

                            /*
                            |--------------------------------------------------------------------------
                            | Move request to Payment Pending
                            |--------------------------------------------------------------------------
                            |
                            | Customer accepted the quotation.
                            | The quotation itself becomes accepted, then the request moves
                            | to payment_pending and a payment record is created.
                            |
                            | Razorpay/payment-link integration can be added later by Admin.
                            |
                            */

                            $updateRequest = $pdo->prepare("
                                UPDATE spare_part_requests
                                SET status = 'payment_pending'
                                WHERE id = ?
                                  AND customer_id = ?
                            ");

                            $updateRequest->execute([
                                $requestId,
                                $customerId
                            ]);

                            /*
                            |--------------------------------------------------------------------------
                            | Status History - Accepted
                            |--------------------------------------------------------------------------
                            */

                            $historyStmt = $pdo->prepare("
                                INSERT INTO request_status_history
                                (
                                    request_id,
                                    old_status,
                                    new_status,
                                    changed_by,
                                    note
                                )
                                VALUES (?, ?, ?, ?, ?)
                            ");

                            $historyStmt->execute([
                                $requestId,
                                $verifiedRequest['status'],
                                'accepted',
                                $customerId,
                                'Customer accepted the quotation.'
                            ]);

                            /*
                            |--------------------------------------------------------------------------
                            | Status History - Payment Pending
                            |--------------------------------------------------------------------------
                            */

                            $paymentHistoryStmt = $pdo->prepare("
                                INSERT INTO request_status_history
                                (
                                    request_id,
                                    old_status,
                                    new_status,
                                    changed_by,
                                    note
                                )
                                VALUES (?, ?, ?, ?, ?)
                            ");

                            $paymentHistoryStmt->execute([
                                $requestId,
                                'accepted',
                                'payment_pending',
                                $customerId,
                                'Payment is pending after quotation acceptance.'
                            ]);

                            /*
                            |--------------------------------------------------------------------------
                            | Create Payment Record
                            |--------------------------------------------------------------------------
                            */

                            $paymentCheckStmt = $pdo->prepare("
                                SELECT id
                                FROM payments
                                WHERE request_id = ?
                                  AND quotation_id = ?
                                ORDER BY id DESC
                                LIMIT 1
                            ");

                            $paymentCheckStmt->execute([
                                $requestId,
                                $latestQuotation['id']
                            ]);

                            $existingPayment = $paymentCheckStmt->fetch();

                            if (!$existingPayment) {

                                $createPaymentStmt = $pdo->prepare("
                                    INSERT INTO payments
                                    (
                                        request_id,
                                        quotation_id,
                                        amount,
                                        currency,
                                        method,
                                        status
                                    )
                                    VALUES (?, ?, ?, 'INR', 'razorpay', 'pending')
                                ");

                                $createPaymentStmt->execute([
                                    $requestId,
                                    $latestQuotation['id'],
                                    $latestQuotation['total_amount']
                                ]);
                            }

                            $pdo->commit();

                            $actionMessage = 'Quotation accepted successfully. Payment is now pending.';
                            $actionType = 'success';

                        } else {

                            /*
                            |--------------------------------------------------------------------------
                            | Reject quotation
                            |--------------------------------------------------------------------------
                            */

                            $updateQuotation = $pdo->prepare("
                                UPDATE quotations
                                SET
                                    status = 'rejected',
                                    rejected_at = NOW()
                                WHERE id = ?
                                  AND request_id = ?
                                  AND status = 'sent'
                            ");

                            $updateQuotation->execute([
                                $latestQuotation['id'],
                                $requestId
                            ]);

                            $updateRequest = $pdo->prepare("
                                UPDATE spare_part_requests
                                SET status = 'rejected'
                                WHERE id = ?
                                  AND customer_id = ?
                            ");

                            $updateRequest->execute([
                                $requestId,
                                $customerId
                            ]);

                            $historyStmt = $pdo->prepare("
                                INSERT INTO request_status_history
                                (
                                    request_id,
                                    old_status,
                                    new_status,
                                    changed_by,
                                    note
                                )
                                VALUES (?, ?, ?, ?, ?)
                            ");

                            $historyStmt->execute([
                                $requestId,
                                $verifiedRequest['status'],
                                'rejected',
                                $customerId,
                                'Customer rejected the quotation.'
                            ]);

                            $pdo->commit();

                            $actionMessage = 'Quotation rejected.';
                            $actionType = 'success';
                        }
                    }
                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Khammam Auto Request Details Error: ' .
                    $e->getMessage()
                );

                $actionMessage = 'Something went wrong. Please try again.';
                $actionType = 'error';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Request
|--------------------------------------------------------------------------
*/

$requestStmt = $pdo->prepare("
    SELECT
        r.*,

        sb.name AS brand_name,
        sm.name AS model_name,

        u.name AS customer_name,
        u.email AS customer_email,
        u.phone AS customer_phone,
        u.whatsapp AS customer_whatsapp

    FROM spare_part_requests r

    LEFT JOIN scooter_brands sb
        ON sb.id = r.brand_id

    LEFT JOIN scooter_models sm
        ON sm.id = r.model_id

    INNER JOIN users u
        ON u.id = r.customer_id

    WHERE r.id = ?
      AND r.customer_id = ?

    LIMIT 1
");

$requestStmt->execute([
    $requestId,
    $customerId
]);

$request = $requestStmt->fetch();

if (!$request) {
    header('Location: /kmm-aut/public/pages/my-requests/');
    exit;
}

/*
|--------------------------------------------------------------------------
| Category
|--------------------------------------------------------------------------
*/

$categoryStmt = $pdo->prepare("
    SELECT
        pc.name
    FROM request_categories rc

    INNER JOIN part_categories pc
        ON pc.id = rc.category_id

    WHERE rc.request_id = ?

    ORDER BY pc.sort_order ASC, pc.name ASC
");

$categoryStmt->execute([
    $requestId
]);

$categories = $categoryStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Request Files
|--------------------------------------------------------------------------
*/

$fileStmt = $pdo->prepare("
    SELECT
        id,
        file_type,
        slot,
        original_name,
        stored_name,
        relative_path,
        mime_type,
        file_size,
        created_at
    FROM request_files
    WHERE request_id = ?
    ORDER BY
        CASE file_type
            WHEN 'voice' THEN 1
            WHEN 'rc_card' THEN 2
            WHEN 'part_image' THEN 3
            ELSE 4
        END,
        slot ASC
");

$fileStmt->execute([
    $requestId
]);

$files = $fileStmt->fetchAll();

$voiceFile = null;
$rcFile = null;
$partImages = [];

foreach ($files as $file) {

    if ($file['file_type'] === 'voice') {
        $voiceFile = $file;
    }

    if ($file['file_type'] === 'rc_card') {
        $rcFile = $file;
    }

    if ($file['file_type'] === 'part_image') {
        $partImages[] = $file;
    }
}

/*
|--------------------------------------------------------------------------
| Status History
|--------------------------------------------------------------------------
*/

$historyStmt = $pdo->prepare("
    SELECT
        h.id,
        h.old_status,
        h.new_status,
        h.note,
        h.created_at,
        u.name AS changed_by_name
    FROM request_status_history h

    LEFT JOIN users u
        ON u.id = h.changed_by

    WHERE h.request_id = ?

    ORDER BY h.created_at ASC, h.id ASC
");

$historyStmt->execute([
    $requestId
]);

$statusHistory = $historyStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Latest Quotation
|--------------------------------------------------------------------------
*/

$quotationStmt = $pdo->prepare("
    SELECT *
    FROM quotations
    WHERE request_id = ?
    ORDER BY version_no DESC, id DESC
    LIMIT 1
");

$quotationStmt->execute([
    $requestId
]);

$quotation = $quotationStmt->fetch();

/*
|--------------------------------------------------------------------------
| Quotation Items
|--------------------------------------------------------------------------
*/

$quotationItems = [];

if ($quotation) {

    $quotationItemsStmt = $pdo->prepare("
        SELECT
            id,
            description,
            quantity,
            unit_price,
            line_total
        FROM quotation_items
        WHERE quotation_id = ?
        ORDER BY id ASC
    ");

    $quotationItemsStmt->execute([
        $quotation['id']
    ]);

    $quotationItems = $quotationItemsStmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| Latest Payment
|--------------------------------------------------------------------------
*/

$payment = null;

if ($quotation) {

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

    $payment = $paymentStmt->fetch();
}

/*
|--------------------------------------------------------------------------
| Display Values
|--------------------------------------------------------------------------
*/

$brandName = $request['brand_name'];

if (empty($brandName)) {
    $brandName = $request['brand_other'];
}

$modelName = $request['model_name'];

if (empty($modelName)) {
    $modelName = $request['model_other'];
}

$categoryNames = [];

foreach ($categories as $category) {
    $categoryNames[] = $category['name'];
}

$categoryText = !empty($categoryNames)
    ? implode(', ', $categoryNames)
    : 'Not specified';

$baseFileUrl = '/kmm-aut/public/pages/';

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
        <?= e($request['request_no']) ?> - Khammam Auto
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

        button,
        input {
            font: inherit;
        }

        .app {
            min-height: 100vh;
        }

        /* Sidebar */

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
            overflow-y: auto;
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
            display: flex;
            align-items: center;
            justify-content: center;
            background: #111827;
            color: #ffffff;
            font-size: 19px;
            font-weight: 800;
        }

        .brand-text strong {
            display: block;
            font-size: 17px;
            line-height: 1.1;
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
            padding: 12px 12px;
            border-radius: 10px;
            color: #60697a;
            font-size: 14px;
            font-weight: 600;
            transition: .2s;
        }

        .nav a:hover {
            background: #f4f6f9;
            color: #111827;
        }

        .nav a.active {
            background: #111827;
            color: #ffffff;
        }

        .nav-icon {
            width: 22px;
            text-align: center;
            font-size: 16px;
        }

        /* Main */

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
            font-size: 14px;
            font-weight: 700;
        }

        .content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 32px 60px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #667085;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 18px;
        }

        .back-link:hover {
            color: #111827;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-header h1 {
            margin: 0 0 7px;
            font-size: 27px;
            letter-spacing: -.02em;
        }

        .page-header p {
            margin: 0;
            color: #7a8394;
            font-size: 13px;
        }

        .request-status {
            display: inline-flex;
            align-items: center;
            padding: 8px 13px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 750;
            white-space: nowrap;
        }

        .status-new {
            background: #eaf2ff;
            color: #1769d2;
        }

        .status-contacted {
            background: #fff5dc;
            color: #a46700;
        }

        .status-quotation {
            background: #f0eaff;
            color: #6941c6;
        }

        .status-accepted {
            background: #e8f8ef;
            color: #16834b;
        }

        .status-payment {
            background: #fff1e8;
            color: #c45111;
        }

        .status-paid,
        .status-completed {
            background: #e7f8ee;
            color: #157347;
        }

        .status-cancelled,
        .status-rejected {
            background: #ffebed;
            color: #c92a3b;
        }

        .status-default {
            background: #eef0f4;
            color: #596273;
        }

        /* Alerts */

        .alert {
            padding: 14px 16px;
            border-radius: 11px;
            margin-bottom: 22px;
            font-size: 13px;
            font-weight: 600;
        }

        .alert.success {
            background: #eaf8ef;
            border: 1px solid #c8ecd5;
            color: #187542;
        }

        .alert.error {
            background: #fff0f1;
            border: 1px solid #f4cdd1;
            color: #b42331;
        }

        /* Cards */

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .card {
            background: #ffffff;
            border: 1px solid #e7eaf0;
            border-radius: 15px;
            box-shadow: 0 2px 8px rgba(16, 24, 40, .025);
            overflow: hidden;
        }

        .card.full {
            grid-column: 1 / -1;
        }

        .card-header {
            padding: 18px 20px;
            border-bottom: 1px solid #edf0f4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .card-header h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 750;
        }

        .card-body {
            padding: 20px;
        }

        .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px 25px;
        }

        .detail-item label {
            display: block;
            color: #929aaa;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            margin-bottom: 5px;
        }

        .detail-item div {
            color: #242b3a;
            font-size: 14px;
            font-weight: 600;
            word-break: break-word;
        }

        .detail-item.full {
            grid-column: 1 / -1;
        }

        .details-divider {
            grid-column: 1 / -1;
            height: 1px;
            background: #edf0f4;
        }

        /* Files */

        .file-section {
            margin-top: 20px;
        }

        .file-section:first-child {
            margin-top: 0;
        }

        .file-title {
            font-size: 12px;
            font-weight: 750;
            color: #626b7c;
            margin-bottom: 10px;
        }

        .voice-box {
            border: 1px solid #e8ebf0;
            border-radius: 10px;
            padding: 13px;
            background: #fafbfc;
        }

        audio {
            width: 100%;
        }

        .file-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 13px;
            border-radius: 9px;
            background: #f4f6f8;
            border: 1px solid #e4e7ec;
            color: #344054;
            font-size: 13px;
            font-weight: 650;
        }

        .file-link:hover {
            background: #eef1f5;
        }

        .image-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
        }

        .image-item {
            aspect-ratio: 1 / 1;
            border-radius: 10px;
            overflow: hidden;
            background: #f2f4f7;
            border: 1px solid #e4e7ec;
        }

        .image-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* Timeline */

        .timeline {
            position: relative;
        }

        .timeline-item {
            position: relative;
            padding-left: 34px;
            padding-bottom: 24px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-item::before {
            content: "";
            position: absolute;
            left: 8px;
            top: 21px;
            bottom: -2px;
            width: 2px;
            background: #e7eaf0;
        }

        .timeline-item:last-child::before {
            display: none;
        }

        .timeline-dot {
            position: absolute;
            left: 0;
            top: 1px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #111827;
            border: 4px solid #ffffff;
            box-shadow: 0 0 0 1px #dfe3e9;
        }

        .timeline-status {
            font-size: 13px;
            font-weight: 750;
            margin-bottom: 3px;
        }

        .timeline-date {
            font-size: 11px;
            color: #929aaa;
            margin-bottom: 5px;
        }

        .timeline-note {
            font-size: 12px;
            color: #667085;
            line-height: 1.55;
        }

        /* Quotation */

        .quotation-card {
            border: 1px solid #dfe4ec;
            border-radius: 12px;
            overflow: hidden;
        }

        .quotation-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 16px;
            background: #fafbfc;
            border-bottom: 1px solid #e7eaf0;
        }

        .quotation-total-label {
            color: #667085;
            font-size: 12px;
        }

        .quotation-total {
            margin-top: 3px;
            font-size: 24px;
            font-weight: 800;
            color: #111827;
        }

        .quotation-version {
            font-size: 12px;
            color: #667085;
        }

        .quotation-table {
            width: 100%;
            border-collapse: collapse;
        }

        .quotation-table th,
        .quotation-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #edf0f4;
            text-align: left;
            font-size: 12px;
        }

        .quotation-table th {
            color: #8b93a2;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .quotation-table td:last-child,
        .quotation-table th:last-child {
            text-align: right;
        }

        .quotation-totals {
            padding: 15px;
            display: flex;
            justify-content: flex-end;
        }

        .totals-box {
            width: 270px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 5px 0;
            font-size: 13px;
            color: #667085;
        }

        .total-row.final {
            margin-top: 7px;
            padding-top: 12px;
            border-top: 1px solid #e7eaf0;
            color: #111827;
            font-size: 16px;
            font-weight: 800;
        }

        .quotation-notes {
            margin: 0 15px 15px;
            padding: 12px 14px;
            background: #f8f9fb;
            border-radius: 9px;
            color: #667085;
            font-size: 12px;
            line-height: 1.55;
        }

        .quotation-actions {
            display: flex;
            gap: 10px;
            padding: 15px;
            border-top: 1px solid #e7eaf0;
        }

        .btn {
            border: 0;
            cursor: pointer;
            border-radius: 9px;
            padding: 11px 17px;
            font-size: 13px;
            font-weight: 750;
            transition: .2s;
        }

        .btn-accept {
            background: #16834b;
            color: #ffffff;
        }

        .btn-accept:hover {
            background: #116c3d;
        }

        .btn-reject {
            background: #ffffff;
            color: #c92a3b;
            border: 1px solid #efc7cc;
        }

        .btn-reject:hover {
            background: #fff5f6;
        }

        .payment-box {
            margin-top: 15px;
            padding: 15px;
            background: #f8fafc;
            border: 1px solid #e4e8ee;
            border-radius: 10px;
        }

        .payment-title {
            font-size: 13px;
            font-weight: 750;
            margin-bottom: 5px;
        }

        .payment-status {
            color: #667085;
            font-size: 12px;
            margin-bottom: 12px;
        }

        .payment-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #111827;
            color: #ffffff;
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 750;
        }


        .payment-pending-message {
            padding: 11px 13px;
            border-radius: 8px;
            background: #fff7e6;
            color: #9a6700;
            border: 1px solid #f3dfad;
            font-size: 12px;
            line-height: 1.5;
        }

        .payment-success-message {
            padding: 11px 13px;
            border-radius: 8px;
            background: #eaf8ef;
            color: #16834b;
            border: 1px solid #c8ecd5;
            font-size: 12px;
            font-weight: 700;
        }

        .payment-failed-message {
            padding: 11px 13px;
            border-radius: 8px;
            background: #fff0f1;
            color: #b42331;
            border: 1px solid #f4cdd1;
            font-size: 12px;
            line-height: 1.5;
        }

        .empty {
            padding: 22px;
            text-align: center;
            color: #8b93a2;
            font-size: 13px;
        }

        .request-number {
            font-family: monospace;
            color: #596273;
        }

        /* Mobile */

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
                background: #ffffff;
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

            .grid {
                grid-template-columns: 1fr;
            }

            .card.full {
                grid-column: auto;
            }

            .image-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 600px) {

            .page-header {
                flex-direction: column;
            }

            .page-header h1 {
                font-size: 23px;
            }

            .details {
                grid-template-columns: 1fr;
            }

            .detail-item.full {
                grid-column: auto;
            }

            .details-divider {
                grid-column: auto;
            }

            .quotation-summary {
                flex-direction: column;
                align-items: flex-start;
            }

            .quotation-table {
                min-width: 600px;
            }

            .quotation-card {
                overflow-x: auto;
            }

            .quotation-summary,
            .quotation-actions,
            .quotation-notes,
            .quotation-totals {
                min-width: 0;
            }

            .quotation-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .image-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

    </style>

</head>

<body>

<div class="app">

    <!-- Mobile Header -->

    <div class="mobile-header">

        <button
            class="menu-btn"
            type="button"
            onclick="toggleSidebar()"
            aria-label="Open menu"
        >
            ☰
        </button>

        <strong>Khammam Auto</strong>

        <span></span>

    </div>


    <!-- Sidebar -->

    <aside class="sidebar" id="sidebar">

        <div class="brand">

            <div class="brand-icon">
                KA
            </div>

            <div class="brand-text">

                <strong>Khammam Auto</strong>

                <span>Spare Part Requests</span>

            </div>

        </div>

        <div class="nav-title">
            Menu
        </div>

        <nav class="nav">

            <a href="/kmm-aut/public/pages/dashboard/">

                <span class="nav-icon">⌂</span>

                Dashboard

            </a>

            <a href="/kmm-aut/public/pages/request/">

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

            <a href="/kmm-aut/public/pages/logout/">

                <span class="nav-icon">↪</span>

                Logout

            </a>

        </nav>

    </aside>


    <!-- Main -->

    <main class="main">

        <!-- Desktop Topbar -->

        <header class="topbar">

            <div class="topbar-title">
                Request Details
            </div>

            <div class="topbar-right">

                <span class="customer-name">
                    <?= e($request['customer_name']) ?>
                </span>

                <div class="avatar">

                    <?= e(
                        strtoupper(
                            substr(
                                trim($request['customer_name']),
                                0,
                                1
                            )
                        )
                    ) ?>

                </div>

            </div>

        </header>


        <section class="content">

            <a
                href="/kmm-aut/public/pages/my-requests/"
                class="back-link"
            >
                ← Back to My Requests
            </a>


            <div class="page-header">

                <div>

                    <h1>
                        Request Details
                    </h1>

                    <p>
                        Request
                        <span class="request-number">
                            <?= e($request['request_no']) ?>
                        </span>
                    </p>

                </div>

                <span
                    class="request-status <?= e(statusClass($request['status'])) ?>"
                >
                    <?= e(statusLabel($request['status'])) ?>
                </span>

            </div>


            <?php if ($actionMessage): ?>

                <div
                    class="alert <?= e($actionType) ?>"
                >
                    <?= e($actionMessage) ?>
                </div>

            <?php endif; ?>


            <div class="grid">

                <!-- Request Information -->

                <div class="card">

                    <div class="card-header">

                        <h2>
                            Request Information
                        </h2>

                    </div>

                    <div class="card-body">

                        <div class="details">

                            <div class="detail-item">

                                <label>
                                    Request ID
                                </label>

                                <div class="request-number">
                                    <?= e($request['request_no']) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Submitted
                                </label>

                                <div>
                                    <?= formatDateTime($request['created_at']) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Status
                                </label>

                                <div>
                                    <?= e(statusLabel($request['status'])) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Quantity
                                </label>

                                <div>
                                    <?= e($request['quantity']) ?>
                                </div>

                            </div>

                            <div class="details-divider"></div>

                            <div class="detail-item">

                                <label>
                                    Part Required
                                </label>

                                <div>
                                    <?= e($request['part_required']) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Quality
                                </label>

                                <div>
                                    <?= e(
                                        qualityLabel(
                                            $request['quality_preference']
                                        )
                                    ) ?>
                                </div>

                            </div>

                            <div class="detail-item full">

                                <label>
                                    Category
                                </label>

                                <div>
                                    <?= e($categoryText) ?>
                                </div>

                            </div>

                            <?php if (!empty($request['additional_details'])): ?>

                                <div class="detail-item full">

                                    <label>
                                        Additional Details
                                    </label>

                                    <div style="line-height:1.6;">
                                        <?= nl2br(
                                            e(
                                                $request['additional_details']
                                            )
                                        ) ?>
                                    </div>

                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>


                <!-- Scooter Information -->

                <div class="card">

                    <div class="card-header">

                        <h2>
                            Scooter Details
                        </h2>

                    </div>

                    <div class="card-body">

                        <div class="details">

                            <div class="detail-item">

                                <label>
                                    Brand
                                </label>

                                <div>
                                    <?= e($brandName ?: 'Not specified') ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Model
                                </label>

                                <div>
                                    <?= e($modelName ?: 'Not specified') ?>
                                </div>

                            </div>

                            <div class="details-divider"></div>

                            <div class="detail-item">

                                <label>
                                    Customer
                                </label>

                                <div>
                                    <?= e($request['customer_name']) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Phone
                                </label>

                                <div>
                                    <?= e($request['customer_phone'] ?: '-') ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    Email
                                </label>

                                <div>
                                    <?= e($request['customer_email'] ?: '-') ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <label>
                                    WhatsApp
                                </label>

                                <div>
                                    <?= e($request['customer_whatsapp'] ?: '-') ?>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- Uploaded Files -->

                <div class="card full">

                    <div class="card-header">

                        <h2>
                            Uploaded Files
                        </h2>

                    </div>

                    <div class="card-body">

                        <?php if ($voiceFile): ?>

                            <div class="file-section">

                                <div class="file-title">
                                    Voice Message
                                </div>

                                <div class="voice-box">

                                    <audio
                                        controls
                                        preload="metadata"
                                    >
                                        <source
                                            src="<?= e(
                                                $baseFileUrl .
                                                $voiceFile['relative_path']
                                            ) ?>"
                                            type="<?= e($voiceFile['mime_type']) ?>"
                                        >

                                        Your browser does not support audio playback.

                                    </audio>

                                </div>

                            </div>

                        <?php endif; ?>


                        <?php if ($rcFile): ?>

                            <div class="file-section">

                                <div class="file-title">
                                    RC Card
                                </div>

                                <a
                                    href="<?= e(
                                        $baseFileUrl .
                                        $rcFile['relative_path']
                                    ) ?>"
                                    target="_blank"
                                    rel="noopener"
                                    class="file-link"
                                >
                                    📄 View RC Card
                                </a>

                            </div>

                        <?php endif; ?>


                        <?php if (!empty($partImages)): ?>

                            <div class="file-section">

                                <div class="file-title">
                                    Part Images
                                </div>

                                <div class="image-grid">

                                    <?php foreach ($partImages as $image): ?>

                                        <a
                                            href="<?= e(
                                                $baseFileUrl .
                                                $image['relative_path']
                                            ) ?>"
                                            target="_blank"
                                            rel="noopener"
                                            class="image-item"
                                        >

                                            <img
                                                src="<?= e(
                                                    $baseFileUrl .
                                                    $image['relative_path']
                                                ) ?>"
                                                alt="Part image"
                                            >

                                        </a>

                                    <?php endforeach; ?>

                                </div>

                            </div>

                        <?php endif; ?>


                        <?php if (!$voiceFile && !$rcFile && empty($partImages)): ?>

                            <div class="empty">
                                No files uploaded with this request.
                            </div>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- Status Timeline -->

                <div class="card">

                    <div class="card-header">

                        <h2>
                            Request Timeline
                        </h2>

                    </div>

                    <div class="card-body">

                        <?php if (!empty($statusHistory)): ?>

                            <div class="timeline">

                                <?php foreach ($statusHistory as $history): ?>

                                    <div class="timeline-item">

                                        <div class="timeline-dot"></div>

                                        <div class="timeline-status">

                                            <?= e(
                                                statusLabel(
                                                    $history['new_status']
                                                )
                                            ) ?>

                                        </div>

                                        <div class="timeline-date">

                                            <?= formatDateTime(
                                                $history['created_at']
                                            ) ?>

                                            <?php if (!empty($history['changed_by_name'])): ?>

                                                ·
                                                <?= e(
                                                    $history['changed_by_name']
                                                ) ?>

                                            <?php endif; ?>

                                        </div>

                                        <?php if (!empty($history['note'])): ?>

                                            <div class="timeline-note">

                                                <?= e($history['note']) ?>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <div class="empty">
                                No status history available.
                            </div>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- Quotation -->

                <div class="card">

                    <div class="card-header">

                        <h2>
                            Quotation
                        </h2>

                    </div>

                    <div class="card-body">

                        <?php if ($quotation): ?>

                            <div class="quotation-card">

                                <div class="quotation-summary">

                                    <div>

                                        <div class="quotation-total-label">
                                            Quotation Total
                                        </div>

                                        <div class="quotation-total">
                                            <?= formatMoney(
                                                $quotation['total_amount']
                                            ) ?>
                                        </div>

                                    </div>

                                    <div>

                                        <div class="quotation-version">
                                            Version
                                            <?= e($quotation['version_no']) ?>
                                        </div>

                                        <span
                                            class="request-status <?= e(
                                                $quotation['status'] === 'sent'
                                                    ? 'status-quotation'
                                                    : (
                                                        $quotation['status'] === 'accepted'
                                                            ? 'status-accepted'
                                                            : (
                                                                $quotation['status'] === 'rejected'
                                                                    ? 'status-rejected'
                                                                    : 'status-default'
                                                            )
                                                    )
                                            ) ?>"
                                        >
                                            <?= e(
                                                ucfirst(
                                                    $quotation['status']
                                                )
                                            ) ?>
                                        </span>

                                    </div>

                                </div>


                                <?php if (!empty($quotationItems)): ?>

                                    <table class="quotation-table">

                                        <thead>

                                            <tr>

                                                <th>
                                                    Item
                                                </th>

                                                <th>
                                                    Qty
                                                </th>

                                                <th>
                                                    Unit Price
                                                </th>

                                                <th>
                                                    Total
                                                </th>

                                            </tr>

                                        </thead>

                                        <tbody>

                                            <?php foreach ($quotationItems as $item): ?>

                                                <tr>

                                                    <td>
                                                        <?= e(
                                                            $item['description']
                                                        ) ?>
                                                    </td>

                                                    <td>
                                                        <?= e(
                                                            $item['quantity']
                                                        ) ?>
                                                    </td>

                                                    <td>
                                                        <?= formatMoney(
                                                            $item['unit_price']
                                                        ) ?>
                                                    </td>

                                                    <td>
                                                        <?= formatMoney(
                                                            $item['line_total']
                                                        ) ?>
                                                    </td>

                                                </tr>

                                            <?php endforeach; ?>

                                        </tbody>

                                    </table>

                                <?php endif; ?>


                                <div class="quotation-totals">

                                    <div class="totals-box">

                                        <div class="total-row">

                                            <span>
                                                Subtotal
                                            </span>

                                            <strong>
                                                <?= formatMoney(
                                                    $quotation['subtotal']
                                                ) ?>
                                            </strong>

                                        </div>

                                        <div class="total-row">

                                            <span>
                                                Labour
                                            </span>

                                            <strong>
                                                <?= formatMoney(
                                                    $quotation['labour_charges']
                                                ) ?>
                                            </strong>

                                        </div>

                                        <div class="total-row">

                                            <span>
                                                Other Charges
                                            </span>

                                            <strong>
                                                <?= formatMoney(
                                                    $quotation['other_charges']
                                                ) ?>
                                            </strong>

                                        </div>

                                        <div class="total-row">

                                            <span>
                                                Discount
                                            </span>

                                            <strong>
                                                -<?= formatMoney(
                                                    $quotation['discount']
                                                ) ?>
                                            </strong>

                                        </div>

                                        <div class="total-row final">

                                            <span>
                                                Total
                                            </span>

                                            <strong>
                                                <?= formatMoney(
                                                    $quotation['total_amount']
                                                ) ?>
                                            </strong>

                                        </div>

                                    </div>

                                </div>


                                <?php if (!empty($quotation['valid_until'])): ?>

                                    <div
                                        style="
                                            padding:0 15px 15px;
                                            color:#667085;
                                            font-size:12px;
                                        "
                                    >

                                        Valid until:
                                        <strong>
                                            <?= e(
                                                date(
                                                    'd M Y',
                                                    strtotime(
                                                        $quotation['valid_until']
                                                    )
                                                )
                                            ) ?>
                                        </strong>

                                    </div>

                                <?php endif; ?>


                                <?php if (!empty($quotation['notes'])): ?>

                                    <div class="quotation-notes">

                                        <strong>
                                            Notes:
                                        </strong>

                                        <br>

                                        <?= nl2br(
                                            e($quotation['notes'])
                                        ) ?>

                                    </div>

                                <?php endif; ?>


                                <?php
                                $quotationExpired =
                                    !empty($quotation['valid_until']) &&
                                    $quotation['valid_until'] < date('Y-m-d');
                                ?>


                                <?php if (
                                    $quotation['status'] === 'sent' &&
                                    !$quotationExpired
                                ): ?>

                                    <div class="quotation-actions">

                                        <form
                                            method="POST"
                                            onsubmit="
                                                return confirm(
                                                    'Are you sure you want to accept this quotation?'
                                                );
                                            "
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="quotation_action"
                                                value="accept"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-accept"
                                            >
                                                Accept Quotation
                                            </button>

                                        </form>


                                        <form
                                            method="POST"
                                            onsubmit="
                                                return confirm(
                                                    'Are you sure you want to reject this quotation?'
                                                );
                                            "
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="quotation_action"
                                                value="reject"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-reject"
                                            >
                                                Reject Quotation
                                            </button>

                                        </form>

                                    </div>

                                <?php endif; ?>


                                <?php if ($quotationExpired): ?>

                                    <div
                                        style="
                                            padding:15px;
                                            color:#b42318;
                                            font-size:12px;
                                            font-weight:650;
                                            border-top:1px solid #e7eaf0;
                                        "
                                    >
                                        This quotation has expired.
                                    </div>

                                <?php endif; ?>


                                <?php if ($payment): ?>

                                    <div class="payment-box">

                                        <div class="payment-title">
                                            Payment
                                        </div>

                                        <div class="payment-status">

                                            Status:
                                            <strong>
                                                <?= e(
                                                    ucfirst(
                                                        $payment['status']
                                                    )
                                                ) ?>
                                            </strong>

                                            <?php if (!empty($payment['amount'])): ?>

                                                ·
                                                <?= formatMoney(
                                                    $payment['amount']
                                                ) ?>

                                            <?php endif; ?>

                                        </div>


                                        <?php if (
                                            in_array(
                                                $payment['status'],
                                                ['created', 'pending'],
                                                true
                                            )
                                        ): ?>

                                            <?php if (!empty($payment['payment_link'])): ?>

                                                <a
                                                    href="<?= e($payment['payment_link']) ?>"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="payment-btn"
                                                >
                                                    ₹&nbsp; Pay Now
                                                </a>

                                            <?php else: ?>

                                                <div class="payment-pending-message">
                                                    Payment link is not available yet.
                                                    Please wait for the payment link from Khammam Auto.
                                                </div>

                                            <?php endif; ?>

                                        <?php elseif ($payment['status'] === 'paid'): ?>

                                            <div class="payment-success-message">
                                                ✓ Payment completed successfully.
                                            </div>

                                        <?php elseif ($payment['status'] === 'failed'): ?>

                                            <div class="payment-failed-message">
                                                Payment failed. Please contact Khammam Auto for the next payment attempt.
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                <?php endif; ?>

                            </div>

                        <?php else: ?>

                            <div class="empty">

                                No quotation has been sent yet.

                                <br><br>

                                Once Khammam Auto prepares a quotation,
                                it will appear here.

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </section>

    </main>

</div>


<script>

function toggleSidebar() {

    const sidebar = document.getElementById('sidebar');

    sidebar.classList.toggle('open');

}

</script>

</body>

</html>