<?php

require 'config.php';
requireLogin();

$user = currentUser(true);
$role = $user['role'] ?? '';
$isContractor = in_array($role, ['handyman', 'company', 'admin'], true);
$isAdmin = $role === 'admin';

$pageTitle = 'Dashboard';
include 'header.php';
?>

<h1>Dashboard</h1>

<p class="tf-page-intro">
    Welcome back, <strong><?= htmlspecialchars($user['name'] ?? 'TrustFix user', ENT_QUOTES, 'UTF-8') ?></strong>.
    Keep your properties, jobs, and repair records together in one place.
</p>

<div class="tf-card-grid">
    <section class="tf-card">
        <h2>Profile</h2>
        <p>Keep your contact information and account settings current.</p>
        <div class="tf-actions">
            <a class="tf-button" href="edit_profile.php">Edit Profile</a>
        </div>
    </section>

    <section class="tf-card">
        <h2>Properties</h2>
        <p>Add a property before posting work, then keep its photos and authorized users organized.</p>
        <div class="tf-actions">
            <a class="tf-button" href="add_property.php">Add Property</a>
            <a class="tf-button tf-button-secondary" href="list_properties.php">View Properties</a>
        </div>
    </section>

    <section class="tf-card">
        <h2><?= $isContractor ? 'Contractor Jobs' : 'Jobs' ?></h2>
        <p>
            <?= $isContractor
                ? 'Review work opportunities and manage jobs already assigned to you.'
                : 'Describe the work you need and follow it from estimate through completion.' ?>
        </p>
        <div class="tf-actions">
            <?php if ($isContractor): ?>
                <a class="tf-button tf-button-success" href="available_jobs.php">Available Jobs</a>
            <?php else: ?>
                <a class="tf-button" href="add_job.php">Post a Job</a>
            <?php endif; ?>
            <a class="tf-button tf-button-secondary" href="my_jobs.php">My Jobs</a>
        </div>
    </section>

    <section class="tf-card">
        <h2>Contractors</h2>
        <p>Browse approved TrustFix professionals and review their service details.</p>
        <div class="tf-actions">
            <a class="tf-button" href="list_contractors.php">Browse Contractors</a>
        </div>
    </section>

    <?php if ($isContractor): ?>
        <section class="tf-card">
            <h2>Contractor Workspace</h2>
            <p>Review your approval status, payout readiness, and operating information.</p>
            <div class="tf-actions">
                <a class="tf-button" href="contractor_dashboard.php">Open Contractor Dashboard</a>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <section class="tf-card">
            <h2>Administration</h2>
            <p>Review users, jobs, approvals, materials, and estimate performance.</p>
            <div class="tf-actions">
                <a class="tf-button" href="list_users.php">Manage Users</a>
                <a class="tf-button tf-button-secondary" href="manage_jobs.php">Manage Jobs</a>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
