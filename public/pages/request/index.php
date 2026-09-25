<?php

session_start();

require_once __DIR__ . '/../../../database/database.php';

/*
|--------------------------------------------------------------------------
| Customer Login Check
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header('Location: /kmm-aut/public/pages/login/');
    exit;
}

$customerId = (int) $_SESSION['user_id'];

$errors = [];

/*
|--------------------------------------------------------------------------
| Form Values
|--------------------------------------------------------------------------
*/

$partRequired      = '';
$quantity          = 1;
$additionalDetails = '';
$qualityPreference = '';
$categoryId        = 0;

$scooterBrand      = '';
$scooterModel      = '';

$scooterBrandOther = '';
$scooterModelOther = '';

/*
|--------------------------------------------------------------------------
| Load Customer
|--------------------------------------------------------------------------
*/

$customerStmt = $pdo->prepare("
    SELECT
        id,
        name,
        email,
        phone,
        whatsapp
    FROM users
    WHERE id = :id
      AND role = 'customer'
      AND status = 'active'
    LIMIT 1
");

$customerStmt->execute([
    ':id' => $customerId
]);

$customer = $customerStmt->fetch();

if (!$customer) {
    session_destroy();

    header('Location: /kmm-aut/public/pages/login/');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load Part Categories
|--------------------------------------------------------------------------
*/

$categories = [];

try {

    $categoryStmt = $pdo->query("
        SELECT
            id,
            name
        FROM part_categories
        WHERE is_active = 1
        ORDER BY sort_order ASC, name ASC
    ");

    $categories = $categoryStmt->fetchAll();

} catch (Throwable $e) {

    $categories = [];
}

/*
|--------------------------------------------------------------------------
| Load Scooter Brands
|--------------------------------------------------------------------------
*/

$brands = [];

try {

    $brandListStmt = $pdo->query("
        SELECT
            id,
            name
        FROM scooter_brands
        WHERE is_active = 1
        ORDER BY sort_order ASC, name ASC
    ");

    $brands = $brandListStmt->fetchAll();

} catch (Throwable $e) {

    $brands = [];
}

/*
|--------------------------------------------------------------------------
| Load Scooter Models
|--------------------------------------------------------------------------
*/

$models = [];

try {

    $modelListStmt = $pdo->query("
        SELECT
            id,
            brand_id,
            name
        FROM scooter_models
        WHERE is_active = 1
        ORDER BY name ASC
    ");

    $models = $modelListStmt->fetchAll();

} catch (Throwable $e) {

    $models = [];
}

/*
|--------------------------------------------------------------------------
| Submit Request
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $partRequired = trim($_POST['part_required'] ?? '');

    $quantity = (int) ($_POST['quantity'] ?? 1);

    $additionalDetails =
        trim($_POST['additional_details'] ?? '');

    $qualityPreference =
        trim($_POST['quality_preference'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Scooter Values
    |--------------------------------------------------------------------------
    */

    $scooterBrand =
        trim($_POST['scooter_brand'] ?? '');

    $scooterModel =
        trim($_POST['scooter_model'] ?? '');

    $scooterBrandOther =
        trim($_POST['scooter_brand_other'] ?? '');

    $scooterModelOther =
        trim($_POST['scooter_model_other'] ?? '');

    $categoryId =
        (int) ($_POST['category_id'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($partRequired === '') {

        $errors[] =
            'Please enter the part you require.';
    }

    if ($quantity < 1) {

        $quantity = 1;
    }

    if ($quantity > 99) {

        $errors[] =
            'Quantity cannot be more than 99.';
    }

    /*
    |--------------------------------------------------------------------------
    | Handle Other Brand
    |--------------------------------------------------------------------------
    */

    if ($scooterBrand === '__other__') {

        if ($scooterBrandOther === '') {

            $errors[] =
                'Please enter your scooter brand.';
        } else {

            $scooterBrand = $scooterBrandOther;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Handle Other Model
    |--------------------------------------------------------------------------
    */

    if ($scooterModel === '__other__') {

        if ($scooterModelOther === '') {

            $errors[] =
                'Please enter your scooter model.';
        } else {

            $scooterModel = $scooterModelOther;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Quality Mapping
    |--------------------------------------------------------------------------
    */

    $quality = null;

    if (
        $qualityPreference === 'genuine' ||
        $qualityPreference === 'Company / Genuine'
    ) {

        $quality = 'genuine';

    } elseif (
        $qualityPreference === 'first_quality' ||
        $qualityPreference === 'First Quality / Premium'
    ) {

        $quality = 'first_quality';

    } elseif (
        $qualityPreference === 'either' ||
        $qualityPreference === 'Either is Fine'
    ) {

        $quality = 'either';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Category
    |--------------------------------------------------------------------------
    */

    if ($categoryId > 0) {

        $categoryCheck = $pdo->prepare("
            SELECT id
            FROM part_categories
            WHERE id = :id
              AND is_active = 1
            LIMIT 1
        ");

        $categoryCheck->execute([
            ':id' => $categoryId
        ]);

        if (!$categoryCheck->fetch()) {

            $categoryId = 0;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Allowed Upload Types
    |--------------------------------------------------------------------------
    */

    $allowedImageMimes = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    $allowedAudioMimes = [
        'audio/webm',
        'audio/wav',
        'audio/mpeg',
        'audio/mp4',
        'audio/ogg',
        'video/webm'
    ];

    $maxImageSize = 5 * 1024 * 1024;
    $maxAudioSize = 10 * 1024 * 1024;

    /*
    |--------------------------------------------------------------------------
    | RC Card Validation
    |--------------------------------------------------------------------------
    */

    if (
        isset($_FILES['rc_card']) &&
        $_FILES['rc_card']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        if (
            $_FILES['rc_card']['error'] !==
            UPLOAD_ERR_OK
        ) {

            $errors[] =
                'RC Card upload failed.';

        } elseif (
            $_FILES['rc_card']['size'] >
            $maxImageSize
        ) {

            $errors[] =
                'RC Card must be less than 5 MB.';

        } else {

            $finfo = new finfo(FILEINFO_MIME_TYPE);

            $rcMime =
                $finfo->file(
                    $_FILES['rc_card']['tmp_name']
                );

            if (
                !in_array(
                    $rcMime,
                    $allowedImageMimes,
                    true
                )
            ) {

                $errors[] =
                    'RC Card must be JPG, PNG, or WEBP.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Part Images Validation
    |--------------------------------------------------------------------------
    */

    if (
        isset($_FILES['part_images']) &&
        isset($_FILES['part_images']['name']) &&
        is_array($_FILES['part_images']['name'])
    ) {

        $imageCount =
            count($_FILES['part_images']['name']);

        if ($imageCount > 5) {

            $errors[] =
                'You can upload a maximum of 5 part images.';
        }

        $finfo =
            new finfo(FILEINFO_MIME_TYPE);

        for (
            $i = 0;
            $i < min($imageCount, 5);
            $i++
        ) {

            if (
                ($_FILES['part_images']['error'][$i]
                    ?? UPLOAD_ERR_NO_FILE)
                === UPLOAD_ERR_NO_FILE
            ) {

                continue;
            }

            if (
                $_FILES['part_images']['error'][$i]
                !== UPLOAD_ERR_OK
            ) {

                $errors[] =
                    'One of the part images failed to upload.';

                continue;
            }

            if (
                $_FILES['part_images']['size'][$i]
                > $maxImageSize
            ) {

                $errors[] =
                    'Each part image must be less than 5 MB.';

                continue;
            }

            $mime =
                $finfo->file(
                    $_FILES['part_images']['tmp_name'][$i]
                );

            if (
                !in_array(
                    $mime,
                    $allowedImageMimes,
                    true
                )
            ) {

                $errors[] =
                    'Part images must be JPG, PNG, or WEBP.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Voice Validation
    |--------------------------------------------------------------------------
    */

    if (
        isset($_FILES['voice_message']) &&
        $_FILES['voice_message']['error'] !==
        UPLOAD_ERR_NO_FILE
    ) {

        if (
            $_FILES['voice_message']['error'] !==
            UPLOAD_ERR_OK
        ) {

            $errors[] =
                'Voice message upload failed.';

        } elseif (
            $_FILES['voice_message']['size'] >
            $maxAudioSize
        ) {

            $errors[] =
                'Voice message must be less than 10 MB.';

        } else {

            $finfo =
                new finfo(FILEINFO_MIME_TYPE);

            $voiceMime =
                $finfo->file(
                    $_FILES['voice_message']['tmp_name']
                );

            if (
                !in_array(
                    $voiceMime,
                    $allowedAudioMimes,
                    true
                )
            ) {

                $errors[] =
                    'Unsupported voice message format.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Create Request
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | Generate Request Number
            |--------------------------------------------------------------------------
            */

            do {

                $requestNo =
                    'KA-' .
                    date('YmdHis') .
                    '-' .
                    random_int(1000, 9999);

                $requestCheck = $pdo->prepare("
                    SELECT id
                    FROM spare_part_requests
                    WHERE request_no = :request_no
                    LIMIT 1
                ");

                $requestCheck->execute([
                    ':request_no' => $requestNo
                ]);

            } while ($requestCheck->fetch());

            /*
            |--------------------------------------------------------------------------
            | Find Brand
            |--------------------------------------------------------------------------
            */

            $brandId = null;
            $brandOther = null;

            if ($scooterBrand !== '') {

                $brandStmt = $pdo->prepare("
                    SELECT id
                    FROM scooter_brands
                    WHERE name = :name
                      AND is_active = 1
                    LIMIT 1
                ");

                $brandStmt->execute([
                    ':name' => $scooterBrand
                ]);

                $brand = $brandStmt->fetch();

                if ($brand) {

                    $brandId =
                        (int) $brand['id'];

                } else {

                    $brandOther =
                        $scooterBrand;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Find Model
            |--------------------------------------------------------------------------
            */

            $modelId = null;
            $modelOther = null;

            if ($scooterModel !== '') {

                if ($brandId !== null) {

                    $modelStmt = $pdo->prepare("
                        SELECT id
                        FROM scooter_models
                        WHERE brand_id = :brand_id
                          AND name = :name
                          AND is_active = 1
                        LIMIT 1
                    ");

                    $modelStmt->execute([
                        ':brand_id' => $brandId,
                        ':name' => $scooterModel
                    ]);

                } else {

                    $modelStmt = $pdo->prepare("
                        SELECT id
                        FROM scooter_models
                        WHERE name = :name
                          AND is_active = 1
                        LIMIT 1
                    ");

                    $modelStmt->execute([
                        ':name' => $scooterModel
                    ]);
                }

                $model =
                    $modelStmt->fetch();

                if ($model) {

                    $modelId =
                        (int) $model['id'];

                } else {

                    $modelOther =
                        $scooterModel;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Insert Request
            |--------------------------------------------------------------------------
            */

            $insert = $pdo->prepare("
                INSERT INTO spare_part_requests
                (
                    request_no,
                    customer_id,
                    brand_id,
                    model_id,
                    brand_other,
                    model_other,
                    part_required,
                    quantity,
                    quality_preference,
                    additional_details,
                    status
                )
                VALUES
                (
                    :request_no,
                    :customer_id,
                    :brand_id,
                    :model_id,
                    :brand_other,
                    :model_other,
                    :part_required,
                    :quantity,
                    :quality_preference,
                    :additional_details,
                    'new'
                )
            ");

            $insert->execute([
                ':request_no' =>
                    $requestNo,

                ':customer_id' =>
                    $customerId,

                ':brand_id' =>
                    $brandId,

                ':model_id' =>
                    $modelId,

                ':brand_other' =>
                    $brandOther,

                ':model_other' =>
                    $modelOther,

                ':part_required' =>
                    $partRequired,

                ':quantity' =>
                    $quantity,

                ':quality_preference' =>
                    $quality,

                ':additional_details' =>
                    $additionalDetails !== ''
                        ? $additionalDetails
                        : null
            ]);

            $requestId =
                (int) $pdo->lastInsertId();

            /*
            |--------------------------------------------------------------------------
            | Save Category
            |--------------------------------------------------------------------------
            */

            if ($categoryId > 0) {

                $categoryInsert = $pdo->prepare("
                    INSERT INTO request_categories
                    (
                        request_id,
                        category_id
                    )
                    VALUES
                    (
                        :request_id,
                        :category_id
                    )
                ");

                $categoryInsert->execute([
                    ':request_id' =>
                        $requestId,

                    ':category_id' =>
                        $categoryId
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Status History
            |--------------------------------------------------------------------------
            */

            $history = $pdo->prepare("
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
                    :request_id,
                    NULL,
                    'new',
                    :changed_by,
                    :note
                )
            ");

            $history->execute([
                ':request_id' =>
                    $requestId,

                ':changed_by' =>
                    $customerId,

                ':note' =>
                    'Spare part request submitted by customer.'
            ]);

            /*
            |--------------------------------------------------------------------------
            | Create Upload Directory
            |--------------------------------------------------------------------------
            */

            $uploadBase =
                __DIR__ .
                '/../../uploads/requests/' .
                $requestNo;

            if (!is_dir($uploadBase)) {

                if (
                    !mkdir(
                        $uploadBase,
                        0755,
                        true
                    )
                ) {

                    throw new RuntimeException(
                        'Unable to create upload directory.'
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Helper: Save Request File
            |--------------------------------------------------------------------------
            */

            $saveRequestFile = function (
                $tmpPath,
                $originalName,
                $mimeType,
                $fileSize,
                $fileType,
                $slot,
                $extension
            ) use (
                $pdo,
                $requestId,
                $requestNo,
                $uploadBase
            ) {

                $safeExtension =
                    strtolower(
                        preg_replace(
                            '/[^a-zA-Z0-9]/',
                            '',
                            $extension
                        )
                    );

                if ($safeExtension === '') {

                    $safeExtension = 'bin';
                }

                $storedName =
                    $fileType .
                    '_' .
                    $slot .
                    '_' .
                    bin2hex(
                        random_bytes(8)
                    ) .
                    '.' .
                    $safeExtension;

                $destination =
                    $uploadBase .
                    '/' .
                    $storedName;

                if (
                    !move_uploaded_file(
                        $tmpPath,
                        $destination
                    )
                ) {

                    throw new RuntimeException(
                        'Unable to save uploaded file.'
                    );
                }

                $relativePath =
                    'uploads/requests/' .
                    $requestNo .
                    '/' .
                    $storedName;

                $fileStmt = $pdo->prepare("
                    INSERT INTO request_files
                    (
                        request_id,
                        file_type,
                        slot,
                        original_name,
                        stored_name,
                        relative_path,
                        mime_type,
                        file_size
                    )
                    VALUES
                    (
                        :request_id,
                        :file_type,
                        :slot,
                        :original_name,
                        :stored_name,
                        :relative_path,
                        :mime_type,
                        :file_size
                    )
                ");

                $fileStmt->execute([
                    ':request_id' =>
                        $requestId,

                    ':file_type' =>
                        $fileType,

                    ':slot' =>
                        $slot,

                    ':original_name' =>
                        $originalName,

                    ':stored_name' =>
                        $storedName,

                    ':relative_path' =>
                        $relativePath,

                    ':mime_type' =>
                        $mimeType,

                    ':file_size' =>
                        $fileSize
                ]);
            };

            /*
            |--------------------------------------------------------------------------
            | Save RC Card
            |--------------------------------------------------------------------------
            */

            if (
                isset($_FILES['rc_card']) &&
                $_FILES['rc_card']['error'] ===
                UPLOAD_ERR_OK
            ) {

                $originalName =
                    $_FILES['rc_card']['name'];

                $tmpPath =
                    $_FILES['rc_card']['tmp_name'];

                $fileSize =
                    (int) $_FILES['rc_card']['size'];

                $finfo =
                    new finfo(FILEINFO_MIME_TYPE);

                $mimeType =
                    $finfo->file($tmpPath);

                $extension =
                    pathinfo(
                        $originalName,
                        PATHINFO_EXTENSION
                    );

                $saveRequestFile(
                    $tmpPath,
                    $originalName,
                    $mimeType,
                    $fileSize,
                    'rc_card',
                    1,
                    $extension
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Save Part Images
            |--------------------------------------------------------------------------
            */

            if (
                isset($_FILES['part_images']) &&
                is_array(
                    $_FILES['part_images']['name']
                )
            ) {

                $finfo =
                    new finfo(FILEINFO_MIME_TYPE);

                $imageCount =
                    count(
                        $_FILES['part_images']['name']
                    );

                for (
                    $i = 0;
                    $i < min($imageCount, 5);
                    $i++
                ) {

                    if (
                        ($_FILES['part_images']['error'][$i]
                            ?? UPLOAD_ERR_NO_FILE)
                        !== UPLOAD_ERR_OK
                    ) {

                        continue;
                    }

                    $originalName =
                        $_FILES['part_images']['name'][$i];

                    $tmpPath =
                        $_FILES['part_images']['tmp_name'][$i];

                    $fileSize =
                        (int)
                        $_FILES['part_images']['size'][$i];

                    $mimeType =
                        $finfo->file($tmpPath);

                    $extension =
                        pathinfo(
                            $originalName,
                            PATHINFO_EXTENSION
                        );

                    $saveRequestFile(
                        $tmpPath,
                        $originalName,
                        $mimeType,
                        $fileSize,
                        'part_image',
                        $i + 1,
                        $extension
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Save Voice Message
            |--------------------------------------------------------------------------
            */

            if (
                isset($_FILES['voice_message']) &&
                $_FILES['voice_message']['error'] ===
                UPLOAD_ERR_OK
            ) {

                $originalName =
                    $_FILES['voice_message']['name'];

                $tmpPath =
                    $_FILES['voice_message']['tmp_name'];

                $fileSize =
                    (int) $_FILES['voice_message']['size'];

                $finfo =
                    new finfo(FILEINFO_MIME_TYPE);

                $mimeType =
                    $finfo->file($tmpPath);

                $extension =
                    pathinfo(
                        $originalName,
                        PATHINFO_EXTENSION
                    );

                if ($extension === '') {

                    if (
                        $mimeType === 'audio/webm' ||
                        $mimeType === 'video/webm'
                    ) {

                        $extension = 'webm';

                    } elseif (
                        $mimeType === 'audio/ogg'
                    ) {

                        $extension = 'ogg';

                    } elseif (
                        $mimeType === 'audio/wav'
                    ) {

                        $extension = 'wav';

                    } elseif (
                        $mimeType === 'audio/mpeg'
                    ) {

                        $extension = 'mp3';

                    } else {

                        $extension = 'webm';
                    }
                }

                $saveRequestFile(
                    $tmpPath,
                    $originalName,
                    $mimeType,
                    $fileSize,
                    'voice',
                    1,
                    $extension
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $pdo->commit();

            /*
            |--------------------------------------------------------------------------
            | Redirect Dashboard
            |--------------------------------------------------------------------------
            */

            header(
                'Location: /kmm-aut/public/pages/dashboard/?request_submitted=1&request=' .
                urlencode($requestNo)
            );

            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();
            }

            error_log(
                'Khammam Auto Request Error: ' .
                $e->getMessage()
            );

            $errors[] =
                'Unable to submit your request. Please try again.';
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

    <title>
        Request a Spare Part | Khammam Auto
    </title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f5f7fb;

            color: #111827;

            min-height: 100vh;

            padding: 35px 15px;
        }

        .page-wrapper {

            width: 100%;

            max-width: 900px;

            margin: 0 auto;
        }

        .brand {

            text-align: center;

            margin-bottom: 25px;
        }

        .brand h1 {

            font-size: 32px;

            font-weight: 800;

            color: #111827;
        }

        .brand h1 span {

            color: #2563eb;
        }

        .brand p {

            margin-top: 7px;

            color: #64748b;

            font-size: 15px;
        }

        .request-card {

            background: #ffffff;

            border-radius: 18px;

            box-shadow:
                0 12px 40px
                rgba(15, 23, 42, 0.08);

            padding: 32px;
        }

        .card-heading {

            margin-bottom: 25px;
        }

        .card-heading h2 {

            font-size: 25px;

            margin-bottom: 7px;
        }

        .card-heading p {

            color: #64748b;

            line-height: 1.6;

            font-size: 14px;
        }

        .customer-box {

            background: #f8fafc;

            border: 1px solid #e2e8f0;

            border-radius: 12px;

            padding: 15px 17px;

            margin-bottom: 25px;
        }

        .customer-box strong {

            display: block;

            margin-bottom: 4px;
        }

        .customer-box span {

            color: #64748b;

            font-size: 13px;
        }

        .section {

            border-top: 1px solid #e5e7eb;

            padding-top: 25px;

            margin-top: 25px;
        }

        .section-title {

            font-size: 17px;

            font-weight: 700;

            margin-bottom: 17px;
        }

        .form-grid {

            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 18px;
        }

        .full {

            grid-column: 1 / -1;
        }

        .form-group {

            margin-bottom: 0;
        }

        label {

            display: block;

            font-size: 14px;

            font-weight: 700;

            color: #334155;

            margin-bottom: 7px;
        }

        .required {

            color: #dc2626;
        }

        input,
        select,
        textarea {

            width: 100%;

            border: 1px solid #cbd5e1;

            border-radius: 9px;

            background: #fff;

            color: #111827;

            font-size: 14px;

            outline: none;

            transition: 0.2s;
        }

        input,
        select {

            height: 46px;

            padding: 0 13px;
        }

        textarea {

            min-height: 115px;

            padding: 13px;

            resize: vertical;
        }

        input:focus,
        select:focus,
        textarea:focus {

            border-color: #2563eb;

            box-shadow:
                0 0 0 3px
                rgba(37, 99, 235, 0.10);
        }

        .other-input {

            margin-top: 10px;
        }

        .quality-options {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 12px;
        }

        .quality-option {

            position: relative;
        }

        .quality-option input {

            position: absolute;

            opacity: 0;

            pointer-events: none;
        }

        .quality-option label {

            border: 1px solid #cbd5e1;

            border-radius: 10px;

            padding: 14px;

            cursor: pointer;

            height: 100%;

            transition: 0.2s;
        }

        .quality-option input:checked + label {

            border-color: #2563eb;

            background: #eff6ff;

            color: #1d4ed8;
        }

        .upload-box {

            border: 1px dashed #cbd5e1;

            border-radius: 11px;

            padding: 18px;

            background: #f8fafc;
        }

        .upload-box input {

            height: auto;

            border: none;

            padding: 0;

            background: transparent;
        }

        .upload-help {

            margin-top: 7px;

            font-size: 12px;

            color: #64748b;
        }

        .voice-controls {

            display: flex;

            flex-wrap: wrap;

            gap: 10px;

            margin-bottom: 12px;
        }

        .voice-btn {

            border: none;

            border-radius: 8px;

            padding: 11px 17px;

            font-weight: 700;

            cursor: pointer;
        }

        .voice-btn:disabled {

            opacity: 0.55;

            cursor: not-allowed;
        }

        .start-btn {

            background: #2563eb;

            color: white;
        }

        .stop-btn {

            background: #dc2626;

            color: white;
        }

        .delete-btn {

            background: #e5e7eb;

            color: #374151;
        }

        .voice-status {

            font-size: 13px;

            color: #64748b;

            margin-bottom: 10px;
        }

        audio {

            width: 100%;

            margin-top: 5px;
        }

        .error-box {

            background: #fef2f2;

            border: 1px solid #fecaca;

            color: #b91c1c;

            padding: 13px 15px;

            border-radius: 9px;

            margin-bottom: 20px;

            font-size: 13px;
        }

        .error-box div {

            margin-bottom: 4px;
        }

        .error-box div:last-child {

            margin-bottom: 0;
        }

        .submit-area {

            margin-top: 30px;

            display: flex;

            gap: 12px;
        }

        .submit-btn {

            flex: 1;

            height: 52px;

            border: none;

            border-radius: 10px;

            background: #ef2b2d;

            color: #ffffff;

            font-size: 16px;

            font-weight: 700;

            cursor: pointer;
        }

        .submit-btn:hover {

            background: #d92123;
        }

        .submit-btn:disabled {

            opacity: 0.7;

            cursor: not-allowed;
        }

        .back-btn {

            height: 52px;

            padding: 0 20px;

            border-radius: 10px;

            border: 1px solid #cbd5e1;

            display: flex;

            align-items: center;

            justify-content: center;

            text-decoration: none;

            color: #475569;

            background: #fff;

            font-weight: 600;
        }

        .note {

            margin-top: 17px;

            text-align: center;

            color: #64748b;

            font-size: 12px;

            line-height: 1.5;
        }

        @media (max-width: 700px) {

            body {

                padding: 20px 10px;
            }

            .request-card {

                padding: 22px;
            }

            .form-grid {

                grid-template-columns: 1fr;
            }

            .full {

                grid-column: auto;
            }

            .quality-options {

                grid-template-columns: 1fr;
            }

            .submit-area {

                flex-direction: column;
            }

            .back-btn {

                width: 100%;
            }
        }

    </style>

</head>

<body>

<div class="page-wrapper">

    <!-- =====================================================
         BRAND
    ====================================================== -->

    <div class="brand">

        <h1>
            KHAMMAM <span>AUTO</span>
        </h1>

        <p>
            Spare Part Request Platform
        </p>

    </div>


    <!-- =====================================================
         REQUEST CARD
    ====================================================== -->

    <div class="request-card">

        <div class="card-heading">

            <h2>
                Request a Spare Part
            </h2>

            <p>
                Tell us what spare part you need.
                Our team will review your request and
                contact you with availability and quotation.
            </p>

        </div>


        <!-- =====================================================
             CUSTOMER
        ====================================================== -->

        <div class="customer-box">

            <strong>
                <?= htmlspecialchars($customer['name']) ?>
            </strong>

            <span>

                <?= htmlspecialchars(
                    $customer['phone'] ?? ''
                ) ?>

                <?php if (!empty($customer['email'])): ?>

                    &nbsp; • &nbsp;

                    <?= htmlspecialchars(
                        $customer['email']
                    ) ?>

                <?php endif; ?>

            </span>

        </div>


        <!-- =====================================================
             ERRORS
        ====================================================== -->

        <?php if (!empty($errors)): ?>

            <div class="error-box">

                <?php foreach ($errors as $error): ?>

                    <div>
                        • <?= htmlspecialchars($error) ?>
                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <!-- =====================================================
             FORM
        ====================================================== -->

        <form
            method="POST"
            action=""
            enctype="multipart/form-data"
            id="requestForm"
        >


            <!-- =================================================
                 PART INFORMATION
            ================================================== -->

            <div class="section">

                <div class="section-title">
                    Part Information
                </div>

                <div class="form-grid">


                    <!-- CATEGORY -->

                    <div class="form-group">

                        <label for="category_id">
                            Part Category
                        </label>

                        <select
                            id="category_id"
                            name="category_id"
                        >

                            <option value="">
                                Select category
                            </option>

                            <?php foreach (
                                $categories
                                as $category
                            ): ?>

                                <option
                                    value="<?= (int) $category['id'] ?>"
                                    <?= (
                                        $categoryId ===
                                        (int) $category['id']
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >

                                    <?= htmlspecialchars(
                                        $category['name']
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- QUANTITY -->

                    <div class="form-group">

                        <label for="quantity">
                            Quantity
                        </label>

                        <input
                            type="number"
                            id="quantity"
                            name="quantity"
                            value="<?= (int) $quantity ?>"
                            min="1"
                            max="99"
                        >

                    </div>


                    <!-- PART REQUIRED -->

                    <div class="form-group full">

                        <label for="part_required">

                            Part Required

                            <span class="required">*</span>

                        </label>

                        <input
                            type="text"
                            id="part_required"
                            name="part_required"
                            value="<?= htmlspecialchars(
                                $partRequired
                            ) ?>"
                            placeholder="Example: Front Brake Pads"
                            maxlength="255"
                            required
                        >

                    </div>

                </div>

            </div>


            <!-- =================================================
                 SCOOTER DETAILS
            ================================================== -->

            <div class="section">

                <div class="section-title">
                    Scooter Details
                </div>

                <div class="form-grid">


                    <!-- =================================================
                         SCOOTER BRAND
                    ================================================== -->

                    <div class="form-group">

                        <label for="scooter_brand">
                            Scooter Brand
                        </label>

                        <select
                            id="scooter_brand"
                            name="scooter_brand"
                        >

                            <option value="">
                                Select Brand
                            </option>

                            <?php foreach (
                                $brands
                                as $brand
                            ): ?>

                                <option
                                    value="<?= htmlspecialchars(
                                        $brand['name']
                                    ) ?>"
                                    data-brand-id="<?= (int) $brand['id'] ?>"
                                    <?= (
                                        $scooterBrand ===
                                        $brand['name']
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >

                                    <?= htmlspecialchars(
                                        $brand['name']
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                            <option
                                value="__other__"
                                <?= (
                                    isset(
                                        $_POST['scooter_brand']
                                    ) &&
                                    $_POST['scooter_brand'] ===
                                    '__other__'
                                )
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                Other
                            </option>

                        </select>


                        <!-- OTHER BRAND -->

                        <input
                            type="text"
                            id="scooter_brand_other"
                            name="scooter_brand_other"
                            class="other-input"
                            value="<?= htmlspecialchars(
                                $scooterBrandOther
                            ) ?>"
                            placeholder="Enter scooter brand"
                            maxlength="100"
                            style="display:none;"
                        >

                    </div>


                    <!-- =================================================
                         SCOOTER MODEL
                    ================================================== -->

                    <div class="form-group">

                        <label for="scooter_model">
                            Scooter Model
                        </label>

                        <select
                            id="scooter_model"
                            name="scooter_model"
                        >

                            <option value="">
                                Select Model
                            </option>

                            <?php foreach (
                                $models
                                as $model
                            ): ?>

                                <option
                                    value="<?= htmlspecialchars(
                                        $model['name']
                                    ) ?>"
                                    data-brand-id="<?= (int) $model['brand_id'] ?>"
                                    <?= (
                                        $scooterModel ===
                                        $model['name']
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >

                                    <?= htmlspecialchars(
                                        $model['name']
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                            <option
                                value="__other__"
                                <?= (
                                    isset(
                                        $_POST['scooter_model']
                                    ) &&
                                    $_POST['scooter_model'] ===
                                    '__other__'
                                )
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                Other
                            </option>

                        </select>


                        <!-- OTHER MODEL -->

                        <input
                            type="text"
                            id="scooter_model_other"
                            name="scooter_model_other"
                            class="other-input"
                            value="<?= htmlspecialchars(
                                $scooterModelOther
                            ) ?>"
                            placeholder="Enter scooter model"
                            maxlength="120"
                            style="display:none;"
                        >

                    </div>

                </div>

            </div>


            <!-- =================================================
                 QUALITY
            ================================================== -->

            <div class="section">

                <div class="section-title">
                    Part Quality Preference
                </div>

                <div class="quality-options">


                    <div class="quality-option">

                        <input
                            type="radio"
                            id="quality_genuine"
                            name="quality_preference"
                            value="genuine"
                            <?= (
                                $qualityPreference ===
                                'genuine'
                            )
                                ? 'checked'
                                : ''
                            ?>
                        >

                        <label for="quality_genuine">
                            Company / Genuine
                        </label>

                    </div>


                    <div class="quality-option">

                        <input
                            type="radio"
                            id="quality_first"
                            name="quality_preference"
                            value="first_quality"
                            <?= (
                                $qualityPreference ===
                                'first_quality'
                            )
                                ? 'checked'
                                : ''
                            ?>
                        >

                        <label for="quality_first">
                            First Quality / Premium
                        </label>

                    </div>


                    <div class="quality-option">

                        <input
                            type="radio"
                            id="quality_either"
                            name="quality_preference"
                            value="either"
                            <?= (
                                $qualityPreference ===
                                'either'
                            )
                                ? 'checked'
                                : ''
                            ?>
                        >

                        <label for="quality_either">
                            Either is Fine
                        </label>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 ADDITIONAL DETAILS
            ================================================== -->

            <div class="section">

                <div class="section-title">
                    Additional Details
                </div>

                <div class="form-group">

                    <label for="additional_details">
                        Message / Details
                    </label>

                    <textarea
                        id="additional_details"
                        name="additional_details"
                        placeholder="Tell us anything else about the part you need..."
                    ><?= htmlspecialchars(
                        $additionalDetails
                    ) ?></textarea>

                </div>

            </div>


            <!-- =================================================
                 VOICE MESSAGE
            ================================================== -->

            <div class="section">

                <div class="section-title">
                    Voice Message
                </div>

                <div class="upload-box">

                    <div class="voice-controls">

                        <button
                            type="button"
                            class="voice-btn start-btn"
                            id="startRecording"
                        >
                            🎙 Start Recording
                        </button>

                        <button
                            type="button"
                            class="voice-btn stop-btn"
                            id="stopRecording"
                            disabled
                        >
                            ⏹ Stop
                        </button>

                        <button
                            type="button"
                            class="voice-btn delete-btn"
                            id="deleteRecording"
                            disabled
                        >
                            Delete
                        </button>

                    </div>

                    <div
                        class="voice-status"
                        id="voiceStatus"
                    >
                        No voice message recorded.
                    </div>

                    <audio
                        id="audioPreview"
                        controls
                        style="display:none;"
                    ></audio>

                    <input
                        type="file"
                        name="voice_message"
                        id="voiceMessage"
                        accept="audio/*,video/webm"
                        hidden
                    >

                    <div class="upload-help">
                        Optional. Maximum 10 MB.
                    </div>

                </div>

            </div>


            <!-- =================================================
                 RC CARD
            ================================================== -->

            <div class="section">

                <div class="section-title">
                    RC Card
                </div>

                <div class="upload-box">

                    <label for="rc_card">
                        Upload RC Card
                    </label>

                    <input
                        type="file"
                        id="rc_card"
                        name="rc_card"
                        accept="image/jpeg,image/png,image/webp"
                    >

                    <div class="upload-help">
                        Optional. JPG, PNG or WEBP.
                        Maximum 5 MB.
                    </div>

                </div>

            </div>


            <!-- =================================================
                 PART PHOTOS
            ================================================== -->

            <div class="section">

                <div class="section-title">
                    Part Photos
                </div>

                <div class="upload-box">

                    <label for="part_images">
                        Upload Part Photos
                    </label>

                    <input
                        type="file"
                        id="part_images"
                        name="part_images[]"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                    >

                    <div class="upload-help">
                        Optional. You can upload up to 5 photos.
                        Each photo maximum 5 MB.
                    </div>

                </div>

            </div>


            <!-- =================================================
                 SUBMIT
            ================================================== -->

            <div class="submit-area">

                <a
                    href="/kmm-aut/public/pages/dashboard/"
                    class="back-btn"
                >
                    Back to Dashboard
                </a>

                <button
                    type="submit"
                    class="submit-btn"
                >
                    Submit Spare Part Request
                </button>

            </div>

            <div class="note">

                After submitting, our team will review your request
                and contact you regarding availability and quotation.

            </div>

        </form>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| Scooter Brand + Model
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const brandSelect =
            document.getElementById(
                'scooter_brand'
            );

        const brandOther =
            document.getElementById(
                'scooter_brand_other'
            );

        const modelSelect =
            document.getElementById(
                'scooter_model'
            );

        const modelOther =
            document.getElementById(
                'scooter_model_other'
            );


        /*
        |--------------------------------------------------------------------------
        | Store all model options
        |--------------------------------------------------------------------------
        */

        const allModelOptions =
            Array.from(
                modelSelect.options
            ).map(function (option) {

                return {
                    value: option.value,
                    text: option.text,
                    brandId:
                        option.getAttribute(
                            'data-brand-id'
                        )
                };

            });


        /*
        |--------------------------------------------------------------------------
        | Show / Hide Other Brand
        |--------------------------------------------------------------------------
        */

        function toggleBrandOther() {

            if (
                brandSelect.value ===
                '__other__'
            ) {

                brandOther.style.display =
                    'block';

                brandOther.required =
                    true;

            } else {

                brandOther.style.display =
                    'none';

                brandOther.required =
                    false;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Filter Models Based On Brand
        |--------------------------------------------------------------------------
        */

        function filterModels() {

            const selectedBrandOption =
                brandSelect.options[
                    brandSelect.selectedIndex
                ];

            const brandId =
                selectedBrandOption
                    ? selectedBrandOption.getAttribute(
                        'data-brand-id'
                    )
                    : null;

            const currentModel =
                modelSelect.value;


            /*
            |--------------------------------------------------------------------------
            | Rebuild Model Dropdown
            |--------------------------------------------------------------------------
            */

            modelSelect.innerHTML = '';

            const defaultOption =
                document.createElement(
                    'option'
                );

            defaultOption.value = '';

            defaultOption.textContent =
                'Select Model';

            modelSelect.appendChild(
                defaultOption
            );


            allModelOptions.forEach(
                function (item) {

                    /*
                    | Other option
                    */

                    if (
                        item.value ===
                        '__other__'
                    ) {

                        return;
                    }


                    /*
                    | If no brand selected
                    | don't show models
                    */

                    if (!brandId) {

                        return;
                    }


                    /*
                    | Only show matching models
                    */

                    if (
                        item.brandId !==
                        brandId
                    ) {

                        return;
                    }

                    const option =
                        document.createElement(
                            'option'
                        );

                    option.value =
                        item.value;

                    option.textContent =
                        item.text;

                    option.setAttribute(
                        'data-brand-id',
                        item.brandId
                    );

                    if (
                        item.value ===
                        currentModel
                    ) {

                        option.selected =
                            true;
                    }

                    modelSelect.appendChild(
                        option
                    );

                }
            );


            /*
            |--------------------------------------------------------------------------
            | Add Other Option
            |--------------------------------------------------------------------------
            */

            const otherOption =
                document.createElement(
                    'option'
                );

            otherOption.value =
                '__other__';

            otherOption.textContent =
                'Other';

            modelSelect.appendChild(
                otherOption
            );


            /*
            |--------------------------------------------------------------------------
            | Preserve Other Model
            |--------------------------------------------------------------------------
            */

            if (
                currentModel ===
                '__other__'
            ) {

                modelSelect.value =
                    '__other__';
            }


            toggleModelOther();
        }


        /*
        |--------------------------------------------------------------------------
        | Show / Hide Other Model
        |--------------------------------------------------------------------------
        */

        function toggleModelOther() {

            if (
                modelSelect.value ===
                '__other__'
            ) {

                modelOther.style.display =
                    'block';

                modelOther.required =
                    true;

            } else {

                modelOther.style.display =
                    'none';

                modelOther.required =
                    false;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Brand Change
        |--------------------------------------------------------------------------
        */

        brandSelect.addEventListener(
            'change',
            function () {

                /*
                | Reset model when brand changes
                */

                modelSelect.value = '';

                modelOther.value = '';

                modelOther.style.display =
                    'none';

                modelOther.required =
                    false;

                filterModels();

                toggleBrandOther();
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Model Change
        |--------------------------------------------------------------------------
        */

        modelSelect.addEventListener(
            'change',
            function () {

                toggleModelOther();
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Initial State
        |--------------------------------------------------------------------------
        */

        const postedBrand =
            <?= json_encode(
                $_POST['scooter_brand']
                    ?? ''
            ) ?>;

        const postedModel =
            <?= json_encode(
                $_POST['scooter_model']
                    ?? ''
            ) ?>;


        /*
        | If Other was submitted
        */

        if (
            postedBrand ===
            '__other__'
        ) {

            brandSelect.value =
                '__other__';

        }


        /*
        | Build model dropdown
        */

        filterModels();


        /*
        | Restore Other Model
        */

        if (
            postedModel ===
            '__other__'
        ) {

            modelSelect.value =
                '__other__';

            toggleModelOther();
        }


        toggleBrandOther();

    }
);


/*
|--------------------------------------------------------------------------
| Voice Recording
|--------------------------------------------------------------------------
*/

let mediaRecorder = null;

let audioChunks = [];

let audioBlob = null;

let microphoneStream = null;


const startButton =
    document.getElementById(
        'startRecording'
    );

const stopButton =
    document.getElementById(
        'stopRecording'
    );

const deleteButton =
    document.getElementById(
        'deleteRecording'
    );

const voiceStatus =
    document.getElementById(
        'voiceStatus'
    );

const audioPreview =
    document.getElementById(
        'audioPreview'
    );

const voiceInput =
    document.getElementById(
        'voiceMessage'
    );


/*
|--------------------------------------------------------------------------
| Start Recording
|--------------------------------------------------------------------------
*/

startButton.addEventListener(
    'click',
    async function () {

        try {

            microphoneStream =
                await navigator.mediaDevices.getUserMedia(
                    {
                        audio: true
                    }
                );

            audioChunks = [];

            mediaRecorder =
                new MediaRecorder(
                    microphoneStream
                );


            mediaRecorder.ondataavailable =
                function (event) {

                    if (
                        event.data.size > 0
                    ) {

                        audioChunks.push(
                            event.data
                        );
                    }
                };


            mediaRecorder.onstop =
                function () {

                    audioBlob =
                        new Blob(
                            audioChunks,
                            {
                                type:
                                    mediaRecorder.mimeType ||
                                    'audio/webm'
                            }
                        );


                    const audioUrl =
                        URL.createObjectURL(
                            audioBlob
                        );

                    audioPreview.src =
                        audioUrl;

                    audioPreview.style.display =
                        'block';

                    voiceStatus.textContent =
                        'Voice message recorded successfully.';

                    deleteButton.disabled =
                        false;


                    /*
                    |--------------------------------------------------------------------------
                    | Put Blob Into File Input
                    |--------------------------------------------------------------------------
                    */

                    let extension =
                        'webm';

                    if (
                        audioBlob.type.includes(
                            'ogg'
                        )
                    ) {

                        extension = 'ogg';

                    } else if (
                        audioBlob.type.includes(
                            'mpeg'
                        )
                    ) {

                        extension = 'mp3';

                    }


                    const voiceFile =
                        new File(
                            [
                                audioBlob
                            ],
                            'voice-message.' +
                            extension,
                            {
                                type:
                                    audioBlob.type
                            }
                        );


                    const dataTransfer =
                        new DataTransfer();

                    dataTransfer.items.add(
                        voiceFile
                    );

                    voiceInput.files =
                        dataTransfer.files;


                    /*
                    |--------------------------------------------------------------------------
                    | Stop Microphone
                    |--------------------------------------------------------------------------
                    */

                    if (
                        microphoneStream
                    ) {

                        microphoneStream
                            .getTracks()
                            .forEach(
                                function (track) {

                                    track.stop();

                                }
                            );
                    }

                };


            mediaRecorder.start();


            startButton.disabled =
                true;

            stopButton.disabled =
                false;

            deleteButton.disabled =
                true;

            voiceStatus.textContent =
                'Recording...';

        } catch (error) {

            voiceStatus.textContent =
                'Microphone access was denied or unavailable.';

            console.error(error);
        }

    }
);


/*
|--------------------------------------------------------------------------
| Stop Recording
|--------------------------------------------------------------------------
*/

stopButton.addEventListener(
    'click',
    function () {

        if (
            mediaRecorder &&
            mediaRecorder.state !==
            'inactive'
        ) {

            mediaRecorder.stop();

            startButton.disabled =
                false;

            stopButton.disabled =
                true;
        }

    }
);


/*
|--------------------------------------------------------------------------
| Delete Recording
|--------------------------------------------------------------------------
*/

deleteButton.addEventListener(
    'click',
    function () {

        audioChunks = [];

        audioBlob = null;

        audioPreview.pause();

        audioPreview.removeAttribute(
            'src'
        );

        audioPreview.style.display =
            'none';

        voiceInput.value = '';

        voiceStatus.textContent =
            'No voice message recorded.';

        deleteButton.disabled =
            true;

        startButton.disabled =
            false;

        stopButton.disabled =
            true;

    }
);


/*
|--------------------------------------------------------------------------
| Prevent Double Submit
|--------------------------------------------------------------------------
*/

document
    .getElementById('requestForm')
    .addEventListener(
        'submit',
        function () {

            const submitButton =
                this.querySelector(
                    '.submit-btn'
                );

            submitButton.disabled =
                true;

            submitButton.textContent =
                'Submitting...';

        }
    );

</script>

</body>

</html>