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
        $isAuthenticated = !empty($_SESSION['jwt_token']);
        $sessionUser = $isAuthenticated ? currentUser() : [];
        $sessionRole = $sessionUser['role'] ?? '';
        $contractorRoles = ['handyman', 'company', 'admin'];
        $notificationSummary = $isAuthenticated
            ? messageNotificationSummary()
            : ['unread_count' => 0, 'latest_job_id' => null];
        $unreadMessageCount = max(0, (int)($notificationSummary['unread_count'] ?? 0));
        $notificationUrl = !empty($notificationSummary['latest_job_id'])
            ? 'job_workspace.php?id=' . (int)$notificationSummary['latest_job_id']
            : 'my_jobs.php';
        $notificationLabel = $unreadMessageCount > 0
            ? $unreadMessageCount . ' unread ' . ($unreadMessageCount === 1 ? 'message' : 'messages')
            : 'No unread messages';

        $menuSections = [
            'Workspace' => [
                ['href' => 'list_contractors.php', 'label' => 'Contractors'],
                ['href' => 'my_jobs.php', 'label' => 'My Jobs'],
                ['href' => 'list_properties.php', 'label' => 'Properties'],
            ],
        ];

        if (in_array($sessionRole, $contractorRoles, true)) {
            array_splice($menuSections['Workspace'], 1, 0, [[
                'href' => 'available_jobs.php',
                'label' => 'Available Jobs',
            ]]);
            $menuSections['Contractor'] = [
                ['href' => 'contractor_dashboard.php', 'label' => 'Contractor Dashboard'],
                ['href' => 'estimate_settings.php', 'label' => 'Estimate Settings'],
            ];
        }

        if ($sessionRole === 'admin') {
            $menuSections['Administration'] = [
                ['href' => 'list_users.php', 'label' => 'Manage Users'],
                ['href' => 'manage_jobs.php', 'label' => 'Manage Jobs'],
                ['href' => 'manage_material_prices.php', 'label' => 'Material Prices'],
                ['href' => 'estimate_training_data.php', 'label' => 'ML Data'],
                ['href' => 'estimate_accuracy.php', 'label' => 'Estimate Accuracy'],
            ];
        }

        $menuSections['Account'] = [
            ['href' => 'edit_profile.php', 'label' => 'Profile'],
        ];
        $mainClasses = trim('container ' . ($pageContainerClass ?? ''));
    ?>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($documentTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="TF-Style.css?v=20260812-sticky-nav">
</head>

<body>

<a class="tf-skip-link" href="#main-content">Skip to main content</a>

<header class="tf-top-nav">
    <div class="tf-nav-inner">
        <a class="tf-brand" href="dashboard.php" aria-label="TrustFix Dashboard">
            <img src="images/7749CCB4-A449-4A1E-961A-6D6A38CE5E12.png" alt="TrustFix logo" class="tf-brand-logo">
            <span class="tf-brand-name">TrustFix</span>
        </a>

        <?php if ($isAuthenticated) { ?>
            <nav class="tf-nav-actions" aria-label="Primary navigation">
                <a
                    class="tf-dashboard-link<?= $scriptName === 'dashboard.php' ? ' is-active' : '' ?>"
                    href="dashboard.php"
                    <?= $scriptName === 'dashboard.php' ? 'aria-current="page"' : '' ?>
                >Dashboard</a>

                <a
                    class="tf-notification-link<?= $unreadMessageCount > 0 ? ' has-unread' : '' ?>"
                    href="<?= htmlspecialchars($notificationUrl, ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="Messages: <?= htmlspecialchars($notificationLabel, ENT_QUOTES, 'UTF-8') ?>"
                    title="<?= htmlspecialchars($notificationLabel, ENT_QUOTES, 'UTF-8') ?>"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                        <path d="M10 21h4"></path>
                    </svg>
                    <?php if ($unreadMessageCount > 0) { ?>
                        <span class="tf-notification-count" aria-hidden="true">
                            <?= $unreadMessageCount > 99 ? '99+' : $unreadMessageCount ?>
                        </span>
                    <?php } ?>
                </a>

                <details class="tf-nav-menu">
                    <summary aria-label="Dashboard menu">
                        <span class="tf-hamburger" aria-hidden="true">
                            <span></span>
                            <span></span>
                            <span></span>
                        </span>
                        <span class="tf-sr-only">Dashboard menu</span>
                    </summary>

                    <div class="tf-nav-menu-panel">
                        <?php foreach ($menuSections as $sectionLabel => $sectionLinks) { ?>
                            <div class="tf-nav-menu-section">
                                <div class="tf-nav-menu-heading">
                                    <?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php foreach ($sectionLinks as $menuLink) { ?>
                                    <?php $isCurrentMenuPage = $scriptName === $menuLink['href']; ?>
                                    <a
                                        class="<?= $isCurrentMenuPage ? 'is-active' : '' ?>"
                                        href="<?= htmlspecialchars($menuLink['href'], ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $isCurrentMenuPage ? 'aria-current="page"' : '' ?>
                                    >
                                        <span><?= htmlspecialchars($menuLink['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="tf-menu-arrow" aria-hidden="true">›</span>
                                    </a>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                </details>

                <form class="tf-nav-logout" method="POST" action="logout.php">
                    <?= csrfField() ?>
                    <button class="tf-signout-button" type="submit">Sign Out</button>
                </form>
            </nav>
        <?php } else { ?>
            <nav class="tf-public-nav" aria-label="Account navigation">
                <a href="login.php">Sign In</a>
                <a class="tf-public-cta" href="register.php">Create Account</a>
            </nav>
        <?php } ?>
    </div>
</header>

<script>
    (() => {
        const menu = document.querySelector('.tf-nav-menu');

        if (!menu) {
            return;
        }

        document.addEventListener('click', (event) => {
            if (menu.open && !menu.contains(event.target)) {
                menu.removeAttribute('open');
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && menu.open) {
                menu.removeAttribute('open');
                menu.querySelector('summary').focus();
            }
        });
    })();
</script>

<main class="<?= htmlspecialchars($mainClasses, ENT_QUOTES, 'UTF-8') ?>" id="main-content">

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
