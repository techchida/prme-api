<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function prime_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = prime_db_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['name'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, (string)$cfg['user'], (string)$cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if ($cfg['auto_migrate']) {
        prime_run_schema($pdo);
    }

    if ($cfg['seed_defaults']) {
        prime_seed_defaults($pdo);
    }

    return $pdo;
}

function prime_run_schema(PDO $pdo): void
{
    $schemaFile = __DIR__ . '/schema.sql';
    if (!is_file($schemaFile)) {
        return;
    }

    $sql = file_get_contents($schemaFile);
    if ($sql === false || trim($sql) === '') {
        return;
    }

    // Simple splitter is enough for this schema file.
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }

    // Backward-compatible incremental migrations for existing installations.
    prime_ensure_registration_columns($pdo);
}

function prime_ensure_registration_columns(PDO $pdo): void
{
    if (!prime_table_exists($pdo, 'registrations')) {
        return;
    }

    if (!prime_column_exists($pdo, 'registrations', 'phone')) {
        $pdo->exec("ALTER TABLE registrations ADD COLUMN phone VARCHAR(80) NOT NULL DEFAULT '' AFTER email");
    }

    if (!prime_column_exists($pdo, 'registrations', 'organized_before_report')) {
        $pdo->exec("ALTER TABLE registrations ADD COLUMN organized_before_report TEXT NOT NULL AFTER has_organized_before");
    }

    if (!prime_column_exists($pdo, 'registrations', 'conference_mode')) {
        $pdo->exec("ALTER TABLE registrations ADD COLUMN conference_mode VARCHAR(20) NOT NULL DEFAULT 'Onsite' AFTER country");
    }
}

function prime_table_exists(PDO $pdo, string $table): bool
{
    $cfg = prime_db_config();
    $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->execute([(string)$cfg['name'], $table]);
    return $stmt->fetchColumn() !== false;
}

function prime_column_exists(PDO $pdo, string $table, string $column): bool
{
    $cfg = prime_db_config();
    $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute([(string)$cfg['name'], $table, $column]);
    return $stmt->fetchColumn() !== false;
}

function prime_seed_defaults(PDO $pdo): void
{
    $resourceCount = (int)$pdo->query('SELECT COUNT(*) FROM resources')->fetchColumn();
    if ($resourceCount === 0) {
        $stmt = $pdo->prepare('INSERT INTO resources (title, type, url, thumbnail) VALUES (?, ?, ?, ?)');
        $stmt->execute(['The Power of Prayer', 'video', 'https://example.com/video1', 'https://picsum.photos/seed/prayer/400/225']);
        $stmt->execute(['Mobilization Guide', 'pdf', 'https://example.com/pdf1', 'https://picsum.photos/seed/guide/400/225']);
        $stmt->execute(['Gospel Impact', 'audio', 'https://example.com/audio1', 'https://picsum.photos/seed/impact/400/225']);
    }

    $gallerySeedRows = [
        ['ISM Conference Lagos', 'https://i.imgur.com/Lf8slst.jpeg', 'past'],
        ['Global Ministers Prayer', 'https://i.imgur.com/LbgImh1.jpeg', 'ongoing'],
        ['Youth Empowerment Summit', 'https://i.imgur.com/FbOu0hQ.jpeg', 'past'],
    ];

    $galleryCount = (int)$pdo->query('SELECT COUNT(*) FROM gallery')->fetchColumn();
    if ($galleryCount === 0) {
        $stmt = $pdo->prepare('INSERT INTO gallery (title, image_url, status) VALUES (?, ?, ?)');
        foreach ($gallerySeedRows as $row) {
            $stmt->execute($row);
        }
    }

    // Keep seeded demo rows visually consistent across both gallery views.
    $updateGallery = $pdo->prepare('UPDATE gallery SET image_url = ?, status = ? WHERE title = ?');
    foreach ($gallerySeedRows as [$title, $imageUrl, $status]) {
        $updateGallery->execute([$imageUrl, $status, $title]);
    }
}
