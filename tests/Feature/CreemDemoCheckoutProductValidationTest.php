<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\CreemDemo\Livewire\ConfigurationForm;
use Modules\CreemDemo\Livewire\ProductsList;
use Modules\CreemDemo\Livewire\SubscriptionsList;
use Tests\TestCase;

class CreemDemoCheckoutProductValidationTest extends TestCase
{
    public function test_one_time_checkout_rejects_product_ids_outside_the_authorized_list(): void
    {
        $this->configureDemoProfile();
        Http::fake([
            'https://test-api.creem.io/v1/products/search*' => Http::response([
                'items' => [
                    $this->product('prod_allowed', 'onetime'),
                    $this->product('prod_recurring', 'recurring'),
                ],
                'pagination' => ['total_records' => 2],
            ]),
            'https://test-api.creem.io/v1/checkouts' => Http::response([
                'checkout_url' => 'https://checkout.test/session',
            ], 201),
        ]);

        Livewire::test(ProductsList::class)
            ->call('buyProduct', 'prod_recurring')
            ->assertNoRedirect()
            ->assertSet('products', [$this->product('prod_allowed', 'onetime')]);

        Http::assertNotSent(fn (Request $request) => $this->isCheckoutCreateRequest($request));
    }

    public function test_subscription_checkout_rejects_product_ids_outside_the_authorized_plan_list(): void
    {
        $this->configureDemoProfile();
        Http::fake([
            'https://test-api.creem.io/v1/products/search*' => Http::response([
                'items' => [
                    $this->product('prod_onetime', 'onetime'),
                    $this->product('prod_plan', 'recurring'),
                ],
                'pagination' => ['total_records' => 2],
            ]),
            'https://test-api.creem.io/v1/checkouts' => Http::response([
                'checkout_url' => 'https://checkout.test/session',
            ], 201),
        ]);

        Livewire::test(SubscriptionsList::class)
            ->call('subscribe', 'prod_onetime')
            ->assertNotDispatched('open-url')
            ->assertSet('products', [$this->product('prod_plan', 'recurring')]);

        Http::assertNotSent(fn (Request $request) => $this->isCheckoutCreateRequest($request));
    }

    public function test_checkout_uses_the_server_authorized_product_id(): void
    {
        $this->configureDemoProfile();
        Http::fake([
            'https://test-api.creem.io/v1/products/search*' => Http::response([
                'items' => [$this->product('prod_allowed', 'onetime')],
                'pagination' => ['total_records' => 1],
            ]),
            'https://test-api.creem.io/v1/checkouts' => Http::response([
                'checkout_url' => 'https://checkout.test/session',
            ], 201),
        ]);

        Livewire::test(ProductsList::class)
            ->call('buyProduct', 'prod_allowed');

        Http::assertSent(fn (Request $request) => $this->isCheckoutCreateRequest($request)
            && $request['product_id'] === 'prod_allowed');
    }

    private function configureDemoProfile(): void
    {
        $this->startSession();

        cache()->put(ConfigurationForm::getCacheConfigKey(), [
            'default' => [
                'api_key' => 'creem_test_key',
                'webhook_secret' => '',
                'test_mode' => true,
                'cache_key' => 'test-cache-key',
                'webhook_url' => 'https://demo.test/creem/hook/test-cache-key',
            ],
        ], ConfigurationForm::CACHE_TTL);

        cache()->put(ConfigurationForm::getCacheActiveProfileKey(), 'default', ConfigurationForm::CACHE_TTL);
    }

    private function product(string $id, string $billingType): array
    {
        return [
            'id' => $id,
            'name' => 'Demo Product',
            'billing_type' => $billingType,
            'price' => 1000,
            'currency' => 'USD',
        ];
    }

    private function isCheckoutCreateRequest(Request $request): bool
    {
        return $request->method() === 'POST'
            && $request->url() === 'https://test-api.creem.io/v1/checkouts';
    }
}
