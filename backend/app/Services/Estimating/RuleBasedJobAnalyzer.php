<?php

namespace App\Services\Estimating;

use App\Models\Job;

class RuleBasedJobAnalyzer
{
    public function analyze(Job $job, array $answers = []): array
    {
        $description = trim((string) $job->initial_description);
        $haystack = strtolower($description . ' ' . implode(' ', $job->skills ?? []));
        $rule = $this->matchingRule($haystack);
        $questions = $rule['questions'];

        if ($description === '' || strlen($description) < 80) {
            array_unshift($questions, [
                'key' => 'scope_details',
                'question' => 'Please describe the size, location, current condition, and desired finished result.',
                'why_it_matters' => 'A short description can hide steps that materially change labor time.',
                'answer_type' => 'text',
                'choices' => [],
            ]);
        }

        if ($job->images()->count() === 0) {
            $questions[] = [
                'key' => 'photos_available',
                'question' => 'Can you add clear photos of the work area and any visible damage?',
                'why_it_matters' => 'Photos help identify access issues, damage extent, and likely materials.',
                'answer_type' => 'yes_no',
                'choices' => ['Yes', 'No'],
            ];
        }

        $questions = array_values(array_filter($questions, function (array $question) use ($answers) {
            $answer = trim((string) ($answers[$question['key']] ?? ''));
            return $answer === '';
        }));

        $answeredCount = count(array_filter($answers, fn ($answer) => trim((string) $answer) !== ''));
        $photoCount = $job->images()->count();
        $confidence = $photoCount > 0 && strlen($description) >= 80 && $answeredCount > 0
            ? 'medium'
            : 'low';

        $risks = $rule['risks'];
        if ($photoCount === 0) {
            $risks[] = 'No job photos were available for this analysis.';
        }
        if ($description === '' || strlen($description) < 80) {
            $risks[] = 'The job description is too short for a high-confidence time estimate.';
        }

        return [
            'project_type' => $rule['project_type'],
            'scope_summary' => $description !== ''
                ? $description
                : 'The project scope still needs to be described.',
            'confidence' => $confidence,
            'follow_up_questions' => $questions,
            'steps' => $rule['steps'],
            'materials' => $rule['materials'],
            'assumptions' => array_merge($rule['assumptions'], [
                'Work is performed during normal business hours with safe, unobstructed access.',
                'Permit, specialty engineering, hazardous-material, and concealed-damage work is excluded unless listed.',
            ]),
            'risk_flags' => array_values(array_unique($risks)),
        ];
    }

