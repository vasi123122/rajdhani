<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();

require_once __DIR__ . '/../../../database/database.php';

$error = '';

/*
|--------------------------------------------------------------------------
| Already Logged In
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['user_id'])) {

    header('Location: /kmm-aut/public/pages/dashboard/');
    exit;
}


/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($login === '') {

        $error = 'Please enter your mobile number or email.';

    } elseif ($password === '') {

        $error = 'Please enter your password.';

    } else {


        /*
        |--------------------------------------------------------------------------
        | Find Customer
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | Use different parameter names for phone and email.
        |
        */

        $stmt = $pdo->prepare("
            SELECT
                id,
                name,
                email,
                phone,
                whatsapp,
                password_hash,
                role,
                status
            FROM users
            WHERE phone = :phone
               OR email = :email
            LIMIT 1
        ");


        $stmt->execute([
            ':phone' => $login,
            ':email' => $login
        ]);


        $user = $stmt->fetch();


        /*
        |--------------------------------------------------------------------------
        | Check User
        |--------------------------------------------------------------------------
        */

        if (!$user) {

            $error =
                'Invalid mobile number/email or password.';

        } elseif ($user['status'] !== 'active') {

            $error =
                'Your account is not active. Please contact support.';

        } elseif (!password_verify(
            $password,
            $user['password_hash']
        )) {

            $error =
                'Invalid mobile number/email or password.';

        } elseif ($user['role'] !== 'customer') {

            $error =
                'This login is only for customer accounts.';

        } else {


            /*
            |--------------------------------------------------------------------------
            | Create Secure Session
            |--------------------------------------------------------------------------
            */

            session_regenerate_id(true);

            $_SESSION['user_id'] =
                (int) $user['id'];

            $_SESSION['user_name'] =
                $user['name'];

            $_SESSION['user_email'] =
                $user['email'];

            $_SESSION['user_phone'] =
                $user['phone'];

            $_SESSION['user_role'] =
                $user['role'];


            /*
            |--------------------------------------------------------------------------
            | Redirect To Customer Dashboard
            |--------------------------------------------------------------------------
            */

            header(
                'Location: /kmm-aut/public/pages/dashboard/'
            );

            exit;
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

    <title>Customer Login | Khammam Auto</title>


    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }


        body {

            min-height: 100vh;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f5f7fa;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 25px;
        }


        a {
            text-decoration: none;
        }


        .login-wrapper {

            width: 100%;

            max-width: 430px;
        }


        .brand {

            text-align: center;

            margin-bottom: 22px;
        }


        .brand a {

            display: inline-block;
        }


        .logo {

            font-size: 29px;

            font-weight: 800;

            letter-spacing: -0.5px;
        }


        .logo-dark {

            color: #111827;
        }


        .logo-red {

            color: #e32626;
        }


        .brand p {

            margin-top: 7px;

            color: #64748b;

            font-size: 14px;
        }


        .login-card {

            background: #ffffff;

            border: 1px solid #e5e7eb;

            border-radius: 18px;

            padding: 34px;

            box-shadow:
                0 12px 35px
                rgba(15, 23, 42, 0.08);
        }


        .login-card h1 {

            color: #111827;

            font-size: 25px;

            margin-bottom: 7px;
        }


        .login-description {

            color: #64748b;

            font-size: 14px;

            line-height: 1.6;

            margin-bottom: 25px;
        }


        .error-box {

            background: #fef2f2;

            border: 1px solid #fecaca;

            color: #b91c1c;

            padding: 12px 14px;

            border-radius: 9px;

            font-size: 13px;

            margin-bottom: 18px;
        }


        .form-group {

            margin-bottom: 18px;
        }


        .form-group label {

            display: block;

            font-size: 14px;

            font-weight: 700;

            color: #374151;

            margin-bottom: 8px;
        }


        .form-group input {

            width: 100%;

            height: 48px;

            border: 1px solid #d1d5db;

            border-radius: 9px;

            padding: 0 14px;

            font-family: inherit;

            font-size: 15px;

            outline: none;

            transition:
                border-color 0.2s,
                box-shadow 0.2s;
        }


        .form-group input:focus {

            border-color: #e32626;

            box-shadow:
                0 0 0 3px
                rgba(227, 38, 38, 0.08);
        }


        .password-wrapper {

            position: relative;
        }


        .password-wrapper input {

            padding-right: 80px;
        }


        .show-password {

            position: absolute;

            right: 12px;

            top: 50%;

            transform: translateY(-50%);

            border: 0;

            background: transparent;

            color: #64748b;

            font-size: 12px;

            font-weight: 700;

            cursor: pointer;
        }


        .login-btn {

            width: 100%;

            height: 49px;

            border: 0;

            border-radius: 9px;

            background: #e32626;

            color: #ffffff;

            font-size: 15px;

            font-weight: 800;

            cursor: pointer;

            margin-top: 4px;
        }


        .login-btn:hover {

            background: #c91f1f;
        }


        .register-text {

            text-align: center;

            margin-top: 22px;

            color: #64748b;

            font-size: 14px;
        }


        .register-text a {

            color: #e32626;

            font-weight: 700;
        }


        .back-home {

            text-align: center;

            margin-top: 18px;
        }


        .back-home a {

            color: #64748b;

            font-size: 13px;
        }


        .back-home a:hover {

            color: #111827;
        }


        @media (max-width: 500px) {

            body {
                padding: 16px;
            }

            .login-card {
                padding: 24px;
                border-radius: 14px;
            }

            .logo {
                font-size: 25px;
            }

        }

    </style>

</head>


<body>


<div class="login-wrapper">


    <!-- BRAND -->

    <div class="brand">

        <a href="/kmm-aut/">

            <div class="logo">

                <span class="logo-dark">
                    KHAMMAM
                </span>

                <span class="logo-red">
                    AUTO
                </span>

            </div>

        </a>


        <p>
            Spare Part Request Platform
        </p>

    </div>



    <!-- LOGIN CARD -->

    <div class="login-card">


        <h1>
            Customer Login
        </h1>


        <p class="login-description">

            Login to submit spare part requests
            and track your requests.

        </p>


        <?php if ($error !== ''): ?>

            <div class="error-box">

                <?= htmlspecialchars($error) ?>

            </div>

        <?php endif; ?>


        <form
            method="POST"
            action=""
        >


            <!-- LOGIN -->

            <div class="form-group">

                <label>
                    Mobile Number or Email
                </label>


                <input
                    type="text"
                    name="login"
                    placeholder="Enter mobile number or email"
                    value="<?= htmlspecialchars(
                        $_POST['login'] ?? ''
                    ) ?>"
                    autocomplete="username"
                    required
                >

            </div>



            <!-- PASSWORD -->

            <div class="form-group">

                <label>
                    Password
                </label>


                <div class="password-wrapper">

                    <input
                        type="password"
                        name="password"
                        id="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >


                    <button
                        type="button"
                        class="show-password"
                        onclick="togglePassword()"
                    >
                        SHOW
                    </button>

                </div>

            </div>



            <!-- LOGIN BUTTON -->

            <button
                type="submit"
                class="login-btn"
            >
                Login
            </button>


        </form>



        <!-- REGISTER -->

        <div class="register-text">

            Don't have an account?

            <a
                href="/kmm-aut/public/pages/register/"
            >
                Create Account
            </a>

        </div>



        <!-- HOME -->

        <div class="back-home">

            <a href="/kmm-aut/">

                ← Back to Home

            </a>

        </div>


    </div>

</div>



<script>

function togglePassword() {

    const password =
        document.getElementById('password');

    const button =
        document.querySelector('.show-password');


    if (password.type === 'password') {

        password.type = 'text';

        button.textContent = 'HIDE';

    } else {

        password.type = 'password';

        button.textContent = 'SHOW';
    }

}

</script>


</body>

</html>