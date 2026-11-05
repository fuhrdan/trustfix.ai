<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageUploadFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_and_job_images_are_persisted_and_returned_to_the_owner(): void
    {
        Storage::fake('public');

        $user = User::create([
            'name' => 'Image Test Customer',
            'email' => 'images@example.test',
            'password' => Hash::make('CorrectPassword123!'),
            'role' => 'customer',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $property = Property::create([
            'owner_user_id' => $user->id,
            'street_address' => '10 Image Lane',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);
        $job = Job::create([
            'customer_id' => $user->id,
            'property_id' => $property->id,
            'status' => 'posted',
            'address' => '10 Image Lane, Denver, CO 80202',
            'lat' => 0,
            'lng' => 0,
            'initial_description' => 'Image upload test',
            'skills' => [],
        ]);
        $token = Auth::guard('api')->login($user);

        $propertyResponse = $this->withToken($token)->post(
            "/api/properties/{$property->id}/images",
            ['images' => [$this->imageFile('property.png')]]
        );
        $propertyResponse->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('property_images', ['property_id' => $property->id]);

        $jobResponse = $this->withToken($token)->post(
            "/api/jobs/{$job->id}/images",
            ['images' => [$this->imageFile('job.png')]]
        );
        $jobResponse->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('job_images', ['job_id' => $job->id]);

        $propertyImage = $property->images()->firstOrFail();
        $jobImage = $job->images()->firstOrFail();
        Storage::disk('public')->assertExists($propertyImage->image_path);
        Storage::disk('public')->assertExists($jobImage->image_path);

        $this->withToken($token)->getJson("/api/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('images.0.id', $propertyImage->id);
        $this->withToken($token)->getJson("/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('images.0.id', $jobImage->id);
    }

    public function test_image_routes_reject_an_empty_upload_instead_of_reporting_false_success(): void
    {
        $user = User::create([
            'name' => 'Empty Image Customer',
            'email' => 'empty-images@example.test',
            'password' => Hash::make('CorrectPassword123!'),
            'role' => 'customer',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $property = Property::create([
            'owner_user_id' => $user->id,
            'street_address' => '11 Image Lane',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);
        $token = Auth::guard('api')->login($user);

        $this->withToken($token)
            ->postJson("/api/properties/{$property->id}/images", [])
            ->assertUnprocessable();
    }

    private function imageFile(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL6gAAAAABJRU5ErkJggg==', true)
        );
    }
}
