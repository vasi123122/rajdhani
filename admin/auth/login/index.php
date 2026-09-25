<?php

session_start();

require_once __DIR__ . '/../../../database/database.php';


// If already logged in as admin/staff
if (
    isset($_SESSION['user_id']) &&
    isset($_SESSION['user_role']) &&
    in_array($_SESSION['user_role'], ['admin', 'staff'], true)
) {
    header('Location: /kmm-aut/admin/dashboard/');
    exit;
}


$error = '';
$email = '';


// LOGIN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';


    if ($email === '' || $password === '') {

        $error = 'Please enter your email and password.';

    } else {

        try {

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    name,
                    email,
                    password_hash,
                    role,
                    status
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->execute([$email]);

            $user = $stmt->fetch();


            if (!$user) {

                $error = 'Invalid email or password.';

            } elseif (!in_array($user['role'], ['admin', 'staff'], true)) {

                $error = 'You do not have admin access.';

            } elseif ($user['status'] !== 'active') {

                $error = 'Your account is not active.';

            } elseif (!password_verify($password, $user['password_hash'])) {

                $error = 'Invalid email or password.';

            } else {

                // Login successful
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_email'] = $user['email'];

                header('Location: /kmm-aut/admin/dashboard/');
                exit;
            }

        } catch (Throwable $e) {

            error_log($e->getMessage());

            $error = 'Something went wrong. Please try again.';
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

    <title>Admin Login | Khammam Auto</title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {
            margin: 0;
            min-height: 100vh;

            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                Arial,
                sans-serif;

            background: #f4f6fa;

            display: flex;
            align-items: center;
            justify-content: center;

            color: #172033;
        }


        .login-wrapper {
            width: 100%;
            max-width: 430px;
            padding: 25px;
        }


        .login-card {
            background: #fff;

            border: 1px solid #e5e7eb;
            border-radius: 18px;

            padding: 38px;

            box-shadow:
                0 12px 35px rgba(15, 23, 42, .08);
        }


        .brand {
            text-align: center;
            margin-bottom: 30px;
        }


        .brand-icon {
            width: 58px;
            height: 58px;

            margin: 0 auto 15px;

            border-radius: 15px;

            background: #111827;
            color: #fff;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 18px;
            font-weight: 900;
        }


        .brand h1 {
            margin: 0;

            font-size: 24px;
            font-weight: 850;
        }


        .brand p {
            margin: 7px 0 0;

            color: #7b8495;
            font-size: 13px;
        }


        .error {
            background: #fff0f1;
            border: 1px solid #ffd1d5;

            color: #c62839;

            padding: 12px 13px;

            border-radius: 9px;

            font-size: 13px;
            margin-bottom: 18px;
        }


        .form-group {
            margin-bottom: 18px;
        }


        label {
            display: block;

            margin-bottom: 7px;

            color: #344054;

            font-size: 13px;
            font-weight: 700;
        }


        input {
            width: 100%;
            height: 46px;

            border: 1px solid #d7dce4;
            border-radius: 9px;

            padding: 0 13px;

            outline: none;

            color: #172033;
            background: #fff;

            font-size: 14px;
        }


        input:focus {
            border-color: #111827;

            box-shadow:
                0 0 0 3px rgba(17, 24, 39, .07);
        }


        .login-btn {
            width: 100%;
            height: 47px;

            border: 0;
            border-radius: 9px;

            background: #111827;
            color: #fff;

            font-size: 14px;
            font-weight: 800;

            cursor: pointer;

            margin-top: 5px;
        }


        .login-btn:hover {
            background: #273244;
        }


        .back-link {
            display: block;

            text-align: center;

            margin-top: 22px;

            color: #667085;

            font-size: 13px;
            font-weight: 600;

            text-decoration: none;
        }


        .back-link:hover {
            color: #111827;
        }


        .footer {
            text-align: center;

            margin-top: 18px;

            color: #98a2b3;

            font-size: 11px;
        }


        @media (max-width: 500px) {

            .login-wrapper {
                padding: 15px;
            }

            .login-card {
                padding: 28px 22px;
            }

        }

    </style>

</head>


<body>


<div class="login-wrapper">

    <div class="login-card">


        <div class="brand">

            <div class="brand-icon">
                KA
            </div>

            <h1>
                Khammam Auto
            </h1>

            <p>
                Admin Panel Login
            </p>

        </div>


        <?php if ($error !== ''): ?>

            <div class="error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>

        <?php endif; ?>


        <form
            method="post"
            action=""
            autocomplete="off"
        >


            <div class="form-group">

                <label for="email">
                    Email Address
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="admin@example.com"
                    autocomplete="username"
                    required
                >

            </div>


            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    required
                >

            </div>


            <button
                type="submit"
                class="login-btn"
            >
                Login to Admin Panel
            </button>


        </form>


        <a
            href="/kmm-aut/"
            class="back-link"
        >
            ← Back to Khammam Auto
        </a>


    </div>


    <div class="footer">
        Khammam Auto Admin Panel
    </div>

</div>


</body>

</html>