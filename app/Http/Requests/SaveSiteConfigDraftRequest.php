<?php

namespace App\Http\Requests;

use App\Support\WebsiteEditor\WebsiteDocumentRules;
use Illuminate\Foundation\Http\FormRequest;

class SaveSiteConfigDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminLike() ?? false;
    }

    public function rules(): array
    {
        return array_merge([
            'base_updated_at' => ['required', 'date'],
            'draft_version' => ['nullable', 'integer', 'min:1'],
            'payload' => ['required', 'array'],
        ], self::payloadRules());
    }

    /** @return array<string, array<int, mixed>> */
    public static function payloadRules(): array
    {
        return WebsiteDocumentRules::all();
    }

    public function messages(): array
    {
        return [
            'base_updated_at.required' => 'Refresh the editor before saving this draft.',
            'payload.required' => 'The website draft is missing.',
            'payload.hero_overlay_color.regex' => 'Choose a valid six-digit hero overlay colour.',
        ];
    }
}
