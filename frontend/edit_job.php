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

// Remove before production
// This is for debugging, leave it in, but don't use it.
// The Friendzoned block of code.
//echo '<pre>';
//print_r($job);
//echo '</pre>';

if (!$job) {

    echo '<p>Job not found.</p>';

    include 'footer.php';
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payload = [

        'address' =>
            $_POST['address'],

        'lat' =>
            (float)$_POST['lat'],

        'lng' =>
            (float)$_POST['lng'],

        'initial_description' =>
            $_POST['initial_description'],

        'agreed_price' =>
            (float)$_POST['agreed_price'],

        'onsite_contact_name' =>
            $_POST['onsite_contact_name'],

        'onsite_contact_phone' =>
            $_POST['onsite_contact_phone'],

        'skills' =>
            $_POST['skills'] ?? []
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

$skills = $job['skills'] ?? [];

?>

<head>
    <title>Edit Jobs</title>

    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="TF-Style.css">
</head>

<body>
<h1>Edit Job</h1>

<?= $message ?>

<form method="POST" enctype="multipart/form-data">

    <input
        type="text"
        name="address"
        value="<?= htmlspecialchars($job['address'] ?? '') ?>"
        placeholder="Address"
        required
    >

    <input
        type="text"
        name="lat"
        value="<?= htmlspecialchars($job['lat'] ?? '') ?>"
        placeholder="Latitude"
    >

    <input
        type="text"
        name="lng"
        value="<?= htmlspecialchars($job['lng'] ?? '') ?>"
        placeholder="Longitude"
    >

    <textarea
        name="initial_description"
        placeholder="Job Description"
        required
    ><?= htmlspecialchars(
        $job['initial_description'] ?? ''
    ) ?></textarea>

    <input
        type="number"
        step="0.01"
        name="agreed_price"
        value="<?= htmlspecialchars(
            $job['agreed_price'] ?? ''
        ) ?>"
        placeholder="Price"
    >

    <input
        type="text"
        name="onsite_contact_name"
        value="<?= htmlspecialchars(
            $job['onsite_contact_name'] ?? ''
        ) ?>"
        placeholder="On-site Contact Name"
    >

    <input
        type="text"
        name="onsite_contact_phone"
        value="<?= htmlspecialchars(
            $job['onsite_contact_phone'] ?? ''
        ) ?>"
        placeholder="On-site Contact Phone"
    >

    <h3>Required Skills</h3>

    <div class="skills-group">
    <label class="skill-item">
        <input
            type="checkbox"
            name="skills[]"
            value="electrical"
            <?= in_array('electrical', $skills)
                ? 'checked'
                : '' ?>
        >
        Electrical
    </label>

    <label class="skill-item">
        <input
            type="checkbox"
            name="skills[]"
            value="plumbing"
            <?= in_array('plumbing', $skills)
                ? 'checked'
                : '' ?>
        >
        Plumbing
    </label>

    <label class="skill-item">
        <input
            type="checkbox"
            name="skills[]"
            value="drywall"
            <?= in_array('drywall', $skills)
                ? 'checked'
                : '' ?>
        >
        Drywall
    </label>

    <label class="skill-item">
        <input
            type="checkbox"
            name="skills[]"
            value="flooring"
            <?= in_array('flooring', $skills)
                ? 'checked'
                : '' ?>
        >
        Flooring
    </label>

    <label class="skill-item">
        <input
            type="checkbox"
            name="skills[]"
            value="general"
            <?= in_array('general', $skills)
                ? 'checked'
                : '' ?>
        >
        General
    </label>
    
    </div>

    <h3>Upload Pictures</h3>

    <input
        type="file"
        name="images[]"
        multiple
        accept="image/*"
    >

    <button type="submit">
        Update Job
    </button>

</form>

<?php include 'footer.php'; ?>

</body>