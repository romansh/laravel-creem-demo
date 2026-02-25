<?php

namespace Modules\CreemDemo\Listeners;

use Romansh\LaravelCreem\Events\CheckoutCompleted;
use Romansh\LaravelCreem\Events\DisputeCreated;
use Romansh\LaravelCreem\Events\GrantAccess;
use Romansh\LaravelCreem\Events\RefundCreated;
use Romansh\LaravelCreem\Events\RevokeAccess;
use Romansh\LaravelCreem\Events\SubscriptionActive;
use Romansh\LaravelCreem\Events\SubscriptionCanceled;
use Romansh\LaravelCreem\Events\SubscriptionExpired;
use Romansh\LaravelCreem\Events\SubscriptionPaid;
use Romansh\LaravelCreem\Events\SubscriptionPaused;

/**
 * Creem Demo Event Listener.
 *
 * Listens to ALL events dispatched by romansh/laravel-creem and writes
 * them into the PHP session for display in the demo dashboard.
 *
 * This module is a pure OBSERVER — it owns no webhook endpoint.
 * The package's own WebhookController handles receipt and dispatching.
 *
 * Registered in CreemDemoServiceProvider::registerEventListeners().
 */
class CreemDemoListener
{
    private const MAX_WEBHOOK_LOGS = 20;

    // ── CreemEvent subclasses ──────────────────────────────────
    // All have: $event->eventType, $event->object, $event->payload

    public function onCheckoutCompleted(CheckoutCompleted $event): void
    {
        $this->pushWebhookLog($event->eventType, $event->payload);

        // Capture license key from checkout payload if present
        $licenseKey = $event->object['license_key']['key']
            ?? $event->object['license_key']
            ?? null;

        if ($licenseKey) {
            $customer = $event->object['customer'] ?? [];
            $product  = $event->object['product']  ?? [];

            $licenses   = session('demo.captured_licenses', []);
            $licenses[] = [
                'key'          => $licenseKey,
                'email'        => $customer['email'] ?? '?',
                'product_name' => $product['name']   ?? '?',
                'captured_at'  => now()->toDateTimeString(),
                'instance_id'  => null,
                'status'       => 'not_activated',
            ];
            session(['demo.captured_licenses' => $licenses]);
        }

        // Capture subscription_id for real management actions
        $subscriptionId = $event->object['subscription']['id'] ?? null;
        if ($subscriptionId) {
            $this->pushSubscriptionRecord($event->object, $subscriptionId, 'active');
        }
    }

    public function onSubscriptionActive(SubscriptionActive $event): void
    {
        $this->pushWebhookLog($event->eventType, $event->payload);

        $subscriptionId = $event->object['subscription']['id']
            ?? $event->object['id']
            ?? null;

        if ($subscriptionId) {
            $this->pushSubscriptionRecord($event->object, $subscriptionId, 'active');
        }
    }

    public function onSubscriptionPaid(SubscriptionPaid $event): void
    {
        $this->pushWebhookLog($event->eventType, $event->payload);
    }

    public function onSubscriptionCanceled(SubscriptionCanceled $event): void
    {
        $this->pushWebhookLog($event->eventType, $event->payload);
        $this->updateSubscriptionStatus(
            $event->object['subscription']['id'] ?? $event->object['id'] ?? null,
            'cancelled'
        );
    }

    public function onSubscriptionExpired(SubscriptionExpired $event): void
    {
        $this->pushWebhookLog($event->eventType, $event->payload);
        $this->updateSubscriptionStatus(
            $event->object['subscription']['id'] ?? $event->object['id'] ?? null,
            'expired'
        );
    }

    public function onSubscriptionPaused(SubscriptionPaused $event): void
    {
        $this->pushWebhookLog($event->eventType, $event->payload);
        $this->updateSubscriptionStatus(
            $event->object['subscription']['id'] ?? $event->object['id'] ?? null,
            'paused'
        );
    }

    public function onRefundCreated(RefundCreated $event): void
    {
        $this->pushWebhookLog($event->eventType, $event->payload);
    }

