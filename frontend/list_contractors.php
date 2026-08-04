<?php
require 'config.php';
requireLogin();

include 'header.php';



$contractors = apiRequest('GET', '/contractors');
$contractorRows = is_array($contractors['data'] ?? null)
    ? $contractors['data']
    : [];
?>

<h1>Contractors</h1>

<?php if (empty($contractorRows)) { ?>
    <div style="background:white;border:1px dashed #bbb;border-radius:10px;padding:22px;">
        No approved public contractors were found.
    </div>
<?php } else { ?>
    <table>
        <tr>
            <th>Business</th>
            <th>Area</th>
            <th>Experience</th>
            <th>Rating</th>
        </tr>

        <?php foreach ($contractorRows as $contractor) { ?>
            <?php
                $experience = $contractor['years_experience'] ?? null;
                if (($experience === null || $experience === '') && !empty($contractor['year_established'])) {
                    $experience = max(0, (int)date('Y') - (int)$contractor['year_established']);
                }
            ?>
            <tr>
                <td><?= htmlspecialchars($contractor['business_name'] ?? 'Contractor') ?></td>
                <td><?= htmlspecialchars($contractor['service_area'] ?? 'Not listed') ?></td>
                <td><?= $experience === null || $experience === '' ? 'Not listed' : (int)$experience . ' years' ?></td>
                <td><?= number_format((float)($contractor['average_rating'] ?? 0), 1) ?></td>
            </tr>
        <?php } ?>
    </table>
<?php } ?>

<?php include 'footer.php'; ?>
