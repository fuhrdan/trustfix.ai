<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvailableJobResource extends JsonResource
{
    /**
     * Return only the information an approved, unassigned contractor needs
     * to decide whether to accept a job.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $location = 'Location shared after acceptance';

        if ($this->relationLoaded('property') && $this->property) {
            $parts = array_values(array_filter([
                trim((string) $this->property->city),
                trim((string) $this->property->state),
            ]));

            if ($parts) {
                $location = implode(', ', $parts);
            }
        }

        $estimate = null;
        if ($this->relationLoaded('estimate') && $this->estimate) {
            $estimate = [
                'estimate_low' => $this->estimate->estimate_low,
                'estimate_high' => $this->estimate->estimate_high,
            ];
        }

        return [
            'id' => $this->id,
            'status' => $this->status,
            'initial_description' => $this->initial_description,
            'agreed_price' => $this->agreed_price,
            'skills' => $this->skills ?? [],
            'location' => $location,
            'image_count' => (int) ($this->images_count ?? 0),
            'estimate' => $estimate,
            'is_preview' => true,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
