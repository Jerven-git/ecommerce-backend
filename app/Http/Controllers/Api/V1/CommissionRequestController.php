<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommissionRequest;
use App\Mail\CommissionRequestSubmittedMail;
use App\Models\CommissionRequest;
use App\Models\SiteConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CommissionRequestController extends Controller
{
    public function store(StoreCommissionRequest $request): JsonResponse
    {
        $this->abortIfModuleDisabled();

        $validated = $request->validated();

        $this->verifyRecaptcha($validated['recaptcha_token'] ?? null);

        $referenceUrl = null;
        if ($request->hasFile('reference_image')) {
            Storage::disk('public')->makeDirectory('commissions');
            $path = $request->file('reference_image')->store('commissions', 'public');
            $referenceUrl = Storage::disk('public')->url($path);
        }

        $commission = CommissionRequest::create([
            'customer_name' => $validated['customer_name'],
            'customer_email' => $validated['customer_email'],
            'customer_phone' => $validated['customer_phone'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'budget_range' => $validated['budget_range'] ?? null,
            'preferred_medium' => $validated['preferred_medium'] ?? null,
            'preferred_size' => $validated['preferred_size'] ?? null,
            'deadline' => $validated['deadline'] ?? null,
            'reference_image_url' => $referenceUrl,
            'status' => 'pending',
        ]);

        $this->notifyAdmins($commission);

        return response()->json([
            'message' => 'Your commission request has been submitted. We will contact you within 48 hours.',
            'data' => ['id' => $commission->id],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = CommissionRequest::query();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $term = $request->query('search');
            $query->where(function ($q) use ($term): void {
                $q->where('customer_name', 'like', "%{$term}%")
                    ->orWhere('customer_email', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 20), 100);

        return response()->json(
            $query->orderByDesc('created_at')->paginate($perPage),
        );
    }

    public function show(CommissionRequest $commissionRequest): JsonResponse
    {
        return response()->json(['data' => $commissionRequest]);
    }

    public function update(Request $request, CommissionRequest $commissionRequest): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(CommissionRequest::STATUSES)],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $commissionRequest->update($validated);

        return response()->json(['data' => $commissionRequest->fresh()]);
    }

    public function destroy(CommissionRequest $commissionRequest): JsonResponse
    {
        $commissionRequest->delete();

        return response()->json(['message' => 'Commission request deleted.']);
    }

    /**
     * Aborts the request when the commissions module is disabled on this
     * tenant — applied only to the public submit so admins can still review
     * past submissions even after the module is turned off.
     */
    private function abortIfModuleDisabled(): void
    {
        $modules = SiteConfig::queryForDefaultStore()->value('modules_enabled');
        $enabled = is_array($modules) ? (bool) ($modules['commissions'] ?? false) : false;

        if (! $enabled) {
            abort(404);
        }
    }

    private function notifyAdmins(CommissionRequest $commission): void
    {
        $config = SiteConfig::forDefaultStore();
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
            return;
        }

        Mail::to($recipients->first())
            ->cc($recipients->slice(1)->all())
            ->send(new CommissionRequestSubmittedMail($commission));
    }

    private function verifyRecaptcha(?string $token): void
    {
        $secretKey = config('services.recaptcha.secret_key');
        $threshold = config('services.recaptcha.threshold', 0.5);

        if (! $secretKey || ! $token) {
            return;
        }

        /** @var Response $response */
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secretKey,
            'response' => $token,
        ]);

        $result = $response->json();

        if (! ($result['success'] ?? false) || ($result['score'] ?? 0) < $threshold) {
            abort(422, 'reCAPTCHA verification failed. Please try again.');
        }
    }
}
