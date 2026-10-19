<?php

namespace App\Support\WebsiteEditor;

final class WebsiteDocumentSanitizer
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        foreach (['site_name', 'hero_title', 'hero_subtitle', 'welcome_popup_heading', 'welcome_popup_body'] as $field) {
            if (! empty($payload[$field]) && is_string($payload[$field])) {
                $payload[$field] = strip_tags(trim($payload[$field]));
            }
        }

        if (! empty($payload['about_content']) && is_string($payload['about_content'])) {
            $payload['about_content'] = trim($payload['about_content']);
        }

        if (! empty($payload['contact_email']) && is_string($payload['contact_email'])) {
            $payload['contact_email'] = strtolower(trim($payload['contact_email']));
        }

        if (! empty($payload['contact_phone']) && is_string($payload['contact_phone'])) {
            $payload['contact_phone'] = trim($payload['contact_phone']);
        }

        return $payload;
    }
}
