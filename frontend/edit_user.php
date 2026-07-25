<?php

require 'config.php';
requireLogin();

$currentAdmin = apiRequest('GET', '/me');

if (($currentAdmin['role'] ?? '') !== 'admin')
{
    http_response_code(403);
    die('Administrator access required.');
}

$userId = (int)($_GET['id'] ?? 0);

if (!$userId)
{
    die('Missing user id');
}

$message = '';

include 'header.php';

function contractorDocumentLabel($type)
{
    $labels = [
        'state_license' => 'State License',
        'sales_tax_license' => 'Sales Tax License',
        'certificate_of_liability_insurance' => 'Certificate of Liability Insurance',
        'certificate_of_liability' => 'Certificate of Liability Insurance',
        'surety_bond' => 'Surety Bond',
        'service_agreement' => 'Contract / Service Agreement',
    ];

    return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function contractorDocumentStatus($status)
{
    $status = (int)$status;

    if ($status === 1)
    {
        return '<span style="color:#2e7d32;font-weight:bold;">✓ Approved</span>';
    }

    if ($status === 2)
    {
        return '<span style="color:#b00020;font-weight:bold;">Denied</span>';
    }

    return '<span style="color:#b36b00;font-weight:bold;">Pending Approval</span>';
}

function contractorDocumentUrl($storedFilename)
{
    global $apiBase;

    if (empty($storedFilename))
    {
        return '';
    }

    $base = preg_replace('#/api/?$#', '', $apiBase);

    return rtrim($base, '/') . '/storage/' . ltrim($storedFilename, '/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $action = $_POST['action'] ?? 'update_user';

    if ($action === 'approve_contractor')
    {
        $contractorProfileId = (int)($_POST['contractor_profile_id'] ?? 0);

        if ($contractorProfileId <= 0)
        {
            $message = "
                <div class='tf-alert tf-alert-error'>
                    This user does not have a contractor profile to approve.
                </div>
            ";
        }
        else
        {
            $response = apiRequest(
                'POST',
                "/admin/contractor-profiles/$contractorProfileId/status",
                [
                    'status' => 'approved',
                    'is_public' => true,
                ]
            );

            if (($response['_http_code'] ?? 500) >= 200
                && ($response['_http_code'] ?? 500) < 300
                && ($response['status'] ?? '') === 'approved')
            {
                $message = "
                    <div class='tf-alert tf-alert-success'>
                        Contractor approved successfully. The contractor dashboard
                        and available jobs are now enabled for this user.
                    </div>
                ";
            }
            else
            {
                $message = "
                    <div class='tf-alert tf-alert-error'>
                        Contractor approval failed.
                        <pre>" . htmlspecialchars(print_r($response, true)) . "</pre>
                    </div>
                ";
            }
        }
    }
    elseif ($action === 'update_document_status')
    {
        $documentId = (int)($_POST['document_id'] ?? 0);
        $status = (int)($_POST['verification_status'] ?? 0);
        $notes = $_POST['notes'] ?? '';

        if ($documentId > 0)
        {
            $response = apiRequest(
                'POST',
                "/admin/contractor-documents/$documentId/status",
                [
                    'verification_status' => $status,
                    'notes' => $notes,
                ]
            );

            if (($response['success'] ?? false) === true)
            {
                $message = "
                    <div style='
                        background:#dff0d8;
                        padding:15px;
                        border-radius:8px;
                        margin-bottom:20px;
                    '>
                        Contractor document updated.
                    </div>
                ";
            }
            else
            {
                $message = "
                    <div style='
                        background:#f8d7da;
                        padding:15px;
                        border-radius:8px;
                        margin-bottom:20px;
                    '>
                        Document update failed.
                        <pre>" . htmlspecialchars(print_r($response, true)) . "</pre>
                    </div>
                ";
            }
        }
    }
    else
    {
        $payload = [
            'name' => $_POST['name'] ?? '',
            'email' => $_POST['email'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'address' => $_POST['address'] ?? '',
            'role' => $_POST['role'] ?? 'customer'
        ];

        $response = apiRequest(
            'PUT',
            "/admin/users/$userId",
            $payload
        );

        if (($response['success'] ?? false) === true)
        {
            $message = "
                <div style='
                    background:#dff0d8;
                    padding:15px;
                    border-radius:8px;
                    margin-bottom:20px;
                '>
                    User Updated Successfully
                </div>
            ";
        }
        else
        {
            $message = "
                <div style='
                    background:#f8d7da;
                    padding:15px;
                    border-radius:8px;
                    margin-bottom:20px;
                '>
                    User update failed.
                    <pre>" . htmlspecialchars(print_r($response, true)) . "</pre>
                </div>
            ";
        }
    }
}

$user = apiRequest(
    'GET',
    "/admin/users/$userId"
);

if (!is_array($user) || isset($user['error']))
{
    die('User not found');
}

$documents = $user['contractor_documents'] ?? [];
$contractorProfile = $user['contractor_profile'] ?? null;
$pendingDocuments = 0;

foreach ($documents as $document)
{
    if ((int)($document['verification_status'] ?? 0) === 0)
    {
        $pendingDocuments++;
    }
}

?>

<h1>Edit User</h1>

<?= $message ?>

<form method="POST">

    <input type="hidden" name="action" value="update_user">

    <label>Name</label><br>

    <input
        type="text"
        name="name"
        required
        value="<?= htmlspecialchars($user['name'] ?? '') ?>"
    >

    <br><br>

    <label>Email</label><br>

    <input
        type="email"
        name="email"
        required
        value="<?= htmlspecialchars($user['email'] ?? '') ?>"
    >

    <br><br>

    <label>Phone</label><br>

    <input
        type="text"
        name="phone"
        value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
    >

    <br><br>

    <label>Address</label><br>

    <input
        type="text"
        name="address"
        value="<?= htmlspecialchars($user['address'] ?? '') ?>"
    >

    <br><br>

    <label>Role</label><br>

    <select name="role">

        <option
            value="customer"
            <?= ($user['role'] ?? '') === 'customer'
                ? 'selected'
                : '' ?>
        >
            Customer
        </option>

        <option
            value="handyman"
            <?= ($user['role'] ?? '') === 'handyman'
                ? 'selected'
                : '' ?>
        >
            Handyman
        </option>

        <option
            value="company"
            <?= ($user['role'] ?? '') === 'company'
                ? 'selected'
                : '' ?>
        >
            Company
        </option>

        <option
            value="admin"
            <?= ($user['role'] ?? '') === 'admin'
                ? 'selected'
                : '' ?>
        >
            Admin
        </option>

    </select>

    <br><br>

    <button type="submit">
        Update User
    </button>

</form>

<hr>

<h2>Contractor Approval</h2>

<?php if (empty($contractorProfile)): ?>

    <p>
        This user has not created a contractor profile yet.
    </p>

<?php else: ?>

    <?php
        $contractorStatus = $contractorProfile['status'] ?? 'pending';
        $isApprovedContractor = $contractorStatus === 'approved';
    ?>

    <div style="
        background:#fafafa;
        border:1px solid #ddd;
        border-radius:8px;
        padding:18px;
        margin-bottom:25px;
    ">
        <p>
            <strong>Business:</strong>
            <?= htmlspecialchars($contractorProfile['business_name'] ?? 'Not provided') ?>
        </p>

        <p>
            <strong>Profile status:</strong>
            <span style="
                color:<?= $isApprovedContractor ? '#2e7d32' : '#b36b00' ?>;
                font-weight:bold;
            ">
                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $contractorStatus))) ?>
            </span>
        </p>

        <?php if ($isApprovedContractor): ?>

            <p style="color:#2e7d32;font-weight:bold;">
                ✓ This contractor is approved and has dashboard access.
            </p>

        <?php else: ?>

            <?php if ($pendingDocuments > 0): ?>
                <p style="color:#b36b00;">
                    Warning: <?= $pendingDocuments ?> contractor document<?= $pendingDocuments === 1 ? ' is' : 's are' ?>
                    still awaiting review.
                </p>
            <?php endif; ?>

            <form method="POST" onsubmit="return confirm('Approve this contractor and enable contractor dashboard access?');">
                <input type="hidden" name="action" value="approve_contractor">
                <input
                    type="hidden"
                    name="contractor_profile_id"
                    value="<?= (int)($contractorProfile['id'] ?? 0) ?>"
                >
                <button type="submit" style="
                    background:#2e7d32;
                    color:white;
                    border:0;
                    border-radius:6px;
                    padding:11px 18px;
                    cursor:pointer;
                    width:auto;
                ">
                    Approve Contractor
                </button>
            </form>

        <?php endif; ?>
    </div>

