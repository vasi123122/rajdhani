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
        'new'             => 'new',
        'contacted'       => 'contacted',
        'quotation_sent'  => 'quotation',
        'accepted'        => 'accepted',
        'payment_pending' => 'pending',
        'paid'            => 'paid',
        'completed'       => 'completed',
        'cancelled'       => 'cancelled',
        'rejected'        => 'rejected'
    ];

    return $classes[$status] ?? 'default';
}

function qualityLabel($quality)
{
    $labels = [
        'genuine'      => 'Company / Genuine',
        'first_quality'=> 'First Quality / Premium',
        'either'       => 'Either is Fine'
    ];

    return $labels[$quality] ?? 'Not specified';
}

/*
|--------------------------------------------------------------------------
| REQUEST ID
|--------------------------------------------------------------------------
*/

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$requestId) {
    header('Location: /kmm-aut/admin/requests/');
    exit;
}

/*
|--------------------------------------------------------------------------
| STATUS UPDATE
|--------------------------------------------------------------------------
*/

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['admin_csrf'], $_POST['csrf_token'])
    ) {
        $errorMessage = 'Invalid form request. Please refresh the page and try again.';
    }

    elseif ($action === 'create_quotation') {

        $descriptions = $_POST['description'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unitPrices = $_POST['unit_price'] ?? [];
        $labourCharges = max(0, (float)($_POST['labour_charges'] ?? 0));
        $otherCharges = max(0, (float)($_POST['other_charges'] ?? 0));
        $discount = max(0, (float)($_POST['discount'] ?? 0));
        $validUntil = trim($_POST['valid_until'] ?? '');
        $notes = trim($_POST['quotation_notes'] ?? '');
        $sendQuotation = isset($_POST['send_quotation']) && $_POST['send_quotation'] === '1';

        $items = [];

        if (is_array($descriptions)) {
            foreach ($descriptions as $i => $description) {
                $description = trim($description);
                $quantity = isset($quantities[$i]) ? (float)$quantities[$i] : 0;
                $unitPrice = isset($unitPrices[$i]) ? (float)$unitPrices[$i] : 0;

                if ($description === '' && $quantity <= 0 && $unitPrice <= 0) {
                    continue;
                }

                if ($description === '' || $quantity <= 0 || $unitPrice < 0) {
                    $errorMessage = 'Please enter valid quotation item details.';
                    break;
                }

                $items[] = [
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $quantity * $unitPrice
                ];
            }
        }

        if (!$errorMessage && empty($items)) {
            $errorMessage = 'Please add at least one quotation item.';
        }

        if (!$errorMessage && $validUntil !== '') {
            $dateObject = DateTime::createFromFormat('Y-m-d', $validUntil);
            if (!$dateObject || $dateObject->format('Y-m-d') !== $validUntil) {
                $errorMessage = 'Please select a valid quotation validity date.';
            }
        }

        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += $item['line_total'];
        }

        $beforeDiscount = $subtotal + $labourCharges + $otherCharges;
        if (!$errorMessage && $discount > $beforeDiscount) {
            $errorMessage = 'Discount cannot be greater than the quotation amount.';
        }

        $totalAmount = max(0, $beforeDiscount - $discount);

        if (!$errorMessage && $totalAmount <= 0) {
            $errorMessage = 'Quotation total must be greater than ₹0.';
        }

        if (!$errorMessage) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT COALESCE(MAX(version_no), 0) AS max_version
                    FROM quotations
                    WHERE request_id = ?
                ");
                $stmt->execute([$requestId]);
                $versionRow = $stmt->fetch();
                $versionNo = ((int)$versionRow['max_version']) + 1;

                $quoteStatus = $sendQuotation ? 'sent' : 'draft';
                $sentAt = $sendQuotation ? date('Y-m-d H:i:s') : null;

                $stmt = $pdo->prepare("
                    INSERT INTO quotations
                    (request_id, version_no, status, labour_charges, other_charges, discount, subtotal, total_amount, valid_until, notes, sent_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $requestId,
                    $versionNo,
                    $quoteStatus,
                    $labourCharges,
                    $otherCharges,
                    $discount,
                    $subtotal,
                    $totalAmount,
                    $validUntil !== '' ? $validUntil : null,
                    $notes !== '' ? $notes : null,
                    $sentAt,
                    $adminId
                ]);

                $quotationId = (int)$pdo->lastInsertId();

                $itemStmt = $pdo->prepare("
                    INSERT INTO quotation_items
                    (quotation_id, description, quantity, unit_price, line_total)
                    VALUES (?, ?, ?, ?, ?)
                ");

                foreach ($items as $item) {
                    $itemStmt->execute([
                        $quotationId,
                        $item['description'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['line_total']
                    ]);
                }

                if ($sendQuotation) {
                    $stmt = $pdo->prepare("SELECT status FROM spare_part_requests WHERE id = ? LIMIT 1");
                    $stmt->execute([$requestId]);
                    $current = $stmt->fetch();

                    if (!$current) {
                        throw new Exception('Request not found.');
                    }

                    $oldStatus = $current['status'];

                    if ($oldStatus !== 'quotation_sent') {
                        $stmt = $pdo->prepare("
                            UPDATE spare_part_requests
                            SET status = 'quotation_sent', updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([$requestId]);

                        $stmt = $pdo->prepare("
                            INSERT INTO request_status_history
                            (request_id, old_status, new_status, changed_by, note)
                            VALUES (?, ?, 'quotation_sent', ?, ?)
                        ");
                        $stmt->execute([
                            $requestId,
                            $oldStatus,
                            $adminId,
                            'Quotation sent to customer.'
                        ]);
                    }
                }

                $pdo->commit();

                $successMessage = $sendQuotation
                    ? 'Quotation created and sent successfully.'
                    : 'Quotation draft saved successfully.';

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Quotation error: ' . $e->getMessage());
                $errorMessage = 'Unable to save quotation. Please try again.';
            }
        }
    }

    elseif ($action === 'update_status') {

        $newStatus = $_POST['status'] ?? '';

        $allowedStatuses = [
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

        if (!in_array($newStatus, $allowedStatuses, true)) {

            $errorMessage = 'Invalid status selected.';

        } else {

            try {

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT status
                    FROM spare_part_requests
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->execute([$requestId]);

                $currentRequest = $stmt->fetch();

                if (!$currentRequest) {

                    throw new Exception('Request not found.');

                }

                $oldStatus = $currentRequest['status'];

                if ($oldStatus !== $newStatus) {

                    $stmt = $pdo->prepare("
                        UPDATE spare_part_requests
                        SET status = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $newStatus,
                        $requestId
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
                            note,
                            created_at
                        )
                        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");

                    $stmt->execute([
                        $requestId,
                        $oldStatus,
                        $newStatus,
                        $adminId,
                        'Status updated by admin.'
                    ]);

                    $successMessage = 'Request status updated successfully.';

                } else {

                    $successMessage = 'Request is already in this status.';
                }

                $pdo->commit();

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errorMessage = 'Unable to update request status.';
            }
        }
    }
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

    LEFT JOIN users u
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
    header('Location: /kmm-aut/admin/requests/');
    exit;
}

/*
|--------------------------------------------------------------------------
| QUOTATIONS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM quotations
    WHERE request_id = ?
    ORDER BY version_no DESC
    LIMIT 1
");
$stmt->execute([$requestId]);
$latestQuotation = $stmt->fetch();

$quotationItems = [];

if ($latestQuotation) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM quotation_items
        WHERE quotation_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([(int)$latestQuotation['id']]);
    $quotationItems = $stmt->fetchAll();
}

$defaultQuotationItems = [];
if ($quotationItems) {
    foreach ($quotationItems as $item) {
        $defaultQuotationItems[] = $item;
    }
} else {
    $defaultQuotationItems[] = [
        'description' => $request['part_required'],
        'quantity' => $request['quantity'],
        'unit_price' => 0
    ];
}

$nextQuotationVersion = $latestQuotation
    ? ((int)$latestQuotation['version_no'] + 1)
    : 1;

$defaultValidUntil = date('Y-m-d', strtotime('+7 days'));

/*
|--------------------------------------------------------------------------
| CATEGORY
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        pc.name
    FROM request_categories rc
    INNER JOIN part_categories pc
        ON pc.id = rc.category_id
    WHERE rc.request_id = ?
    ORDER BY pc.sort_order ASC, pc.name ASC
");

$stmt->execute([$requestId]);

$categories = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| FILES
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM request_files
    WHERE request_id = ?
    ORDER BY file_type ASC, slot ASC
");

$stmt->execute([$requestId]);

$files = $stmt->fetchAll();

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
| STATUS HISTORY
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        h.*,
        u.name AS changed_by_name
    FROM request_status_history h
    LEFT JOIN users u
        ON u.id = h.changed_by
    WHERE h.request_id = ?
    ORDER BY h.created_at ASC
");

$stmt->execute([$requestId]);

$statusHistory = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| CATEGORY TEXT
|--------------------------------------------------------------------------
*/

$categoryNames = [];

foreach ($categories as $category) {
    $categoryNames[] = $category['name'];
}

$categoryText = !empty($categoryNames)
    ? implode(', ', $categoryNames)
    : 'Not specified';

/*
|--------------------------------------------------------------------------
| SCOOTER TEXT
|--------------------------------------------------------------------------
*/

$brandText = $request['brand_name']
    ?: $request['brand_other']
    ?: 'Not specified';

$modelText = $request['model_name']
    ?: $request['model_other']
    ?: 'Not specified';

/*
|--------------------------------------------------------------------------
| FILE URL HELPER
|--------------------------------------------------------------------------
*/

function fileUrl($relativePath)
{
    return '/kmm-aut/public/pages/' . ltrim($relativePath, '/');
}

function money($value)
{
    return number_format((float)$value, 2);
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['admin_csrf'];

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
        Request <?= e($request['request_no']) ?> - Khammam Auto Admin
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
            background: #111827;
            color: white;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            padding: 28px 18px;
        }

        .brand {
            padding: 0 12px 28px;
            border-bottom: 1px solid rgba(255,255,255,.1);
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-logo {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: white;
            color: #111827;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
        }

        .brand-name {
            font-size: 19px;
            font-weight: 800;
        }

        .brand-subtitle {
            color: #94a3b8;
            font-size: 13px;
            margin-top: 3px;
        }

        .menu-title {
            margin: 30px 12px 14px;
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .08em;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 14px 14px;
            margin-bottom: 6px;
            border-radius: 10px;
            color: #cbd5e1;
            font-weight: 600;
            font-size: 14px;
        }

        .nav-link:hover {
            background: rgba(255,255,255,.08);
            color: white;
        }

        .nav-link.active {
            background: white;
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
            color: #fca5a5;
        }

        /* MAIN */

        .main {
            margin-left: 280px;
            width: calc(100% - 280px);
            min-height: 100vh;
        }

        .topbar {
            height: 78px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 34px;
        }

        .topbar-title {
            font-size: 20px;
            font-weight: 800;
        }

        .admin-user {
            color: #64748b;
            font-size: 14px;
        }

        .content {
            padding: 34px;
            max-width: 1500px;
        }

        .back-link {
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 20px;
        }

        .back-link:hover {
            color: #111827;
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
            font-size: 30px;
        }

        .request-no {
            margin-top: 8px;
            color: #64748b;
            font-size: 14px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 9px 15px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 800;
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
            background: #ecfdf5;
            color: #047857;
        }

        .status-pending {
            background: #fffbeb;
            color: #b45309;
        }

        .status-paid {
            background: #ecfdf5;
            color: #047857;
        }

        .status-completed {
            background: #dcfce7;
            color: #15803d;
        }

        .status-cancelled,
        .status-rejected {
            background: #fef2f2;
            color: #dc2626;
        }

        .status-default {
            background: #f1f5f9;
            color: #475569;
        }

        /* ALERTS */

        .alert {
            padding: 14px 17px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: 14px;
            font-weight: 600;
        }

        .alert-success {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        /* GRID */

        .grid {
            display: grid;
            grid-template-columns: 1.45fr 1fr;
            gap: 22px;
        }

        .card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 22px;
        }

        .card-header {
            padding: 20px 23px;
            border-bottom: 1px solid #e5e7eb;
        }

        .card-header h2 {
            margin: 0;
            font-size: 18px;
        }

        .card-body {
            padding: 23px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px 30px;
        }

        .info-item label {
            display: block;
            color: #94a3b8;
            text-transform: uppercase;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .05em;
            margin-bottom: 7px;
        }

        .info-item strong,
        .info-item span {
            font-size: 15px;
            color: #1e293b;
        }

        .full {
            grid-column: 1 / -1;
        }

        .details-box {
            background: #f8fafc;
            border-radius: 10px;
            padding: 15px;
            line-height: 1.6;
            color: #475569;
            white-space: pre-wrap;
        }

        /* STATUS UPDATE */

        .status-form {
            display: flex;
            gap: 10px;
        }

        .status-form select {
            flex: 1;
            height: 45px;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            padding: 0 12px;
            font-size: 14px;
            background: white;
        }

        .btn {
            border: 0;
            border-radius: 9px;
            padding: 0 18px;
            height: 45px;
            font-weight: 800;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-primary {
            background: #111827;
            color: white;
        }

        .btn-primary:hover {
            background: #1f2937;
        }

        .btn-red {
            background: #ef4444;
            color: white;
        }

        /* CUSTOMER */

        .customer-box {
            display: grid;
            gap: 18px;
        }

        .customer-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eef2f7;
        }

        .customer-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .customer-label {
            color: #94a3b8;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .customer-value {
            color: #1e293b;
            font-weight: 600;
            text-align: right;
        }

        /* FILES */

        .file-list {
            display: grid;
            gap: 12px;
        }

        .file-item {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .file-name {
            font-weight: 700;
            font-size: 14px;
            word-break: break-word;
        }

        .file-meta {
            color: #94a3b8;
            font-size: 12px;
            margin-top: 4px;
        }

        .file-btn {
            background: #111827;
            color: white;
            border-radius: 8px;
            padding: 9px 13px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        audio {
            width: 100%;
            margin-top: 8px;
        }

        .images-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .part-image {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }

        /* TIMELINE */

        .timeline {
            position: relative;
        }

        .timeline-item {
            position: relative;
            padding-left: 32px;
            padding-bottom: 25px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-item::before {
            content: "";
            position: absolute;
            left: 7px;
            top: 18px;
            bottom: -2px;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-item:last-child::before {
            display: none;
        }

        .timeline-dot {
            position: absolute;
            left: 0;
            top: 4px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #111827;
            border: 3px solid #e5e7eb;
        }

        .timeline-status {
            font-weight: 800;
            font-size: 14px;
        }

        .timeline-date {
            color: #94a3b8;
            font-size: 12px;
            margin-top: 4px;
        }

        .timeline-note {
            color: #64748b;
            font-size: 13px;
            margin-top: 6px;
        }

        /* ACTIONS */

        .action-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .action-btn {
            height: 48px;
            border-radius: 9px;
            border: 1px solid #e5e7eb;
            background: white;
            font-weight: 800;
            cursor: pointer;
        }

        .action-btn:hover {
            background: #f8fafc;
        }

        .quotation-btn {
            background: #111827;
            color: white;
            border-color: #111827;
        }

        /* QUOTATION */

        .quote-summary {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .quote-summary-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 7px 0;
            font-size: 14px;
            color: #475569;
        }

        .quote-summary-row.total {
            border-top: 1px solid #e2e8f0;
            margin-top: 8px;
            padding-top: 13px;
            color: #111827;
            font-size: 18px;
            font-weight: 900;
        }

        .quote-item-head, .quote-item-row {
            display: grid;
            grid-template-columns: 1fr 95px 125px 120px 42px;
            gap: 8px;
            align-items: center;
        }

        .quote-item-head {
            color: #94a3b8;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .quote-item-row {
            margin-bottom: 9px;
        }

        .quote-item-row input {
            width: 100%;
            height: 42px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0 10px;
            outline: none;
        }

        .quote-item-row input:focus {
            border-color: #111827;
        }

        .quote-line-total {
            background: #f8fafc !important;
            font-weight: 800;
        }

        .quote-remove {
            height: 42px;
            border: 1px solid #fecaca;
            background: #fff1f2;
            color: #dc2626;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            font-weight: 800;
        }

        .quote-add {
            margin-top: 4px;
            height: 40px;
            border: 1px dashed #94a3b8;
            background: #f8fafc;
            color: #334155;
            border-radius: 8px;
            padding: 0 14px;
            font-weight: 800;
            cursor: pointer;
        }

        .quote-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 14px;
            margin-top: 18px;
        }

        .quote-form-grid label {
            display: block;
            color: #475569;
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .quote-form-grid input, .quote-form-grid textarea {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px;
            outline: none;
        }

        .quote-form-grid input {
            height: 42px;
        }

        .quote-form-grid textarea {
            min-height: 85px;
            resize: vertical;
        }

        .quote-full {
            grid-column: 1 / -1;
        }

        .quote-actions {
            display: flex;
            gap: 10px;
            margin-top: 18px;
        }

        .quote-actions button {
            min-height: 44px;
            border-radius: 8px;
            padding: 0 16px;
            font-weight: 800;
            cursor: pointer;
        }

        .quote-draft {
            background: white;
            border: 1px solid #d1d5db;
            color: #111827;
        }

        .quote-send {
            background: #111827;
            border: 1px solid #111827;
            color: white;
        }

        .quote-current {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            align-items: center;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
            margin-bottom: 18px;
        }

        .quote-current small {
            color: #64748b;
        }

        .quote-status {
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: #4338ca;
            font-size: 11px;
            font-weight: 800;
        }

        /* RESPONSIVE */

        @media (max-width: 1100px) {

            .sidebar {
                width: 230px;
            }

            .main {
                margin-left: 230px;
                width: calc(100% - 230px);
            }

            .grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 760px) {

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

            .content {
                padding: 20px;
            }

            .topbar {
                padding: 0 20px;
            }

            .page-heading {
                flex-direction: column;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }

            .images-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .quote-item-head {
                display: none;
            }

            .quote-item-row {
                grid-template-columns: 1fr 1fr;
                padding: 12px;
                border: 1px solid #e5e7eb;
                border-radius: 9px;
            }

            .quote-item-row input:first-child {
                grid-column: 1 / -1;
            }

            .quote-item-row .quote-line-total {
                grid-column: 1 / 2;
            }

            .quote-remove {
                grid-column: 2 / 3;
                justify-self: end;
                width: 42px;
            }

            .quote-form-grid {
                grid-template-columns: 1fr;
            }

            .quote-full {
                grid-column: auto;
            }

            .quote-actions {
                flex-direction: column;
            }

            .quote-actions button {
                width: 100%;
            }

            .status-form {
                flex-direction: column;
            }

            .status-form select,
            .status-form .btn {
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

            <div class="brand-row">

                <div class="brand-logo">
                    KA
                </div>

                <div>
                    <div class="brand-name">
                        Khammam Auto
                    </div>

                    <div class="brand-subtitle">
                        Admin Panel
                    </div>
                </div>

            </div>

        </div>

        <div class="menu-title">
            MAIN MENU
        </div>

        <a
            href="/kmm-aut/admin/dashboard/"
            class="nav-link"
        >
            <span class="nav-icon">⌂</span>
            Dashboard
        </a>

        <a
            href="/kmm-aut/admin/requests/"
            class="nav-link active"
        >
            <span class="nav-icon">▣</span>
            Requests
        </a>

        <a
            href="/kmm-aut/admin/customers/"
            class="nav-link"
        >
            <span class="nav-icon">♟</span>
            Customers
        </a>

        <a
            href="#quotation-section"
            class="nav-link"
        >
            <span class="nav-icon">₹</span>
            Quotations
        </a>

        <a
            href="#"
            class="nav-link"
        >
            <span class="nav-icon">▤</span>
            Payments
        </a>

        <a
            href="#"
            class="nav-link"
        >
            <span class="nav-icon">♟</span>
            Notifications
        </a>

        <a
            href="#"
            class="nav-link"
        >
            <span class="nav-icon">⚙</span>
            Settings
        </a>

        <a
            href="/kmm-aut/admin/auth/logout/"
            class="nav-link logout"
        >
            <span class="nav-icon">↪</span>
            Logout
        </a>

    </aside>

    <!-- MAIN -->

    <main class="main">

        <header class="topbar">

            <div class="topbar-title">
                Request Management
            </div>

            <div class="admin-user">
                Khammam Auto Admin
            </div>

        </header>

        <div class="content">

            <a
                href="/kmm-aut/admin/requests/"
                class="back-link"
            >
                ← Back to Requests
            </a>

            <?php if ($successMessage): ?>

                <div class="alert alert-success">
                    <?= e($successMessage) ?>
                </div>

            <?php endif; ?>

            <?php if ($errorMessage): ?>

                <div class="alert alert-error">
                    <?= e($errorMessage) ?>
                </div>

            <?php endif; ?>

            <!-- PAGE HEADER -->

            <div class="page-heading">

                <div>

                    <h1>
                        Request Details
                    </h1>

                    <div class="request-no">
                        <?= e($request['request_no']) ?>
                    </div>

                </div>

                <div>

                    <span class="status-badge status-<?= e(statusClass($request['status'])) ?>">
                        <?= e(statusLabel($request['status'])) ?>
                    </span>

                </div>

            </div>

            <!-- MAIN GRID -->

            <div class="grid">

                <!-- LEFT -->

                <div>

                    <!-- REQUEST INFORMATION -->

                    <section class="card">

                        <div class="card-header">
                            <h2>Request Information</h2>
                        </div>

                        <div class="card-body">

                            <div class="info-grid">

                                <div class="info-item">

                                    <label>Request ID</label>

                                    <strong>
                                        <?= e($request['request_no']) ?>
                                    </strong>

                                </div>

                                <div class="info-item">

                                    <label>Submitted</label>

                                    <span>
                                        <?= date('d M Y, h:i A', strtotime($request['created_at'])) ?>
                                    </span>

                                </div>

                                <div class="info-item">

                                    <label>Status</label>

                                    <span>
                                        <?= e(statusLabel($request['status'])) ?>
                                    </span>

                                </div>

                                <div class="info-item">

                                    <label>Quantity</label>

                                    <span>
                                        <?= e($request['quantity']) ?>
                                    </span>

                                </div>

                                <div class="info-item">

                                    <label>Part Required</label>

                                    <strong>
                                        <?= e($request['part_required']) ?>
                                    </strong>

                                </div>

                                <div class="info-item">

                                    <label>Quality</label>

                                    <span>
                                        <?= e(qualityLabel($request['quality_preference'])) ?>
                                    </span>

                                </div>

                                <div class="info-item full">

                                    <label>Category</label>

                                    <span>
                                        <?= e($categoryText) ?>
                                    </span>

                                </div>

                                <?php if (!empty($request['additional_details'])): ?>

                                    <div class="info-item full">

                                        <label>Additional Details</label>

                                        <div class="details-box">
                                            <?= e($request['additional_details']) ?>
                                        </div>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    </section>

                    <!-- SCOOTER -->

                    <section class="card">

                        <div class="card-header">
                            <h2>Scooter Details</h2>
                        </div>

                        <div class="card-body">

                            <div class="info-grid">

                                <div class="info-item">

                                    <label>Brand</label>

                                    <span>
                                        <?= e($brandText) ?>
                                    </span>

                                </div>

                                <div class="info-item">

                                    <label>Model</label>

                                    <span>
                                        <?= e($modelText) ?>
                                    </span>

                                </div>

                            </div>

                        </div>

                    </section>

                    <!-- UPLOADED FILES -->

                    <section class="card">

                        <div class="card-header">
                            <h2>Uploaded Files</h2>
                        </div>

                        <div class="card-body">

                            <?php if (!$voiceFile && !$rcFile && empty($partImages)): ?>

                                <p style="color:#94a3b8;">
                                    No files uploaded with this request.
                                </p>

                            <?php endif; ?>


                            <!-- VOICE -->

                            <?php if ($voiceFile): ?>

                                <div style="margin-bottom:25px;">

                                    <div
                                        style="
                                        font-weight:800;
                                        margin-bottom:8px;
                                        "
                                    >
                                        🎙 Voice Message
                                    </div>

                                    <audio controls>

                                        <source
                                            src="<?= e(fileUrl($voiceFile['relative_path'])) ?>"
                                            type="<?= e($voiceFile['mime_type']) ?>"
                                        >

                                    </audio>

                                </div>

                            <?php endif; ?>


                            <!-- RC CARD -->

                            <?php if ($rcFile): ?>

                                <div class="file-item">

                                    <div>

                                        <div class="file-name">
                                            RC Card
                                        </div>

                                        <div class="file-meta">
                                            <?= e($rcFile['original_name']) ?>
                                        </div>

                                    </div>

                                    <a
                                        href="<?= e(fileUrl($rcFile['relative_path'])) ?>"
                                        target="_blank"
                                        class="file-btn"
                                    >
                                        View RC
                                    </a>

                                </div>

                            <?php endif; ?>


                            <!-- PART IMAGES -->

                            <?php if (!empty($partImages)): ?>

                                <div style="margin-top:25px;">

                                    <div
                                        style="
                                        font-weight:800;
                                        margin-bottom:12px;
                                        "
                                    >
                                        📷 Part Photos
                                    </div>

                                    <div class="images-grid">

                                        <?php foreach ($partImages as $image): ?>

                                            <a
                                                href="<?= e(fileUrl($image['relative_path'])) ?>"
                                                target="_blank"
                                            >

                                                <img
                                                    src="<?= e(fileUrl($image['relative_path'])) ?>"
                                                    class="part-image"
                                                    alt="Part Photo"
                                                >

                                            </a>

                                        <?php endforeach; ?>

                                    </div>

                                </div>

                            <?php endif; ?>

                        </div>

                    </section>

                    <!-- STATUS HISTORY -->

                    <section class="card">

                        <div class="card-header">
                            <h2>Status History</h2>
                        </div>

                        <div class="card-body">

                            <?php if (empty($statusHistory)): ?>

                                <p style="color:#94a3b8;">
                                    No status history available.
                                </p>

                            <?php else: ?>

                                <div class="timeline">

                                    <?php foreach ($statusHistory as $history): ?>

                                        <div class="timeline-item">

                                            <div class="timeline-dot"></div>

                                            <div class="timeline-status">

                                                <?= e(statusLabel($history['new_status'])) ?>

                                            </div>

                                            <div class="timeline-date">

                                                <?= date(
                                                    'd M Y, h:i A',
                                                    strtotime($history['created_at'])
                                                ) ?>

                                                <?php if (!empty($history['changed_by_name'])): ?>

                                                    ·
                                                    <?= e($history['changed_by_name']) ?>

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

                            <?php endif; ?>

                        </div>

                    </section>

                </div>


                <!-- RIGHT -->

                <div>

                    <!-- CUSTOMER -->

                    <section class="card">

                        <div class="card-header">
                            <h2>Customer Information</h2>
                        </div>

                        <div class="card-body">

                            <div class="customer-box">

                                <div class="customer-row">

                                    <div class="customer-label">
                                        Name
                                    </div>

                                    <div class="customer-value">
                                        <?= e($request['customer_name']) ?>
                                    </div>

                                </div>

                                <div class="customer-row">

                                    <div class="customer-label">
                                        Phone
                                    </div>

                                    <div class="customer-value">
                                        <?= e($request['customer_phone'] ?: 'Not provided') ?>
                                    </div>

                                </div>

                                <div class="customer-row">

                                    <div class="customer-label">
                                        Email
                                    </div>

                                    <div class="customer-value">
                                        <?= e($request['customer_email'] ?: 'Not provided') ?>
                                    </div>

                                </div>

                                <div class="customer-row">

                                    <div class="customer-label">
                                        WhatsApp
                                    </div>

                                    <div class="customer-value">
                                        <?= e($request['customer_whatsapp'] ?: 'Not provided') ?>
                                    </div>

                                </div>

                            </div>

                        </div>

                    </section>


                    <!-- STATUS MANAGEMENT -->

                    <section class="card">

                        <div class="card-header">
                            <h2>Update Request Status</h2>
                        </div>

                        <div class="card-body">

                            <form
                                method="POST"
                                class="status-form"
                            >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="update_status"
                                >

                                <select name="status">

                                    <?php

                                    $statusOptions = [
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

                                    foreach ($statusOptions as $value => $label):

                                    ?>

                                        <option
                                            value="<?= e($value) ?>"
                                            <?= $request['status'] === $value ? 'selected' : '' ?>
                                        >
                                            <?= e($label) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                >
                                    Update
                                </button>

                            </form>

                        </div>

                    </section>


                    <!-- QUOTATION SECTION -->

                    <section class="card" id="quotation-section">

                        <div class="card-header">
                            <h2>Quotation</h2>
                        </div>

                        <div class="card-body">

                            <?php if ($latestQuotation): ?>

                                <div class="quote-current">
                                    <div>
                                        <strong>Quotation V<?= (int)$latestQuotation['version_no'] ?></strong><br>
                                        <small>Created <?= date('d M Y, h:i A', strtotime($latestQuotation['created_at'])) ?></small>
                                    </div>
                                    <span class="quote-status">
                                        <?= e(ucfirst($latestQuotation['status'])) ?>
                                    </span>
                                </div>

                                <div class="quote-summary">
                                    <div class="quote-summary-row"><span>Parts Subtotal</span><strong>₹ <?= money($latestQuotation['subtotal']) ?></strong></div>
                                    <div class="quote-summary-row"><span>Labour</span><strong>₹ <?= money($latestQuotation['labour_charges']) ?></strong></div>
                                    <div class="quote-summary-row"><span>Other Charges</span><strong>₹ <?= money($latestQuotation['other_charges']) ?></strong></div>
                                    <div class="quote-summary-row"><span>Discount</span><strong>- ₹ <?= money($latestQuotation['discount']) ?></strong></div>
                                    <div class="quote-summary-row total"><span>Total</span><strong>₹ <?= money($latestQuotation['total_amount']) ?></strong></div>
                                </div>

                            <?php endif; ?>

                            <form method="POST" id="quotationForm">

                                <input type="hidden" name="action" value="create_quotation">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                                <div class="quote-item-head">
                                    <div>Description</div>
                                    <div>Qty</div>
                                    <div>Unit Price</div>
                                    <div>Line Total</div>
                                    <div></div>
                                </div>

                                <div id="quoteItems">
                                    <?php foreach ($defaultQuotationItems as $item): ?>
                                        <div class="quote-item-row">
                                            <input type="text" name="description[]" placeholder="Part / service description" value="<?= e($item['description']) ?>" required>
                                            <input type="number" name="quantity[]" class="quote-qty" min="0.01" step="0.01" value="<?= e($item['quantity']) ?>" required>
                                            <input type="number" name="unit_price[]" class="quote-price" min="0" step="0.01" value="<?= e($item['unit_price']) ?>" required>
                                            <input type="text" class="quote-line-total" value="<?= money((float)$item['quantity'] * (float)$item['unit_price']) ?>" readonly>
                                            <button type="button" class="quote-remove" onclick="removeQuoteItem(this)">×</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <button type="button" class="quote-add" onclick="addQuoteItem()">+ Add Item</button>

                                <div class="quote-form-grid">
                                    <div>
                                        <label>Labour Charges (₹)</label>
                                        <input type="number" id="quoteLabour" name="labour_charges" min="0" step="0.01" value="0">
                                    </div>
                                    <div>
                                        <label>Other Charges (₹)</label>
                                        <input type="number" id="quoteOther" name="other_charges" min="0" step="0.01" value="0">
                                    </div>
                                    <div>
                                        <label>Discount (₹)</label>
                                        <input type="number" id="quoteDiscount" name="discount" min="0" step="0.01" value="0">
                                    </div>
                                    <div>
                                        <label>Valid Until</label>
                                        <input type="date" name="valid_until" value="<?= e($defaultValidUntil) ?>">
                                    </div>
                                    <div class="quote-full">
                                        <label>Quotation Notes</label>
                                        <textarea name="quotation_notes" placeholder="Warranty, delivery, fitting or other quotation notes..."></textarea>
                                    </div>
                                </div>

                                <div class="quote-summary" style="margin-top:18px;margin-bottom:0;">
                                    <div class="quote-summary-row"><span>Parts Subtotal</span><strong>₹ <span id="quoteSubtotal">0.00</span></strong></div>
                                    <div class="quote-summary-row"><span>Labour</span><strong>₹ <span id="quoteLabourTotal">0.00</span></strong></div>
                                    <div class="quote-summary-row"><span>Other Charges</span><strong>₹ <span id="quoteOtherTotal">0.00</span></strong></div>
                                    <div class="quote-summary-row"><span>Discount</span><strong>- ₹ <span id="quoteDiscountTotal">0.00</span></strong></div>
                                    <div class="quote-summary-row total"><span>Total</span><strong>₹ <span id="quoteGrandTotal">0.00</span></strong></div>
                                </div>

                                <div class="quote-actions">
                                    <button type="submit" class="quote-draft">Save Draft</button>
                                    <button type="submit" name="send_quotation" value="1" class="quote-send">Create & Send Quotation</button>
                                </div>

                            </form>

                        </div>
                    </section>

                    <!-- QUICK ACTIONS -->

                    <section class="card">

                        <div class="card-header">
                            <h2>Quick Actions</h2>
                        </div>

                        <div class="card-body">

                            <div class="action-grid">

                                <?php if (!empty($request['customer_phone'])): ?>

                                    <a
                                        href="tel:<?= e($request['customer_phone']) ?>"
                                        class="action-btn"
                                        style="
                                        display:flex;
                                        align-items:center;
                                        justify-content:center;
                                        "
                                    >
                                        📞 Call Customer
                                    </a>

                                <?php endif; ?>


                                <?php if (!empty($request['customer_whatsapp'])): ?>

                                    <a
                                        href="https://wa.me/91<?= e(preg_replace('/\D+/', '', $request['customer_whatsapp'])) ?>"
                                        target="_blank"
                                        class="action-btn"
                                        style="
                                        display:flex;
                                        align-items:center;
                                        justify-content:center;
                                        "
                                    >
                                        💬 WhatsApp
                                    </a>

                                <?php endif; ?>


                                <button
                                    type="button"
                                    class="action-btn quotation-btn"
                                    onclick="createQuotation()"
                                >
                                    ₹ Create Quotation
                                </button>

                                <button
                                    type="button"
                                    class="action-btn"
                                    onclick="window.print()"
                                >
                                    🖨 Print Request
                                </button>

                            </div>

                        </div>

                    </section>


                    <!-- FLOW -->

                    <section class="card">

                        <div class="card-header">
                            <h2>Request Flow</h2>
                        </div>

                        <div class="card-body">

                            <div style="line-height:2.2;">

                                <div>
                                    1. 🆕 <strong>New</strong>
                                </div>

                                <div>
                                    ↓
                                </div>

                                <div>
                                    2. 📞 <strong>Contacted</strong>
                                </div>

                                <div>
                                    ↓
                                </div>

                                <div>
                                    3. ₹ <strong>Quotation Sent</strong>
                                </div>

                                <div>
                                    ↓
                                </div>

                                <div>
                                    4. ✅ <strong>Accepted</strong>
                                </div>

                                <div>
                                    ↓
                                </div>

                                <div>
                                    5. 💳 <strong>Payment Pending</strong>
                                </div>

                                <div>
                                    ↓
                                </div>

                                <div>
                                    6. 💰 <strong>Paid</strong>
                                </div>

                                <div>
                                    ↓
                                </div>

                                <div>
                                    7. 🎉 <strong>Completed</strong>
                                </div>

                            </div>

                        </div>

                    </section>

                </div>

            </div>

        </div>

    </main>

</div>

<script>

function createQuotation() {
    const section = document.getElementById('quotation-section');
    if (section) {
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function bindQuoteRow(row) {
    row.querySelectorAll('.quote-qty, .quote-price').forEach(function(input) {
        input.addEventListener('input', calculateQuote);
    });
}

function addQuoteItem() {
    const container = document.getElementById('quoteItems');
    const row = document.createElement('div');
    row.className = 'quote-item-row';
    row.innerHTML = `
        <input type="text" name="description[]" placeholder="Part / service description" required>
        <input type="number" name="quantity[]" class="quote-qty" min="0.01" step="0.01" value="1" required>
        <input type="number" name="unit_price[]" class="quote-price" min="0" step="0.01" value="0" required>
        <input type="text" class="quote-line-total" value="0.00" readonly>
        <button type="button" class="quote-remove" onclick="removeQuoteItem(this)">×</button>
    `;
    container.appendChild(row);
    bindQuoteRow(row);
    calculateQuote();
}

function removeQuoteItem(button) {
    const rows = document.querySelectorAll('#quoteItems .quote-item-row');
    if (rows.length <= 1) {
        alert('At least one quotation item is required.');
        return;
    }
    button.closest('.quote-item-row').remove();
    calculateQuote();
}

function calculateQuote() {
    let subtotal = 0;
    document.querySelectorAll('#quoteItems .quote-item-row').forEach(function(row) {
        const qty = parseFloat(row.querySelector('.quote-qty').value) || 0;
        const price = parseFloat(row.querySelector('.quote-price').value) || 0;
        const line = qty * price;
        row.querySelector('.quote-line-total').value = line.toFixed(2);
        subtotal += line;
    });

    const labour = parseFloat(document.getElementById('quoteLabour').value) || 0;
    const other = parseFloat(document.getElementById('quoteOther').value) || 0;
    const discount = parseFloat(document.getElementById('quoteDiscount').value) || 0;
    const total = Math.max(0, subtotal + labour + other - discount);

    document.getElementById('quoteSubtotal').textContent = subtotal.toFixed(2);
    document.getElementById('quoteLabourTotal').textContent = labour.toFixed(2);
    document.getElementById('quoteOtherTotal').textContent = other.toFixed(2);
    document.getElementById('quoteDiscountTotal').textContent = discount.toFixed(2);
    document.getElementById('quoteGrandTotal').textContent = total.toFixed(2);
}

document.querySelectorAll('#quoteItems .quote-item-row').forEach(bindQuoteRow);
document.getElementById('quoteLabour').addEventListener('input', calculateQuote);
document.getElementById('quoteOther').addEventListener('input', calculateQuote);
document.getElementById('quoteDiscount').addEventListener('input', calculateQuote);
calculateQuote();

</script>

</body>
</html>