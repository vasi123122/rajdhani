<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();

require_once __DIR__ . '/../../../database/database.php';

$errors = [];
$success = '';

$name = '';
$email = '';
$phone = '';
$whatsapp = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($name === '') {
        $errors[] = 'Please enter your name.';
    }

    if ($phone === '') {

        $errors[] = 'Please enter your mobile number.';

    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {

        $errors[] = 'Please enter a valid 10-digit mobile number.';
    }


    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $errors[] = 'Please enter a valid email address.';
    }


    if ($password === '') {

        $errors[] = 'Please enter a password.';

    } elseif (strlen($password) < 6) {

        $errors[] = 'Password must be at least 6 characters.';
    }


    if ($password !== $confirm) {

        $errors[] = 'Passwords do not match.';
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK EXISTING ACCOUNT
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        if ($email !== '') {

            $check = $pdo->prepare("
                SELECT id
                FROM users
                WHERE phone = :phone
                   OR email = :email
                LIMIT 1
            ");

            $check->execute([
                ':phone' => $phone,
                ':email' => $email
            ]);

        } else {

            $check = $pdo->prepare("
                SELECT id
                FROM users
                WHERE phone = :phone
                LIMIT 1
            ");

            $check->execute([
                ':phone' => $phone
            ]);
        }


        if ($check->fetch()) {

            $errors[] =
                'An account already exists with this mobile number or email.';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE CUSTOMER
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        try {

            $passwordHash =
                password_hash($password, PASSWORD_DEFAULT);


            $stmt = $pdo->prepare("
                INSERT INTO users
                (
                    name,
                    email,
                    phone,
                    whatsapp,
                    password_hash,
                    role,
                    status
                )
                VALUES
                (
                    :name,
                    :email,
                    :phone,
                    :whatsapp,
                    :password_hash,
                    'customer',
                    'active'
                )
            ");


            $stmt->execute([
                ':name'          => $name,
                ':email'         => $email !== '' ? $email : null,
                ':phone'         => $phone,
                ':whatsapp'      => $whatsapp !== '' ? $whatsapp : null,
                ':password_hash' => $passwordHash
            ]);


            $success =
                'Account created successfully. You can now login.';


            /*
            |--------------------------------------------------------------------------
            | CLEAR FORM
            |--------------------------------------------------------------------------
            */

            $name = '';
            $email = '';
            $phone = '';
            $whatsapp = '';


        } catch (PDOException $e) {

            $errors[] =
                'Unable to create account. Please try again.';

            /*
            | Temporary development error
            | Remove later for production.
            */
            $errors[] = $e->getMessage();
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

    <title>Create Account | Khammam Auto</title>

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

            min-height: 100vh;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 30px 15px;
        }

        .register-wrapper {
            width: 100%;
            max-width: 460px;
        }

        .brand {
            text-align: center;
            margin-bottom: 20px;
        }

        .brand h1 {
            font-size: 28px;
            font-weight: 800;
            color: #111827;
        }

        .brand span {
            color: #2563eb;
        }

        .brand p {
            margin-top: 6px;
            color: #6b7280;
            font-size: 14px;
        }

        .register-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 30px;

            box-shadow:
                0 10px 35px rgba(0, 0, 0, 0.08);
        }

        .register-card h2 {
            font-size: 23px;
            color: #111827;
            margin-bottom: 6px;
        }

        .subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 17px;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .required {
            color: #dc2626;
        }

        input {
            width: 100%;
            height: 46px;

            padding: 0 13px;

            border: 1px solid #d1d5db;
            border-radius: 9px;

            outline: none;

            font-size: 14px;
            color: #111827;

            background: #ffffff;
        }

        input:focus {
            border-color: #2563eb;

            box-shadow:
                0 0 0 3px rgba(37, 99, 235, 0.10);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .error-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;

            padding: 12px 14px;

            border-radius: 9px;

            margin-bottom: 18px;

            font-size: 13px;
        }

        .error-box div {
            margin-bottom: 5px;
        }

        .error-box div:last-child {
            margin-bottom: 0;
        }

        .success-box {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #047857;

            padding: 12px 14px;

            border-radius: 9px;

            margin-bottom: 18px;

            font-size: 13px;
        }

        .register-btn {
            width: 100%;
            height: 48px;

            border: none;
            border-radius: 9px;

            background: #2563eb;
            color: #ffffff;

            font-size: 15px;
            font-weight: 700;

            cursor: pointer;
        }

        .register-btn:hover {
            background: #1d4ed8;
        }

        .login-link {
            text-align: center;

            margin-top: 20px;

            font-size: 14px;
            color: #6b7280;
        }

        .login-link a {
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
        }

        .back-home {
            text-align: center;
            margin-top: 18px;
        }

        .back-home a {
            color: #6b7280;
            font-size: 13px;
            text-decoration: none;
        }

        @media (max-width: 520px) {

            .register-card {
                padding: 22px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .brand h1 {
                font-size: 25px;
            }
        }

    </style>

</head>

<body>

<div class="register-wrapper">

    <div class="brand">

        <h1>
            KHAMMAM <span>AUTO</span>
        </h1>

        <p>
            Spare Part Request Platform
        </p>

    </div>


    <div class="register-card">

        <h2>
            Create Account
        </h2>

        <p class="subtitle">
            Create your customer account to request and track spare parts.
        </p>


        <?php if (!empty($errors)): ?>

            <div class="error-box">

                <?php foreach ($errors as $error): ?>

                    <div>
                        • <?= htmlspecialchars($error) ?>
                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <?php if ($success !== ''): ?>

            <div class="success-box">

                <?= htmlspecialchars($success) ?>

                <br><br>

                <a
                    href="/kmm-aut/public/pages/login/"
                    style="font-weight:700;color:#047857;"
                >
                    Go to Login →
                </a>

            </div>

        <?php endif; ?>


        <form method="POST" action="">


            <div class="form-group">

                <label>
                    Full Name
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    name="name"
                    value="<?= htmlspecialchars($name) ?>"
                    placeholder="Enter your full name"
                    maxlength="120"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Mobile Number
                    <span class="required">*</span>
                </label>

                <input
                    type="tel"
                    name="phone"
                    value="<?= htmlspecialchars($phone) ?>"
                    placeholder="10-digit mobile number"
                    maxlength="10"
                    pattern="[0-9]{10}"
                    inputmode="numeric"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Email Address
                </label>

                <input
                    type="email"
                    name="email"
                    value="<?= htmlspecialchars($email) ?>"
                    placeholder="example@email.com"
                    maxlength="190"
                >

            </div>


            <div class="form-group">

                <label>
                    WhatsApp Number
                </label>

                <input
                    type="tel"
                    name="whatsapp"
                    value="<?= htmlspecialchars($whatsapp) ?>"
                    placeholder="WhatsApp number"
                    maxlength="30"
                    inputmode="numeric"
                >

            </div>


            <div class="form-row">


                <div class="form-group">

                    <label>
                        Password
                        <span class="required">*</span>
                    </label>

                    <input
                        type="password"
                        name="password"
                        placeholder="Minimum 6 characters"
                        minlength="6"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Confirm Password
                        <span class="required">*</span>
                    </label>

                    <input
                        type="password"
                        name="confirm_password"
                        placeholder="Confirm password"
                        minlength="6"
                        required
                    >

                </div>


            </div>


            <button
                type="submit"
                class="register-btn"
            >
                Create Account
            </button>


        </form>


        <div class="login-link">

            Already have an account?

            <a href="/kmm-aut/public/pages/login/">
                Login
            </a>

        </div>


        <div class="back-home">

            <a href="/kmm-aut/">
                ← Back to Home
            </a>

        </div>

    </div>

</div>

</body>

</html>