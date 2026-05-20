<?php

require 'config.php';

requireLogin();

include 'header.php';

$user = $_SESSION['user'] ?? [];
?>

<h1>Dashboard</h1>

<p>
    Welcome
    <strong>
        <?= htmlspecialchars($user['name'] ?? 'User') ?>
    </strong>
</p>

<div
    style="
        display:grid;
        grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
        gap:20px;
        margin-top:30px;
    "
>

    <div
        style="
            background:#fafafa;
            padding:20px;
            border-radius:8px;
        "
    >
        <h3>Contractors</h3>

        <p>
            Manage contractor profiles.
        </p>

        <a href="add_contractor.php">
            Add Contractor
        </a>

        <br><br>

        <a href="list_contractors.php">
            View Contractors
        </a>
    </div>

    <div
        style="
            background:#fafafa;
            padding:20px;
            border-radius:8px;
        "
    >
        <h3>Jobs</h3>

        <p>
            Create and manage jobs.
        </p>

        <a href="add_job.php">
            Add Job
        </a>

        <br><br>

        <a href="list_jobs.php">
            View Jobs
        </a>
    </div>

</div>

<?php include 'footer.php'; ?>