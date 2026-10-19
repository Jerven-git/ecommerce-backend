<?php

namespace App\Support\SiteConfig;

final class SiteConfigModules
{
    public const KEYS = ['shop', 'blog', 'services', 'about', 'contact', 'commissions', 'gift_cards'];

    public const DEFAULT_ON = ['shop', 'blog', 'services', 'about', 'contact'];

    /** @return array<string, bool> */
    public static function resolve(?array $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $resolved = [];

        foreach (self::KEYS as $key) {
            $resolved[$key] = (bool) ($stored[$key] ?? in_array($key, self::DEFAULT_ON, true));
        }

        return $resolved;
    }
}
