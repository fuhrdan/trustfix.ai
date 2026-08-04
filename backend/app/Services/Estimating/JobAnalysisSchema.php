<?php

namespace App\Services\Estimating;

class JobAnalysisSchema
{
    public static function make(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'project_type' => [
                    'type' => 'string',
                    'description' => 'Short snake_case project classification.',
                ],
                'scope_summary' => [
                    'type' => 'string',
                    'description' => 'Plain-language summary of the understood scope. Do not include pricing.',
                ],
                'confidence' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high'],
                ],
                'follow_up_questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'question' => ['type' => 'string'],
                            'why_it_matters' => ['type' => 'string'],
                            'answer_type' => [
                                'type' => 'string',
                                'enum' => ['text', 'number', 'yes_no', 'choice'],
                            ],
                            'choices' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['key', 'question', 'why_it_matters', 'answer_type', 'choices'],
                    ],
                ],
                'steps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'hours_low' => ['type' => 'number', 'description' => 'Non-negative labor hours.'],
                            'hours_high' => ['type' => 'number', 'description' => 'Labor hours at least as large as hours_low.'],
                        ],
                        'required' => ['title', 'description', 'hours_low', 'hours_high'],
                    ],
                ],
                'materials' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'quantity_low' => ['type' => 'number', 'description' => 'Non-negative quantity.'],
                            'quantity_high' => ['type' => 'number', 'description' => 'Quantity at least as large as quantity_low.'],
                            'unit' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'quantity_low', 'quantity_high', 'unit', 'notes'],
                    ],
                ],
                'assumptions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'risk_flags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => [
                'project_type',
                'scope_summary',
                'confidence',
                'follow_up_questions',
                'steps',
                'materials',
                'assumptions',
                'risk_flags',
            ],
        ];
    }
}
