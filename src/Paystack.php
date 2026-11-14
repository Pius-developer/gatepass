<?php
declare(strict_types=1);

/**
 * Paystack, the payment provider (cards, bank transfer, USSD in Nigeria).
 *
 * 1. initialize(): ask Paystack for a checkout page for this order, send the buyer there.
 * 2. Paystack sends the buyer back to /pay/callback?reference=...
 * 3. verify(): ask Paystack directly whether that reference was paid, and how much.
 *    Only this server-to-server answer is trusted, never the browser.
 * 4. The webhook (/paystack/webhook) does the same for buyers who close the tab
 *    before being sent back. Its signature is checked with the secret key.
 */
final class Paystack
{
    private const API = 'https://api.paystack.co';

    public function __construct(private string $secretKey) {}

    public static function fromConfig(): ?self
    {
        $key = (string) (config('paystack')['secret_key'] ?? '');
        return $key !== '' ? new self($key) : null;
    }

    public function isTestMode(): bool
    {
        return str_starts_with($this->secretKey, 'sk_test_');
    }

    /** @return string the URL of Paystack's checkout page */
    public function initialize(string $email, int $amountKobo, string $reference, string $callbackUrl, array $metadata = []): string
    {
        $r = $this->request('POST', '/transaction/initialize', [
            'email'        => $email,
            'amount'       => $amountKobo,
            'currency'     => 'NGN',
            'reference'    => $reference,
            'callback_url' => $callbackUrl,
            'metadata'     => $metadata,
        ]);
        if (empty($r['status']) || empty($r['data']['authorization_url'])) {
            throw new RuntimeException('Paystack: ' . ($r['message'] ?? 'could not start the payment'));
        }
        return $r['data']['authorization_url'];
    }

    /** @return array{paid: bool, amount: int, currency: string} */
    public function verify(string $reference): array
    {
        $r = $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
        $d = $r['data'] ?? [];
        return [
            'paid'     => !empty($r['status']) && ($d['status'] ?? '') === 'success' && ($d['reference'] ?? '') === $reference,
            'amount'   => (int) ($d['amount'] ?? 0),
            'currency' => (string) ($d['currency'] ?? ''),
        ];
    }

    /** Paystack signs each webhook with HMAC-SHA512 of the raw body using your secret key. */
    public function webhookIsGenuine(string $rawBody, string $signature): bool
    {
        return $signature !== '' && hash_equals(hash_hmac('sha512', $rawBody, $this->secretKey), $signature);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Could not reach Paystack: ' . $err);
        }
        return json_decode((string) $raw, true) ?: [];
    }
}
