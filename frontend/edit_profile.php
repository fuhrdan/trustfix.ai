<?php

require 'config.php';

requireLogin();

include 'header.php';

$message = '';

/*
|--------------------------------------------------------------------------
| Load User Account
|--------------------------------------------------------------------------
*/

$user = apiRequest(
    'GET',
    '/me'
);

/*
|--------------------------------------------------------------------------
| Load Contractor Profile
|--------------------------------------------------------------------------
*/

$contractor = apiRequest(
    'GET',
    '/contractor/profile'
);

/*
|--------------------------------------------------------------------------
| Save Updates
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    /*
    |--------------------------------------------------------------------------
    | Update User Account
    |--------------------------------------------------------------------------
    */

    $userPayload = [

        'name' =>
            $_POST['name'],

        'email' =>
            $_POST['email'],

        'phone' =>
            $_POST['phone']
    ];

    apiRequest(
        'POST',
        '/me/update',
        $userPayload
    );

    /*
    |--------------------------------------------------------------------------
    | Update Contractor Profile
    |--------------------------------------------------------------------------
    */

    $contractorPayload = [

        'business_name' =>
            $_POST['business_name'],

        'bio' =>
            $_POST['bio'],

        'address' =>
            $_POST['address']
    ];

    apiRequest(
        'POST',
        '/contractor/profile',
        $contractorPayload
    );

    /*
    |--------------------------------------------------------------------------
    | Reload Fresh Data
    |--------------------------------------------------------------------------
    */

    $user = apiRequest(
        'GET',
        '/me'
    );

    $contractor = apiRequest(
        'GET',
        '/contractor/profile'
    );

    $message =
        '<p style="color:green;">
            Profile updated successfully.
        </p>';
}

?>

<h1>Edit Profile</h1>

<?= $message ?>

<form method="POST">

    <h2>Account Information</h2>

    <input
        type="text"
        name="name"
        placeholder="Full Name"
        value="<?= htmlspecialchars(
            $user['name']
            ?? ''
        ) ?>"
    >

    <input
        type="email"
        name="email"
        placeholder="Email Address"
        value="<?= htmlspecialchars(
            $user['email']
            ?? ''
        ) ?>"
    >

    <input
        type="text"
        name="phone"
        placeholder="Phone Number"
        value="<?= htmlspecialchars(
            $user['phone']
            ?? ''
        ) ?>"
    >

    <h2>Business Information</h2>

    <input
        type="text"
        name="business_name"
        placeholder="Business Name"
        value="<?= htmlspecialchars(
            $contractor['business_name']
            ?? ''
        ) ?>"
    >

    <textarea
        name="bio"
        placeholder="Business Bio"
    ><?= htmlspecialchars(
        $contractor['bio']
        ?? ''
    ) ?></textarea>

    <input
        type="text"
        name="address"
        placeholder="Business Address"
        value="<?= htmlspecialchars(
            $contractor['address']
            ?? ''
        ) ?>"
    >

    <button type="submit">
        Save Profile
    </button>

</form>

<?php include 'footer.php'; ?>