    private function matchingRule(string $text): array
    {
        $rules = $this->rules();

        foreach ($rules as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $rule;
                }
            }
        }

        return $rules['general'];
    }

    private function rules(): array
    {
        return [
            'plumbing' => [
                'keywords' => ['plumb', 'faucet', 'toilet', 'sink', 'pipe', 'leak', 'drain', 'water heater'],
                'project_type' => 'plumbing_repair',
                'steps' => [
                    ['title' => 'Diagnose and isolate', 'description' => 'Confirm the source, protect the area, and isolate water as needed.', 'hours_low' => 0.5, 'hours_high' => 1.5],
                    ['title' => 'Repair or replace', 'description' => 'Complete the described plumbing repair using accessible components.', 'hours_low' => 1.0, 'hours_high' => 4.0],
                    ['title' => 'Test and clean up', 'description' => 'Restore service, test for leaks and proper operation, and clean the area.', 'hours_low' => 0.5, 'hours_high' => 1.0],
                ],
                'materials' => [
                    ['name' => 'Plumbing fittings', 'quantity_low' => 1, 'quantity_high' => 4, 'unit' => 'each', 'notes' => 'Exact type and size must be confirmed on site.'],
                    ['name' => 'Thread seal tape', 'quantity_low' => 1, 'quantity_high' => 1, 'unit' => 'roll', 'notes' => 'Use only where appropriate for the connection type.'],
                ],
                'questions' => [
                    ['key' => 'water_shutoff', 'question' => 'Can the affected fixture or water line be shut off?', 'why_it_matters' => 'Shutoff access changes preparation time and risk.', 'answer_type' => 'yes_no', 'choices' => ['Yes', 'No', 'Not sure']],
                    ['key' => 'active_leak', 'question' => 'Is water actively leaking now?', 'why_it_matters' => 'An active leak may require urgent containment and hidden-damage inspection.', 'answer_type' => 'yes_no', 'choices' => ['Yes', 'No']],
                ],
                'assumptions' => ['Supply and drain connections are accessible without opening finished walls or floors.'],
                'risks' => ['Concealed water damage or corroded piping may expand the scope.'],
            ],
            'electrical' => [
                'keywords' => ['electric', 'outlet', 'switch', 'breaker', 'light fixture', 'wiring', 'ceiling fan'],
                'project_type' => 'electrical_repair',
                'steps' => [
                    ['title' => 'Make safe and diagnose', 'description' => 'De-energize the circuit and identify the fault or installation requirements.', 'hours_low' => 0.5, 'hours_high' => 1.5],
                    ['title' => 'Repair or install', 'description' => 'Repair or replace accessible devices and wiring within the stated scope.', 'hours_low' => 1.0, 'hours_high' => 4.0],
                    ['title' => 'Test', 'description' => 'Restore power and verify safe operation.', 'hours_low' => 0.5, 'hours_high' => 1.0],
                ],
                'materials' => [
                    ['name' => 'Electrical device', 'quantity_low' => 1, 'quantity_high' => 2, 'unit' => 'each', 'notes' => 'Device rating and style must match the circuit and customer selection.'],
                    ['name' => 'Electrical connectors', 'quantity_low' => 2, 'quantity_high' => 8, 'unit' => 'each', 'notes' => 'Quantity varies with the existing wiring.'],
                ],
                'questions' => [
                    ['key' => 'circuit_behavior', 'question' => 'What happens now: no power, intermittent power, tripped breaker, heat, odor, or sparks?', 'why_it_matters' => 'Symptoms determine safety priority and diagnostic time.', 'answer_type' => 'choice', 'choices' => ['No power', 'Intermittent', 'Breaker trips', 'Heat or odor', 'Sparks', 'Other']],
                    ['key' => 'panel_access', 'question' => 'Is the electrical panel accessible and clearly labeled?', 'why_it_matters' => 'Safe circuit isolation is required before work begins.', 'answer_type' => 'yes_no', 'choices' => ['Yes', 'No', 'Not sure']],
                ],
                'assumptions' => ['Existing wiring and panel capacity are code-compliant and suitable for the requested work.'],
                'risks' => ['Damaged or non-compliant concealed wiring may require a licensed electrician and a revised scope.'],
            ],
            'drywall' => [
                'keywords' => ['drywall', 'sheetrock', 'wall hole', 'ceiling hole', 'patch wall'],
                'project_type' => 'drywall_repair',
                'steps' => [
                    ['title' => 'Prepare repair', 'description' => 'Protect the room, square the damaged area, and add backing if needed.', 'hours_low' => 0.5, 'hours_high' => 1.5],
                    ['title' => 'Patch and finish', 'description' => 'Install patch material, tape, and compound in required coats.', 'hours_low' => 1.5, 'hours_high' => 4.0],
                    ['title' => 'Sand and clean', 'description' => 'Sand the repair and leave it ready for primer or paint.', 'hours_low' => 0.5, 'hours_high' => 1.5],
                ],
                'materials' => [
                    ['name' => 'Drywall panel', 'quantity_low' => 0.25, 'quantity_high' => 1, 'unit' => 'sheet', 'notes' => 'Thickness must match the existing surface.'],
                    ['name' => 'Joint compound', 'quantity_low' => 1, 'quantity_high' => 1, 'unit' => 'container', 'notes' => 'Multiple coats and drying time may be required.'],
                    ['name' => 'Drywall tape', 'quantity_low' => 1, 'quantity_high' => 1, 'unit' => 'roll', 'notes' => 'Type depends on repair method.'],
                ],
                'questions' => [
                    ['key' => 'damage_size', 'question' => 'What are the approximate width and height of the damaged area?', 'why_it_matters' => 'Patch size drives material quantity and finishing time.', 'answer_type' => 'text', 'choices' => []],
                    ['key' => 'paint_included', 'question' => 'Should the estimate include primer and paint blending?', 'why_it_matters' => 'Painting can add materials, setup, and return visits.', 'answer_type' => 'yes_no', 'choices' => ['Yes', 'No']],
                ],
                'assumptions' => ['The source of any leak or moisture damage has already been corrected.'],
                'risks' => ['Texture matching and drying conditions can add time or require another visit.'],
            ],
            'flooring' => [
                'keywords' => ['floor', 'tile', 'laminate', 'vinyl plank', 'hardwood', 'carpet'],
                'project_type' => 'flooring_repair',
                'steps' => [
                    ['title' => 'Measure and prepare', 'description' => 'Measure the area, move minor obstructions, and inspect the substrate.', 'hours_low' => 0.75, 'hours_high' => 2.0],
                    ['title' => 'Remove and install', 'description' => 'Remove affected flooring and install replacement material.', 'hours_low' => 2.0, 'hours_high' => 8.0],
                    ['title' => 'Finish and clean', 'description' => 'Complete edges or transitions and clean the work area.', 'hours_low' => 0.75, 'hours_high' => 2.0],
                ],
                'materials' => [
                    ['name' => 'Replacement flooring', 'quantity_low' => 10, 'quantity_high' => 50, 'unit' => 'square foot', 'notes' => 'Product, pattern, waste, and matching must be confirmed.'],
                    ['name' => 'Flooring underlayment', 'quantity_low' => 10, 'quantity_high' => 50, 'unit' => 'square foot', 'notes' => 'Only needed for applicable systems.'],
                ],
                'questions' => [
                    ['key' => 'floor_area', 'question' => 'Approximately how many square feet are affected?', 'why_it_matters' => 'Area is the main driver of flooring labor and material quantity.', 'answer_type' => 'number', 'choices' => []],
                    ['key' => 'replacement_available', 'question' => 'Do you already have matching replacement flooring?', 'why_it_matters' => 'Matching discontinued products may change the feasible repair.', 'answer_type' => 'yes_no', 'choices' => ['Yes', 'No', 'Not sure']],
                ],
                'assumptions' => ['The subfloor is dry, sound, level, and does not require structural repair.'],
                'risks' => ['Hidden subfloor damage or an unavailable product match can expand the scope.'],
            ],
            'general' => [
                'keywords' => [],
                'project_type' => 'general_repair',
                'steps' => [
                    ['title' => 'Inspect and prepare', 'description' => 'Confirm measurements, access, condition, and the requested finish.', 'hours_low' => 0.5, 'hours_high' => 1.5],
                    ['title' => 'Complete repair', 'description' => 'Perform the described repair using standard methods and accessible components.', 'hours_low' => 1.0, 'hours_high' => 6.0],
                    ['title' => 'Test and clean up', 'description' => 'Verify the result, remove debris, and review the work with the customer.', 'hours_low' => 0.5, 'hours_high' => 1.0],
                ],
                'materials' => [],
                'questions' => [
                    ['key' => 'dimensions', 'question' => 'What are the approximate dimensions or quantity involved?', 'why_it_matters' => 'Size and quantity directly affect labor and materials.', 'answer_type' => 'text', 'choices' => []],
                    ['key' => 'access', 'question' => 'Are there height, crawlspace, attic, furniture, parking, or access constraints?', 'why_it_matters' => 'Access conditions can add setup time, travel, equipment, and risk.', 'answer_type' => 'text', 'choices' => []],
                ],
                'assumptions' => ['Standard readily available materials and ordinary residential access are sufficient.'],
                'risks' => ['The general description may omit trade-specific requirements.'],
            ],
        ];
    }
}
