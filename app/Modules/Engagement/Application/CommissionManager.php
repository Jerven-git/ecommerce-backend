<?php

namespace App\Modules\Engagement\Application;

use App\Mail\CommissionRequestSubmittedMail;
use App\Models\CommissionRequest;
use App\Models\SiteConfig;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class CommissionManager
{
    public function __construct(
        private readonly RecaptchaVerifier $recaptcha,
        private readonly ContactDelivery $contacts,
    ) {}

    public function moduleEnabled(): bool
    {
        $modules = SiteConfig::value('modules_enabled');

        return is_array($modules) && (bool) ($modules['commissions'] ?? false);
    }

    public function submit(array $attributes, ?UploadedFile $reference): CommissionRequest
    {
        $this->recaptcha->verify($attributes['recaptcha_token'] ?? null);
        $referenceUrl = null;

        if ($reference) {
            Storage::disk('public')->makeDirectory('commissions');
            $referenceUrl = Storage::disk('public')->url($reference->store('commissions', 'public'));
        }

        $commission = CommissionRequest::create([
            ...collect($attributes)->only([
                'customer_name', 'customer_email', 'customer_phone', 'title', 'description',
                'budget_range', 'preferred_medium', 'preferred_size', 'deadline',
            ])->all(),
            'reference_image_url' => $referenceUrl,
            'status' => 'pending',
        ]);

        $recipients = $this->contacts->recipients();
        if ($recipients->isNotEmpty()) {
            Mail::to($recipients->first())->cc($recipients->slice(1)->all())
                ->send(new CommissionRequestSubmittedMail($commission));
        }

        return $commission;
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = CommissionRequest::query();
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['search'] ?? null)) {
            $term = $filters['search'];
            $query->where(fn ($query) => $query->where('customer_name', 'like', "%{$term}%")
                ->orWhere('customer_email', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%"));
        }

        return $query->latest()->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function update(CommissionRequest $commission, array $attributes): CommissionRequest
    {
        $commission->update($attributes);

        return $commission->fresh();
    }

    public function delete(CommissionRequest $commission): void
    {
        $commission->delete();
    }
}
