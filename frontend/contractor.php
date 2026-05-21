<?php

require 'config.php';

requireLogin();

include 'header.php';

$id = (int)($_GET['id'] ?? 0);

$contractor = apiRequest(
    'GET',
    '/contractors/' . $id
);

if (empty($contractor)) {

    echo '<p>Contractor not found.</p>';

    include 'footer.php';
    exit;
}

?>

<h1>
    <?= htmlspecialchars(
        $contractor['business_name']
        ?? 'Contractor'
    ) ?>
</h1>

<p>
    <?= nl2br(htmlspecialchars(
        $contractor['bio'] ?? ''
    )) ?>
</p>

<p>
    <strong>Phone:</strong>
    <?= htmlspecialchars(
        $contractor['phone'] ?? ''
    ) ?>
</p>

<p>
    <strong>Address:</strong>
    <?= htmlspecialchars(
        $contractor['address'] ?? ''
    ) ?>
</p>

<?php include 'footer.php'; ?>