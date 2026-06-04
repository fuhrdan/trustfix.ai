<?php
require 'config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $propertyId = $_POST['property_id'] ?? null;

    if (!$propertyId) {
        die('Missing property id');
    }

    $result = apiRequest('DELETE', "/properties/$id");

    header("Location: list_properties.php");
    exit;
}