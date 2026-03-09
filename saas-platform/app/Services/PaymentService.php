<?php

declare(strict_types=1);

namespace Saas\Services;

use Saas\Core\Config;

/**
 * Handles Stripe and PayPal payment processing for Marketplace purchases.
 *
 * Required .env keys:
 *   STRIPE_SECRET_KEY, STRIPE_PUBLISHABLE_KEY, STRIPE_WEBHOOK_SECRET
 *   PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_MODE (sandbox|live)
 */
class PaymentService
{
    public function __construct(private Config $config) {}

    // ──────────────────────────────────────────────────────────────────────
    // Stripe
    // ──────────────────────────────────────────────────────────────────────

    public function stripeEnabled(): bool
    {
        return (bool)$this->config->get('payment.stripe_secret');
    }

    public function paypalEnabled(): bool
    {
        return (bool)$this->config->get('payment.paypal_client_id');
    }

    /**
     * Create a Stripe Checkout Session for a one-time plugin purchase.
     * Returns the checkout URL to redirect the user to.
     */
    public function createStripeCheckout(
        float  $amount,
        string $currency,
        string $pluginName,
        string $successUrl,
        string $cancelUrl,
        array  $metadata = []
    ): string {
        $secretKey = $this->config->get('payment.stripe_secret');

        $payload = [
            'payment_method_types' => ['card'],
            'mode'                 => 'payment',
            'line_items'           => [[
                'price_data' => [
                    'currency'     => strtolower($currency),
                    'unit_amount'  => (int)round($amount * 100),
                    'product_data' => ['name' => $pluginName],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'metadata'    => $metadata,
        ];

        $response = $this->stripeRequest('POST', 'checkout/sessions', $payload, $secretKey);

        if (!isset($response['url'])) {
            throw new \RuntimeException('Stripe Checkout konnte nicht erstellt werden: ' . ($response['error']['message'] ?? 'Unbekannter Fehler'));
        }

        return $response['url'];
    }

    /**
     * Verify a Stripe webhook signature and return the event array.
     */
    public function verifyStripeWebhook(string $payload, string $sigHeader): array
    {
        $secret = $this->config->get('payment.stripe_webhook_secret');
        if (!$secret) {
            throw new \RuntimeException('Stripe Webhook Secret nicht konfiguriert');
        }

        $parts     = explode(',', $sigHeader);
        $timestamp = null;
        $signatures = [];

        foreach ($parts as $part) {
            [$key, $val] = explode('=', trim($part), 2);
            if ($key === 't') $timestamp = $val;
            if ($key === 'v1') $signatures[] = $val;
        }

        if (!$timestamp || empty($signatures)) {
            throw new \RuntimeException('Ungültige Stripe Webhook Signatur');
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected      = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return json_decode($payload, true);
            }
        }

        throw new \RuntimeException('Stripe Webhook Signatur ungültig');
    }

    /**
     * Retrieve a Stripe Checkout Session by ID.
     */
    public function getStripeSession(string $sessionId): array
    {
        $secretKey = $this->config->get('payment.stripe_secret');
        return $this->stripeRequest('GET', "checkout/sessions/{$sessionId}", [], $secretKey);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PayPal
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create a PayPal Order and return the approval URL.
     */
    public function createPayPalOrder(
        float  $amount,
        string $currency,
        string $description,
        string $returnUrl,
        string $cancelUrl,
        array  $customId = []
    ): array {
        $token = $this->getPayPalAccessToken();
        $mode  = $this->config->get('payment.paypal_mode', 'sandbox');
        $base  = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        $payload = [
            'intent'         => 'CAPTURE',
            'purchase_units' => [[
                'amount'      => [
                    'currency_code' => strtoupper($currency),
                    'value'         => number_format($amount, 2, '.', ''),
                ],
                'description' => $description,
                'custom_id'   => json_encode($customId),
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'brand_name' => $this->config->get('app.name', 'Tierphysio SaaS'),
                'user_action' => 'PAY_NOW',
            ],
        ];

        $ch = curl_init($base . '/v2/checkout/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!isset($response['id'])) {
            throw new \RuntimeException('PayPal Order konnte nicht erstellt werden: ' . ($response['message'] ?? 'Unbekannter Fehler'));
        }

        $approvalUrl = '';
        foreach ($response['links'] as $link) {
            if ($link['rel'] === 'approve') {
                $approvalUrl = $link['href'];
                break;
            }
        }

        return ['order_id' => $response['id'], 'approval_url' => $approvalUrl];
    }

    /**
     * Capture a PayPal Order after user approval.
     */
    public function capturePayPalOrder(string $orderId): array
    {
        $token = $this->getPayPalAccessToken();
        $mode  = $this->config->get('payment.paypal_mode', 'sandbox');
        $base  = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        $ch = curl_init($base . "/v2/checkout/orders/{$orderId}/capture");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (($response['status'] ?? '') !== 'COMPLETED') {
            throw new \RuntimeException('PayPal Zahlung konnte nicht abgeschlossen werden');
        }

        return $response;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function stripeRequest(string $method, string $endpoint, array $data, string $secretKey): array
    {
        $url = 'https://api.stripe.com/v1/' . $endpoint;
        $ch  = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $this->flattenForStripe($data);
        }

        curl_setopt_array($ch, $options);
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return $result ?? [];
    }

    private function flattenForStripe(array $data, string $prefix = ''): string
    {
        $parts = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}[{$key}]" : $key;
            if (is_array($value)) {
                $parts[] = $this->flattenForStripe($value, $fullKey);
            } else {
                $parts[] = urlencode($fullKey) . '=' . urlencode((string)$value);
            }
        }
        return implode('&', $parts);
    }

    private function getPayPalAccessToken(): string
    {
        $clientId     = $this->config->get('payment.paypal_client_id');
        $clientSecret = $this->config->get('payment.paypal_client_secret');
        $mode         = $this->config->get('payment.paypal_mode', 'sandbox');
        $base         = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        $ch = curl_init($base . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $clientId . ':' . $clientSecret,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!isset($response['access_token'])) {
            throw new \RuntimeException('PayPal Authentifizierung fehlgeschlagen');
        }

        return $response['access_token'];
    }
}
