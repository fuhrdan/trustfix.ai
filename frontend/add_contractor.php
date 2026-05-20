<?php
require 'config.php';
include 'header.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payload = [
        'business_name' => $_POST['business_name'],
        'bio' => $_POST['bio'],
        'service_area' => $_POST['service_area'],
        'years_experience' => (int)$_POST['years_experience'],
        'license_number' => $_POST['license_number']
    ];

    $result = apiRequest(
        'POST',
        '/contractor/profile',
        $payload
    );

    $message = '<pre>' . print_r($result, true) . '</pre>';
}
?>

<h1>Add Contractor</h1>

<?= $message ?>

<form method="POST">

    <input
        type="text"
        name="business_name"
        placeholder="Business Name"
        required
    >

    <textarea
        name="bio"
        placeholder="Business Description"
        required
    ></textarea>

    <input
        type="text"
        name="service_area"
        placeholder="Service Area"
        required
    >

    <input
        type="number"
        name="years_experience"
        placeholder="Years Experience"
        required
    >

    <input
        type="text"
        name="license_number"
        placeholder="License Number"
    >

    <button type="submit">
        Save Contractor
    </button>

</form>

<?php include 'footer.php'; ?>