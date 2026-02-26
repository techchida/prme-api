<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/stripe.php';

if (PHP_SAPI === 'cli-server') {
    $requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $fullPath = __DIR__ . $requestedPath;
    if ($requestedPath !== '/' && is_file($fullPath)) {
        return false;
    }
}

sendCorsHeaders();
prime_admin_session_start();

header('Content-Type: application/json; charset=utf-8');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $pdo = prime_pdo();
} catch (Throwable $e) {
    error_log('[prime-api] DB connection failed: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Database connection failed. Check api/.env MySQL credentials.'], 500);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = normalizePath(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

route($pdo, $method, $path);

function route(PDO $pdo, string $method, string $path): void
{
    if ($method === 'GET' && $path === '/admin/auth/me') {
        if (!prime_admin_is_authenticated()) {
            respond(['success' => false, 'authenticated' => false], 401);
        }
        respond([
            'success' => true,
            'authenticated' => true,
            'user' => [
                'username' => (string)($_SESSION['prime_admin_username'] ?? 'admin'),
            ],
        ]);
    }

    if ($method === 'POST' && $path === '/admin/auth/login') {
        $payload = requireJsonBody();
        $username = trim((string)($payload['username'] ?? ''));
        $password = (string)($payload['password'] ?? '');

        if (!prime_admin_verify_credentials($username, $password)) {
            respond(['success' => false, 'message' => 'Invalid admin credentials.'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['prime_admin_authenticated'] = true;
        $_SESSION['prime_admin_username'] = $username;
        respond([
            'success' => true,
            'authenticated' => true,
            'user' => ['username' => $username],
        ]);
    }

    if ($method === 'POST' && $path === '/admin/auth/logout') {
        prime_admin_logout();
        respond(['success' => true]);
    }

    if (str_starts_with($path, '/admin/') && !str_starts_with($path, '/admin/auth/')) {
        prime_require_admin_auth();
    }

    if ($method === 'GET' && $path === '/health') {
        $dbOk = false;
        try {
            $pdo->query('SELECT 1')->fetchColumn();
            $dbOk = true;
        } catch (Throwable) {
            $dbOk = false;
        }

        respond([
            'success' => true,
            'message' => 'API is running',
            'database' => $dbOk ? 'ok' : 'error',
            'timestamp' => gmdate('c'),
        ]);
    }

    if ($method === 'GET' && $path === '/resources') {
        $items = $pdo->query('SELECT id, title, type, url, thumbnail FROM resources ORDER BY id DESC')->fetchAll();
        respond(castRows($items, ['id']));
    }

    if ($method === 'GET' && $path === '/gallery') {
        $items = $pdo->query('SELECT id, title, image_url, status FROM gallery ORDER BY id DESC')->fetchAll();
        respond(castRows($items, ['id']));
    }

    if ($method === 'POST' && $path === '/payments/stripe/checkout-session') {
        $payload = requireJsonBody();
        try {
            $session = prime_create_stripe_checkout_session($payload);
            logStripeCheckoutSession($pdo, $session, $payload);
            respond([
                'success' => true,
                'checkout_url' => $session['url'],
                'session_id' => $session['id'],
            ], 201);
        } catch (InvalidArgumentException $e) {
            respond(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[prime-api] Stripe checkout session failed: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Unable to initialize Stripe checkout.'], 500);
        }
    }

    if ($method === 'POST' && $path === '/payments/bank-transfer') {
        try {
            handleBankTransferSubmission($pdo);
        } catch (InvalidArgumentException $e) {
            respond(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[prime-api] Bank transfer submission failed: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Unable to submit bank transfer proof right now.'], 500);
        }
    }

    if ($method === 'POST' && $path === '/payments/espees') {
        try {
            handleEspeesPaymentSubmission($pdo);
        } catch (InvalidArgumentException $e) {
            respond(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[prime-api] Espees POP submission failed: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Unable to submit Espees proof of payment right now.'], 500);
        }
    }

    if ($method === 'POST' && $path === '/payments/stripe/webhook') {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) {
            respond(['success' => false, 'message' => 'Unable to read request body.'], 400);
        }

        $cfg = prime_stripe_config();
        $signature = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

        if (($cfg['webhook_secret'] ?? '') !== '') {
            $isValid = prime_verify_stripe_webhook_signature($rawBody, $signature, (string)$cfg['webhook_secret']);
            if (!$isValid) {
                respond(['success' => false, 'message' => 'Invalid Stripe signature.'], 400);
            }
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            respond(['success' => false, 'message' => 'Invalid webhook payload.'], 400);
        }

        try {
            $updated = applyStripeWebhookEvent($pdo, $event);
            respond(['success' => true, 'updated' => $updated]);
        } catch (Throwable $e) {
            error_log('[prime-api] Stripe webhook processing failed: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Webhook processing failed.'], 500);
        }
    }

    if ($method === 'GET' && $path === '/payments/stripe/session-status') {
        $sessionId = trim((string)($_GET['session_id'] ?? ''));
        if ($sessionId === '') {
            respond(['success' => false, 'message' => 'session_id is required.'], 422);
        }
        try {
            $session = prime_retrieve_stripe_checkout_session($sessionId);
            $updated = applyStripeWebhookEvent($pdo, [
                'type' => 'checkout.session.status_check',
                'data' => ['object' => $session],
            ]);

            respond([
                'success' => true,
                'session_id' => (string)($session['id'] ?? $sessionId),
                'checkout_status' => (string)($session['status'] ?? 'open'),
                'payment_status' => (string)($session['payment_status'] ?? 'unpaid'),
                'amount_total' => isset($session['amount_total']) ? (int)$session['amount_total'] : null,
                'currency' => isset($session['currency']) ? (string)$session['currency'] : null,
                'updated_rows' => $updated,
            ]);
        } catch (InvalidArgumentException $e) {
            respond(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[prime-api] Stripe session status lookup failed: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Unable to verify payment status.'], 500);
        }
    }

    if ($method === 'GET' && $path === '/admin/payments') {
        $stmt = $pdo->query(
            'SELECT id, stripe_session_id, stripe_payment_intent_id, donor_name, donor_email, amount_cents, currency, checkout_status, payment_status, checkout_url, created_at, updated_at
             FROM stripe_payments
             ORDER BY created_at DESC, id DESC'
        );
        respond(castRows($stmt->fetchAll(), ['id', 'amount_cents']));
    }

    if ($method === 'GET' && $path === '/admin/payments/bank-transfers') {
        $stmt = $pdo->query(
            'SELECT id, donor_name, donor_email, amount, currency, proof_original_name, proof_stored_name, proof_path, mime_type, file_size_bytes, status, created_at
             FROM bank_transfer_submissions
             ORDER BY created_at DESC, id DESC'
        );
        respond(castRows($stmt->fetchAll(), ['id', 'file_size_bytes']));
    }

    if ($method === 'GET' && $path === '/admin/payments/espees') {
        $stmt = $pdo->query(
            'SELECT id, donor_name, donor_email, amount, currency, espees_code, proof_original_name, proof_stored_name, proof_path, mime_type, file_size_bytes, status, created_at
             FROM espees_payment_submissions
             ORDER BY created_at DESC, id DESC'
        );
        respond(castRows($stmt->fetchAll(), ['id', 'file_size_bytes']));
    }

    if ($method === 'GET' && $path === '/register/check-email') {
        $email = trim((string)($_GET['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['success' => false, 'message' => 'A valid email is required.'], 422);
        }

        $stmt = $pdo->prepare('SELECT id FROM registrations WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        respond([
            'success' => true,
            'exists' => $stmt->fetchColumn() !== false,
        ]);
    }

    if ($method === 'POST' && $path === '/register') {
        handleRegister($pdo);
    }

    if ($method === 'GET' && $path === '/admin/registrations') {
        $stmt = $pdo->query(
            'SELECT id, title, minister_name, email, phone, category, venue_details, city, state, country, conference_mode, attendance_target, mobilization_strategy, conference_type, has_organized_before, organized_before_report, created_at
             FROM registrations
             ORDER BY created_at DESC, id DESC'
        );
        respond(castRows($stmt->fetchAll(), ['id', 'attendance_target']));
    }

    if ($method === 'GET' && preg_match('#^/admin/registrations/(\d+)$#', $path, $m)) {
        $stmt = $pdo->prepare(
            'SELECT id, title, minister_name, email, phone, category, venue_details, city, state, country, conference_mode, attendance_target, mobilization_strategy, conference_type, has_organized_before, organized_before_report, created_at
             FROM registrations
             WHERE id = ? LIMIT 1'
        );
        $stmt->execute([(int)$m[1]]);
        $row = $stmt->fetch();
        if (!$row) {
            respond(['success' => false, 'message' => 'Registration not found.'], 404);
        }
        $rows = castRows([$row], ['id', 'attendance_target']);
        respond(['success' => true, 'registration' => $rows[0]]);
    }

    if ($method === 'DELETE' && preg_match('#^/admin/registrations/(\d+)$#', $path, $m)) {
        $stmt = $pdo->prepare('DELETE FROM registrations WHERE id = ?');
        $stmt->execute([(int)$m[1]]);
        respond(['success' => true]);
    }

    if ($method === 'POST' && $path === '/admin/resources') {
        $payload = requireJsonBody();
        requireFields($payload, ['title', 'type', 'url', 'thumbnail']);

        $stmt = $pdo->prepare('INSERT INTO resources (title, type, url, thumbnail) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            trim((string)$payload['title']),
            trim((string)$payload['type']),
            trim((string)$payload['url']),
            trim((string)$payload['thumbnail']),
        ]);

        respond(['success' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($method === 'DELETE' && preg_match('#^/admin/resources/(\d+)$#', $path, $m)) {
        $stmt = $pdo->prepare('DELETE FROM resources WHERE id = ?');
        $stmt->execute([(int)$m[1]]);
        respond(['success' => true]);
    }

    if ($method === 'POST' && $path === '/admin/gallery') {
        $payload = requireJsonBody();
        requireFields($payload, ['title', 'image_url', 'status']);

        $stmt = $pdo->prepare('INSERT INTO gallery (title, image_url, status) VALUES (?, ?, ?)');
        $stmt->execute([
            trim((string)$payload['title']),
            trim((string)$payload['image_url']),
            trim((string)$payload['status']),
        ]);

        respond(['success' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($method === 'DELETE' && preg_match('#^/admin/gallery/(\d+)$#', $path, $m)) {
        $stmt = $pdo->prepare('DELETE FROM gallery WHERE id = ?');
        $stmt->execute([(int)$m[1]]);
        respond(['success' => true]);
    }

    respond(['success' => false, 'message' => 'Not found'], 404);
}

function handleRegister(PDO $pdo): void
{
    $payload = requireJsonBody();

    $ministerName = trim((string)($payload['minister_name'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    $title = trim((string)($payload['title'] ?? ''));
    $phone = trim((string)($payload['phone'] ?? ''));
    $conferenceMode = trim((string)($payload['conference_mode'] ?? 'Onsite'));
    $hasOrganizedBefore = normalizeYesNo($payload['has_organized_before'] ?? 'No');
    $organizedBeforeReport = trim((string)($payload['organized_before_report'] ?? ''));

    if ($ministerName === '') {
        respond(['success' => false, 'message' => 'Minister name is required.'], 422);
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'message' => 'A valid email is required.'], 422);
    }

    if ($phone === '') {
        respond(['success' => false, 'message' => 'Phone number is required.'], 422);
    }

    if ($conferenceMode === '') {
        $conferenceMode = 'Onsite';
    }
    if (!in_array($conferenceMode, ['Onsite', 'Online'], true)) {
        respond(['success' => false, 'message' => 'Conference mode must be Online or Onsite.'], 422);
    }

    if ($hasOrganizedBefore === 'Yes' && $organizedBeforeReport === '') {
        respond(['success' => false, 'message' => 'Please provide a brief report about your previous ministers’ conference.'], 422);
    }

    $attendanceTarget = 0;

    $duplicateCheck = $pdo->prepare('SELECT id FROM registrations WHERE email = ? LIMIT 1');
    $duplicateCheck->execute([$email]);
    if ($duplicateCheck->fetchColumn() !== false) {
        respond([
            'success' => false,
            'message' => 'A registration with this email already exists.',
        ], 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO registrations
        (title, minister_name, email, phone, category, venue_details, city, state, country, conference_mode, attendance_target, mobilization_strategy, conference_type, has_organized_before, organized_before_report)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $title,
        $ministerName,
        $email,
        $phone,
        trim((string)($payload['category'] ?? '')),
        '',
        trim((string)($payload['city'] ?? '')),
        trim((string)($payload['state'] ?? '')),
        trim((string)($payload['country'] ?? '')),
        $conferenceMode,
        $attendanceTarget,
        '',
        trim((string)($payload['conference_type'] ?? '')),
        $hasOrganizedBefore,
        $hasOrganizedBefore === 'Yes' ? $organizedBeforeReport : '',
    ]);

    $savedRegistration = [
        'id' => (int)$pdo->lastInsertId(),
        'title' => $title,
        'minister_name' => $ministerName,
        'email' => $email,
        'phone' => $phone,
        'category' => trim((string)($payload['category'] ?? '')),
        'venue_details' => '',
        'city' => trim((string)($payload['city'] ?? '')),
        'state' => trim((string)($payload['state'] ?? '')),
        'country' => trim((string)($payload['country'] ?? '')),
        'conference_mode' => $conferenceMode,
        'attendance_target' => $attendanceTarget,
        'mobilization_strategy' => '',
        'conference_type' => trim((string)($payload['conference_type'] ?? '')),
        'has_organized_before' => $hasOrganizedBefore,
        'organized_before_report' => $hasOrganizedBefore === 'Yes' ? $organizedBeforeReport : '',
    ];

    $mailSent = false;
    try {
        $mailSent = prime_send_registration_confirmation_email($savedRegistration);
    } catch (Throwable $e) {
        error_log('[prime-api] Registration email failed for ' . $email . ': ' . $e->getMessage());
    }

    respond([
        'success' => true,
        'message' => 'Registration successful',
        'email_sent' => $mailSent,
    ]);
}

function requireFields(array $payload, array $fields): void
{
    foreach ($fields as $field) {
        if (!array_key_exists($field, $payload) || trim((string)$payload[$field]) === '') {
            respond(['success' => false, 'message' => "Missing field: {$field}"], 422);
        }
    }
}

function logStripeCheckoutSession(PDO $pdo, array $session, array $payload): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO stripe_payments
        (stripe_session_id, stripe_payment_intent_id, donor_name, donor_email, amount_cents, currency, checkout_status, payment_status, checkout_url, metadata_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          stripe_payment_intent_id = VALUES(stripe_payment_intent_id),
          donor_name = VALUES(donor_name),
          donor_email = VALUES(donor_email),
          amount_cents = VALUES(amount_cents),
          currency = VALUES(currency),
          checkout_status = VALUES(checkout_status),
          payment_status = VALUES(payment_status),
          checkout_url = VALUES(checkout_url),
          metadata_json = VALUES(metadata_json)'
    );

    $metadataJson = json_encode($session['metadata'] ?? [], JSON_UNESCAPED_SLASHES);
    if ($metadataJson === false) {
        $metadataJson = '{}';
    }

    $stmt->execute([
        (string)($session['id'] ?? ''),
        isset($session['payment_intent_id']) ? (string)$session['payment_intent_id'] : null,
        trim((string)($payload['name'] ?? '')),
        trim((string)($payload['email'] ?? '')),
        (int)($session['amount_total'] ?? 0),
        strtolower((string)($session['currency'] ?? 'usd')),
        (string)($session['checkout_status'] ?? 'open'),
        (string)($session['payment_status'] ?? 'unpaid'),
        (string)($session['url'] ?? ''),
        $metadataJson,
    ]);
}

function handleBankTransferSubmission(PDO $pdo): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $amountRaw = trim((string)($_POST['amount'] ?? ''));
    $currency = strtolower(trim((string)($_POST['currency'] ?? 'usd')));

    if ($name === '') {
        throw new InvalidArgumentException('Name is required.');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid email address is required.');
    }
    if ($amountRaw === '' || !is_numeric($amountRaw)) {
        throw new InvalidArgumentException('A valid amount is required.');
    }

    $amount = round((float)$amountRaw, 2);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be greater than zero.');
    }

    if (!isset($_FILES['proof'])) {
        throw new InvalidArgumentException('Proof of payment file is required.');
    }

    $proof = $_FILES['proof'];
    if (!is_array($proof)) {
        throw new InvalidArgumentException('Invalid proof upload.');
    }
    if (($proof['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(uploadErrorMessage((int)($proof['error'] ?? UPLOAD_ERR_CANT_WRITE)));
    }

    $tmpPath = (string)($proof['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new InvalidArgumentException('Uploaded proof file could not be verified.');
    }

    $originalName = trim((string)($proof['name'] ?? 'proof'));
    $fileSize = (int)($proof['size'] ?? 0);
    if ($fileSize <= 0) {
        throw new InvalidArgumentException('Uploaded file is empty.');
    }
    if ($fileSize > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('Proof file must be 10MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($tmpPath);
    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowedMime[$mimeType])) {
        throw new InvalidArgumentException('Only JPG, PNG, WEBP, or PDF proof files are allowed.');
    }

    $uploadDir = __DIR__ . '/uploads/bank-transfer-proofs';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    $storedName = sprintf(
        'bank-proof-%s-%s.%s',
        gmdate('YmdHis'),
        bin2hex(random_bytes(8)),
        $allowedMime[$mimeType]
    );
    $targetPath = $uploadDir . '/' . $storedName;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Failed to store uploaded proof file.');
    }

    $relativePath = 'uploads/bank-transfer-proofs/' . $storedName;
    $stmt = $pdo->prepare(
        'INSERT INTO bank_transfer_submissions
        (donor_name, donor_email, amount, currency, proof_original_name, proof_stored_name, proof_path, mime_type, file_size_bytes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        $email,
        $amount,
        $currency !== '' ? $currency : 'usd',
        substr($originalName, 0, 255),
        $storedName,
        $relativePath,
        $mimeType,
        $fileSize,
    ]);

    respond([
        'success' => true,
        'message' => 'Bank transfer proof submitted successfully.',
        'id' => (int)$pdo->lastInsertId(),
    ], 201);
}

function handleEspeesPaymentSubmission(PDO $pdo): void
{
    [$name, $email, $amount, $currency, $proof, $tmpPath, $originalName, $fileSize, $mimeType, $extension] = validateManualPaymentUpload($_POST, $_FILES, 'proof');
    $espeesCode = trim((string)($_POST['espees_code'] ?? ''));
    if ($espeesCode === '') {
        $espeesCode = 'HLCLC';
    }

    $uploadDir = __DIR__ . '/uploads/espees-proofs';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    $storedName = sprintf(
        'espees-proof-%s-%s.%s',
        gmdate('YmdHis'),
        bin2hex(random_bytes(8)),
        $extension
    );
    $targetPath = $uploadDir . '/' . $storedName;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Failed to store uploaded proof file.');
    }

    $relativePath = 'uploads/espees-proofs/' . $storedName;
    $stmt = $pdo->prepare(
        'INSERT INTO espees_payment_submissions
        (donor_name, donor_email, amount, currency, espees_code, proof_original_name, proof_stored_name, proof_path, mime_type, file_size_bytes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        $email,
        $amount,
        $currency,
        substr($espeesCode, 0, 50),
        substr($originalName, 0, 255),
        $storedName,
        $relativePath,
        $mimeType,
        $fileSize,
    ]);

    respond([
        'success' => true,
        'message' => 'Espees proof of payment submitted successfully.',
        'id' => (int)$pdo->lastInsertId(),
    ], 201);
}

/**
 * @return array{0:string,1:string,2:float,3:string,4:array,5:string,6:string,7:int,8:string,9:string}
 */
function validateManualPaymentUpload(array $post, array $files, string $fileField): array
{
    $name = trim((string)($post['name'] ?? ''));
    $email = trim((string)($post['email'] ?? ''));
    $amountRaw = trim((string)($post['amount'] ?? ''));
    $currency = strtolower(trim((string)($post['currency'] ?? 'usd')));

    if ($name === '') {
        throw new InvalidArgumentException('Name is required.');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid email address is required.');
    }
    if ($amountRaw === '' || !is_numeric($amountRaw)) {
        throw new InvalidArgumentException('A valid amount is required.');
    }
    $amount = round((float)$amountRaw, 2);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be greater than zero.');
    }

    if (!isset($files[$fileField]) || !is_array($files[$fileField])) {
        throw new InvalidArgumentException('Proof of payment file is required.');
    }

    $proof = $files[$fileField];
    if (($proof['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(uploadErrorMessage((int)($proof['error'] ?? UPLOAD_ERR_CANT_WRITE)));
    }

    $tmpPath = (string)($proof['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new InvalidArgumentException('Uploaded proof file could not be verified.');
    }

    $originalName = trim((string)($proof['name'] ?? 'proof'));
    $fileSize = (int)($proof['size'] ?? 0);
    if ($fileSize <= 0) {
        throw new InvalidArgumentException('Uploaded file is empty.');
    }
    if ($fileSize > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('Proof file must be 10MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($tmpPath);
    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowedMime[$mimeType])) {
        throw new InvalidArgumentException('Only JPG, PNG, WEBP, or PDF proof files are allowed.');
    }

    return [$name, $email, $amount, $currency !== '' ? $currency : 'usd', $proof, $tmpPath, $originalName, $fileSize, $mimeType, $allowedMime[$mimeType]];
}

function applyStripeWebhookEvent(PDO $pdo, array $event): int
{
    $eventType = (string)($event['type'] ?? '');
    $object = $event['data']['object'] ?? null;
    if (!is_array($object)) {
        return 0;
    }

    if ($eventType === 'checkout.session.status_check' || str_starts_with($eventType, 'checkout.session.')) {
        $sessionId = (string)($object['id'] ?? '');
        if ($sessionId === '') {
            return 0;
        }

        $checkoutStatus = (string)($object['status'] ?? ($eventType === 'checkout.session.expired' ? 'expired' : 'open'));
        $paymentStatus = (string)($object['payment_status'] ?? 'unpaid');
        $paymentIntent = isset($object['payment_intent']) && is_string($object['payment_intent']) ? $object['payment_intent'] : null;
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        $amountCents = isset($object['amount_total']) ? (int)$object['amount_total'] : null;
        $currency = isset($object['currency']) && is_string($object['currency']) ? strtolower($object['currency']) : null;
        $donorEmail = isset($object['customer_details']['email']) && is_string($object['customer_details']['email'])
            ? $object['customer_details']['email']
            : (isset($object['customer_email']) && is_string($object['customer_email']) ? $object['customer_email'] : null);
        $donorName = isset($object['customer_details']['name']) && is_string($object['customer_details']['name'])
            ? $object['customer_details']['name']
            : (isset($metadata['donor_name']) && is_string($metadata['donor_name']) ? $metadata['donor_name'] : null);
        $checkoutUrl = isset($object['url']) && is_string($object['url']) ? $object['url'] : null;

        $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        if ($metadataJson === false) {
            $metadataJson = '{}';
        }

        $stmt = $pdo->prepare(
            'UPDATE stripe_payments
             SET stripe_payment_intent_id = COALESCE(?, stripe_payment_intent_id),
                 donor_name = COALESCE(NULLIF(?, \'\'), donor_name),
                 donor_email = COALESCE(NULLIF(?, \'\'), donor_email),
                 amount_cents = COALESCE(?, amount_cents),
                 currency = COALESCE(NULLIF(?, \'\'), currency),
                 checkout_status = ?,
                 payment_status = ?,
                 checkout_url = COALESCE(?, checkout_url),
                 metadata_json = ?
             WHERE stripe_session_id = ?'
        );
        $stmt->execute([
            $paymentIntent,
            $donorName,
            $donorEmail,
            $amountCents,
            $currency,
            $checkoutStatus,
            $paymentStatus,
            $checkoutUrl,
            $metadataJson,
            $sessionId,
        ]);
        return $stmt->rowCount();
    }

    if (str_starts_with($eventType, 'payment_intent.')) {
        $paymentIntentId = (string)($object['id'] ?? '');
        if ($paymentIntentId === '') {
            return 0;
        }

        $paymentStatus = (string)($object['status'] ?? 'requires_payment_method');
        if ($eventType === 'payment_intent.succeeded') {
            $paymentStatus = 'paid';
        } elseif ($eventType === 'payment_intent.payment_failed') {
            $paymentStatus = 'failed';
        }
        $stmt = $pdo->prepare(
            'UPDATE stripe_payments
             SET stripe_payment_intent_id = ?,
                 payment_status = ?
             WHERE stripe_payment_intent_id = ?'
        );
        $stmt->execute([$paymentIntentId, $paymentStatus, $paymentIntentId]);
        return $stmt->rowCount();
    }

    return 0;
}

function normalizePath(string $path): string
{
    $clean = preg_replace('#/+#', '/', $path) ?: '/';
    if (str_starts_with($clean, '/api/')) {
        $clean = substr($clean, 4);
    } elseif ($clean === '/api') {
        $clean = '/';
    }

    if ($clean !== '/' && str_ends_with($clean, '/')) {
        $clean = rtrim($clean, '/');
    }

    return $clean === '' ? '/' : $clean;
}

function normalizeYesNo(mixed $value): string
{
    return strtoupper(trim((string)$value)) === 'YES' ? 'Yes' : 'No';
}

function requireJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        respond(['success' => false, 'message' => 'Invalid JSON payload.'], 400);
    }

    return $decoded;
}

function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file is too large.',
        UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Please upload a proof of payment file.',
        UPLOAD_ERR_NO_TMP_DIR => 'Upload temp directory is missing on the server.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not save the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by a server extension.',
        default => 'Unable to process uploaded file.',
    };
}

function castRows(array $rows, array $intFields): array
{
    foreach ($rows as &$row) {
        foreach ($intFields as $field) {
            if (isset($row[$field])) {
                $row[$field] = (int)$row[$field];
            }
        }
    }
    unset($row);

    return $rows;
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function sendCorsHeaders(): void
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $allowedOrigin = '*';
    $allowCredentials = false;

    if ($origin !== '' && prime_is_allowed_cors_origin($origin)) {
        $allowedOrigin = $origin;
        $allowCredentials = true;
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    if ($allowCredentials) {
        header('Access-Control-Allow-Credentials: true');
    }
}

function prime_is_allowed_cors_origin(string $origin): bool
{
    $frontendUrl = trim((string)(prime_env('FRONTEND_URL', '') ?? ''));
    $allowed = [];
    if ($frontendUrl !== '') {
        $frontendOrigin = parse_url($frontendUrl, PHP_URL_SCHEME) && parse_url($frontendUrl, PHP_URL_HOST)
            ? (parse_url($frontendUrl, PHP_URL_SCHEME) . '://' . parse_url($frontendUrl, PHP_URL_HOST) . (parse_url($frontendUrl, PHP_URL_PORT) ? ':' . parse_url($frontendUrl, PHP_URL_PORT) : ''))
            : '';
        if ($frontendOrigin !== '') {
            $allowed[] = $frontendOrigin;
        }
    }
    $allowed[] = 'http://127.0.0.1:3000';
    $allowed[] = 'http://localhost:3000';
    $allowed[] = 'http://0.0.0.0:3000';
    $allowed[] = 'http://127.0.0.1:5173';
    $allowed[] = 'http://localhost:5173';
    $allowed[] = 'http://0.0.0.0:5173';

    return in_array($origin, array_values(array_unique(array_filter($allowed))), true);
}

function prime_admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $cfg = prime_admin_auth_config();
    if (($cfg['session_name'] ?? '') !== '') {
        session_name((string)$cfg['session_name']);
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (bool)($cfg['session_secure'] ?? false),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function prime_admin_is_authenticated(): bool
{
    return (bool)($_SESSION['prime_admin_authenticated'] ?? false);
}

function prime_require_admin_auth(): void
{
    if (prime_admin_is_authenticated()) {
        return;
    }
    respond(['success' => false, 'message' => 'Unauthorized'], 401);
}

function prime_admin_verify_credentials(string $username, string $password): bool
{
    $cfg = prime_admin_auth_config();
    $expectedUser = (string)($cfg['username'] ?? 'admin');
    if ($username === '' || !hash_equals($expectedUser, $username)) {
        return false;
    }

    $passwordHash = trim((string)($cfg['password_hash'] ?? ''));
    if ($passwordHash !== '') {
        return password_verify($password, $passwordHash);
    }

    $plainPassword = (string)($cfg['password'] ?? '');
    if ($plainPassword === '') {
        return false;
    }

    return hash_equals($plainPassword, $password);
}

function prime_admin_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
        }
        session_destroy();
    }
}
