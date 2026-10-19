<?php

namespace Tests\Unit\WebsiteEditor;

use App\Http\Requests\SaveSiteConfigDraftRequest;
use App\Support\WebsiteEditor\WebsiteDocumentDefaults;
use App\Support\WebsiteEditor\WebsiteDocumentRules;
use App\Support\WebsiteEditor\WebsiteDocumentSchema;
use App\Support\WebsiteEditor\WebsiteEditorAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebsiteDocumentContractTest extends TestCase
{
    public function test_each_draft_field_has_one_validation_rule_and_publish_area(): void
    {
        $draftFields = WebsiteDocumentSchema::draftFields();
        $validatedFields = collect(array_keys(WebsiteDocumentRules::all()))
            ->map(fn (string $path): string => str_replace('payload.', '', $path))
            ->reject(fn (string $path): bool => str_contains($path, '.'))
            ->values()
            ->all();
        $publishedFields = collect(WebsiteDocumentSchema::PUBLISH_AREAS)->flatten()->all();

        $this->assertSame($draftFields, array_values(array_unique($draftFields)));
        $this->assertSame($publishedFields, array_values(array_unique($publishedFields)));
        $this->assertEqualsCanonicalizing($draftFields, $validatedFields);
        $this->assertEqualsCanonicalizing($draftFields, $publishedFields);
    }

    public function test_request_and_publish_services_share_the_same_validation_registry(): void
    {
        $this->assertEquals(
            WebsiteDocumentRules::all(),
            SaveSiteConfigDraftRequest::payloadRules(),
        );
    }

    public function test_every_editor_asset_reference_is_part_of_the_document_contract(): void
    {
        $draftFields = WebsiteDocumentSchema::draftFields();
        $rules = WebsiteDocumentRules::all();

        foreach (WebsiteEditorAssets::SLOTS as $slot) {
            foreach ([WebsiteEditorAssets::idField($slot), WebsiteEditorAssets::urlField($slot)] as $field) {
                $this->assertContains($field, $draftFields);
                $this->assertArrayHasKey('payload.'.$field, $rules);
            }
        }
    }

    public function test_defaults_only_describe_persisted_website_document_fields(): void
    {
        $this->assertEqualsCanonicalizing(
            WebsiteDocumentSchema::FIELDS,
            array_keys(WebsiteDocumentDefaults::draft()),
        );
    }

    #[DataProvider('defaultProfileProvider')]
    public function test_public_and_draft_default_profiles_are_explicit(
        callable $draft,
        callable $public,
        string $path,
        mixed $draftValue,
        mixed $publicValue,
    ): void {
        $this->assertSame($draftValue, data_get($draft(), $path));
        $this->assertSame($publicValue, data_get($public(), $path));
    }

    /** @return array<string, array{callable, callable, string, mixed, mixed}> */
    public static function defaultProfileProvider(): array
    {
        return [
            'footer tagline' => [
                WebsiteDocumentDefaults::draftFooter(...),
                WebsiteDocumentDefaults::publicFooter(...),
                'tagline',
                '',
                'Your trusted online shopping destination',
            ],
            'homepage statement eyebrow' => [
                WebsiteDocumentDefaults::draftHomepageStatement(...),
                WebsiteDocumentDefaults::publicHomepageStatement(...),
                'eyebrow',
                '',
                'Our Promise',
            ],
            'story heading' => [
                WebsiteDocumentDefaults::draftStoryPage(...),
                WebsiteDocumentDefaults::publicStoryPage(...),
                'hero.heading',
                '',
                'Our Story',
            ],
        ];
    }
}
