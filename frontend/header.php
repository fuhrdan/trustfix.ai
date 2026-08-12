<!DOCTYPE html>
<html lang="en">
<head>
    <?php
        $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $pageTitles = [
            'dashboard.php' => 'Dashboard',
            'login.php' => 'Sign In',
            'register.php' => 'Create Account',
            'forgot_password.php' => 'Forgot Password',
            'reset_password.php' => 'Reset Password',
            'edit_profile.php' => 'Profile',
            'list_properties.php' => 'Properties',
            'add_property.php' => 'Add Property',
            'edit_property.php' => 'Edit Property',
            'list_contractors.php' => 'Contractors',
            'contractor.php' => 'Contractor Profile',
            'my_jobs.php' => 'My Jobs',
            'available_jobs.php' => 'Available Jobs',
            'add_job.php' => 'Post a Job',
            'edit_job.php' => 'Edit Job',
            'job_detail.php' => 'Job Details',
            'job_workspace.php' => 'Job Workspace',
            'estimate_job.php' => 'Smart Estimate',
            'contractor_dashboard.php' => 'Contractor Dashboard',
            'estimate_settings.php' => 'Estimate Settings',
            'list_users.php' => 'Manage Users',
            'manage_jobs.php' => 'Manage Jobs',
            'manage_material_prices.php' => 'Material Prices',
            'estimate_training_data.php' => 'Estimate Training Data',
            'estimate_accuracy.php' => 'Estimate Accuracy',
        ];
        $resolvedPageTitle = $pageTitle
            ?? ($pageTitles[$scriptName] ?? 'TrustFix');
        $documentTitle = $resolvedPageTitle === 'TrustFix'
            ? 'TrustFix'
            : $resolvedPageTitle . ' | TrustFix';
        $sessionRole = $_SESSION['user']['role'] ?? '';
        $contractorRoles = ['handyman', 'company', 'admin'];
    ?>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($documentTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="TF-Style.css?v=20260811-polish">
</head>

<body>

<a class="tf-skip-link" href="#main-content">Skip to main content</a>

<nav class="tf-top-nav" aria-label="Primary navigation">
    <div class="tf-nav-inner">
        <a class="tf-brand" href="dashboard.php" aria-label="TrustFix Dashboard">
            <img src="images/7749CCB4-A449-4A1E-961A-6D6A38CE5E12.png" alt="TrustFix logo" class="tf-brand-logo">
            <span class="tf-brand-name">TrustFix</span>
        </a>

        <div class="tf-nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="list_contractors.php">Contractors</a>
            <?php if (in_array($sessionRole, $contractorRoles, true)) { ?>
                <a href="available_jobs.php">Available Jobs</a>
            <?php } ?>
            <a href="my_jobs.php">My Jobs</a>
            <?php if (in_array($sessionRole, $contractorRoles, true)) { ?>
                <a href="contractor_dashboard.php">Contractor Dashboard</a>
                <a href="estimate_settings.php">Estimate Settings</a>
            <?php } ?>
            <a href="list_properties.php">Properties</a>

            <?php if ($sessionRole === 'admin') { ?>

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

                <a href="estimate_accuracy.php">
                    Estimate Accuracy
                </a>

            <?php } ?>
    
            <?php if (empty($_SESSION['jwt_token'])) { ?>

                <a href="login.php">Login</a>
                <a class="tf-nav-button" href="register.php">Register</a>

            <?php } else { ?>

                <a href="edit_profile.php">Profile</a>
                <form class="tf-nav-logout" method="POST" action="logout.php">
                    <?= csrfField() ?>
                    <button class="tf-nav-button" type="submit">Logout</button>
                </form>

            <?php } ?>
        </div>
    </div>
</nav>

<main class="container" id="main-content">

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
