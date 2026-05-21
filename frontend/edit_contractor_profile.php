<?php

require 'config.php';

requireLogin();

include 'header.php';

$message = '';

$profile = apiRequest(
    'GET',
    '/contractor/profile'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payload = [

        'business_name' =>
            $_POST['business_name'],

        'bio' =>
            $_POST['bio'],

        'phone' =>
            $_POST['phone'],

        'address' =>
            $_POST['address']
    ];

    $result = apiRequest(
        'POST',
        '/contractor/profile',
        $payload
    );

    $message =
        '<p style="color:green;">
            Profile updated.
        </p>';

    $profile = $result;
}

?>

<h1>Edit Contractor Profile</h1>

<?= $message ?>

<form method="POST">

    <input
        type="text"
        name="business_name"
        placeholder="Business Name"
        value="<?= htmlspecialchars(
            $profile['business_name']
            ?? ''
        ) ?>"
    >

    <textarea
        name="bio"
        placeholder="Business Bio"
    ><?= htmlspecialchars(
        $profile['bio']
        ?? ''
    ) ?></textarea>

    <input
        type="text"
        name="phone"
        placeholder="Phone"
        value="<?= htmlspecialchars(
            $profile['phone']
            ?? ''
        ) ?>"
    >

    <input
        type="text"
        name="address"
        placeholder="Address"
        value="<?= htmlspecialchars(
            $profile['address']
            ?? ''
        ) ?>"
    >

    <button type="submit">
        Save Profile
    </button>

</form>

<?php include 'footer.php'; ?>