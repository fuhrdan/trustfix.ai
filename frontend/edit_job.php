<?php

require 'config.php';

requireLogin();

include 'header.php';

$jobId = (int)($_GET['id'] ?? 0);

$result = apiRequest(
    'GET',
    '/jobs/' . $jobId
);

$job = $result ?? null;

//Debugging section to show information

echo '<pre>';
print_r($job);
echo '</pre>';

//Comment out before production.

if (!$job) {

    echo '<p>Job not found.</p>';

    include 'footer.php';
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payload = [
        'address' => $_POST['address'],
        'lat' => (float)$_POST['lat'],
        'lng' => (float)$_POST['lng'],
        'initial_description' =>
            $_POST['initial_description'],
        'agreed_price' =>
            (float)$_POST['agreed_price']
    ];

    $updateResult = apiRequest(
        'PUT',
        '/jobs/' . $jobId,
        $payload
    );

    $message =
        '<p style="color:green;">
            Job updated successfully.
        </p>';

    $job = $updateResult;
}

?>

<h1>Edit Job</h1>

<?= $message ?>

<form method="POST">

    <input
        type="text"
        name="address"
        value="<?= htmlspecialchars($job['address']) ?>"
        required
    >

    <input
        type="text"
        name="lat"
        value="<?= htmlspecialchars($job['lat']) ?>"
        required
    >

    <input
        type="text"
        name="lng"
        value="<?= htmlspecialchars($job['lng']) ?>"
        required
    >

    <textarea
        name="initial_description"
        required
    ><?= htmlspecialchars(
        $job['initial_description']
    ) ?></textarea>

    <input
        type="number"
        step="0.01"
        name="agreed_price"
        value="<?= htmlspecialchars(
            $job['agreed_price']
        ) ?>"
    >

    <button type="submit">
        Update Job
    </button>

</form>

<?php include 'footer.php'; ?>