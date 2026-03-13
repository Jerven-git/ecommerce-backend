<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\ContactFormMail;
use App\Models\SiteConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Client\Response;

class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'email'           => 'required|email:rfc,dns|max:255',
            'subject'         => 'required|string|max:255',
            'message'         => 'required|string|max:5000',
            'recaptcha_token' => 'required|string',
        ]);

        // Verify reCAPTCHA v3 token
        $this->verifyRecaptcha($validated['recaptcha_token']);

        // Determine recipients from site config contact_entries, falling back to contact_email
        $config = SiteConfig::first();
        $recipients = collect($config?->contact_entries ?? [])
            ->pluck('email')
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            $fallback = $config?->contact_email ?: config('mail.from.address');
            $recipients = collect([$fallback])->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL));
        }

        if ($recipients->isEmpty()) {
            abort(503, 'No valid contact email configured.');
        }

        // Send email to all configured contact entries
        $mail = new ContactFormMail(
            name: $validated['name'],
            email: $validated['email'],
            contactSubject: $validated['subject'],
            body: $validated['message'],
        );

        Mail::to($recipients->first())
            ->cc($recipients->slice(1)->all())
            ->send($mail);

        return response()->json([
            'message' => 'Your message has been sent successfully.',
        ]);
    }

    private function verifyRecaptcha(string $token): void
    {
        $secretKey = config('services.recaptcha.secret_key');
        $threshold = config('services.recaptcha.threshold', 0.5);

        if (!$secretKey) {
            return;
        }
        
        /** @var Response $response */
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret'   => $secretKey,
            'response' => $token,
        ]);

        $result = $response->json();

        if (!($result['success'] ?? false) || ($result['score'] ?? 0) < $threshold) {
            abort(422, 'reCAPTCHA verification failed. Please try again.');
        }
    }
}
