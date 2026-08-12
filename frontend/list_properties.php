<?php

require 'config.php';
requireLogin();

$properties = apiRequest('GET', '/properties');

if (!is_array($properties) || isset($properties['error']) || isset($properties['message'])) {
    $properties = [];
}

$pageTitle = 'Properties';
include 'header.php';
?>

<div class="tf-actions" style="justify-content:space-between;margin-bottom:22px;">
    <div>
        <h1 style="margin-bottom:6px;">My Properties</h1>
        <p class="tf-page-intro" style="margin:0;">Save each address once, then use it when posting and tracking repair work.</p>
    </div>
    <a class="tf-button" href="add_property.php">Add Property</a>
</div>

<?php if (empty($properties)): ?>
    <section class="tf-empty-state">
        <div class="tf-empty-state-icon" aria-hidden="true">+</div>
        <h2>No properties yet</h2>
        <p>Add your first property to start posting jobs and organizing repair history.</p>
        <a class="tf-button" href="add_property.php">Add Your First Property</a>
    </section>
<?php else: ?>
    <div class="tf-table-wrap">
        <table>
            <caption class="tf-sr-only">Your saved TrustFix properties</caption>
            <thead>
                <tr>
                    <th scope="col">Address</th>
                    <th scope="col">Unit</th>
                    <th scope="col">City</th>
                    <th scope="col">State</th>
                    <th scope="col">ZIP</th>
                    <th scope="col">County</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($properties as $property): ?>
                    <tr>
                        <td><?= htmlspecialchars($property['street_address'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($property['apartment'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($property['city'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($property['state'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($property['zip'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($property['county'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <div class="tf-actions">
                                <a class="tf-button tf-button-secondary" href="edit_property.php?id=<?= (int)($property['id'] ?? 0) ?>">Edit</a>
                                <form method="POST" action="delete_property.php" onsubmit="return confirm('Delete this property?');" style="margin:0;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="property_id" value="<?= (int)($property['id'] ?? 0) ?>">
                                    <button class="tf-button-danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
