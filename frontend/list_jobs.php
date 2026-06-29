<?php
require 'config.php';
include 'header.php';

$jobs = apiRequest('GET', '/jobs/my');
?>

<h1>My Jobs</h1>

<table border="1" cellpadding="8">

    <tr>

        <th>ID</th>

        <th>Status</th>

        <th>Contact Name</th>

        <th>Contact Phone</th>

        <th>Address</th>

        <th>Price</th>

        <th>Edit</th>

        <th>Bid</th>

        <th>Delete</th>
    </tr>

    <?php
    foreach ($jobs as $job) {
    ?>

        <tr>

            <td>
                <?= $job['id'] ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    $job['status']
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    $job['onsite_contact_name']
                    ?? ''
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    $job['onsite_contact_phone']
                    ?? ''
                ) ?>
            </td>

            <td>
                <?php
                    $jobAddress = $job['address'] ?? '';

                    if (!empty($job['property'])) {

                        $addressParts = [];

                        foreach ([
                            'street_address',
                            'address_line_2',
                            'apartment',
                            'city',
                            'state',
                            'zip'
                        ] as $field) {

                            if (!empty($job['property'][$field])) {

                                $addressParts[] = $job['property'][$field];
                            }
                        }

                        if (!empty($addressParts)) {

                            $jobAddress = implode(', ', $addressParts);
                        }
                    }
                ?>

                <?= htmlspecialchars($jobAddress) ?>
            </td>

            <td>
                $<?= number_format(
                    $job['agreed_price'] ?? 0,
                    2
                ) ?>
            </td>

            <td>

                <a href="edit_job.php?id=<?= $job['id'] ?>">

                    Edit

                </a>

            </td>

            <td>

                <button type="button">

                    Bid

                </button>

            </td>
            
            <td>
                <form method="POST" action="delete_job.php" onsubmit="return confirm('Delete this job?');">
                <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                <button type="submit" style="color:red;">

                    Delete
                    
                </button>
    </form>
</td>

        </tr>

    <?php
    }
    ?>

</table>

<?php include 'footer.php'; ?>