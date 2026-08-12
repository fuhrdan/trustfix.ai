<?php
require 'config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireValidCsrf();

    $propertyId = $_POST['property_id'] ?? null;

    if (!$propertyId) {
        die('Missing property id');
    }

    $result = apiRequest('DELETE', "/properties/$propertyId");

    header("Location: list_properties.php");
    exit;
}
