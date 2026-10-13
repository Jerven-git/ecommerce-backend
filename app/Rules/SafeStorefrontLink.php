<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeStorefrontLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be a valid storefront link.');

            return;
        }

        $link = trim($value);

        if ($link === '' || preg_match('/[\x00-\x1F\x7F]/', $link) === 1) {
            $fail('The :attribute must be a valid storefront link.');

            return;
        }

        if ((str_starts_with($link, '/') && ! str_starts_with($link, '//') && ! str_starts_with($link, '/\\'))
            || str_starts_with($link, '#')) {
            return;
        }

        $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));

        if (in_array($scheme, ['http', 'https'], true) && filter_var($link, FILTER_VALIDATE_URL)) {
            return;
        }

        if (in_array($scheme, ['mailto', 'tel'], true) && strlen((string) parse_url($link, PHP_URL_PATH)) > 0) {
            return;
        }

        $fail('The :attribute must use a relative path, anchor, or an http, https, mailto, or tel link.');
    }
}
