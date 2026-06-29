<?php

require 'config.php';

requireLogin();

header('Content-Type: application/json');

$allowedTypes = [
    'state_license',
    'sales_tax_license',
    'certificate_of_liability_insurance',
    'surety_bond',
    'service_agreement'
];

$documentType = $_POST['document_type'] ?? '';

if (!in_array($documentType, $allowedTypes, true))
{
    echo json_encode([
        'success' => false,
        'error' => 'Invalid document type.'
    ]);
    exit;
}

if (
    empty($_FILES['file']) ||
    ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
)
{
    echo json_encode([
        'success' => false,
        'error' => 'Please choose a PDF or image before uploading.'
    ]);
    exit;
}

$upload = $_FILES['file'];

$result = apiRequest(
    'POST',
    '/contractor/documents',
    [
        'type' => $documentType,
        'file' => new CURLFile(
            $upload['tmp_name'],
            $upload['type'],
            $upload['name']
        )
    ]
);

$httpCode = $result['_http_code'] ?? 200;

if ($httpCode >= 400 || !empty($result['errors']) || !empty($result['error']))
{
    $error = $result['message']
        ?? $result['error']
        ?? 'Upload failed.';

    if (!empty($result['errors']))
    {
        $error .= ' ' . json_encode($result['errors']);
    }

    echo json_encode([
        'success' => false,
        'error' => $error,
        'debug' => $result
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'document' => $result
]);
