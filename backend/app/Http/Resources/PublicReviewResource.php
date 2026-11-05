<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'reviewer' => $this->reviewerName(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function reviewerName(): string
    {
        $name = trim((string) ($this->customer?->name ?? ''));
        if ($name === '') {
            return 'Verified customer';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $first = array_shift($parts) ?: 'Verified customer';
        $last = array_pop($parts);

        return $last
            ? $first . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.'
            : $first;
    }
}
