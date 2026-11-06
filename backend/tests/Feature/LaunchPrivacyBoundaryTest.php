<?php

namespace Tests\Feature;

use App\Models\ContractorProfile;
use App\Models\Job;
use App\Models\JobEstimate;
use App\Models\JobImage;
use App\Models\Property;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LaunchPrivacyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unassigned_approved_contractor_receives_only_a_safe_job_preview(): void
    {
        [$customer, $property, $job] = $this->openJob();
        $contractor = $this->user('Approved Contractor', 'approved@example.test', 'handyman');
        $this->profile($contractor, true);

        JobImage::create([
            'job_id' => $job->id,
            'image_path' => 'jobs/secret-kitchen.jpg',
        ]);
        JobEstimate::create([
            'job_id' => $job->id,
            'created_by_user_id' => $customer->id,
            'estimate_low' => 125,
            'estimate_high' => 225,
            'pricing_snapshot' => ['internal_margin' => 'SECRET-MARGIN'],
        ]);

        $token = Auth::guard('api')->login($contractor);
        $listResponse = $this->withToken($token)->getJson('/api/jobs/available');
        $listResponse->assertOk()
            ->assertJsonPath('data.0.id', $job->id)
            ->assertJsonPath('data.0.location', 'Denver, CO')
            ->assertJsonPath('data.0.image_count', 1)
            ->assertJsonPath('data.0.estimate.estimate_low', '125.00')
            ->assertJsonPath('data.0.is_preview', true);

        $this->assertSafePreview($listResponse->json('data.0'));
        $this->assertStringNotContainsString('SECRET-MARGIN', $listResponse->getContent());
        $this->assertStringNotContainsString('jobs/secret-kitchen.jpg', $listResponse->getContent());

        $detailResponse = $this->withToken($token)->getJson("/api/jobs/{$job->id}");
        $detailResponse->assertOk()
            ->assertJsonPath('location', 'Denver, CO')
            ->assertJsonPath('is_preview', true);
        $this->assertSafePreview($detailResponse->json());

        $this->withToken($token)->getJson("/api/jobs/{$job->id}/estimate")
            ->assertForbidden();
        $this->withToken($token)->getJson("/api/jobs/{$job->id}/estimate/revisions")
            ->assertForbidden();

        $unapproved = $this->user('Pending Contractor', 'pending@example.test', 'handyman');
        $this->profile($unapproved, false);
        $unapprovedToken = Auth::guard('api')->login($unapproved);
        $this->withToken($unapprovedToken)->getJson("/api/jobs/{$job->id}")
            ->assertForbidden();

        $ownerToken = Auth::guard('api')->login($customer);
        $this->withToken($ownerToken)->getJson("/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('address', '123 Private Street, Denver, CO 80202')
            ->assertJsonPath('customer.email', 'homeowner-secret@example.test')
            ->assertJsonPath('property.street_address', $property->street_address);
    }

    public function test_public_contractor_and_review_endpoints_use_explicit_safe_fields(): void
    {
        $customer = $this->user('Homeowner Secret', 'reviewer-secret@example.test', 'customer');
        $contractor = $this->user('Contractor Account Secret', 'contractor-secret@example.test', 'handyman');
        $profile = $this->profile($contractor, true);
        $property = Property::create([
            'owner_user_id' => $customer->id,
            'street_address' => '999 Review Secret Road',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);
        $job = Job::create([
            'customer_id' => $customer->id,
            'handyman_id' => $contractor->id,
            'property_id' => $property->id,
            'status' => 'completed',
            'address' => '999 Review Secret Road, Denver, CO 80202',
            'lat' => 39.7000000,
            'lng' => -104.9000000,
            'initial_description' => 'Completed private repair',
            'skills' => ['general'],
        ]);
        Review::create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'handyman_id' => $contractor->id,
            'contractor_profile_id' => $profile->id,
            'rating' => 5,
            'comment' => 'Excellent work.',
            'is_visible' => true,
            'admin_notes' => 'SECRET-MODERATION-NOTE',
        ]);

        $listResponse = $this->getJson('/api/contractors');
        $listResponse->assertOk()
            ->assertJsonPath('data.0.id', $profile->id)
            ->assertJsonPath('data.0.business_name', 'Safe Public Repairs');
        $this->assertSafePublicProfile($listResponse->json('data.0'));

        $detailResponse = $this->getJson("/api/contractors/{$profile->id}");
        $detailResponse->assertOk()
            ->assertJsonPath('visible_reviews.0.reviewer', 'Homeowner S.')
            ->assertJsonPath('visible_reviews.0.rating', 5);
        $this->assertSafePublicProfile($detailResponse->json());

        $reviewResponse = $this->getJson("/api/contractors/{$profile->id}/reviews");
        $reviewResponse->assertOk()
            ->assertJsonPath('data.0.reviewer', 'Homeowner S.');

        foreach ([$listResponse, $detailResponse, $reviewResponse] as $response) {
            $content = $response->getContent();
            $this->assertStringNotContainsString('reviewer-secret@example.test', $content);
            $this->assertStringNotContainsString('contractor-secret@example.test', $content);
            $this->assertStringNotContainsString('303-555-0199', $content);
            $this->assertStringNotContainsString('SECRET-STRIPE-ACCOUNT', $content);
            $this->assertStringNotContainsString('SECRET-MODERATION-NOTE', $content);
            $this->assertStringNotContainsString('999 Review Secret Road', $content);
        }
    }

    /** @return array{User, Property, Job} */
    private function openJob(): array
    {
        $customer = $this->user('Private Homeowner', 'homeowner-secret@example.test', 'customer');
        $property = Property::create([
            'owner_user_id' => $customer->id,
            'street_address' => '123 Private Street',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
            'description' => 'Alarm code is SECRET-1234',
        ]);
        $job = Job::create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
            'status' => 'posted',
            'address' => '123 Private Street, Denver, CO 80202',
            'lat' => 39.7000000,
            'lng' => -104.9000000,
            'initial_description' => 'Repair a leaking kitchen faucet.',
            'agreed_price' => 175,
            'onsite_contact_name' => 'Private Resident',
            'onsite_contact_phone' => '303-555-0101',
            'skills' => ['plumbing'],
        ]);

        return [$customer, $property, $job];
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('CorrectPassword123!'),
            'role' => $role,
            'phone' => '303-555-0199',
            'address' => '456 Account Secret Avenue',
            'lat' => 39.71,
            'lng' => -104.91,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function profile(User $user, bool $approved): ContractorProfile
    {
        return ContractorProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Safe Public Repairs',
            'business_address' => '789 Contractor Private Boulevard',
            'business_phone' => '303-555-0188',
            'bio' => 'General home repair services.',
            'service_area' => 'Denver metro',
            'website' => 'https://contractor.example.test',
            'license_number' => 'PUBLIC-LICENSE-42',
            'sales_tax_license' => 'SECRET-SALES-TAX',
            'coi_path' => 'documents/secret-insurance.pdf',
            'service_agreement' => 'SECRET-SERVICE-AGREEMENT',
            'status' => $approved ? 'approved' : 'pending',
            'is_public' => $approved,
            'is_verified' => $approved,
            'stripe_account_id' => 'SECRET-STRIPE-ACCOUNT-' . $user->id,
        ]);
    }

    /** @param array<string, mixed> $preview */
    private function assertSafePreview(array $preview): void
    {
        foreach ([
            'address',
            'lat',
            'lng',
            'customer_id',
            'handyman_id',
            'property_id',
            'onsite_contact_name',
            'onsite_contact_phone',
            'customer',
            'property',
            'images',
            'messages',
            'activities',
            'change_orders',
            'disputes',
            'reports',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $preview, "Preview exposed {$field}");
        }
    }

    /** @param array<string, mixed> $profile */
    private function assertSafePublicProfile(array $profile): void
    {
        foreach ([
            'user_id',
            'user',
            'business_address',
            'business_phone',
            'phone',
            'sales_tax_license',
            'coi_path',
            'surety_bond_path',
            'service_agreement',
            'background_check_status',
            'stripe_account_id',
            'stripe_details_submitted',
            'stripe_charges_enabled',
            'stripe_payouts_enabled',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $profile, "Public profile exposed {$field}");
        }
    }
}
