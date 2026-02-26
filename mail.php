<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function prime_send_registration_confirmation_email(array $registration): bool
{
    $cfg = prime_mail_config();
    if (!$cfg['enabled']) {
        return false;
    }

    if ($cfg['host'] === '' || $cfg['from_email'] === '') {
        throw new RuntimeException('SMTP is enabled but SMTP_HOST or SMTP_FROM_EMAIL is missing.');
    }

    $toEmail = trim((string)($registration['email'] ?? ''));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Recipient email is missing or invalid.');
    }

    $displayName = trim(sprintf(
        '%s %s',
        (string)($registration['title'] ?? ''),
        (string)($registration['minister_name'] ?? '')
    ));

    $subject = 'PRIME Registration Received - Next Steps & Training Resources';
    $frontendUrl = $cfg['frontend_url'] !== '' ? $cfg['frontend_url'] : '#';
    $trainingUrl = $cfg['training_url'] !== ''
        ? $cfg['training_url']
        : (
            $cfg['resources_url'] !== ''
                ? $cfg['resources_url']
                : ($cfg['frontend_url'] !== '' ? ($cfg['frontend_url'] . '#training') : '#')
        );

    $safeName = htmlspecialchars($displayName !== '' ? $displayName : 'Minister', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCategory = htmlspecialchars((string)($registration['category'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeConferenceType = htmlspecialchars((string)($registration['conference_type'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLocation = htmlspecialchars(trim(sprintf(
        '%s, %s, %s',
        (string)($registration['city'] ?? ''),
        (string)($registration['state'] ?? ''),
        (string)($registration['country'] ?? '')
    ), ', '), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PRIME Registration Confirmation</title>
</head>
<body style="margin:0;padding:0;background:#070b14;font-family:Segoe UI,Arial,sans-serif;color:#e5e7eb;">
  <div style="padding:32px 16px;background:radial-gradient(circle at top,#1a2438 0%,#070b14 60%);">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:680px;margin:0 auto;">
      <tr>
        <td style="padding:0 0 18px 0;text-align:center;">
          <div style="display:inline-block;padding:8px 14px;border-radius:999px;background:#f59e0b1a;border:1px solid #f59e0b40;color:#fbbf24;font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;">
            PRIME Conference Registration
          </div>
        </td>
      </tr>
      <tr>
        <td style="background:#0f172acc;border:1px solid #ffffff14;border-radius:24px;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,0.35);">
          <div style="padding:32px 28px 20px;background:linear-gradient(135deg,#111827 0%,#0b1220 100%);border-bottom:1px solid #ffffff0f;">
            <div style="font-size:28px;line-height:1.2;font-weight:800;color:#ffffff;margin:0 0 8px;">
              Registration Received
            </div>
            <div style="font-size:15px;line-height:1.6;color:#cbd5e1;">
              Thank you, {$safeName}. Your request to host a PRIME conference has been received successfully.
            </div>
          </div>

          <div style="padding:24px 28px 10px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;border-spacing:0 10px;">
              <tr>
                <td style="width:42%;padding:12px 14px;border-radius:12px;background:#ffffff05;color:#94a3b8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Conference Category</td>
                <td style="padding:12px 14px;border-radius:12px;background:#ffffff03;color:#e2e8f0;font-size:14px;">{$safeCategory}</td>
              </tr>
              <tr>
                <td style="padding:12px 14px;border-radius:12px;background:#ffffff05;color:#94a3b8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Conference Type</td>
                <td style="padding:12px 14px;border-radius:12px;background:#ffffff03;color:#e2e8f0;font-size:14px;">{$safeConferenceType}</td>
              </tr>
              <tr>
                <td style="padding:12px 14px;border-radius:12px;background:#ffffff05;color:#94a3b8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Proposed Location</td>
                <td style="padding:12px 14px;border-radius:12px;background:#ffffff03;color:#e2e8f0;font-size:14px;">{$safeLocation}</td>
              </tr>
            </table>
          </div>

          <div style="padding:18px 28px 8px;">
            <div style="padding:18px;border-radius:16px;background:linear-gradient(135deg,#0ea5e91a,#f59e0b14);border:1px solid #38bdf826;">
              <div style="font-size:15px;font-weight:700;color:#ffffff;margin:0 0 8px;">What happens next?</div>
              <div style="font-size:14px;line-height:1.6;color:#cbd5e1;">
                A PRIME coordinator will review your submission and contact you with next steps, guidance, and planning support.
              </div>
            </div>
          </div>

          <div style="padding:18px 28px 8px;">
            <div style="padding:20px;border-radius:16px;background:#f59e0b12;border:1px solid #f59e0b33;">
              <div style="font-size:16px;font-weight:800;color:#fbbf24;margin:0 0 8px;">Prepare early in the Training Section</div>
              <div style="font-size:14px;line-height:1.6;color:#e5e7eb;margin:0 0 14px;">
                While your registration is being reviewed, visit the Training section in the PRIME frontend to access materials, guides, and media that can help you plan effectively.
              </div>
              <a href="{$trainingUrl}" style="display:inline-block;padding:12px 18px;border-radius:12px;background:#f59e0b;color:#111827;text-decoration:none;font-weight:800;font-size:13px;letter-spacing:.04em;">
                Open Training Section
              </a>
              <div style="font-size:12px;line-height:1.5;color:#cbd5e1;margin-top:10px;">
                This link opens the frontend Training tab directly when hash links are supported.
              </div>
            </div>
          </div>

          <div style="padding:18px 28px 28px;">
            <a href="{$frontendUrl}" style="display:inline-block;padding:12px 18px;border-radius:12px;border:1px solid #ffffff1f;color:#ffffff;text-decoration:none;font-weight:700;font-size:13px;">
              Return to PRIME Portal
            </a>
          </div>
        </td>
      </tr>
      <tr>
        <td style="padding:14px 8px 0;text-align:center;color:#94a3b8;font-size:12px;line-height:1.6;">
          PRIME Global Impact Team
        </td>
      </tr>
    </table>
  </div>
</body>
</html>
HTML;

    $text = "PRIME Registration Received\n\n"
        . "Dear " . ($displayName !== '' ? $displayName : 'Minister') . ",\n\n"
        . "Your PRIME conference registration has been received successfully.\n"
        . "A PRIME coordinator will contact you with next steps.\n\n"
        . "Resources:\n"
        . ($trainingUrl !== '#' ? $trainingUrl : 'Visit the PRIME portal and open the Training section.') . "\n\n"
        . "PRIME Global Impact Team\n";

    return prime_smtp_send_mail([
        'to_email' => $toEmail,
        'to_name' => $displayName !== '' ? $displayName : 'Minister',
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ], $cfg);
}

function prime_smtp_send_mail(array $message, array $cfg): bool
{
    $secure = $cfg['secure'] ?? 'tls';
    $host = (string)$cfg['host'];
    $port = (int)$cfg['port'];
    $timeout = (int)$cfg['timeout'];
    $transport = $secure === 'ssl' ? 'ssl://' : '';

    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        max(1, $timeout),
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
    }

    stream_set_timeout($socket, max(1, $timeout));

    try {
        prime_smtp_expect($socket, [220]);
        $serverName = gethostname() ?: 'localhost';
        prime_smtp_cmd($socket, "EHLO {$serverName}", [250]);

        if ($secure === 'tls') {
            prime_smtp_cmd($socket, 'STARTTLS', [220]);
            $cryptoOk = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoOk !== true) {
                throw new RuntimeException('Failed to enable STARTTLS.');
            }
            prime_smtp_cmd($socket, "EHLO {$serverName}", [250]);
        }

        if (($cfg['auth'] ?? true) === true) {
            prime_smtp_cmd($socket, 'AUTH LOGIN', [334]);
            prime_smtp_cmd($socket, base64_encode((string)$cfg['username']), [334]);
            prime_smtp_cmd($socket, base64_encode((string)$cfg['password']), [235]);
        }

        $fromEmail = (string)$cfg['from_email'];
        prime_smtp_cmd($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        prime_smtp_cmd($socket, 'RCPT TO:<' . (string)$message['to_email'] . '>', [250, 251]);
        prime_smtp_cmd($socket, 'DATA', [354]);

        $rawMessage = prime_build_mime_message($message, $cfg);
        $rawMessage = preg_replace("/(?<!\r)\n/", "\r\n", $rawMessage) ?? $rawMessage;
        // Dot-stuffing for SMTP DATA.
        $rawMessage = preg_replace('/^\./m', '..', $rawMessage) ?? $rawMessage;
        fwrite($socket, $rawMessage . "\r\n.\r\n");
        prime_smtp_expect($socket, [250]);

        prime_smtp_cmd($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        fclose($socket);
        throw $e;
    }
}

function prime_build_mime_message(array $message, array $cfg): string
{
    $boundary = '=_prime_' . bin2hex(random_bytes(8));
    $from = prime_format_address((string)$cfg['from_email'], (string)($cfg['from_name'] ?? ''));
    $to = prime_format_address((string)$message['to_email'], (string)($message['to_name'] ?? ''));
    $subject = prime_mime_header((string)$message['subject']);

    $headers = [
        "Date: " . gmdate('D, d M Y H:i:s O'),
        "From: {$from}",
        "To: {$to}",
        "Subject: {$subject}",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
    ];

    if (!empty($cfg['reply_to'])) {
        $headers[] = 'Reply-To: ' . (string)$cfg['reply_to'];
    }

    $textBody = chunk_split(base64_encode((string)$message['text']));
    $htmlBody = chunk_split(base64_encode((string)$message['html']));

    return implode("\r\n", $headers) . "\r\n\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . $textBody . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . $htmlBody . "\r\n"
        . "--{$boundary}--\r\n";
}

function prime_mime_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function prime_format_address(string $email, string $name = ''): string
{
    $email = trim($email);
    $name = trim($name);
    if ($name === '') {
        return "<{$email}>";
    }

    return prime_mime_header($name) . " <{$email}>";
}

function prime_smtp_cmd($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    return prime_smtp_expect($socket, $expectedCodes);
}

function prime_smtp_expect($socket, array $expectedCodes): string
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;

        if (preg_match('/^(\d{3})([ -])/', $line, $m) === 1 && $m[2] === ' ') {
            $code = (int)$m[1];
            if (!in_array($code, $expectedCodes, true)) {
                throw new RuntimeException("Unexpected SMTP response {$code}: " . trim($response));
            }
            return $response;
        }
    }

    throw new RuntimeException('No SMTP response received.');
}
