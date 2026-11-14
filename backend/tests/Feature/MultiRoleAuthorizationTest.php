<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleMiddleware;
use App\Models\ContractorProfile;
use App\Models\Job;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MultiRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_created_user_receives_a_role_assignment(): void
    {
        $user = $this->user('customer');

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role' => 'customer',
        ]);
        $this->assertSame(['customer'], $user->fresh()->roles);
    }

    public function test_secondary_role_does_not_replace_primary_role(): void
    {
        $user = $this->user('customer');

        $user->grantRole('handyman');

        $fresh = $user->fresh();
        $this->assertSame('customer', $fresh->role);
        $this->assertSame(['customer', 'handyman'], $fresh->roles);
        $this->assertTrue($fresh->hasRole('customer'));
        $this->assertTrue($fresh->hasRole('handyman'));
        $this->assertSame(1, User::withRole('handyman')->whereKey($user->id)->count());
    }

    public function test_role_middleware_accepts_an_additive_role(): void
    {
        $user = $this->user('customer');
        $user->grantRole('handyman');

        $request = Request::create('/role-check', 'GET');
        $request->setUserResolver(
            static fn ($guard = null): User => $user->fresh()
        );

        $response = (new RoleMiddleware)->handle(
            $request,
            static fn () => response()->json(['allowed' => true]),
            'handyman'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['allowed' => true], $response->getData(true));
    }

    public function test_role_middleware_rejects_an_unassigned_role(): void
    {
        $user = $this->user('customer');
        $request = Request::create('/role-check', 'GET');
        $request->setUserResolver(
            static fn ($guard = null): User => $user->fresh()
        );

        $response = (new RoleMiddleware)->handle(
            $request,
            static fn () => response()->json(['allowed' => true]),
            'admin'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_contractor_approval_adds_handyman_and_keeps_customer(): void
    {
        $contractor = $this->user('customer');
        $profile = ContractorProfile::create([
            'user_id' => $contractor->id,
            'business_name' => 'Multi Role Repair',
            'service_area' => 'Denver metro',
            'status' => 'pending',
            'is_public' => false,
            'is_verified' => false,
        ]);
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')
            ->postJson(
                "/api/admin/contractor-profiles/{$profile->id}/status",
                ['status' => 'approved', 'is_public' => false]
            )
            ->assertOk();

        $fresh = $contractor->fresh();
        $this->assertSame('handyman', $fresh->role);
        $this->assertSame(['customer', 'handyman'], $fresh->roles);
    }

    public function test_contractor_approval_keeps_admin_as_primary_role(): void
    {
        $contractorAdmin = $this->user('admin');
        $profile = ContractorProfile::create([
            'user_id' => $contractorAdmin->id,
            'business_name' => 'Administrator Repair',
            'service_area' => 'Denver metro',
            'status' => 'pending',
            'is_public' => false,
            'is_verified' => false,
        ]);
        $actingAdmin = $this->user('admin');

        $this->actingAs($actingAdmin, 'api')
            ->postJson(
                "/api/admin/contractor-profiles/{$profile->id}/status",
                ['status' => 'approved', 'is_public' => false]
            )
            ->assertOk();

        $fresh = $contractorAdmin->fresh();
        $this->assertSame('admin', $fresh->role);
        $this->assertSame(['handyman', 'admin'], $fresh->roles);
    }

    public function test_customer_and_contractor_roles_include_both_job_sides(): void
    {
        $dualRoleUser = $this->user('customer');
        $dualRoleUser->grantRole('handyman');
        $otherCustomer = $this->user('customer');
        $unrelatedCustomer = $this->user('customer');

        $ownedJob = $this->job($dualRoleUser, null, 'Owned homeowner job');
        $assignedJob = $this->job($otherCustomer, $dualRoleUser, 'Assigned contractor job');
        $this->job($unrelatedCustomer, null, 'Unrelated job');

        $token = (string) Auth::guard('api')->login($dualRoleUser->fresh());
        $response = $this->withToken($token)
            ->getJson('/api/jobs/my')
            ->assertOk();

        $ids = array_column($response->json(), 'id');
        sort($ids);
        $expected = [$ownedJob->id, $assignedJob->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_secondary_admin_role_authorizes_controller_checks(): void
    {
        $administrator = $this->user('customer');
        $administrator->grantRole('admin');
        $owner = $this->user('customer');
        $property = Property::create([
            'owner_user_id' => $owner->id,
            'street_address' => '123 Multi Role Street',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);

        $token = (string) Auth::guard('api')->login($administrator->fresh());

        $this->withToken($token)
            ->deleteJson("/api/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('properties', ['id' => $property->id]);
    }

    public function test_admin_stats_count_additive_roles(): void
    {
        $admin = $this->user('admin');
        $contractorCustomer = $this->user('customer');
        $contractorCustomer->grantRole('handyman');
        $secondaryAdmin = $this->user('customer');
        $secondaryAdmin->grantRole('admin');

        $this->actingAs($admin, 'api')
            ->getJson('/api/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('users.total', 3)
            ->assertJsonPath('users.customers', 2)
            ->assertJsonPath('users.handymen', 1)
            ->assertJsonPath('users.companies', 0)
            ->assertJsonPath('users.admins', 2);
    }

    public function test_admin_can_assign_multiple_roles_with_a_primary_role(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('customer');

        $this->actingAs($admin, 'api')
            ->putJson(
                "/api/admin/users/{$target->id}",
                $this->adminPayload(
                    $target,
                    'customer',
                    ['customer', 'handyman']
                )
            )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonPath('user.roles', ['customer', 'handyman']);

        $fresh = $target->fresh();
        $this->assertSame('customer', $fresh->role);
        $this->assertSame(['customer', 'handyman'], $fresh->roles);
    }

    public function test_legacy_admin_update_preserves_secondary_roles(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('customer');
        $target->grantRole('handyman');
        $payload = $this->adminPayload(
            $target,
            'handyman',
            ['customer', 'handyman']
        );
        unset($payload['roles']);

        $this->actingAs($admin, 'api')
            ->putJson("/api/admin/users/{$target->id}", $payload)
            ->assertOk()
            ->assertJsonPath('user.role', 'handyman')
            ->assertJsonPath('user.roles', ['customer', 'handyman']);

        $fresh = $target->fresh();
        $this->assertSame('handyman', $fresh->role);
        $this->assertSame(['customer', 'handyman'], $fresh->roles);
    }

    public function test_primary_role_must_be_in_explicit_assignments(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('customer');

        $this->actingAs($admin, 'api')
            ->putJson(
                "/api/admin/users/{$target->id}",
                $this->adminPayload($target, 'admin', ['customer'])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame(['customer'], $target->fresh()->roles);
    }

    public function test_last_secondary_admin_role_cannot_be_removed(): void
    {
        $soleAdmin = $this->user('customer');
        $soleAdmin->grantRole('admin');

        $this->actingAs($soleAdmin, 'api')
            ->putJson(
                "/api/admin/users/{$soleAdmin->id}",
                $this->adminPayload($soleAdmin, 'customer', ['customer'])
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'TrustFix must keep at least one administrator account.'
            );

        $this->assertTrue($soleAdmin->fresh()->hasRole('admin'));
    }

    public function test_last_verified_secondary_admin_role_cannot_be_removed(): void
    {
        $unverifiedAdmin = $this->user('admin');
        $unverifiedAdmin->forceFill(['email_verified_at' => null])->save();
        $verifiedAdmin = $this->user('customer');
        $verifiedAdmin->grantRole('admin');

        $this->actingAs($unverifiedAdmin, 'api')
            ->putJson(
                "/api/admin/users/{$verifiedAdmin->id}",
                $this->adminPayload($verifiedAdmin, 'customer', ['customer'])
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'TrustFix must keep at least one verified administrator account.'
            );

        $this->assertTrue($verifiedAdmin->fresh()->hasRole('admin'));
    }

    public function test_backend_authorization_consumers_use_additive_role_helpers(): void
    {
        $files = [
            'Http/Controllers/ContractorDashboardController.php',
            'Http/Controllers/AdminDashboardController.php',
            'Http/Controllers/JobController.php',
            'Http/Controllers/JobEstimateController.php',
            'Http/Controllers/JobWorkspaceController.php',
            'Http/Controllers/PaymentController.php',
            'Http/Controllers/PrivateFileController.php',
            'Http/Controllers/PropertyController.php',
            'Http/Controllers/SupportCaseController.php',
            'Services/Estimating/EstimatePricingResolver.php',
        ];

        foreach ($files as $file) {
            $source = file_get_contents(app_path($file));

            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression(
                '/->role\s*(?:===|!==|==|!=)|in_array\([^,\n]*->role|where\(\s*[\'\"]role[\'\"]/',
                $source,
                "{$file} still authorizes against only the primary role."
            );
        }
    }

    public function test_frontend_session_and_navigation_use_additive_roles(): void
    {
        $frontend = dirname(base_path()).'/frontend';
        $config = file_get_contents($frontend.'/config.php');
        $header = file_get_contents($frontend.'/header.php');
        $dashboard = file_get_contents($frontend.'/dashboard.php');

        $this->assertIsString($config);
        $this->assertStringContainsString('function userRoles($user)', $config);
        $this->assertStringContainsString('function userHasRole($user, $role)', $config);
        $this->assertStringContainsString('function userHasAnyRole($user, $roles)', $config);
        $this->assertStringContainsString('userHasAnyRole($user, $roles)', $config);

        $this->assertIsString($header);
        $this->assertStringContainsString('userHasAnyRole(', $header);
        $this->assertStringContainsString("userHasRole(\$sessionUser, 'admin')", $header);
        $this->assertStringNotContainsString("\$sessionRole === 'admin'", $header);

        $this->assertIsString($dashboard);
        $this->assertStringContainsString("userHasRole(\$user, 'customer')", $dashboard);
        $this->assertStringContainsString('userHasAnyRole(', $dashboard);
        $this->assertStringContainsString("userHasRole(\$user, 'admin')", $dashboard);
        $this->assertStringContainsString('if ($isCustomer)', $dashboard);
        $this->assertStringContainsString('if ($isContractor)', $dashboard);
    }

    public function test_admin_frontend_role_editors_submit_explicit_assignments(): void
    {
        $frontend = dirname(base_path()).'/frontend';
        $listUsers = file_get_contents($frontend.'/list_users.php');
        $editUser = file_get_contents($frontend.'/edit_user.php');
        $stylesheet = file_get_contents($frontend.'/TF-Style.css');
        $header = file_get_contents($frontend.'/header.php');
        $updateUserRole = file_get_contents($frontend.'/update_user_role.php');

        $this->assertIsString($listUsers);
        $this->assertStringContainsString('$assignedRoles = userRoles($user);', $listUsers);
        $this->assertStringContainsString('name="roles[]"', $listUsers);
        $this->assertStringContainsString('>Primary role</label>', $listUsers);
        $this->assertStringContainsString('Save Roles &amp; Verification', $listUsers);
        $this->assertStringContainsString('class="tf-role-overview"', $listUsers);
        $this->assertStringContainsString('class="tf-role-badges"', $listUsers);
        $this->assertStringContainsString('class="tf-role-editor"', $listUsers);
        $this->assertStringContainsString(
            'Manage roles &amp; verification',
            $listUsers
        );

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString(
            '.tf-admin-users-table td[data-label="Roles"]',
            $stylesheet
        );
        $this->assertStringContainsString('.tf-role-assignments', $stylesheet);
        $this->assertStringContainsString('.tf-role-overview', $stylesheet);
        $this->assertStringContainsString('.tf-role-badge', $stylesheet);
        $this->assertStringContainsString('.tf-role-editor', $stylesheet);
        $this->assertStringContainsString(
            'grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));',
            $stylesheet
        );

        $this->assertIsString($header);
        $this->assertStringContainsString(
            'TF-Style.css?v=20261114-admin-role-view',
            $header
        );

        $this->assertIsString($editUser);
        $this->assertStringContainsString("'roles' => is_array(\$submittedRoles)", $editUser);
        $this->assertStringContainsString('name="roles[]"', $editUser);
        $this->assertStringContainsString('>Assigned roles</legend>', $editUser);
        $this->assertStringContainsString('>Primary role</label>', $editUser);

        $this->assertIsString($updateUserRole);
        $this->assertStringContainsString("\$submittedRoles = \$_POST['roles'] ?? [];", $updateUserRole);
        $this->assertStringContainsString('|| !$rolesAreValid', $updateUserRole);
        $this->assertStringContainsString('|| !in_array($role, $roles, true)', $updateUserRole);
        $this->assertStringContainsString("'roles' => \$roles", $updateUserRole);
    }

    public function test_dual_role_contractor_dashboard_defaults_to_personal_context(): void
    {
        $dualRoleUser = $this->user('customer');
        $dualRoleUser->grantRole('handyman');
        $dualRoleUser->grantRole('admin');

        $this->actingAs($dualRoleUser, 'api')
            ->getJson('/api/contractor/dashboard')
            ->assertForbidden()
            ->assertJsonPath('error', 'Contractor approval is required.');

        $administrator = $this->user('admin');

        $this->actingAs($administrator, 'api')
            ->getJson('/api/contractor/dashboard')
            ->assertForbidden()
            ->assertJsonPath('error', 'Select an approved contractor.');
    }

    public function test_dual_role_admin_can_choose_contractor_and_global_pricing(): void
    {
        $dualRoleUser = $this->user('customer');
        $dualRoleUser->grantRole('handyman');
        $dualRoleUser->grantRole('admin');

        $this->actingAs($dualRoleUser, 'api')
            ->putJson(
                '/api/estimate-pricing-profile?scope=contractor',
                $this->pricingPayload('My contractor pricing')
            )
            ->assertOk();

        $this->actingAs($dualRoleUser, 'api')
            ->getJson('/api/estimate-pricing-profile?scope=contractor')
            ->assertOk()
            ->assertJsonPath('source', 'contractor')
            ->assertJsonPath('name', 'My contractor pricing');

        $this->actingAs($dualRoleUser, 'api')
            ->putJson(
                '/api/estimate-pricing-profile?scope=global',
                $this->pricingPayload('TrustFix global pricing')
            )
            ->assertOk();

        $this->actingAs($dualRoleUser, 'api')
            ->getJson('/api/estimate-pricing-profile?scope=global')
            ->assertOk()
            ->assertJsonPath('source', 'trustfix')
            ->assertJsonPath('name', 'TrustFix global pricing');
    }

    public function test_contractor_cannot_edit_global_pricing_scope(): void
    {
        $contractor = $this->user('customer');
        $contractor->grantRole('handyman');

        $this->actingAs($contractor, 'api')
            ->putJson(
                '/api/estimate-pricing-profile?scope=global',
                $this->pricingPayload('Unauthorized global pricing')
            )
            ->assertForbidden();
    }

    public function test_remaining_role_consumers_use_additive_capabilities(): void
    {
        $frontend = dirname(base_path()).'/frontend';
        $contractorDashboard = file_get_contents($frontend.'/contractor_dashboard.php');
        $estimateSettings = file_get_contents($frontend.'/estimate_settings.php');
        $jobDetail = file_get_contents($frontend.'/job_detail.php');
        $myJobs = file_get_contents($frontend.'/my_jobs.php');
        $pricingController = file_get_contents(
            app_path('Http/Controllers/EstimatePricingProfileController.php')
        );

        $this->assertIsString($contractorDashboard);
        $this->assertStringContainsString(
            "userHasRole(\$currentUser, 'admin')",
            $contractorDashboard
        );
        $this->assertStringContainsString('$viewingAsAdmin', $contractorDashboard);
        $this->assertStringContainsString('My contractor dashboard', $contractorDashboard);

        $this->assertIsString($estimateSettings);
        $this->assertStringContainsString("userHasRole(\$user, 'admin')", $estimateSettings);
        $this->assertStringContainsString("'scope' => \$scope", $estimateSettings);
        $this->assertStringContainsString('My contractor pricing', $estimateSettings);

        $this->assertIsString($jobDetail);
        $this->assertStringContainsString('userHasAnyRole(', $jobDetail);
        $this->assertStringNotContainsString("\$currentUser['role']", $jobDetail);

        $this->assertIsString($myJobs);
        $this->assertStringContainsString("userHasRole(\$user, 'customer')", $myJobs);
        $this->assertStringContainsString('if ($isCustomerRole)', $myJobs);
        $this->assertStringContainsString('if ($isContractorRole)', $myJobs);

        $this->assertIsString($pricingController);
        $this->assertStringContainsString('pricingScope(', $pricingController);
        $this->assertStringNotContainsString('$user?->role', $pricingController);
    }

    public function test_multi_role_architecture_is_documented_for_human_reviewers(): void
    {
        $documentation = file_get_contents(
            dirname(base_path()).'/docs/multi-role-authorization.md'
        );

        $this->assertIsString($documentation);
        $this->assertStringContainsString(
            '`users` is the canonical identity table',
            $documentation
        );
        $this->assertStringContainsString(
            '`user_roles` stores additive authorization capabilities',
            $documentation
        );
        $this->assertStringContainsString(
            'Do not authorize against `users.role` directly',
            $documentation
        );
        $this->assertStringContainsString(
            'Application rollback does not require dropping `user_roles`',
            $documentation
        );
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => 'Multi Role User',
            'email' => uniqid('multi-role-', true).'@example.test',
            'password' => Hash::make('CorrectPassword123!'),
            'role' => $role,
            'account_status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function adminPayload(
        User $target,
        string $primaryRole,
        array $roles
    ): array {
        return [
            'name' => $target->name,
            'email' => $target->email,
            'phone' => $target->phone,
            'address' => $target->address,
            'role' => $primaryRole,
            'roles' => $roles,
            'email_verified' => $target->hasVerifiedEmail(),
        ];
    }

    private function pricingPayload(string $name): array
    {
        return [
            'name' => $name,
            'hourly_wage' => 45,
            'payroll_burden_percent' => 18,
            'insurance_percent' => 5,
            'tools_percent' => 4,
            'material_markup_percent' => 15,
            'travel_flat_fee' => 25,
            'overhead_percent' => 12,
            'profit_percent' => 10,
            'uncertainty_percent' => 8,
        ];
    }

    private function job(User $customer, ?User $contractor, string $description): Job
    {
        return Job::create([
            'customer_id' => $customer->id,
            'handyman_id' => $contractor?->id,
            'status' => $contractor ? 'accepted' : 'posted',
            'address' => 'Denver, CO',
            'lat' => 39.7000000,
            'lng' => -104.9000000,
            'initial_description' => $description,
            'skills' => ['general'],
        ]);
    }
}
