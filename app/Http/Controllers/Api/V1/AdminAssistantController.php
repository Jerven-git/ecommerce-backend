<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI help chatbot for the admin dashboard.
 *
 * Answers admin "how do I…" questions using the dashboard knowledge base as
 * system context. The Gemini API key lives server-side only — never sent to the
 * browser. Swapping providers later means changing only the body of callModel().
 */
class AdminAssistantController extends Controller
{
    /** Max prior turns (user+assistant pairs) kept from the client to bound token use. */
    private const MAX_HISTORY = 12;

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array', 'max:50'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:4000'],
        ]);

        $apiKey = config('services.gemini.key');
        if (empty($apiKey)) {
            Log::warning('Admin assistant called but GEMINI_API_KEY is not set.');

            return response()->json([
                'message' => 'The assistant is not configured yet. Please add an API key.',
            ], 503);
        }

        $history = array_slice($validated['history'] ?? [], -self::MAX_HISTORY);

        try {
            $reply = $this->callModel($apiKey, $history, $validated['message']);
        } catch (\Throwable $e) {
            Log::error('Admin assistant request failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Sorry, I had trouble answering just now. Please try again.',
            ], 502);
        }

        return response()->json(['message' => $reply]);
    }

    /**
     * Call Google Gemini (free tier). Returns the assistant's reply text.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function callModel(string $apiKey, array $history, string $message): string
    {
        $model = config('services.gemini.model', 'gemini-2.0-flash');

        // Gemini uses "user" / "model" roles; map our "assistant" -> "model".
        $contents = [];
        foreach ($history as $turn) {
            $contents[] = [
                'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['content']]],
            ];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $response = Http::timeout(30)
            ->withQueryParameters(['key' => $apiKey])
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                [
                    'system_instruction' => [
                        'parts' => [['text' => $this->systemPrompt()]],
                    ],
                    'contents' => $contents,
                    'generationConfig' => [
                        'temperature' => 0.3,
                        'maxOutputTokens' => 800,
                    ],
                ]
            );

        if ($response->failed()) {
            throw new \RuntimeException('Gemini API error: '.$response->status().' '.$response->body());
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || $text === '') {
            // Could be a safety block or empty candidate.
            throw new \RuntimeException('Gemini returned no text.');
        }

        return trim($text);
    }

    private function systemPrompt(): string
    {
        $knowledge = $this->knowledge();

        return <<<PROMPT
        You are the in-app help assistant for an e-commerce store admin dashboard.
        Help the admin understand and use the dashboard. Answer ONLY using the
        knowledge base below. Be concise, friendly, and practical — a few sentences
        or a short bullet list. When relevant, name the exact menu item or page
        (e.g. "go to Products → Add a product"). If the answer is not in the
        knowledge base, say you're not sure and suggest where in the dashboard they
        might look, rather than inventing features. Never discuss anything unrelated
        to using this dashboard.

        --- KNOWLEDGE BASE ---
        {$knowledge}
        PROMPT;
    }

    private function knowledge(): string
    {
        $path = resource_path('admin-assistant/knowledge.md');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
