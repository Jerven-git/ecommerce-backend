<?php

namespace App\Http\Requests;

use App\Rules\SafeStorefrontLink;
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

    /** @return array<string, array<int, string>> */
    public static function payloadRules(): array
    {
        return [
            'payload.hero_title' => ['nullable', 'string', 'max:255'],
            'payload.hero_subtitle' => ['nullable', 'string', 'max:255'],
            'payload.hero_overlay_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'payload.hero_overlay_opacity' => ['required', 'integer', 'min:0', 'max:100'],
            'payload.hero_full_bleed' => ['required', 'boolean'],
            'payload.hero_focal_x' => ['required', 'integer', 'min:0', 'max:100'],
            'payload.hero_focal_y' => ['required', 'integer', 'min:0', 'max:100'],
            'payload.homepage_steps' => ['required', 'array'],
            'payload.homepage_steps.label' => ['nullable', 'string', 'max:100'],
            'payload.homepage_steps.heading' => ['nullable', 'string', 'max:100'],
            'payload.homepage_steps.subtitle' => ['nullable', 'string', 'max:255'],
            'payload.homepage_steps.items' => ['present', 'array', 'max:12'],
            'payload.homepage_steps.items.*.icon' => ['nullable', 'string', 'max:100'],
            'payload.homepage_steps.items.*.title' => ['nullable', 'string', 'max:100'],
            'payload.homepage_steps.items.*.description' => ['nullable', 'string', 'max:255'],
            'payload.homepage_features' => ['required', 'array'],
            'payload.homepage_features.items' => ['present', 'array', 'max:12'],
            'payload.homepage_features.items.*.icon' => ['nullable', 'string', 'max:100'],
            'payload.homepage_features.items.*.title' => ['nullable', 'string', 'max:100'],
            'payload.homepage_features.items.*.description' => ['nullable', 'string', 'max:255'],
            'payload.homepage_stats' => ['required', 'array'],
            'payload.homepage_stats.items' => ['present', 'array', 'max:8'],
            'payload.homepage_stats.items.*.value' => ['nullable', 'string', 'max:50'],
            'payload.homepage_stats.items.*.label' => ['nullable', 'string', 'max:100'],
            'payload.homepage_statement' => ['required', 'array'],
            'payload.homepage_statement.enabled' => ['required', 'boolean'],
            'payload.homepage_statement.eyebrow' => ['nullable', 'string', 'max:100'],
            'payload.homepage_statement.quote' => ['nullable', 'string', 'max:1000'],
            'payload.homepage_statement.attribution' => ['nullable', 'string', 'max:100'],
            'payload.homepage_statement.role' => ['nullable', 'string', 'max:100'],
            'payload.homepage_statement.cta_label' => ['nullable', 'string', 'max:50'],
            'payload.homepage_statement.cta_link' => self::linkRules(),
            'payload.homepage_newsletter' => ['required', 'array'],
            'payload.homepage_newsletter.label' => ['nullable', 'string', 'max:100'],
            'payload.homepage_newsletter.heading' => ['nullable', 'string', 'max:100'],
            'payload.homepage_newsletter.subtitle' => ['nullable', 'string', 'max:255'],
            'payload.homepage_newsletter.disclaimer' => ['nullable', 'string', 'max:255'],
            'payload.homepage_sections' => ['required', 'array', 'size:10'],
            'payload.homepage_sections.*.id' => ['required', 'string', 'distinct', 'in:hero,featured-products,showcase,watch-shop,best-sellers,how-it-works,features,stats,statement,newsletter'],
            'payload.homepage_sections.*.visible' => ['required', 'boolean'],
            'payload.header_cta' => ['sometimes', 'array'],
            'payload.header_cta.enabled' => ['required_with:payload.header_cta', 'boolean'],
            'payload.header_cta.label' => ['nullable', 'string', 'max:30'],
            'payload.header_cta.link' => self::linkRules(),
            'payload.footer_banner' => ['sometimes', 'array'],
            'payload.footer_banner.enabled' => ['required_with:payload.footer_banner', 'boolean'],
            'payload.footer_banner.heading' => ['nullable', 'string', 'max:150'],
            'payload.footer_banner.subtitle' => ['nullable', 'string', 'max:255'],
            'payload.footer_banner.button_label' => ['nullable', 'string', 'max:30'],
            'payload.footer_banner.button_link' => self::linkRules(),
            'payload.footer_banner.background_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'payload.footer_banner.background_color_to' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'payload.footer_banner.text_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'payload.footer' => ['sometimes', 'array'],
            'payload.footer.tagline' => ['nullable', 'string', 'max:255'],
            'payload.footer.copyright_text' => ['nullable', 'string', 'max:255'],
            'payload.footer.show_tagline' => ['required_with:payload.footer', 'boolean'],
            'payload.footer.show_quick_links' => ['required_with:payload.footer', 'boolean'],
            'payload.footer.show_contact_info' => ['required_with:payload.footer', 'boolean'],
            'payload.footer.show_social_links' => ['required_with:payload.footer', 'boolean'],
            'payload.about_content' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'payload.about_highlights' => ['sometimes', 'array'],
            'payload.about_highlights.items' => ['nullable', 'array', 'max:12'],
            'payload.about_highlights.items.*.icon' => ['nullable', 'string', 'max:50'],
            'payload.about_highlights.items.*.title' => ['nullable', 'string', 'max:100'],
            'payload.about_highlights.items.*.description' => ['nullable', 'string', 'max:255'],
            'payload.story_page' => ['sometimes', 'array'],
            'payload.story_page.enabled' => ['required_with:payload.story_page', 'boolean'],
            'payload.story_page.hero' => ['required_with:payload.story_page', 'array'],
            'payload.story_page.hero.eyebrow' => ['nullable', 'string', 'max:100'],
            'payload.story_page.hero.heading' => ['nullable', 'string', 'max:150'],
            'payload.story_page.hero.subtitle' => ['nullable', 'string', 'max:255'],
            'payload.story_page.section_a' => ['required_with:payload.story_page', 'array'],
            'payload.story_page.section_b' => ['required_with:payload.story_page', 'array'],
            'payload.story_page.section_a.heading' => ['nullable', 'string', 'max:150'],
            'payload.story_page.section_a.body' => ['nullable', 'string', 'max:2000'],
            'payload.story_page.section_a.cta_label' => ['nullable', 'string', 'max:50'],
            'payload.story_page.section_a.cta_link' => self::linkRules(),
            'payload.story_page.section_a.image_position' => ['nullable', 'string', 'in:left,right'],
            'payload.story_page.section_a.image_url' => self::linkRules(),
            'payload.story_page.section_b.heading' => ['nullable', 'string', 'max:150'],
            'payload.story_page.section_b.body' => ['nullable', 'string', 'max:2000'],
            'payload.story_page.section_b.cta_label' => ['nullable', 'string', 'max:50'],
            'payload.story_page.section_b.cta_link' => self::linkRules(),
            'payload.story_page.section_b.image_position' => ['nullable', 'string', 'in:left,right'],
            'payload.story_page.section_b.image_url' => self::linkRules(),
            'payload.contact_page' => ['sometimes', 'array'],
            'payload.contact_page.info_title' => ['nullable', 'string', 'max:100'],
            'payload.contact_page.info_subtitle' => ['nullable', 'string', 'max:255'],
            'payload.contact_page.info_description' => ['nullable', 'string', 'max:500'],
            'payload.contact_page.info_badge' => ['nullable', 'string', 'max:100'],
            'payload.contact_page.promises' => ['nullable', 'array', 'max:12'],
            'payload.contact_page.promises.*.icon' => ['nullable', 'string', 'max:100'],
            'payload.contact_page.promises.*.title' => ['nullable', 'string', 'max:100'],
            'payload.contact_page.promises.*.description' => ['nullable', 'string', 'max:255'],
            'payload.contact_page.faq_label' => ['nullable', 'string', 'max:50'],
            'payload.contact_page.faq_heading' => ['nullable', 'string', 'max:100'],
            'payload.contact_page.faq_subtitle' => ['nullable', 'string', 'max:255'],
            'payload.contact_page.faqs' => ['nullable', 'array', 'max:30'],
            'payload.contact_page.faqs.*.question' => ['nullable', 'string', 'max:255'],
            'payload.contact_page.faqs.*.answer' => ['nullable', 'string', 'max:1000'],
            'payload.shop_header' => ['sometimes', 'array'],
            'payload.shop_header.label' => ['nullable', 'string', 'max:100'],
            'payload.shop_header.heading' => ['nullable', 'string', 'max:255'],
            'payload.shop_header.subtitle' => ['nullable', 'string', 'max:255'],
            'payload.shop_promo' => ['sometimes', 'array'],
            'payload.shop_promo.badge' => ['nullable', 'string', 'max:100'],
            'payload.shop_promo.heading' => ['nullable', 'string', 'max:255'],
            'payload.shop_promo.subtitle' => ['nullable', 'string', 'max:500'],
            'payload.shop_promo.button_text' => ['nullable', 'string', 'max:100'],
            'payload.shop_promo.perks' => ['nullable', 'array', 'max:6'],
            'payload.shop_promo.perks.*' => ['nullable', 'string', 'max:100'],
            'payload.blog_page' => ['sometimes', 'array'],
            'payload.blog_page.header' => ['required_with:payload.blog_page', 'array'],
            'payload.blog_page.header.label' => ['nullable', 'string', 'max:100'],
            'payload.blog_page.header.heading' => ['nullable', 'string', 'max:255'],
            'payload.blog_page.header.subtitle' => ['nullable', 'string', 'max:500'],
            'payload.blog_page.cta' => ['nullable', 'array'],
            'payload.blog_page.cta.heading' => ['nullable', 'string', 'max:255'],
            'payload.blog_page.cta.subtitle' => ['nullable', 'string', 'max:500'],
            'payload.blog_page.cta.button_label' => ['nullable', 'string', 'max:100'],
            'payload.blog_page.cta.button_link' => self::linkRules(),
            'payload.services_page' => ['sometimes', 'array'],
            'payload.services_page.header' => ['required_with:payload.services_page', 'array'],
            'payload.services_page.header.label' => ['nullable', 'string', 'max:100'],
            'payload.services_page.header.heading' => ['nullable', 'string', 'max:255'],
            'payload.services_page.header.subtitle' => ['nullable', 'string', 'max:500'],
            'payload.services_page.header.primary_cta' => ['nullable', 'array'],
            'payload.services_page.header.primary_cta.label' => ['nullable', 'string', 'max:100'],
            'payload.services_page.header.primary_cta.link' => self::linkRules(),
            'payload.services_page.header.secondary_cta' => ['nullable', 'array'],
            'payload.services_page.header.secondary_cta.label' => ['nullable', 'string', 'max:100'],
            'payload.services_page.header.secondary_cta.link' => self::linkRules(),
            'payload.services_page.summary' => ['nullable', 'array'],
            'payload.services_page.summary.items' => ['nullable', 'array', 'max:6'],
            'payload.services_page.summary.items.*.title' => ['nullable', 'string', 'max:100'],
            'payload.services_page.summary.items.*.description' => ['nullable', 'string', 'max:255'],
            'payload.services_page.stats' => ['nullable', 'array'],
            'payload.services_page.stats.items' => ['nullable', 'array', 'max:6'],
            'payload.services_page.stats.items.*.value' => ['nullable', 'string', 'max:50'],
            'payload.services_page.stats.items.*.label' => ['nullable', 'string', 'max:150'],
            'payload.services_page.cta' => ['nullable', 'array'],
            'payload.services_page.cta.heading' => ['nullable', 'string', 'max:255'],
            'payload.services_page.cta.subtitle' => ['nullable', 'string', 'max:500'],
            'payload.services_page.cta.button_label' => ['nullable', 'string', 'max:100'],
            'payload.services_page.cta.button_link' => self::linkRules(),
            'payload.welcome_popup_enabled' => ['sometimes', 'boolean'],
            'payload.welcome_popup_heading' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payload.welcome_popup_body' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'base_updated_at.required' => 'Refresh the editor before saving this draft.',
            'payload.required' => 'The website draft is missing.',
            'payload.hero_overlay_color.regex' => 'Choose a valid six-digit hero overlay colour.',
        ];
    }

    /** @return array<int, mixed> */
    private static function linkRules(): array
    {
        return ['nullable', 'string', 'max:500', new SafeStorefrontLink];
    }
}
