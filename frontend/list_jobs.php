<?php
require 'config.php';
include 'header.php';

$jobs = apiRequest('GET', '/jobs/my');
?>

<h1>My Jobs</h1>

<table>
    <tr>
        <th>Edit</th>
        <th>ID</th>
        <th>Status</th>
        <th>Address</th>
        <th>Price</th>
    </tr>

    <?php
    foreach ($jobs as $job) {
    ?>
        <tr>
            <td>
                <p>
                    <a href="edit_job.php?id=<?= $job['id'] ?>">
                        Edit Job
                    </a>
                </p>
            </td>
            
            <td>
                <?= $job['id'] ?>
            </td>

            <td>
                <?= htmlspecialchars($job['status']) ?>
            </td>

            <td>
                <?= htmlspecialchars($job['address']) ?>
            </td>

            <td>
                $<?= number_format(
                    $job['agreed_price'] ?? 0,
                    2
                ) ?>
            </td>
        </tr>
    <?php
    }
    ?>

</table>

<?php include 'footer.php'; ?>