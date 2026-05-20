<!DOCTYPE html>
<html>
<head>
    <title>TrustFix</title>

    <style>

        body {
            font-family: Arial;
            margin: 0;
            background: #f4f4f4;
        }

        nav {
            background: #222;
            padding: 15px;
        }

        nav a {
            color: white;
            margin-right: 20px;
            text-decoration: none;
        }

        .container {
            padding: 40px;
        }

        input,
        textarea,
        button {
            width: 100%;
            margin-bottom: 15px;
            padding: 10px;
            box-sizing:border-box;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background:white;
        }

        table th,
        table td {
            border: 1px solid #ddd;
            padding: 10px;
        }

        table th {
            background: #333;
            color: white;
        }

    </style>

</head>

<body>

<nav>

    <a href="dashboard.php">
        Dashboard
    </a>

    <a href="list_contractors.php">
        Contractors
    </a>

    <a href="list_jobs.php">
        Jobs
    </a>

    <?php if (empty($_SESSION['jwt_token'])) { ?>

        <a href="login.php">
            Login
        </a>

        <a href="register.php">
            Register
        </a>

    <?php } else { ?>

        <a href="logout.php">
            Logout
        </a>

    <?php } ?>

</nav>

<div class="container">