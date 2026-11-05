<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicContractorProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $badges = $this->relationLoaded('badges')
            ? $this->badges->map(static fn ($badge) => [
                'id' => $badge->id,
                'name' => $badge->name,
                'slug' => $badge->slug,
                'description' => $badge->description,
            ])->values()->all()
            : [];

        $reviews = $this->relationLoaded('visibleReviews')
            ? $this->visibleReviews->map(
                fn ($review) => (new PublicReviewResource($review))->resolve($request)
            )->values()->all()
            : [];

        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'bio' => $this->bio,
            'service_area' => $this->service_area,
            'emergency_availability' => (bool) $this->emergency_availability,
            'website' => $this->website,
            'year_established' => $this->year_established,
            'years_experience' => $this->years_experience,
            'business_type' => $this->business_type,
            'license_number' => $this->license_number,
            'state_license' => $this->state_license,
            'local_license' => $this->local_license,
            'license_expiration_date' => $this->license_expiration_date?->toDateString(),
            'is_verified' => (bool) $this->is_verified,
            'profile_photo_path' => $this->profile_photo_path,
            'average_rating' => $this->average_rating === null
                ? null
                : (float) $this->average_rating,
            'review_count' => (int) ($this->review_count ?? 0),
            'badges' => $badges,
            'visible_reviews' => $reviews,
        ];
    }
}
