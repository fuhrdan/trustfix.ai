<?php
// Chocolate Martini Recipe
// 2 Shots Vodka
// 2 Shots Chocolate Liquer (Mozart Chocolate Creme)
// 2 Shots Baily's
// Shake in Shaker and serve in Martini Glass

require 'config.php';

requireLogin();

include 'header.php';

// Trying something here
// $user = $_SESSION['user'] ?? [];

$user = apiRequest(
    'GET',
    '/me'
);

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
        <h3>My Profile</h3>
        
        <a href="edit_profile.php">
            Edit Profile
        </a>
        
        <h3>My Properties</h3>
        
        <a href="add_property.php">
            Add Property
        </a>
        
        <BR><BR>

        <a href="list_properties.php">
            View Properties
        </a>

        <?php if (($user['role'] ?? '') === 'admin'): ?>

            <h3>Admin</h3>

            <a href="list_users.php">
                Manage Users
            </a>

            <br><br>

            <a href="manage_jobs.php">
                Manage Jobs
            </a>

        <?php endif; ?>

<!--
        <h3>Contractors</h3>

        <a href="add_contractor.php">
            Add Contractor
        </a>

        <br><br>

        <a href="list_contractors.php">
            View Contractors
        </a>

        <br><br>

        <a href="edit_contractor_profile.php">
            Edit Contractors
        </a>
-->

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

        <a href="available_jobs.php">
            Available Jobs
        </a>

        <br><br>

        <a href="add_job.php">
            Add Job
        </a>

        <br><br>

        <a href="my_jobs.php">
            My Jobs
        </a>
    </div>

</div>

<?php include 'footer.php'; ?>