<?php endif; ?>

<hr>

<h2>Contractor Documents</h2>

<p>
    Pending approvals:
    <strong><?= $pendingDocuments ?></strong>
</p>

<?php if (empty($documents)): ?>

    <p>No contractor documents uploaded yet.</p>

<?php else: ?>

    <table border="1" cellpadding="8" style="width:100%; border-collapse:collapse;">
        <tr>
            <th>Document</th>
            <th>File</th>
            <th>Status</th>
            <th>Uploaded</th>
            <th>Notes</th>
            <th>Action</th>
        </tr>

        <?php foreach ($documents as $document): ?>

            <?php
                $documentId = (int)($document['id'] ?? 0);
                $documentUrl = contractorDocumentUrl($document['stored_filename'] ?? '');
                $status = (int)($document['verification_status'] ?? 0);
            ?>

            <tr>
                <td>
                    <?= htmlspecialchars(contractorDocumentLabel($document['document_type'] ?? '')) ?>
                </td>

                <td>
                    <?php if (!empty($documentUrl)): ?>
                        <a href="<?= htmlspecialchars($documentUrl) ?>" target="_blank">
                            <?= htmlspecialchars($document['original_filename'] ?? 'View Document') ?>
                        </a>
                    <?php else: ?>
                        <?= htmlspecialchars($document['original_filename'] ?? '') ?>
                    <?php endif; ?>
                </td>

                <td>
                    <?= contractorDocumentStatus($status) ?>
                </td>

                <td>
                    <?= htmlspecialchars($document['created_at'] ?? '') ?>
                </td>

                <td>
                    <?= nl2br(htmlspecialchars($document['notes'] ?? '')) ?>
                </td>

                <td>
                    <form method="POST" style="margin-bottom:8px;">
                        <input type="hidden" name="action" value="update_document_status">
                        <input type="hidden" name="document_id" value="<?= $documentId ?>">
                        <input type="hidden" name="verification_status" value="1">
                        <textarea
                            name="notes"
                            rows="2"
                            placeholder="Optional admin notes"
                            style="width:100%;"
                        ><?= htmlspecialchars($document['notes'] ?? '') ?></textarea>
                        <br>
                        <button type="submit" style="color:#2e7d32;">
                            Approve
                        </button>
                    </form>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_document_status">
                        <input type="hidden" name="document_id" value="<?= $documentId ?>">
                        <input type="hidden" name="verification_status" value="2">
                        <input
                            type="text"
                            name="notes"
                            value="<?= htmlspecialchars($document['notes'] ?? '') ?>"
                            placeholder="Reason for denial"
                            style="width:100%;"
                        >
                        <br>
                        <button
                            type="submit"
                            style="color:#b00020;"
                            onclick="return confirm('Deny this contractor document?');"
                        >
                            Deny
                        </button>
                    </form>
                </td>
            </tr>

        <?php endforeach; ?>
    </table>

<?php endif; ?>

<?php include 'footer.php'; ?>
