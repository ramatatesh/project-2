<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiService
{
    /**
     * Generate a plain-text reply from Gemini using a system instruction + user payload.
     *
     * @param  list<array{role: string, text: string}>  $historyTurns
     *         Prior turns only. role: "user" | "assistant". Mapped to Gemini user/model roles.
     *
     * @throws RuntimeException when configuration is missing or the API call fails
     */
    public function generateContent(string $systemInstruction, string $userPrompt, array $historyTurns = []): string
    {
        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException('AI service is not configured.');
        }

        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $model = (string) config('services.gemini.model', 'gemini-3.5-flash');
        $timeout = (int) config('services.gemini.timeout', 30);

        // Google Generative Language API path: /v1beta/models/{model}:generateContent
        $url = "{$baseUrl}/models/{$model}:generateContent";

        $contents = [];
        foreach ($historyTurns as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'model' : 'user';
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $contents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => $text],
                ],
            ];
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $userPrompt],
            ],
        ];

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                ])
                ->post($url, [
                    'systemInstruction' => [
                        'parts' => [
                            ['text' => $systemInstruction],
                        ],
                    ],
                    'contents' => $contents,
                    'generationConfig' => [
                        'temperature' => 0.3,
                        'maxOutputTokens' => 1024,
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Gemini connection failed', [
                'message' => $e->getMessage(),
                'model' => $model,
            ]);

            throw new RuntimeException('AI service is temporarily unavailable.');
        }

        if ($response->status() === 429) {
            Log::warning('Gemini rate limited', ['model' => $model]);

            throw new RuntimeException('AI service is busy. Please try again shortly.');
        }

        if ($response->unauthorized() || $response->forbidden()) {
            Log::error('Gemini authentication failed', [
                'status' => $response->status(),
                'model' => $model,
                'api_error' => data_get($response->json(), 'error.message'),
            ]);

            throw new RuntimeException('AI service is not configured correctly.');
        }

        if ($response->failed()) {
            Log::error('Gemini request failed', [
                'status' => $response->status(),
                'model' => $model,
                'api_error' => data_get($response->json(), 'error.message'),
            ]);

            throw new RuntimeException('AI service is temporarily unavailable.');
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            Log::warning('Gemini returned an empty response payload', [
                'model' => $model,
            ]);

            throw new RuntimeException('AI service returned an empty response.');
        }

        return trim($text);
    }
}
