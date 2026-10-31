<?php

namespace App\Services\Copyleaks;

/**
 * The one place that decides what every scan asks Copyleaks for (AC-6).
 * Changing a value here changes what a paid scan returns.
 */
class ScanOptions
{
    public function __construct(
        private readonly CopyleaksSettings $settings,
        private readonly WebhookUrlBuilder $urls,
    ) {}

    /** @return array<string, mixed> the `properties` object of the submit body */
    public function for(string $scanId, bool $includeAi, bool $sandbox): array
    {
        return [
            'webhooks' => [
                'status' => $this->urls->statusUrl($scanId),
                'statusHeaders' => [['X-Webhook-Secret', $this->settings->webhookSecret()]],
            ],
            'sandbox' => $sandbox,
            'expiration' => (int) config('copyleaks.options.expiration_hours'),
            'developerPayload' => $scanId,
            'cheatDetection' => true,
            'aiGeneratedText' => [
                'detect' => $includeAi,
                'sensitivity' => (int) config('copyleaks.options.ai_sensitivity'),
                'explain' => ['enable' => false],
            ],
            'scanning' => [
                'internet' => true,
                'copyleaksDb' => [
                    'includeUserCollection' => false,
                    'includeSharedCollection' => false,
                ],
            ],
        ];
    }
}