    public function onDisputeCreated(DisputeCreated $event): void
    {
        $this->pushWebhookLog($event->eventType, $event->payload);
    }

    // ── GrantAccess / RevokeAccess ─────────────────────────────
    // Constructor: dispatch($customer, $metadata, $rawPayload)
    // These are NOT CreemEvent subclasses — they have their own shape.

    public function onGrantAccess(GrantAccess $event): void
    {
        $profile  = config('creem.default_profile', 'default');
        $cKey     = "demo_accesses_{$profile}";
        $accesses = cache()->get($cKey, []);

        // Prefer email, fall back to customer id or metadata.internal_customer_id
        $display = $event->customer['email'] ?? $event->customer['id'] ?? $event->metadata['internal_customer_id'] ?? 'Unknown';

        $accesses[] = [
            'email'      => $display,
            'product_id' => $event->metadata['product_id'] ?? '?',
            'status'     => 'granted',
            'at'         => now()->toDateTimeString(),
            'metadata'   => $event->metadata,
        ];

        cache()->put($cKey, $accesses, 3600);
    }

    public function onRevokeAccess(RevokeAccess $event): void
    {
        $profile  = config('creem.default_profile', 'default');
        $cKey     = "demo_accesses_{$profile}";
        $accesses = cache()->get($cKey, []);

        // Use the same matching logic as when creating the entry
        $incoming = $event->customer['email'] ?? $event->customer['id'] ?? $event->metadata['internal_customer_id'] ?? '';

        foreach (array_reverse(array_keys($accesses)) as $i) {
            if (
                ($accesses[$i]['email'] ?? '') === $incoming &&
                $accesses[$i]['status'] === 'granted'
            ) {
                $accesses[$i]['status']     = 'revoked';
                $accesses[$i]['revoked_at'] = now()->toDateTimeString();
                break;
            }
        }

        cache()->put($cKey, $accesses, 3600);
    }

    // ── Helpers ────────────────────────────────────────────────

    private function pushWebhookLog(string $eventType, array $payload): void
    {
        // Write into per-profile CACHE key (not session!) because webhook
        // requests arrive from Creem servers without the browser session.
        // The current runtime profile is published into
        // config('creem.default_profile') by the DemoWebhookController.
        $entry = [
            'event'      => $eventType,
            'payload'    => $payload,
            'created_at' => now()->toDateTimeString(),
        ];

        $profile = config('creem.default_profile', 'default');
        $cKey = "demo_webhooks_{$profile}";
        $logs = cache()->get($cKey, []);
        $logs[] = $entry;
        if (count($logs) > self::MAX_WEBHOOK_LOGS) {
            $logs = array_slice($logs, -self::MAX_WEBHOOK_LOGS);
        }
        cache()->put($cKey, $logs, 3600); // keep for 1 hour
    }

    private function pushSubscriptionRecord(array $object, string $subscriptionId, string $status = 'active'): void
    {
        $subs = session('demo_subscriptions', []);

        foreach ($subs as &$sub) {
            if ($sub['subscription_id'] === $subscriptionId) {
                $sub['status'] = $status;
                session(['demo_subscriptions' => $subs]);
                return;
            }
        }
        unset($sub);

        $customer = $object['customer'] ?? $object['subscription']['customer'] ?? [];
        $product  = $object['product']  ?? [];

        $subs[] = [
            'subscription_id' => $subscriptionId,
            'email'           => $customer['email'] ?? '?',
            'product_name'    => $product['name']   ?? '?',
            'product_id'      => $product['id']     ?? '?',
            'status'          => $status,
            'created_at'      => now()->toDateTimeString(),
        ];

        session(['demo_subscriptions' => $subs]);
    }

    private function updateSubscriptionStatus(?string $subscriptionId, string $status): void
    {
        if (!$subscriptionId) return;

        $subs = session('demo_subscriptions', []);
        foreach ($subs as &$sub) {
            if ($sub['subscription_id'] === $subscriptionId) {
                $sub['status'] = $status;
                break;
            }
        }
        unset($sub);
        session(['demo_subscriptions' => $subs]);
    }
}
