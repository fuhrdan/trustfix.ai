<?php
require 'config.php';

//It is late, I had this open way too long and now the program is telling me
//There are unsaved changes.  Seriously, WTF?  So I'm going to save and close
//When something breaks, blame the other guy, because I am tired.
//Dan

requireLogin();

include 'header.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payload = [
        'address' => $_POST['address'],
        'lat' => (float)$_POST['lat'],
        'lng' => (float)$_POST['lng'],
        'initial_description' => $_POST['initial_description'],
        'agreed_price' => (float)$_POST['agreed_price'],
        'onsite_contact_name' => $_POST['onsite_contact_name'],
        'onsite_contact_phone' => $_POST['onsite_contact_phone'],
        'skills' => $_POST['skills'] ?? []
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

<form method="POST" encrtype="multipart/form-data">

    <input
        type="text"
        name="address"
        placeholder="Address"
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

    <input
        type="text"
        name="onsite_contact_name"
        placeholder="On-site Contact Name"
    >

    <input
        type="text"
        name="onsite_contact_phone"
        placeholder="On-site Contact Phone"
    >

    <h3>Required Skills</h3>

    <label>
        <input type="checkbox" name="skills[]" value="electrical">
        Electrical
    </label>

    <label>
        <input type="checkbox" name="skills[]" value="plumbing">
        Plumbing
    </label>

    <label>
        <input type="checkbox" name="skills[]" value="drywall">
        Drywall
    </label>

    <label>
        <input type="checkbox" name="skills[]" value="flooring">
        Flooring
    </label>

    <label>
        <input type="checkbox" name="skills[]" value="general">
        General
    </label>

    <h3>Upload Pictures</h3>

    <input
        type="file"
        name="images[]"
        multiple
        accept="image/*"
    >

    <button type="submit">
        Save Job
    </button>

</form>
<?php include 'footer.php'; ?>