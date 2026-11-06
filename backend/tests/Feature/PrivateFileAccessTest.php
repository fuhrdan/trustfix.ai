<?php

namespace Tests\Feature;

use App\Models\ContractorDocument;
use App\Models\ContractorProfile;
use App\Models\Document;
use App\Models\Job;
use App\Models\JobImage;
use App\Models\ProfileClaim;
use App\Models\Property;
use App\Models\PropertyImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateFileAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');
    }

    public function test_job_and_property_files_require_relationship_access(): void
    {
        $owner = $this->user('Owner', 'file-owner@example.test', 'customer');
        $assigned = $this->user('Assigned Contractor', 'assigned@example.test', 'handyman');
        $unassigned = $this->user('Unassigned Contractor', 'unassigned@example.test', 'handyman');
        $shared = $this->user('Shared Customer', 'shared@example.test', 'customer');
        $other = $this->user('Other Customer', 'other@example.test', 'customer');
        $admin = $this->user('Administrator', 'admin-files@example.test', 'admin');

        ContractorProfile::create([
            'user_id' => $unassigned->id,
            'business_name' => 'Unassigned Repairs',
            'status' => 'approved',
            'is_public' => true,
        ]);

        $property = Property::create([
            'owner_user_id' => $owner->id,
            'street_address' => '10 Private File Lane',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);
        $property->users()->attach($shared->id);
        $job = Job::create([
            'customer_id' => $owner->id,
            'handyman_id' => $assigned->id,
            'property_id' => $property->id,
            'status' => 'accepted',
            'address' => '10 Private File Lane, Denver, CO 80202',
            'lat' => 39.7,
            'lng' => -104.9,
            'initial_description' => 'Private image access test',
            'skills' => [],
        ]);
        $jobImage = JobImage::create([
            'job_id' => $job->id,
            'image_path' => 'jobs/1/private.png',
        ]);
        $propertyImage = PropertyImage::create([
            'property_id' => $property->id,
            'image_path' => 'properties/1/private.png',
        ]);
        Storage::disk('private')->put($jobImage->image_path, $this->png());
        Storage::disk('private')->put($propertyImage->image_path, $this->png());

        $this->getJson("/api/private-files/job-images/{$jobImage->id}")
            ->assertUnauthorized();

        foreach ([$owner, $assigned, $admin] as $allowed) {
            $token = Auth::guard('api')->login($allowed);
            $this->withToken($token)
                ->get("/api/private-files/job-images/{$jobImage->id}")
                ->assertOk();
        }

        foreach ([$unassigned, $other] as $denied) {
            $token = Auth::guard('api')->login($denied);
            $this->withToken($token)
                ->get("/api/private-files/job-images/{$jobImage->id}")
                ->assertForbidden();
        }

        foreach ([$owner, $shared, $admin] as $allowed) {
            $token = Auth::guard('api')->login($allowed);
            $this->withToken($token)
                ->get("/api/private-files/property-images/{$propertyImage->id}")
                ->assertOk();
        }

        $otherToken = Auth::guard('api')->login($other);
        $this->withToken($otherToken)
            ->get("/api/private-files/property-images/{$propertyImage->id}")
            ->assertForbidden();
    }

    public function test_contractor_documents_are_private_and_owner_or_admin_only(): void
    {
        $contractor = $this->user('Document Owner', 'document-owner@example.test', 'handyman');
        $other = $this->user('Other Contractor', 'other-contractor@example.test', 'handyman');
        $admin = $this->user('Document Admin', 'document-admin@example.test', 'admin');
        $token = Auth::guard('api')->login($contractor);

        $response = $this->withToken($token)->post(
            '/api/contractor/documents',
            [
                'type' => 'state_license',
                'file' => UploadedFile::fake()->create(
                    'state-license.pdf',
                    12,
                    'application/pdf'
                ),
            ]
        );
        $response->assertCreated();

        $document = ContractorDocument::firstOrFail();
        Storage::disk('private')->assertExists($document->stored_filename);
        Storage::disk('public')->assertMissing($document->stored_filename);

        $this->withToken($token)
            ->get("/api/private-files/contractor-documents/{$document->id}")
            ->assertOk()
            ->assertDownload('state-license.pdf');

        $otherToken = Auth::guard('api')->login($other);
        $this->withToken($otherToken)
            ->get("/api/private-files/contractor-documents/{$document->id}")
            ->assertForbidden();

        $adminToken = Auth::guard('api')->login($admin);
        $this->withToken($adminToken)
            ->get("/api/private-files/contractor-documents/{$document->id}")
            ->assertOk();
    }

    public function test_profile_claim_proof_is_private_and_claimant_or_admin_only(): void
    {
        $claimant = $this->user('Claimant', 'claimant@example.test', 'handyman');
        $targetOwner = $this->user('Target Owner', 'target-owner@example.test', 'handyman');
        $other = $this->user('Other Claimant', 'other-claimant@example.test', 'handyman');
        $admin = $this->user('Claim Admin', 'claim-admin@example.test', 'admin');
        $target = ContractorProfile::create([
            'user_id' => $targetOwner->id,
            'business_name' => 'Claim Target Repairs',
            'status' => 'approved',
            'is_public' => true,
        ]);
        $token = Auth::guard('api')->login($claimant);

        $this->withToken($token)->post(
            "/api/contractors/{$target->id}/claim",
            [
                'business_email' => 'claimant@example.test',
                'proof_document' => UploadedFile::fake()->create(
                    'ownership-proof.pdf',
                    8,
                    'application/pdf'
                ),
            ]
        )->assertCreated();

        $claim = ProfileClaim::firstOrFail();
        Storage::disk('private')->assertExists($claim->proof_document_path);
        Storage::disk('public')->assertMissing($claim->proof_document_path);

        $this->withToken($token)
            ->get("/api/private-files/profile-claim-documents/{$claim->id}")
            ->assertOk();

        $otherToken = Auth::guard('api')->login($other);
        $this->withToken($otherToken)
            ->get("/api/private-files/profile-claim-documents/{$claim->id}")
            ->assertForbidden();

        $adminToken = Auth::guard('api')->login($admin);
        $this->withToken($adminToken)
            ->get("/api/private-files/profile-claim-documents/{$claim->id}")
            ->assertOk();
    }

    public function test_legacy_public_files_work_during_migration_and_command_moves_them(): void
    {
        $owner = $this->user('Legacy Owner', 'legacy-owner@example.test', 'customer');
        $property = Property::create([
            'owner_user_id' => $owner->id,
            'street_address' => '11 Legacy Lane',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);
        $job = Job::create([
            'customer_id' => $owner->id,
            'property_id' => $property->id,
            'status' => 'posted',
            'address' => '11 Legacy Lane, Denver, CO 80202',
            'lat' => 39.7,
            'lng' => -104.9,
            'initial_description' => 'Legacy public image',
            'skills' => [],
        ]);
        $image = JobImage::create([
            'job_id' => $job->id,
            'image_path' => 'jobs/legacy/image.png',
        ]);
        Storage::disk('public')->put($image->image_path, $this->png());
        $document = Document::create([
            'handyman_id' => $owner->id,
            'file_path' => 'legacy-documents/license.pdf',
            'type' => 'license',
            'status' => 'pending',
            'verified' => false,
        ]);
        Storage::disk('public')->put($document->file_path, '%PDF-1.4 legacy test');

        $token = Auth::guard('api')->login($owner);
        $this->withToken($token)
            ->get("/api/private-files/job-images/{$image->id}")
            ->assertOk();

        $this->assertSame(0, Artisan::call('trustfix:migrate-private-uploads'));
        Storage::disk('private')->assertMissing($image->image_path);
        Storage::disk('public')->assertExists($image->image_path);
        Storage::disk('private')->assertMissing($document->file_path);
        Storage::disk('public')->assertExists($document->file_path);

        $this->assertSame(0, Artisan::call(
            'trustfix:migrate-private-uploads',
            ['--execute' => true, '--delete-public' => true]
        ));
        Storage::disk('private')->assertExists($image->image_path);
        Storage::disk('public')->assertMissing($image->image_path);
        Storage::disk('private')->assertExists($document->file_path);
        Storage::disk('public')->assertMissing($document->file_path);
    }

    public function test_migration_never_deletes_a_mismatched_public_copy(): void
    {
        $owner = $this->user('Mismatch Owner', 'mismatch-owner@example.test', 'customer');
        $job = Job::create([
            'customer_id' => $owner->id,
            'status' => 'posted',
            'address' => '12 Mismatch Lane, Denver, CO 80202',
            'lat' => 39.7,
            'lng' => -104.9,
            'initial_description' => 'Checksum safety test',
            'skills' => [],
        ]);
        $image = JobImage::create([
            'job_id' => $job->id,
            'image_path' => 'jobs/mismatch/image.png',
        ]);
        Storage::disk('private')->put($image->image_path, $this->png());
        Storage::disk('public')->put($image->image_path, 'different contents');

        $this->assertSame(1, Artisan::call(
            'trustfix:migrate-private-uploads',
            ['--execute' => true, '--delete-public' => true]
        ));

        Storage::disk('private')->assertExists($image->image_path);
        Storage::disk('public')->assertExists($image->image_path);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('CorrectPassword123!'),
            'role' => $role,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function png(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL6gAAAAABJRU5ErkJggg==',
            true
        );
    }
}
