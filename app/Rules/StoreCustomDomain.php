<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Constrains what may be entered as a store's custom domain.
 *
 * The important rule is the last one: a custom domain may never be the
 * platform's own base domain or anything beneath it. Those hosts already
 * belong to the slug-based subdomain router and to the admin apex, and
 * wildcard DNS already points them here — so accepting one as a custom domain
 * would let a store shadow another store's storefront, or the admin login,
 * with no external DNS required at all.
 */
class StoreCustomDomain implements ValidationRule
{
    /**
     * A registrable hostname: two or more DNS labels, alphabetic TLD. Rejects
     * bare IPs, single labels like "localhost", and consecutive dots.
     */
    private const HOSTNAME = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a valid domain name.');

            return;
        }

        $domain = strtolower(trim($value));

        if (strlen($domain) > 253) {
            $fail('The :attribute may not be longer than 253 characters.');

            return;
        }

        if (! preg_match(self::HOSTNAME, $domain)) {
            $fail('The :attribute must be a valid domain name, such as example.com.');

            return;
        }

        // One "www." is stripped on save; a second means they typed "www.www.".
        if (str_starts_with($domain, 'www.') && str_starts_with(substr($domain, 4), 'www.')) {
            $fail('Enter the bare domain — the www. alias is handled automatically.');

            return;
        }

        $baseDomain = strtolower((string) config('storefront.base_domain'));

        if ($baseDomain === '') {
            return;
        }

        $canonical = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;

        if ($canonical === $baseDomain || str_ends_with($canonical, '.'.$baseDomain)) {
            $fail("The :attribute cannot be {$baseDomain} or a subdomain of it. Those hosts are reserved for store subdomains and the admin app.");
        }
    }
}
