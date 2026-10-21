<?php

namespace Tests\Feature\Contracts;

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\ResolveAdminStore;
use App\Http\Middleware\ResolveStorefrontStore;
use App\Http\Middleware\SessionLifetimeMiddleware;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommerceRouteContractTest extends TestCase
{
    /**
     * @param  list<string>  $middleware
     */
    #[DataProvider('commerceRoutes')]
    public function test_commerce_routes_keep_their_public_contract(
        string $method,
        string $uri,
        array $middleware,
    ): void {
        $router = app(Router::class);
        $route = $router->getRoutes()->match(Request::create($uri, $method));
        $resolvedMiddleware = $router->gatherRouteMiddleware($route);

        $this->assertTrue($route->methods() === [$method] || $route->methods() === [$method, 'HEAD']);

        foreach ($middleware as $expectedMiddleware) {
            $this->assertContains($expectedMiddleware, $resolvedMiddleware);
        }
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function commerceRoutes(): array
    {
        $storefront = [
            ResolveStorefrontStore::class,
            ResolveAdminStore::class,
        ];
        $authenticatedAdmin = [
            Authenticate::class.':sanctum',
            SessionLifetimeMiddleware::class,
            EnsureAccountActive::class,
            ResolveAdminStore::class,
            AdminMiddleware::class,
        ];

        return [
            'create order' => ['POST', '/api/v1/orders', [
                ...$storefront,
                ThrottleRequests::class.':order-store',
            ]],
            'start redirect payment' => ['POST', '/api/v1/orders/123/pay', [
                ...$storefront,
                ThrottleRequests::class.':order-pay',
            ]],
            'start stripe payment' => ['POST', '/api/v1/orders/123/stripe/intent', [
                ...$storefront,
                ThrottleRequests::class.':stripe-intent',
            ]],
            'read payment status' => ['GET', '/api/v1/payments/123', [
                ...$storefront,
                ThrottleRequests::class.':payment-show',
            ]],
            'verify backorder token' => ['GET', '/api/v1/backorders/pay/token-value', [
                ...$storefront,
                ThrottleRequests::class.':backorder-token',
            ]],
            'pay backorder' => ['POST', '/api/v1/backorders/pay/token-value', [
                ...$storefront,
                ThrottleRequests::class.':order-pay',
            ]],
            'confirm backorder without payment' => ['POST', '/api/v1/backorders/pay/token-value/confirm', [
                ...$storefront,
                ThrottleRequests::class.':order-pay',
            ]],
            'track shipment' => ['GET', '/api/v1/tracking/SSU-TRACKING', [
                ...$storefront,
                ThrottleRequests::class.':tracking',
            ]],
            'read tracking barcode' => ['GET', '/api/v1/tracking/SSU-TRACKING/barcode', [
                ...$storefront,
                ThrottleRequests::class.':tracking',
            ]],
            'list orders' => ['GET', '/api/v1/orders', $authenticatedAdmin],
            'show order' => ['GET', '/api/v1/orders/123', $authenticatedAdmin],
            'update order status' => ['PATCH', '/api/v1/orders/123', $authenticatedAdmin],
            'confirm order payment' => ['POST', '/api/v1/orders/123/confirm-payment', $authenticatedAdmin],
            'undo order payment' => ['POST', '/api/v1/orders/123/undo-payment', $authenticatedAdmin],
            'cancel and refund order' => ['POST', '/api/v1/orders/123/cancel-refund', $authenticatedAdmin],
            'delete order' => ['DELETE', '/api/v1/orders/123', $authenticatedAdmin],
            'create shipment' => ['POST', '/api/v1/orders/123/ship', $authenticatedAdmin],
            'update shipment' => ['PATCH', '/api/v1/shipments/123', $authenticatedAdmin],
            'list backorders' => ['GET', '/api/v1/backorders', $authenticatedAdmin],
            'show backorder' => ['GET', '/api/v1/backorders/123', $authenticatedAdmin],
            'notify backorder' => ['POST', '/api/v1/backorders/123/notify', $authenticatedAdmin],
            'resend backorder notification' => ['POST', '/api/v1/backorders/123/resend', $authenticatedAdmin],
            'cancel backorder' => ['POST', '/api/v1/backorders/123/cancel', $authenticatedAdmin],
            'mark backorder paid' => ['POST', '/api/v1/backorders/123/mark-paid', $authenticatedAdmin],
        ];
    }
}
