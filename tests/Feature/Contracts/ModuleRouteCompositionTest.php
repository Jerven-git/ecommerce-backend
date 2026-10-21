<?php

namespace Tests\Feature\Contracts;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModuleRouteCompositionTest extends TestCase
{
    private const NON_SITE_CONFIG_ROUTE_FINGERPRINT = '0d152716ebe862d05f71c50b69d4dec96a7e8dd74f32aff4a963b8380e28a727';

    #[DataProvider('moduleRouteFragments')]
    public function test_the_api_composition_root_wires_each_module_route_fragment(string $fragment): void
    {
        $compositionRoot = file_get_contents(base_path('routes/api.php'));

        $this->assertIsString($compositionRoot);
        $this->assertStringContainsString("app/Modules/{$fragment}", $compositionRoot);
        $this->assertFileExists(base_path("app/Modules/{$fragment}"));
    }

    public function test_the_non_site_config_api_contract_is_locked(): void
    {
        Artisan::call('route:list', ['--json' => true]);

        $routes = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $routes = array_values(array_filter(
            $routes,
            fn (array $route): bool => str_starts_with($route['uri'], 'api/')
                && ! str_starts_with($route['uri'], 'api/v1/site-config'),
        ));

        $this->assertCount(168, $routes);
        $this->assertSame(
            self::NON_SITE_CONFIG_ROUTE_FINGERPRINT,
            hash('sha256', json_encode($routes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'A non-SiteConfig API method, URI, action, middleware stack, constraint, or route name changed.',
        );

        $signatures = array_map(
            fn (array $route): string => $route['method'].' '.$route['uri'],
            $routes,
        );

        $this->assertCount(count($signatures), array_unique($signatures), 'Duplicate API route signatures were registered.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function moduleRouteFragments(): array
    {
        return [
            'identity passwords' => ['Identity/Routes/passwords.php'],
            'identity public' => ['Identity/Routes/public.php'],
            'identity authenticated' => ['Identity/Routes/authenticated.php'],
            'control plane public' => ['ControlPlane/Routes/public.php'],
            'control plane authenticated' => ['ControlPlane/Routes/authenticated.php'],
            'subscriptions public' => ['Subscriptions/Routes/public.php'],
            'subscriptions webhooks' => ['Subscriptions/Routes/webhooks.php'],
            'subscriptions authenticated' => ['Subscriptions/Routes/authenticated.php'],
            'catalog storefront' => ['Catalog/Routes/storefront.php'],
            'catalog admin' => ['Catalog/Routes/admin.php'],
            'commerce settings storefront' => ['CommerceSettings/Routes/storefront.php'],
            'commerce settings admin' => ['CommerceSettings/Routes/admin.php'],
            'editorial storefront' => ['Editorial/Routes/storefront.php'],
            'editorial admin' => ['Editorial/Routes/admin.php'],
            'engagement storefront' => ['Engagement/Routes/storefront.php'],
            'engagement admin' => ['Engagement/Routes/admin.php'],
            'orders storefront' => ['Orders/Routes/storefront.php'],
            'orders admin' => ['Orders/Routes/admin.php'],
            'payments storefront' => ['Payments/Routes/storefront.php'],
            'payments webhooks' => ['Payments/Routes/webhooks.php'],
            'backorders storefront' => ['Backorders/Routes/storefront.php'],
            'backorders admin' => ['Backorders/Routes/admin.php'],
            'shipments storefront' => ['Shipments/Routes/storefront.php'],
            'shipments admin' => ['Shipments/Routes/admin.php'],
            'media admin' => ['Media/Routes/admin.php'],
        ];
    }
}
