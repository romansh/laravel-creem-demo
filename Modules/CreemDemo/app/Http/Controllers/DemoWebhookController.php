<?php

namespace Modules\CreemDemo\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Romansh\LaravelCreem\Http\Controllers\WebhookController;
use Romansh\LaravelCreem\Http\Middleware\VerifyCreemWebhook;
use Modules\CreemDemo\Livewire\ConfigurationForm;

/**
 * Handles per-token keyed webhook endpoint for the demo UI.
 *
 * Route: POST /creem/hook/{token}
 *
 * Each profile saved in the demo UI gets a unique UUID token.
 * The token maps to a cache entry containing the profile's credentials.
 * This controller:
 *   1. Resolves the profile from cache by token
 *   2. Applies credentials to runtime config so package middleware/classes can read them
 *   3. Refreshes the cache TTL (keep-alive)
 *   4. Delegates signature verification and event dispatching to package classes
 */
class DemoWebhookController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        // 1. Resolve cached profile config
        $cacheKey = ConfigurationForm::CACHE_PREFIX . $token;
        $profile  = cache($cacheKey);

        if (!$profile) {
            return response()->json([
                'message' => 'Webhook session not found or expired. Re-save your profile in the demo UI to refresh it.',
            ], 404);
        }

        $profileName = $profile['profile_name'];

        // 2. Publish credentials to runtime config so VerifyCreemWebhook and the package can read them
        config([
            "creem.profiles.{$profileName}.api_key"       => $profile['api_key'],
            "creem.profiles.{$profileName}.webhook_secret" => $profile['webhook_secret'],
            "creem.profiles.{$profileName}.test_mode"      => $profile['test_mode'] ?? true,
            'creem.default_profile'                        => $profileName,
            'creem.demo_session_id'                        => $profile['session_id'] ?? null, // Pass session ID for data isolation
        ]);

        // 3. Refresh cache TTL so the session stays alive after each incoming webhook
        cache()->put($cacheKey, $profile, ConfigurationForm::CACHE_TTL);

        // Log incoming webhook for debugging
        \Log::info('Demo webhook received', [
            'token' => $token,
            'profile' => $profileName,
            'payload' => $request->all(),
            'content' => $request->getContent(),
        ]);

        // 4. Run signature verification via the package middleware, then delegate to its controller
        return app(VerifyCreemWebhook::class)->handle(
            $request,
            fn(Request $req) => app(WebhookController::class)($req),
            $profileName
        );
    }
}
