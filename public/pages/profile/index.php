<?php

session_start();

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: /kmm-aut/public/pages/login/');
    exit;
}

require_once __DIR__ . '/../../../database/database.php';

$userId = (int)$_SESSION['user_id'];

$success = '';
$error = '';

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['profile_csrf'];

/*
|--------------------------------------------------------------------------
| LOAD CUSTOMER
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
        status,
        created_at
    FROM users
    WHERE id = ?
      AND role = 'customer'
    LIMIT 1
");

$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: /kmm-aut/public/pages/login/');
    exit;
}

/*
|--------------------------------------------------------------------------
| UPDATE PROFILE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $postedToken)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');

        if ($name === '') {
            $error = 'Please enter your name.';
        } elseif (strlen($name) < 2) {
            $error = 'Name must contain at least 2 characters.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($phone === '') {
            $error = 'Please enter your phone number.';
        } else {

            /*
            |--------------------------------------------------------------------------
            | CHECK EMAIL DUPLICATE
            |--------------------------------------------------------------------------
            */
            if ($email !== '') {

                $emailCheck = $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE email = ?
                      AND id != ?
                    LIMIT 1
                ");

                $emailCheck->execute([$email, $userId]);

                if ($emailCheck->fetch()) {
                    $error = 'This email address is already registered with another account.';
                }
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE
            |--------------------------------------------------------------------------
            */
            if ($error === '') {

                $update = $pdo->prepare("
                    UPDATE users
                    SET
                        name = ?,
                        email = NULLIF(?, ''),
                        phone = ?,
                        whatsapp = NULLIF(?, '')
                    WHERE id = ?
                    LIMIT 1
                ");

                $update->execute([
                    $name,
                    $email,
                    $phone,
                    $whatsapp,
                    $userId
                ]);

                /*
                | Update session name
                */
                $_SESSION['user_name'] = $name;

                /*
                | Reload customer
                */
                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        name,
                        email,
                        phone,
                        whatsapp,
                        role,
                        status,
                        created_at
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->execute([$userId]);
                $user = $stmt->fetch();

                $success = 'Profile updated successfully.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| CHANGE PASSWORD
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $postedToken)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '') {
            $error = 'Please enter your current password.';
        } elseif ($newPassword === '') {
            $error = 'Please enter a new password.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'New password must contain at least 6 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New password and confirm password do not match.';
        } else {

            /*
            |--------------------------------------------------------------------------
            | GET PASSWORD HASH
            |--------------------------------------------------------------------------
            */
            $passwordStmt = $pdo->prepare("
                SELECT password_hash
                FROM users
                WHERE id = ?
                LIMIT 1
            ");

            $passwordStmt->execute([$userId]);
            $passwordData = $passwordStmt->fetch();

            if (!$passwordData || !password_verify($currentPassword, $passwordData['password_hash'])) {

                $error = 'Current password is incorrect.';

            } else {

                $newHash = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                $passwordUpdate = $pdo->prepare("
                    UPDATE users
                    SET password_hash = ?
                    WHERE id = ?
                    LIMIT 1
                ");

                $passwordUpdate->execute([
                    $newHash,
                    $userId
                ]);

                $success = 'Password changed successfully.';

                /*
                | Clear password fields after successful update
                */
                $_POST['current_password'] = '';
                $_POST['new_password'] = '';
                $_POST['confirm_password'] = '';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DISPLAY VALUES
|--------------------------------------------------------------------------
*/
$nameValue = $user['name'] ?? '';
$emailValue = $user['email'] ?? '';
$phoneValue = $user['phone'] ?? '';
$whatsappValue = $user['whatsapp'] ?? '';

$initial = strtoupper(
    substr(
        trim($nameValue) !== '' ? trim($nameValue) : 'C',
        0,
        1
    )
);

$createdDate = '';

if (!empty($user['created_at'])) {
    $createdTimestamp = strtotime($user['created_at']);

    if ($createdTimestamp) {
        $createdDate = date('d M Y', $createdTimestamp);
    }
}

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
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

    <title>My Profile | Khammam Auto</title>

    <style>

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
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

        body {
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        button,
        input {
            font: inherit;
        }

        .app {
            min-height: 100vh;
            display: flex;
        }

        /* =========================================================
           SIDEBAR
        ========================================================= */

        .sidebar {
            width: 285px;
            background: #101827;
            color: #fff;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            z-index: 100;
            overflow-y: auto;
        }

        .brand {
            padding: 34px 32px 28px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .brand-name {
            font-size: 25px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .brand-name span {
            display: block;
            color: #ff2b2b;
            margin-top: 2px;
        }

        .brand-subtitle {
            margin-top: 10px;
            color: #9ca9bd;
            font-size: 14px;
        }

        .menu-title {
            padding: 30px 32px 12px;
            color: #75829a;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1.2px;
        }

        .menu {
            padding: 0 18px;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 14px;
            min-height: 54px;
            padding: 0 20px;
            margin-bottom: 5px;
            border-radius: 10px;
            color: #d7deea;
            font-size: 16px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        .menu a:hover {
            background: rgba(255,255,255,0.06);
            color: #fff;
        }

        .menu a.active {
            background: #ff2929;
            color: #fff;
        }

        .menu-icon {
            width: 18px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-bottom {
            position: absolute;
            left: 18px;
            right: 18px;
            bottom: 22px;
        }

        .logout-link {
            display: flex;
            align-items: center;
            gap: 14px;
            min-height: 52px;
            padding: 0 20px;
            color: #ff7c7c;
            font-weight: 600;
            border-radius: 10px;
        }

        .logout-link:hover {
            background: rgba(255,255,255,0.06);
        }

        /* =========================================================
           MAIN
        ========================================================= */

        .main {
            margin-left: 285px;
            width: calc(100% - 285px);
            min-height: 100vh;
        }

        .topbar {
            height: 82px;
            background: #fff;
            border-bottom: 1px solid #e5e9f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 36px;
        }

        .topbar-title {
            font-size: 23px;
            font-weight: 800;
        }

        .topbar-subtitle {
            margin-top: 4px;
            color: #7b879b;
            font-size: 14px;
        }

        .top-user {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .top-user-name {
            text-align: right;
        }

        .top-user-name strong {
            display: block;
            font-size: 14px;
        }

        .top-user-name span {
            display: block;
            margin-top: 3px;
            color: #7d899b;
            font-size: 13px;
        }

        .avatar {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: #ffe0e0;
            color: #f12626;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 17px;
        }

        .content {
            padding: 42px 36px 60px;
            max-width: 1350px;
        }

        .page-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 28px;
        }

        .page-heading h1 {
            margin: 0;
            font-size: 32px;
            letter-spacing: -0.8px;
        }

        .page-heading p {
            margin: 9px 0 0;
            color: #718099;
            font-size: 15px;
        }

        .back-link {
            color: #68768d;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 14px;
            display: inline-block;
        }

        .back-link:hover {
            color: #ff2929;
        }

        /* =========================================================
           ALERTS
        ========================================================= */

        .alert {
            padding: 16px 18px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: 15px;
            font-weight: 600;
        }

        .alert-success {
            background: #eafaf1;
            color: #13834d;
            border: 1px solid #bfe8d0;
        }

        .alert-error {
            background: #fff0f0;
            color: #c72525;
            border: 1px solid #f4c1c1;
        }

        /* =========================================================
           PROFILE GRID
        ========================================================= */

        .profile-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(300px, 0.8fr);
            gap: 24px;
            align-items: start;
        }

        .card {
            background: #fff;
            border: 1px solid #e3e8ef;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(20, 35, 60, 0.02);
        }

        .card-header {
            padding: 23px 26px;
            border-bottom: 1px solid #e8ecf2;
        }

        .card-header h2 {
            margin: 0;
            font-size: 19px;
        }

        .card-header p {
            margin: 7px 0 0;
            color: #7b879b;
            font-size: 14px;
        }

        .card-body {
            padding: 28px 26px;
        }

        /* =========================================================
           FORM
        ========================================================= */

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
        }

        .form-group {
            margin-bottom: 2px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #59677d;
            font-size: 13px;
            font-weight: 700;
        }

        .required {
            color: #ef2525;
        }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="password"] {
            width: 100%;
            height: 48px;
            padding: 0 14px;
            border: 1px solid #d7dee8;
            border-radius: 9px;
            background: #fff;
            color: #172033;
            outline: none;
            transition: 0.2s ease;
        }

        input:focus {
            border-color: #ff3030;
            box-shadow: 0 0 0 3px rgba(255, 48, 48, 0.08);
        }

        .input-help {
            margin-top: 7px;
            color: #8a95a7;
            font-size: 12px;
        }

        .form-actions {
            margin-top: 26px;
            display: flex;
            justify-content: flex-end;
        }

        .btn {
            border: 0;
            min-height: 48px;
            padding: 0 24px;
            border-radius: 9px;
            cursor: pointer;
            font-weight: 700;
            transition: 0.2s ease;
        }

        .btn-primary {
            background: #ff2929;
            color: #fff;
        }

        .btn-primary:hover {
            background: #e91e1e;
        }

        /* =========================================================
           PROFILE SUMMARY
        ========================================================= */

        .profile-summary {
            text-align: center;
        }

        .large-avatar {
            width: 90px;
            height: 90px;
            margin: 4px auto 17px;
            border-radius: 50%;
            background: #ffe3e3;
            color: #f12626;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 31px;
            font-weight: 800;
        }

        .profile-summary h3 {
            margin: 0;
            font-size: 20px;
        }

        .profile-summary-email {
            margin-top: 7px;
            color: #7c8799;
            font-size: 14px;
            word-break: break-word;
        }

        .account-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: 15px;
            padding: 7px 13px;
            border-radius: 20px;
            background: #eafaf1;
            color: #13834d;
            font-size: 12px;
            font-weight: 800;
        }

        .summary-divider {
            height: 1px;
            background: #e8ecf2;
            margin: 26px 0;
        }

        .summary-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 12px 0;
            text-align: left;
        }

        .summary-row span:first-child {
            color: #8a95a7;
            font-size: 13px;
        }

        .summary-row span:last-child {
            color: #293449;
            font-size: 13px;
            font-weight: 700;
            text-align: right;
        }

        /* =========================================================
           PASSWORD CARD
        ========================================================= */

        .password-card {
            margin-top: 24px;
        }

        .password-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
        }

        /* =========================================================
           INFO BOX
        ========================================================= */

        .info-box {
            margin-top: 24px;
            padding: 18px;
            border: 1px solid #e5eaf1;
            background: #f8faff;
            border-radius: 11px;
        }

        .info-box strong {
            display: block;
            font-size: 14px;
            margin-bottom: 7px;
        }

        .info-box p {
            margin: 0;
            color: #728096;
            font-size: 13px;
            line-height: 1.6;
        }

        /* =========================================================
           MOBILE
        ========================================================= */

        .mobile-menu {
            display: none;
        }

        @media (max-width: 1050px) {

            .profile-grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 800px) {

            .sidebar {
                width: 250px;
                transform: translateX(-100%);
                transition: 0.25s ease;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
                width: 100%;
            }

            .mobile-menu {
                display: flex;
                width: 42px;
                height: 42px;
                border: 1px solid #e1e6ee;
                background: #fff;
                border-radius: 8px;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                font-size: 20px;
            }

            .topbar {
                padding: 0 18px;
                gap: 12px;
            }

            .topbar-left {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .topbar-title {
                font-size: 19px;
            }

            .top-user-name {
                display: none;
            }

            .content {
                padding: 28px 18px 45px;
            }

            .page-heading {
                display: block;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: auto;
            }

            .card-body {
                padding: 22px 18px;
            }

            .card-header {
                padding: 20px 18px;
            }

        }

        @media (max-width: 520px) {

            .page-heading h1 {
                font-size: 27px;
            }

            .topbar {
                height: 72px;
            }

            .avatar {
                width: 40px;
                height: 40px;
            }

            .form-actions .btn {
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

        <div class="brand">

            <div class="brand-name">
                KHAMMAM
                <span>AUTO</span>
            </div>

            <div class="brand-subtitle">
                Customer Portal
            </div>

        </div>

        <div class="menu-title">
            MAIN MENU
        </div>

        <nav class="menu">

            <a href="/kmm-aut/public/pages/dashboard/">
                <span class="menu-icon">⌂</span>
                <span>Dashboard</span>
            </a>

            <a href="/kmm-aut/public/pages/request/">
                <span class="menu-icon">＋</span>
                <span>Request a Part</span>
            </a>

            <a href="/kmm-aut/public/pages/my-requests/">
                <span class="menu-icon">▣</span>
                <span>My Requests</span>
            </a>

            <a
                href="/kmm-aut/public/pages/profile/"
                class="active"
            >
                <span class="menu-icon">♙</span>
                <span>My Profile</span>
            </a>

            <a href="#">
                <span class="menu-icon">●</span>
                <span>Notifications</span>
            </a>

        </nav>

        <div class="sidebar-bottom">

            <a
                class="logout-link"
                href="/kmm-aut/public/pages/logout/"
            >
                <span>↪</span>
                <span>Logout</span>
            </a>

        </div>

    </aside>

    <!-- =========================================================
         MAIN
    ========================================================== -->

    <main class="main">

        <!-- TOP BAR -->

        <header class="topbar">

            <div class="topbar-left">

                <button
                    type="button"
                    class="mobile-menu"
                    onclick="toggleSidebar()"
                    aria-label="Open menu"
                >
                    ☰
                </button>

                <div>

                    <div class="topbar-title">
                        My Profile
                    </div>

                    <div class="topbar-subtitle">
                        Manage your account details
                    </div>

                </div>

            </div>

            <div class="top-user">

                <div class="top-user-name">

                    <strong>
                        <?= e($user['name']) ?>
                    </strong>

                    <span>
                        Customer
                    </span>

                </div>

                <div class="avatar">
                    <?= e($initial) ?>
                </div>

            </div>

        </header>


        <!-- CONTENT -->

        <section class="content">

            <a
                href="/kmm-aut/public/pages/dashboard/"
                class="back-link"
            >
                ← Back to Dashboard
            </a>


            <div class="page-heading">

                <div>

                    <h1>
                        My Profile
                    </h1>

                    <p>
                        Update your personal information and account password.
                    </p>

                </div>

            </div>


            <?php if ($success !== ''): ?>

                <div class="alert alert-success">
                    ✓ <?= e($success) ?>
                </div>

            <?php endif; ?>


            <?php if ($error !== ''): ?>

                <div class="alert alert-error">
                    ⚠ <?= e($error) ?>
                </div>

            <?php endif; ?>


            <div class="profile-grid">

                <!-- =================================================
                     LEFT COLUMN
                ================================================== -->

                <div>

                    <!-- PROFILE INFORMATION -->

                    <div class="card">

                        <div class="card-header">

                            <h2>
                                Personal Information
                            </h2>

                            <p>
                                Keep your contact details up to date.
                            </p>

                        </div>

                        <div class="card-body">

                            <form
                                method="POST"
                                action=""
                            >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="update_profile"
                                >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <div class="form-grid">

                                    <!-- NAME -->

                                    <div class="form-group full">

                                        <label for="name">
                                            Full Name
                                            <span class="required">*</span>
                                        </label>

                                        <input
                                            type="text"
                                            id="name"
                                            name="name"
                                            value="<?= e($nameValue) ?>"
                                            maxlength="120"
                                            required
                                        >

                                    </div>


                                    <!-- PHONE -->

                                    <div class="form-group">

                                        <label for="phone">
                                            Phone Number
                                            <span class="required">*</span>
                                        </label>

                                        <input
                                            type="tel"
                                            id="phone"
                                            name="phone"
                                            value="<?= e($phoneValue) ?>"
                                            maxlength="30"
                                            required
                                        >

                                    </div>


                                    <!-- WHATSAPP -->

                                    <div class="form-group">

                                        <label for="whatsapp">
                                            WhatsApp Number
                                        </label>

                                        <input
                                            type="tel"
                                            id="whatsapp"
                                            name="whatsapp"
                                            value="<?= e($whatsappValue) ?>"
                                            maxlength="30"
                                        >

                                        <div class="input-help">
                                            Leave blank if WhatsApp is the same as your phone and not needed separately.
                                        </div>

                                    </div>


                                    <!-- EMAIL -->

                                    <div class="form-group full">

                                        <label for="email">
                                            Email Address
                                        </label>

                                        <input
                                            type="email"
                                            id="email"
                                            name="email"
                                            value="<?= e($emailValue) ?>"
                                            maxlength="190"
                                        >

                                    </div>

                                </div>


                                <div class="form-actions">

                                    <button
                                        type="submit"
                                        class="btn btn-primary"
                                    >
                                        Save Changes
                                    </button>

                                </div>

                            </form>

                        </div>

                    </div>


                    <!-- CHANGE PASSWORD -->

                    <div class="card password-card">

                        <div class="card-header">

                            <h2>
                                Change Password
                            </h2>

                            <p>
                                Update your password to keep your account secure.
                            </p>

                        </div>

                        <div class="card-body">

                            <form
                                method="POST"
                                action=""
                            >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="change_password"
                                >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <div class="password-grid">

                                    <div class="form-group">

                                        <label for="current_password">
                                            Current Password
                                            <span class="required">*</span>
                                        </label>

                                        <input
                                            type="password"
                                            id="current_password"
                                            name="current_password"
                                            autocomplete="current-password"
                                            required
                                        >

                                    </div>


                                    <div class="form-group">

                                        <label for="new_password">
                                            New Password
                                            <span class="required">*</span>
                                        </label>

                                        <input
                                            type="password"
                                            id="new_password"
                                            name="new_password"
                                            minlength="6"
                                            autocomplete="new-password"
                                            required
                                        >

                                        <div class="input-help">
                                            Minimum 6 characters.
                                        </div>

                                    </div>


                                    <div class="form-group">

                                        <label for="confirm_password">
                                            Confirm New Password
                                            <span class="required">*</span>
                                        </label>

                                        <input
                                            type="password"
                                            id="confirm_password"
                                            name="confirm_password"
                                            minlength="6"
                                            autocomplete="new-password"
                                            required
                                        >

                                    </div>

                                </div>


                                <div class="form-actions">

                                    <button
                                        type="submit"
                                        class="btn btn-primary"
                                    >
                                        Change Password
                                    </button>

                                </div>

                            </form>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                     RIGHT COLUMN
                ================================================== -->

                <div>

                    <div class="card">

                        <div class="card-header">

                            <h2>
                                Account
                            </h2>

                            <p>
                                Your account information.
                            </p>

                        </div>

                        <div class="card-body">

                            <div class="profile-summary">

                                <div class="large-avatar">
                                    <?= e($initial) ?>
                                </div>

                                <h3>
                                    <?= e($user['name']) ?>
                                </h3>

                                <div class="profile-summary-email">

                                    <?php if ($user['email']): ?>

                                        <?= e($user['email']) ?>

                                    <?php else: ?>

                                        No email added

                                    <?php endif; ?>

                                </div>

                                <div class="account-badge">
                                    Active Customer
                                </div>

                            </div>


                            <div class="summary-divider"></div>


                            <div class="summary-row">

                                <span>
                                    Account Type
                                </span>

                                <span>
                                    Customer
                                </span>

                            </div>


                            <div class="summary-row">

                                <span>
                                    Phone
                                </span>

                                <span>
                                    <?= e($user['phone'] ?: 'Not added') ?>
                                </span>

                            </div>


                            <div class="summary-row">

                                <span>
                                    WhatsApp
                                </span>

                                <span>
                                    <?= e($user['whatsapp'] ?: 'Not added') ?>
                                </span>

                            </div>


                            <div class="summary-row">

                                <span>
                                    Status
                                </span>

                                <span>
                                    <?= e(ucfirst($user['status'] ?? 'active')) ?>
                                </span>

                            </div>


                            <?php if ($createdDate !== ''): ?>

                                <div class="summary-row">

                                    <span>
                                        Member Since
                                    </span>

                                    <span>
                                        <?= e($createdDate) ?>
                                    </span>

                                </div>

                            <?php endif; ?>


                            <div class="info-box">

                                <strong>
                                    Need a spare part?
                                </strong>

                                <p>
                                    Submit a new spare-part request and track its progress from your customer dashboard.
                                </p>

                                <div style="margin-top:14px;">

                                    <a
                                        href="/kmm-aut/public/pages/request/"
                                        class="btn btn-primary"
                                        style="display:inline-flex;align-items:center;justify-content:center;"
                                    >
                                        Request a Part
                                    </a>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </section>

    </main>

</div>


<script>

function toggleSidebar()
{
    const sidebar = document.getElementById('sidebar');

    if (sidebar) {
        sidebar.classList.toggle('open');
    }
}

</script>

</body>
</html>