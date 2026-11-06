<?php

$relation = static function (
    string $table,
    string $column,
    string $referencedTable,
    string $onDelete,
    bool $optional = false,
    string $referencedColumn = 'id'
): array {
    return [
        'table' => $table,
        'column' => $column,
        'referenced_table' => $referencedTable,
        'referenced_column' => $referencedColumn,
        'on_delete' => strtoupper($onDelete),
        'optional' => $optional,
    ];
};

return [
    /*
    |--------------------------------------------------------------------------
    | Expected relational constraints
    |--------------------------------------------------------------------------
    |
    | Shared-hosting MySQL/MariaDB installations sometimes default to MyISAM.
    | MyISAM silently omits Laravel foreign keys, so this explicit manifest is
    | used by the database-hardening command to audit and restore them safely.
    |
    */
    'relations' => [
        $relation('users', 'company_id', 'users', 'SET NULL'),
        $relation('users', 'suspended_by', 'users', 'SET NULL'),

        $relation('handyman_skills', 'handyman_id', 'users', 'CASCADE'),
        $relation('handyman_skills', 'skill_id', 'skills', 'CASCADE'),

        $relation('jobs', 'customer_id', 'users', 'CASCADE'),
        $relation('jobs', 'handyman_id', 'users', 'SET NULL'),
        $relation('jobs', 'property_id', 'properties', 'SET NULL'),
        $relation('change_orders', 'job_id', 'jobs', 'CASCADE'),
        $relation('job_images', 'job_id', 'jobs', 'CASCADE'),
        $relation('job_messages', 'job_id', 'jobs', 'CASCADE'),
        $relation('job_messages', 'sender_user_id', 'users', 'SET NULL'),
        $relation('job_activities', 'job_id', 'jobs', 'CASCADE'),
        $relation('job_activities', 'user_id', 'users', 'SET NULL'),

        $relation('documents', 'handyman_id', 'users', 'CASCADE'),
        $relation('documents', 'reviewed_by', 'users', 'SET NULL'),

        $relation('contractor_profiles', 'user_id', 'users', 'CASCADE'),
        // This column exists only on installations created by the later
        // compatibility migration; older installations use is_verified.
        $relation('contractor_profiles', 'verified_by', 'users', 'SET NULL', true),
        $relation('contractor_documents', 'user_id', 'users', 'CASCADE'),
        $relation('contractor_documents', 'verified_by', 'users', 'SET NULL'),
        $relation('contractor_employees', 'contractor_profile_id', 'contractor_profiles', 'CASCADE'),
        $relation('contractor_employees', 'user_id', 'users', 'SET NULL'),

        $relation('profile_claims', 'contractor_profile_id', 'contractor_profiles', 'CASCADE'),
        $relation('profile_claims', 'claimant_user_id', 'users', 'CASCADE'),
        $relation('profile_claims', 'reviewed_by', 'users', 'SET NULL'),

        $relation('contractor_badges', 'contractor_profile_id', 'contractor_profiles', 'CASCADE'),
        $relation('contractor_badges', 'badge_id', 'badges', 'CASCADE'),
        $relation('contractor_badges', 'assigned_by', 'users', 'SET NULL'),

        $relation('reviews', 'job_id', 'jobs', 'CASCADE'),
        $relation('reviews', 'customer_id', 'users', 'CASCADE'),
        $relation('reviews', 'handyman_id', 'users', 'CASCADE'),
        $relation('reviews', 'contractor_profile_id', 'contractor_profiles', 'CASCADE'),
        $relation('reviews', 'moderated_by', 'users', 'SET NULL'),

        $relation('reports', 'reporter_id', 'users', 'CASCADE'),
        $relation('reports', 'reported_user_id', 'users', 'SET NULL'),
        $relation('reports', 'contractor_profile_id', 'contractor_profiles', 'SET NULL'),
        $relation('reports', 'job_id', 'jobs', 'SET NULL'),
        $relation('reports', 'reviewed_by', 'users', 'SET NULL'),

        $relation('disputes', 'job_id', 'jobs', 'CASCADE'),
        $relation('disputes', 'opened_by', 'users', 'CASCADE'),
        $relation('disputes', 'resolved_by', 'users', 'SET NULL'),

        $relation('properties', 'owner_user_id', 'users', 'CASCADE'),
        $relation('property_users', 'property_id', 'properties', 'CASCADE'),
        $relation('property_users', 'user_id', 'users', 'CASCADE'),
        $relation('property_images', 'property_id', 'properties', 'CASCADE'),

        $relation('payments', 'job_id', 'jobs', 'CASCADE'),
        $relation('payments', 'customer_id', 'users', 'CASCADE'),
        $relation('payments', 'contractor_id', 'users', 'CASCADE'),

        $relation('estimate_pricing_profiles', 'user_id', 'users', 'CASCADE'),
        $relation('job_estimates', 'job_id', 'jobs', 'CASCADE'),
        $relation('job_estimates', 'created_by_user_id', 'users', 'SET NULL'),
        $relation('job_estimates', 'reviewed_by_user_id', 'users', 'SET NULL'),
        $relation('job_estimate_revisions', 'job_estimate_id', 'job_estimates', 'CASCADE'),
        $relation('job_estimate_revisions', 'user_id', 'users', 'SET NULL'),

        $relation('admin_audit_logs', 'admin_user_id', 'users', 'SET NULL'),
        $relation('support_cases', 'user_id', 'users', 'SET NULL'),
        $relation('support_cases', 'job_id', 'jobs', 'SET NULL'),
        $relation('support_cases', 'assigned_admin_id', 'users', 'SET NULL'),
        $relation('login_attempts', 'user_id', 'users', 'SET NULL'),
        $relation('blocked_ips', 'blocked_by', 'users', 'SET NULL'),
        $relation('blocked_ips', 'unblocked_by', 'users', 'SET NULL'),

        $relation('oidc_identities', 'user_id', 'users', 'CASCADE'),
        $relation('oidc_flow_tokens', 'user_id', 'users', 'SET NULL'),

        $relation('partner_api_clients', 'created_by', 'users', 'SET NULL'),
        $relation('partner_member_accounts', 'partner_api_client_id', 'partner_api_clients', 'CASCADE'),
        $relation('partner_member_accounts', 'user_id', 'users', 'CASCADE'),
        $relation('partner_service_requests', 'partner_api_client_id', 'partner_api_clients', 'CASCADE'),
        $relation('partner_service_requests', 'partner_member_account_id', 'partner_member_accounts', 'CASCADE'),
        $relation('partner_service_requests', 'job_id', 'jobs', 'CASCADE'),
        $relation('contractor_invitations', 'invited_by', 'users', 'SET NULL'),
        $relation('contractor_invitations', 'accepted_user_id', 'users', 'SET NULL'),
    ],
];
