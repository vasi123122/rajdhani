<?php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Khammam Auto | Spare Part Request</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f6f7f9;
            color: #111827;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .container {
            width: min(1180px, 92%);
            margin: auto;
        }

        /* Header */

        .header {
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav {
            min-height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            font-size: 24px;
            font-weight: 800;
            color: #111827;
        }

        .brand span {
            color: #dc2626;
        }

        .login-btn {
            border: 1px solid #d1d5db;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            background: #fff;
        }

        .login-btn:hover {
            background: #f3f4f6;
        }

        /* Main */

        .hero {
            padding: 55px 0 35px;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1fr 1.15fr;
            gap: 55px;
            align-items: center;
        }

        .hero-content h1 {
            font-size: clamp(34px, 5vw, 54px);
            line-height: 1.08;
            margin-bottom: 18px;
            letter-spacing: -1.5px;
        }

        .hero-content h1 span {
            color: #dc2626;
        }

        .hero-content p {
            color: #6b7280;
            font-size: 17px;
            line-height: 1.7;
            max-width: 500px;
        }

        .form-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 15px 45px rgba(0, 0, 0, 0.07);
        }

        .form-card h2 {
            font-size: 24px;
            margin-bottom: 6px;
        }

        .form-card .subtitle {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 22px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .form-control {
            width: 100%;
            height: 46px;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            padding: 0 13px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }

        .form-control:focus {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.08);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .request-btn {
            width: 100%;
            height: 48px;
            border: none;
            border-radius: 9px;
            background: #dc2626;
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 5px;
        }

        .request-btn:hover {
            background: #b91c1c;
        }

        /* Scrollers */

        .section {
            padding: 28px 0;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .section-header h2 {
            font-size: 22px;
        }

        .scroll-area {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 8px;
            scrollbar-width: thin;
        }

        .scroll-card {
            min-width: 150px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px 16px;
            text-align: center;
            font-weight: 700;
            font-size: 14px;
            white-space: nowrap;
        }

        .scroll-card:hover {
            border-color: #dc2626;
            color: #dc2626;
        }

        /* Footer */

        .footer {
            margin-top: 35px;
            background: #111827;
            color: #d1d5db;
            padding: 28px 0;
        }

        .footer-inner {
            display: flex;
            justify-content: space-between;
            gap: 20px;
        }

        .footer strong {
            color: #fff;
        }

        .footer p {
            font-size: 13px;
        }

        /* Mobile */

        @media (max-width: 800px) {

            .hero {
                padding-top: 30px;
            }

            .hero-grid {
                grid-template-columns: 1fr;
                gap: 25px;
            }

            .hero-content {
                order: 1;
            }

            .form-card {
                order: 0;
            }

            .hero-content h1 {
                font-size: 34px;
            }

            .hero-content p {
                font-size: 15px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .footer-inner {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {

            .nav {
                min-height: 62px;
            }

            .brand {
                font-size: 21px;
            }

            .hero {
                padding: 22px 0;
            }

            .form-card {
                padding: 20px;
                border-radius: 14px;
            }

            .form-card h2 {
                font-size: 21px;
            }

            .section-header h2 {
                font-size: 19px;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <div class="container nav">

        <a href="/" class="brand">
            KHAMMAM <span>AUTO</span>
        </a>

        <a href="#" class="login-btn">
            Customer Login
        </a>

    </div>
</header>


<main>

    <!-- Main Request Area -->

    <section class="hero">

        <div class="container hero-grid">

            <div class="hero-content">

                <h1>
                    Need a Scooter<br>
                    <span>Spare Part?</span>
                </h1>

                <p>
                    Tell us what spare part you need.
                    Our team will check the requirement and
                    get back to you with the available option and quotation.
                </p>

            </div>


            <div class="form-card">

                <h2>Request a Spare Part</h2>

                <p class="subtitle">
                    Enter a few details and we'll help you find the right part.
                </p>

                <form method="post">

                    <div class="form-group">
                        <label for="name">Customer Name *</label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            class="form-control"
                            placeholder="Enter your name"
                            required
                        >
                    </div>


                    <div class="form-group">
                        <label for="mobile">Mobile Number *</label>

                        <input
                            type="tel"
                            id="mobile"
                            name="mobile"
                            class="form-control"
                            placeholder="Enter mobile number"
                            required
                        >
                    </div>


                    <div class="form-row">

                        <div class="form-group">

                            <label for="brand">
                                Scooter Brand *
                            </label>

                            <select
                                id="brand"
                                name="brand"
                                class="form-control"
                                required
                            >
                                <option value="">Select brand</option>
                                <option>Honda</option>
                                <option>TVS</option>
                                <option>Suzuki</option>
                                <option>Yamaha</option>
                                <option>Hero</option>
                                <option>Royal Enfield</option>
                                <option>Other</option>
                            </select>

                        </div>


                        <div class="form-group">

                            <label for="model">
                                Scooter Model *
                            </label>

                            <input
                                type="text"
                                id="model"
                                name="model"
                                class="form-control"
                                placeholder="e.g. Activa 6G"
                                required
                            >

                        </div>

                    </div>


                    <div class="form-group">

                        <label for="part">
                            Part Required *
                        </label>

                        <input
                            type="text"
                            id="part"
                            name="part"
                            class="form-control"
                            placeholder="e.g. Brake Shoe"
                            required
                        >

                    </div>


                    <button
                        type="submit"
                        class="request-btn"
                    >
                        Request Spare Part
                    </button>

                </form>

            </div>

        </div>

    </section>


    <!-- Scooter Brands -->

    <section class="section">

        <div class="container">

            <div class="section-header">
                <h2>Popular Scooter Brands</h2>
            </div>

            <div class="scroll-area">

                <div class="scroll-card">Honda</div>
                <div class="scroll-card">TVS</div>
                <div class="scroll-card">Suzuki</div>
                <div class="scroll-card">Yamaha</div>
                <div class="scroll-card">Hero</div>
                <div class="scroll-card">Royal Enfield</div>
                <div class="scroll-card">Bajaj</div>

            </div>

        </div>

    </section>


    <!-- Popular Parts -->

    <section class="section">

        <div class="container">

            <div class="section-header">
                <h2>Popular Spare Parts</h2>
            </div>

            <div class="scroll-area">

                <div class="scroll-card">Brake Shoe</div>
                <div class="scroll-card">Clutch Cable</div>
                <div class="scroll-card">Accelerator Cable</div>
                <div class="scroll-card">Air Filter</div>
                <div class="scroll-card">Headlight</div>
                <div class="scroll-card">Side Mirror</div>
                <div class="scroll-card">Wiring Harness</div>

            </div>

        </div>

    </section>

</main>


<footer class="footer">

    <div class="container footer-inner">

        <p>
            © <?php echo date('Y'); ?>
            <strong>Khammam Auto</strong>
        </p>

        <p>
            Spare Part Request Platform
        </p>

    </div>

</footer>

</body>
</html>