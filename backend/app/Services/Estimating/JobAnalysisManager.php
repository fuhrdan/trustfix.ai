<?php

namespace App\Services\Estimating;

use App\Models\Job;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class JobAnalysisManager
{
    public function __construct(private readonly RuleBasedJobAnalyzer $rules)
    {
    }

    public function analyze(Job $job, array $answers = []): array
    {
        $requestedProvider = strtolower((string) config('trustfix.estimator.provider', 'rules'));

        if (!in_array($requestedProvider, ['openai', 'anthropic'], true)) {
            return $this->fallback($job, $answers, null);
        }

        try {
            $analysis = $requestedProvider === 'openai'
                ? $this->analyzeWithOpenAI($job, $answers)
                : $this->analyzeWithAnthropic($job, $answers);

            return [
                'analysis' => $this->normalize($analysis),
                'provider' => $requestedProvider,
                'model' => (string) config("trustfix.estimator.$requestedProvider.model"),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            Log::warning('TrustFix AI estimate analysis fell back to rules.', [
                'job_id' => $job->id,
                'provider' => $requestedProvider,
                'exception' => $exception->getMessage(),
            ]);

            return $this->fallback(
                $job,
                $answers,
                ucfirst($requestedProvider) . ' analysis was unavailable; TrustFix used its rules fallback.'
            );
        }
    }

    private function analyzeWithOpenAI(Job $job, array $answers): array
    {
        $apiKey = trim((string) config('trustfix.estimator.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $content = [[
            'type' => 'input_text',
            'text' => $this->jobPrompt($job, $answers),
        ]];

        foreach ($this->images($job) as $image) {
            $content[] = [
                'type' => 'input_image',
                'image_url' => 'data:' . $image['media_type'] . ';base64,' . $image['data'],
            ];
        }

        $response = $this->http()
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('trustfix.estimator.openai.model'),
                'instructions' => $this->systemInstructions(),
                'input' => [[
                    'role' => 'user',
                    'content' => $content,
                ]],
                'reasoning' => ['effort' => 'low'],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'trustfix_job_analysis',
                        'strict' => true,
                        'schema' => JobAnalysisSchema::make(),
                    ],
                ],
                'max_output_tokens' => 5000,
                'store' => false,
            ]);

        $response->throw();
        $body = $response->json();

        foreach ($body['output'] ?? [] as $output) {
            foreach ($output['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'output_text' && isset($block['text'])) {
                    return $this->decodeAnalysis($block['text']);
                }
            }
        }

        throw new RuntimeException('OpenAI returned no structured output.');
    }

    private function analyzeWithAnthropic(Job $job, array $answers): array
    {
        $apiKey = trim((string) config('trustfix.estimator.anthropic.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $content = [[
            'type' => 'text',
            'text' => $this->jobPrompt($job, $answers),
        ]];

        foreach ($this->images($job) as $image) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['media_type'],
                    'data' => $image['data'],
                ],
            ];
        }

        $response = $this->http()
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('trustfix.estimator.anthropic.model'),
                'max_tokens' => 6000,
                'system' => $this->systemInstructions(),
                'messages' => [[
                    'role' => 'user',
                    'content' => $content,
                ]],
                'output_config' => [
                    'effort' => 'low',
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => JobAnalysisSchema::make(),
                    ],
                ],
            ]);

        $response->throw();
        $body = $response->json();

        if (($body['stop_reason'] ?? '') === 'refusal') {
            throw new RuntimeException('Anthropic declined the analysis request.');
        }

        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                return $this->decodeAnalysis($block['text']);
            }
        }

        throw new RuntimeException('Anthropic returned no structured output.');
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout((int) config('trustfix.estimator.timeout_seconds', 60))
            ->connectTimeout(10)
            ->retry(1, 500);
    }

    private function images(Job $job): array
    {
        if (!config('trustfix.estimator.send_images', true)) {
            return [];
        }

        $images = [];
        $limit = max(0, min(5, (int) config('trustfix.estimator.max_images', 3)));
        $maxBytes = max(1024, (int) config('trustfix.estimator.max_image_bytes', 3145728));

        foreach ($job->images()->limit($limit)->get() as $jobImage) {
            $path = $jobImage->image_path;
            if (!$path || !Storage::disk('public')->exists($path)) {
                continue;
            }

            $absolutePath = Storage::disk('public')->path($path);
            $size = @filesize($absolutePath);
            $mediaType = @mime_content_type($absolutePath);

            if (!$size || $size > $maxBytes || !in_array($mediaType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                continue;
            }

            $contents = @file_get_contents($absolutePath);
            if ($contents === false) {
                continue;
            }

            $images[] = [
                'media_type' => $mediaType,
                'data' => base64_encode($contents),
            ];
        }

        return $images;
    }

    private function jobPrompt(Job $job, array $answers): string
    {
        $property = $job->property;

        return implode("\n", [
            'Analyze this residential repair request for TrustFix.',
            'Job description: ' . trim((string) $job->initial_description),
            'Requested skills: ' . implode(', ', $job->skills ?? []),
            'ZIP code: ' . ($property?->zip ?? 'unknown'),
            'Property notes: ' . ($property?->description ?? 'none'),
            'Number of stored job photos: ' . $job->images()->count(),
            'Homeowner follow-up answers (JSON): ' . json_encode($answers, JSON_UNESCAPED_SLASHES),
            'Return conservative low/high labor-hour estimates. Ask only unanswered questions that can materially change scope, hours, materials, safety, access, or trade requirements.',
        ]);
    }

    private function systemInstructions(): string
    {
        return implode(' ', [
            'You are TrustFix job-scope analyst for residential repair work.',
            'Understand the request, identify missing information, break it into safe work steps, estimate labor hours as ranges, and list material names and quantities.',
            'Never provide prices, hourly rates, legal conclusions, or a final quote. TrustFix calculates money using its own verified pricing data.',
            'Do not assume a photo proves concealed conditions. Flag permits, licenses, hazardous materials, structural work, active leaks, electrical hazards, gas, mold, asbestos, or other on-site inspection needs.',
            'Keep the scope concise and useful to both homeowner and contractor. Use zero quantities only when a material truly cannot be inferred.',
        ]);
    }

    private function decodeAnalysis(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The AI response was not valid JSON.');
        }

        return $decoded;
    }

    private function normalize(array $analysis): array
    {
        foreach ($analysis['steps'] ?? [] as $index => $step) {
            $low = max(0, (float) ($step['hours_low'] ?? 0));
            $high = max($low, (float) ($step['hours_high'] ?? $low));
            $analysis['steps'][$index]['hours_low'] = round($low, 2);
            $analysis['steps'][$index]['hours_high'] = round($high, 2);
        }

        foreach ($analysis['materials'] ?? [] as $index => $material) {
            $low = max(0, (float) ($material['quantity_low'] ?? 0));
            $high = max($low, (float) ($material['quantity_high'] ?? $low));
            $analysis['materials'][$index]['quantity_low'] = round($low, 2);
            $analysis['materials'][$index]['quantity_high'] = round($high, 2);
        }

        $confidence = strtolower((string) ($analysis['confidence'] ?? 'low'));
        $analysis['confidence'] = in_array($confidence, ['low', 'medium', 'high'], true)
            ? $confidence
            : 'low';

        return $analysis;
    }

    private function fallback(Job $job, array $answers, ?string $error): array
    {
        return [
            'analysis' => $this->normalize($this->rules->analyze($job, $answers)),
            'provider' => 'rules',
            'model' => null,
            'error' => $error,
        ];
    }
}
