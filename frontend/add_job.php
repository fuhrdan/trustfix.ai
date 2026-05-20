<?php
require 'config.php';
include 'header.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payload = [
        'address' => $_POST['address'],
        'lat' => (float)$_POST['lat'],
        'lng' => (float)$_POST['lng'],
        'initial_description' => $_POST['initial_description'],
        'agreed_price' => (float)$_POST['agreed_price']
    ];

    $result = apiRequest(
        'POST',
        '/jobs',
        $payload
    );

    $message = '<pre>' . print_r($result, true) . '</pre>';
}
?>

<h1>Add Job</h1>

<?= $message ?>

<form method="POST">

    <input
        type="text"
        name="address"
        placeholder="Address"
        required
    >

    <input
        type="text"
        name="lat"
        placeholder="Latitude"
        required
    >

    <input
        type="text"
        name="lng"
        placeholder="Longitude"
        required
    >

    <textarea
        name="initial_description"
        placeholder="Job Description"
        required
    ></textarea>

    <input
        type="number"
        step="0.01"
        name="agreed_price"
        placeholder="Price"
    >

    <button type="submit">
        Save Job
    </button>

</form>

<?php include 'footer.php'; ?>