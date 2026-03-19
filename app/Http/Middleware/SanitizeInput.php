<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    /**
     * Fields that are allowed to contain HTML (sanitized to safe tags only).
     */
    protected array $allowHtml = [
        'about_content',
        'description',
    ];

    /**
     * Allowed HTML tags for fields in $allowHtml.
     */
    protected string $allowedTags = '<p><br><strong><em><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote><span>';

    /**
     * Fields to skip sanitization entirely (e.g. passwords, tokens).
     */
    protected array $except = [
        'password',
        'password_confirmation',
        'current_password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();
        $sanitized = $this->sanitize($input);
        $request->merge($sanitized);

        return $next($request);
    }

    private function sanitize(array $data, string $prefix = ''): array
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                $data[$key] = $this->sanitize($value, $fullKey);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            if ($this->isExcluded($key, $fullKey)) {
                continue;
            }

            if ($this->allowsHtml($key, $fullKey)) {
                $data[$key] = strip_tags(trim($value), $this->allowedTags);
            } else {
                $data[$key] = strip_tags(trim($value));
            }
        }

        return $data;
    }

    private function isExcluded(string $key, string $fullKey): bool
    {
        return in_array($key, $this->except, true)
            || in_array($fullKey, $this->except, true);
    }

    private function allowsHtml(string $key, string $fullKey): bool
    {
        return in_array($key, $this->allowHtml, true)
            || in_array($fullKey, $this->allowHtml, true);
    }
}
