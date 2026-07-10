<?php

namespace App\Http\Requests\SuperAdmin;

/**
 * Normalise the submitted domain to the canonical form the Store model
 * persists (lowercase, no leading "www.") before validation runs, so the
 * uniqueness check compares like with like. Without this, "WWW.Example.com"
 * would sail past `unique:stores,domain` and then collide on save.
 */
trait CanonicalisesDomainInput
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('domain')) {
            return;
        }

        $domain = $this->input('domain');

        if (! is_string($domain)) {
            return;
        }

        $domain = strtolower(trim($domain));

        if ($domain === '') {
            $this->merge(['domain' => null]);

            return;
        }

        // Only one "www." is stripped; StoreCustomDomain rejects the rest so a
        // "www.www." typo surfaces as an error instead of being silently eaten.
        if (str_starts_with($domain, 'www.') && ! str_starts_with(substr($domain, 4), 'www.')) {
            $domain = substr($domain, 4);
        }

        $this->merge(['domain' => $domain]);
    }
}
