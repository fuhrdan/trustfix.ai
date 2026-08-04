<!DOCTYPE html>
<html>
<head>
    <title>TrustFix</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="TF-Style.css?v=20260701-header-hotfix">
</head>

<body>

<nav class="tf-top-nav">
    <div class="tf-nav-inner">
        <a class="tf-brand" href="dashboard.php" aria-label="TrustFix Dashboard">
            <img src="images/7749CCB4-A449-4A1E-961A-6D6A38CE5E12.png" alt="TrustFix logo" class="tf-brand-logo">
            <span class="tf-brand-name">TrustFix</span>
        </a>

        <div class="tf-nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="list_contractors.php">Contractors</a>
            <?php if (in_array(($_SESSION['user']['role'] ?? ''), ['handyman', 'admin'], true)) { ?>
                <a href="available_jobs.php">Available Jobs</a>
            <?php } ?>
            <a href="my_jobs.php">My Jobs</a>
            <?php if (in_array(($_SESSION['user']['role'] ?? ''), ['handyman', 'admin'], true)) { ?>
                <a href="contractor_dashboard.php">Contractor Dashboard</a>
                <a href="estimate_settings.php">Estimate Settings</a>
            <?php } ?>
            <a href="list_properties.php">Properties</a>

            <?php if (($_SESSION['user']['role'] ?? '') === 'admin') { ?>

                <a href="list_users.php">
                    Manage Users
                </a>

                <a href="manage_jobs.php">
                    Manage Jobs
                </a>

                <a href="manage_material_prices.php">
                    Material Prices
                </a>

                <a href="estimate_training_data.php">
                    ML Data
                </a>

            <?php } ?>
    
            <?php if (empty($_SESSION['jwt_token'])) { ?>

                <a href="login.php">Login</a>
                <a class="tf-nav-button" href="register.php">Register</a>

            <?php } else { ?>

                <a href="edit_profile.php">Profile</a>
                <a class="tf-nav-button" href="logout.php">Logout</a>

            <?php } ?>
        </div>
    </div>
</nav>

<div class="container">

<?php if (!empty($_SESSION['flash_success'])) { ?>
    <div class="tf-alert tf-alert-success">
        <?= htmlspecialchars($_SESSION['flash_success']) ?>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
<?php } ?>

<?php if (!empty($_SESSION['flash_error'])) { ?>
    <div class="tf-alert tf-alert-error">
        <?= htmlspecialchars($_SESSION['flash_error']) ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php } ?>
