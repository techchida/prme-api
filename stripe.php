<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function prime_create_stripe_checkout_session(array $input): array
{
    $cfg = prime_stripe_config();
    if (!$cfg['enabled']) {
        throw new RuntimeException('Stripe is not enabled.');
    }
    if ($cfg['secret_key'] === '') {
        throw new RuntimeException('Missing STRIPE_SECRET_KEY.');
    }

    $amount = $input['amount'] ?? null;
    if (!is_numeric($amount)) {
        throw new InvalidArgumentException('Amount is required.');
    }

    $amountFloat = (float)$amount;
    if ($amountFloat <= 0) {
        throw new InvalidArgumentException('Amount must be greater than 0.');
    }

    $unitAmount = (int)round($amountFloat * 100);
    if ($unitAmount < 50) {
        throw new InvalidArgumentException('Minimum Stripe amount is $0.50.');
    }

    $frontendUrl = $cfg['frontend_url'] !== '' ? $cfg['frontend_url'] : 'http://127.0.0.1:3000';
    $successUrl = $cfg['success_url'] !== ''
        ? $cfg['success_url']
        : ($frontendUrl . '?payment=success&session_id={CHECKOUT_SESSION_ID}#giving');
    $cancelUrl = $cfg['cancel_url'] !== ''
        ? $cfg['cancel_url']
        : ($frontendUrl . '?payment=cancelled#giving');

    $email = trim((string)($input['email'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';
    }

    $payload = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'payment_method_types[0]' => 'card',
        'line_items[0][quantity]' => '1',
        'line_items[0][price_data][currency]' => $cfg['currency'],
        'line_items[0][price_data][unit_amount]' => (string)$unitAmount,
        'line_items[0][price_data][product_data][name]' => 'PRIME Giving',
        'line_items[0][price_data][product_data][description]' => 'Online giving via PRIME portal',
        'metadata[source]' => 'prime-web-give-modal',
        'metadata[amount_display]' => number_format($amountFloat, 2, '.', ''),
    ];

    if ($name !== '') {
        $payload['metadata[donor_name]'] = $name;
    }
    if ($email !== '') {
        $payload['metadata[donor_email]'] = $email;
    }

    if ($email !== '') {
        $payload['customer_email'] = $email;
    }

    if ($cfg['statement_descriptor'] !== '') {
        $payload['payment_intent_data[description]'] = $cfg['statement_descriptor'];
    }

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $cfg['secret_key'],
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Stripe request failed: ' . $err);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid Stripe response.');
    }

    if ($status >= 400) {
        $msg = $decoded['error']['message'] ?? 'Stripe error';
        throw new RuntimeException((string)$msg);
    }

    if (empty($decoded['url']) || !is_string($decoded['url'])) {
        throw new RuntimeException('Stripe Checkout URL not returned.');
    }

    return [
        'id' => (string)($decoded['id'] ?? ''),
        'payment_intent_id' => isset($decoded['payment_intent']) && is_string($decoded['payment_intent']) ? $decoded['payment_intent'] : null,
        'url' => $decoded['url'],
        'currency' => (string)($decoded['currency'] ?? $cfg['currency']),
        'amount_total' => (int)($decoded['amount_total'] ?? $unitAmount),
        'checkout_status' => (string)($decoded['status'] ?? 'open'),
        'payment_status' => (string)($decoded['payment_status'] ?? 'unpaid'),
        'metadata' => is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [],
    ];
}

function prime_verify_stripe_webhook_signature(string $payload, string $signatureHeader, string $secret, int $toleranceSeconds = 300): bool
{
    if ($secret === '') {
        throw new RuntimeException('Missing STRIPE_WEBHOOK_SECRET.');
    }

    $parts = [];
    foreach (explode(',', $signatureHeader) as $segment) {
        $kv = explode('=', trim($segment), 2);
        if (count($kv) === 2) {
            $parts[$kv[0]][] = $kv[1];
        }
    }

    $timestamp = isset($parts['t'][0]) ? (int)$parts['t'][0] : 0;
    $signatures = $parts['v1'] ?? [];
    if ($timestamp <= 0 || $signatures === []) {
        return false;
    }

    if (abs(time() - $timestamp) > $toleranceSeconds) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}

function prime_retrieve_stripe_checkout_session(string $sessionId): array
{
    $cfg = prime_stripe_config();
    if (!$cfg['enabled']) {
        throw new RuntimeException('Stripe is not enabled.');
    }
    if ($cfg['secret_key'] === '') {
        throw new RuntimeException('Missing STRIPE_SECRET_KEY.');
    }
    if ($sessionId === '') {
        throw new InvalidArgumentException('Missing session_id.');
    }

    $url = 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId) . '?' . http_build_query([
        'expand' => ['payment_intent'],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $cfg['secret_key'],
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Stripe request failed: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid Stripe response.');
    }
    if ($status >= 400) {
        $msg = $decoded['error']['message'] ?? 'Stripe error';
        throw new RuntimeException((string)$msg);
    }

    return $decoded;
}
