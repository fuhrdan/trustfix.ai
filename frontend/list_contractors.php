<?php
require 'config.php';
requireLogin();

include 'header.php';



$contractors = apiRequest('GET', '/contractors');
?>

<h1>Contractors</h1>

<table>
    <tr>
        <th>Business</th>
        <th>Area</th>
        <th>Experience</th>
        <th>Rating</th>
    </tr>

    <?php if (empty($contractors)) { ?>
        <p>No Contractors found.</p>
        
    <?php
    foreach ($contractors['data'] as $contractor) {
    ?>
        <tr>
            <td>
                <?= htmlspecialchars($contractor['business_name']) ?>
            </td>

            <td>
                <?= htmlspecialchars($contractor['service_area']) ?>
            </td>

            <td>
                <?= htmlspecialchars($contractor['years_experience']) ?>
            </td>

            <td>
                <?= number_format(
                    $contractor['average_rating'] ?? 0,
                    1
                ) ?>
            </td>
        </tr>
    <?php
    }
    ?>

</table>

<?php include 'footer.php'; ?>