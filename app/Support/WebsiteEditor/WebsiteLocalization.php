<?php

namespace App\Support\WebsiteEditor;

use App\Models\SiteConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class WebsiteLocalization
{
    public const DEFAULT_LOCALE = 'en';

    public const MAX_LOCALES = 12;

    /** @var array<string, int> */
    private const STATIC_PATHS = [
        'site_name' => 255,
        'logo_alt_text' => 255,
        'hero_title' => 255,
        'hero_subtitle' => 255,
        'homepage_steps.label' => 100,
        'homepage_steps.heading' => 100,
        'homepage_steps.subtitle' => 255,
        'homepage_statement.eyebrow' => 100,
        'homepage_statement.quote' => 1000,
        'homepage_statement.attribution' => 100,
        'homepage_statement.role' => 100,
        'homepage_statement.cta_label' => 50,
        'homepage_newsletter.label' => 100,
        'homepage_newsletter.heading' => 100,
        'homepage_newsletter.subtitle' => 255,
        'homepage_newsletter.disclaimer' => 255,
        'header_cta.label' => 30,
        'footer_banner.heading' => 150,
        'footer_banner.subtitle' => 255,
        'footer_banner.button_label' => 30,
        'footer.tagline' => 255,
        'footer.copyright_text' => 255,
        'about_content' => 10000,
        'story_page.hero.eyebrow' => 100,
        'story_page.hero.heading' => 150,
        'story_page.hero.subtitle' => 255,
        'story_page.section_a.heading' => 150,
        'story_page.section_a.body' => 2000,
        'story_page.section_a.cta_label' => 50,
        'story_page.section_b.heading' => 150,
        'story_page.section_b.body' => 2000,
        'story_page.section_b.cta_label' => 50,
        'contact_page.info_title' => 100,
        'contact_page.info_subtitle' => 255,
        'contact_page.info_description' => 500,
        'contact_page.info_badge' => 100,
        'contact_page.faq_label' => 50,
        'contact_page.faq_heading' => 100,
        'contact_page.faq_subtitle' => 255,
        'shop_header.label' => 100,
        'shop_header.heading' => 255,
        'shop_header.subtitle' => 255,
        'shop_promo.badge' => 100,
        'shop_promo.heading' => 255,
        'shop_promo.subtitle' => 500,
        'shop_promo.button_text' => 100,
        'blog_page.header.label' => 100,
        'blog_page.header.heading' => 255,
        'blog_page.header.subtitle' => 500,
        'blog_page.cta.heading' => 255,
        'blog_page.cta.subtitle' => 500,
        'blog_page.cta.button_label' => 100,
        'services_page.header.label' => 100,
        'services_page.header.heading' => 255,
        'services_page.header.subtitle' => 500,
        'services_page.header.primary_cta.label' => 100,
        'services_page.header.secondary_cta.label' => 100,
        'services_page.cta.heading' => 255,
        'services_page.cta.subtitle' => 500,
        'services_page.cta.button_label' => 100,
        'welcome_popup_heading' => 255,
        'welcome_popup_body' => 1000,
        'default_seo_title' => 255,
        'default_seo_description' => 1000,
    ];

    /** @var array<string, array{limit: int, max: int}> */
    private const INDEXED_PATHS = [
        'contact_entries.{index}.label' => ['limit' => 20, 'max' => 100],
        'social_links.{index}.label' => ['limit' => 20, 'max' => 100],
        'homepage_steps.items.{index}.title' => ['limit' => 12, 'max' => 100],
        'homepage_steps.items.{index}.description' => ['limit' => 12, 'max' => 255],
        'homepage_features.items.{index}.title' => ['limit' => 12, 'max' => 100],
        'homepage_features.items.{index}.description' => ['limit' => 12, 'max' => 255],
        'homepage_stats.items.{index}.value' => ['limit' => 8, 'max' => 50],
        'homepage_stats.items.{index}.label' => ['limit' => 8, 'max' => 100],
        'about_highlights.items.{index}.title' => ['limit' => 12, 'max' => 100],
        'about_highlights.items.{index}.description' => ['limit' => 12, 'max' => 255],
        'contact_page.promises.{index}.title' => ['limit' => 12, 'max' => 100],
        'contact_page.promises.{index}.description' => ['limit' => 12, 'max' => 255],
        'contact_page.faqs.{index}.question' => ['limit' => 30, 'max' => 255],
        'contact_page.faqs.{index}.answer' => ['limit' => 30, 'max' => 1000],
        'shop_promo.perks.{index}' => ['limit' => 6, 'max' => 100],
        'services_page.summary.items.{index}.title' => ['limit' => 6, 'max' => 100],
        'services_page.summary.items.{index}.description' => ['limit' => 6, 'max' => 255],
        'services_page.stats.items.{index}.value' => ['limit' => 6, 'max' => 50],
        'services_page.stats.items.{index}.label' => ['limit' => 6, 'max' => 150],
    ];

    /** @var array<int, string> */
    private const SEO_PAGES = ['home', 'about', 'story', 'contact', 'shop', 'blog', 'services'];

    public function normalizeLocale(mixed $locale): ?string
    {
        if (! is_string($locale)) {
            return null;
        }

        $parts = preg_split('/[-_]/', trim($locale));
        if (! $parts || ! preg_match('/^[A-Za-z]{2,3}$/', $parts[0])) {
            return null;
        }

        $normalized = strtolower($parts[0]);
        if (isset($parts[1])) {
            if (! preg_match('/^[A-Za-z]{2}$|^[0-9]{3}$/', $parts[1])) {
                return null;
            }
            $normalized .= '-'.(ctype_alpha($parts[1]) ? strtoupper($parts[1]) : $parts[1]);
        }

        return count($parts) <= 2 ? $normalized : null;
    }

    /** @return array<int, string> */
    public function supportedLocales(mixed $locales, mixed $defaultLocale): array
    {
        $default = $this->normalizeLocale($defaultLocale) ?? self::DEFAULT_LOCALE;
        $normalized = collect(is_array($locales) ? $locales : [])
            ->map(fn (mixed $locale): ?string => $this->normalizeLocale($locale))
            ->filter()
            ->prepend($default)
            ->unique()
            ->take(self::MAX_LOCALES)
            ->values()
            ->all();

        return $normalized ?: [$default];
    }

    /** @param array<string, mixed> $payload */
    public function validateDraftPayload(array $payload): void
    {
        $submittedDefault = $payload['default_locale'] ?? null;
        $default = $this->normalizeLocale($submittedDefault) ?? self::DEFAULT_LOCALE;
        $supported = $this->supportedLocales($payload['supported_locales'] ?? [], $default);
        $translations = $payload['localized_content'] ?? [];
        $errors = [];

        if (array_key_exists('default_locale', $payload) && $this->normalizeLocale($submittedDefault) === null) {
            $errors['payload.default_locale'][] = 'Choose a valid default language code.';
        }
        if (array_key_exists('supported_locales', $payload)
            && (! is_array($payload['supported_locales']) || count($payload['supported_locales']) > self::MAX_LOCALES)) {
            $errors['payload.supported_locales'][] = 'Choose between 1 and '.self::MAX_LOCALES.' storefront languages.';
        }
        if (! is_array($translations)) {
            $errors['payload.localized_content'][] = 'Localized content must be grouped by language.';
        } else {
            foreach ($translations as $locale => $values) {
                $normalized = $this->normalizeLocale($locale);
                if (! $normalized || $normalized !== $locale || ! in_array($locale, $supported, true) || $locale === $default) {
                    $errors["payload.localized_content.{$locale}"][] = 'Translations must use a supported non-default language.';
                    continue;
                }
                if (! is_array($values) || count($values) > 500) {
                    $errors["payload.localized_content.{$locale}"][] = 'This language contains too many translated fields.';
                    continue;
                }
                foreach ($values as $path => $value) {
                    $max = $this->maxLengthForPath((string) $path);
                    if ($max === null) {
                        $errors["payload.localized_content.{$locale}.{$path}"][] = 'This field cannot be localized.';
                    } elseif (! is_string($value) || mb_strlen($value) > $max) {
                        $errors["payload.localized_content.{$locale}.{$path}"][] = "Keep this translation within {$max} characters.";
                    }
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, array<string, string>> */
    public function sanitizeLocalizedContent(mixed $translations, array $supported, string $default): array
    {
        if (! is_array($translations)) {
            return [];
        }

        $result = [];
        foreach ($translations as $locale => $values) {
            if (! is_string($locale) || ! in_array($locale, $supported, true) || $locale === $default || ! is_array($values)) {
                continue;
            }
            foreach ($values as $path => $value) {
                if (is_string($path) && is_string($value) && $this->maxLengthForPath($path) !== null) {
                    $result[$locale][$path] = $value;
                }
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $data
     * @param array<string, array<string, string>> $translations
     * @return array<string, mixed>
     */
    public function apply(array $data, array $translations, string $locale): array
    {
        foreach ($translations[$locale] ?? [] as $path => $value) {
            if ($this->maxLengthForPath($path) !== null) {
                Arr::set($data, $path, $value);
            }
        }

        return $data;
    }

    public function resolveRequestLocale(SiteConfig $config, Request $request): string
    {
        $default = $this->normalizeLocale($config->default_locale) ?? self::DEFAULT_LOCALE;
        $supported = $this->supportedLocales($config->supported_locales, $default);
        if ($request->query('locale') === 'default') {
            return $default;
        }
        $candidates = [$request->query('locale'), $request->cookie('storefront_locale')];

        if (! $request->user()) {
            foreach ($request->getLanguages() as $language) {
                $candidates[] = $language;
                $candidates[] = explode('-', $language)[0];
            }
        }

        foreach ($candidates as $candidate) {
            $locale = $this->normalizeLocale($candidate);
            if ($locale && in_array($locale, $supported, true)) {
                return $locale;
            }
        }

        return $default;
    }

    public function maxLengthForPath(string $path): ?int
    {
        if (isset(self::STATIC_PATHS[$path])) {
            return self::STATIC_PATHS[$path];
        }

        if (preg_match('/^pages_seo\.([a-z-]+)\.(seo_title|seo_description)$/', $path, $matches)
            && in_array($matches[1], self::SEO_PAGES, true)) {
            return $matches[2] === 'seo_title' ? 255 : 1000;
        }

        foreach (self::INDEXED_PATHS as $pattern => $constraints) {
            $regex = '/^'.str_replace('\{index\}', '(\d+)', preg_quote($pattern, '/')).'$/';
            if (preg_match($regex, $path, $matches) && (int) $matches[1] < $constraints['limit']) {
                return $constraints['max'];
            }
        }

        return null;
    }
}
