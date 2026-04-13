<?php

declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST only.']);
    exit;
}

$tenant = trim((string) ($_POST['tenant_name'] ?? ''));
$displayName = trim((string) ($_POST['display_name'] ?? ''));
$region = trim((string) ($_POST['region'] ?? ''));
$state = trim((string) ($_POST['state'] ?? 'active'));
$reason = trim((string) ($_POST['reason'] ?? ''));

if ($tenant === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Tenant profile name is required.']);
    exit;
}

if (! preg_match('/^[a-z0-9][a-z0-9._-]{1,80}$/i', $tenant)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Tenant profile name must be filesystem-safe.']);
    exit;
}

$allowedStates = ['active', 'paused', 'support'];
if (! in_array($state, $allowedStates, true)) {
    $state = 'active';
}

$root = __DIR__;
$tenantBase = rtrim((string) (getenv('OCI_TENANT_ROOT') ?: ($root . '/tenants')), '/');
$tenantRoot = $tenantBase . '/' . $tenant;
$metaFile = $root . '/public/tenant-meta.json';

if (! is_dir($tenantRoot) && ! mkdir($tenantRoot, 0775, true) && ! is_dir($tenantRoot)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not create tenant directory.']);
    exit;
}

$saveUpload = static function (string $field, string $destination, array $allowedExtensions = []): ?string {
    if (! isset($_FILES[$field]) || ! is_array($_FILES[$field])) {
        return null;
    }

    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed for ' . $field . '.');
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($allowedExtensions !== [] && ! in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Unsupported file type for ' . $field . '.');
    }

    if (! move_uploaded_file((string) $file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not move uploaded file for ' . $field . '.');
    }

    chmod($destination, 0660);

    return basename($destination);
};

try {
    $written = [];
    foreach ([
        'private_key' => [$tenantRoot . '/key.pem', ['pem']],
        'public_key' => [$tenantRoot . '/public-key.pem', ['pem']],
        'config_file' => [$tenantRoot . '/config.config', ['config', 'conf', 'txt']],
    ] as $field => [$destination, $extensions]) {
        $result = $saveUpload($field, $destination, $extensions);
        if ($result !== null) {
            $written[$field] = $result;
        }
    }

    if ($written === [] && $displayName === '' && $region === '' && $reason === '' && $state === 'active') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Drop at least one tenant file or update metadata.']);
        exit;
    }

    $meta = [];
    if (is_file($metaFile)) {
        $decoded = json_decode((string) file_get_contents($metaFile), true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    $existing = is_array($meta[$tenant] ?? null) ? $meta[$tenant] : [];
    $meta[$tenant] = [
        'profile' => $tenant,
        'display_name' => $displayName !== '' ? $displayName : ($existing['display_name'] ?? $tenant),
        'region' => $region !== '' ? $region : ($existing['region'] ?? ''),
        'state' => $state !== '' ? $state : ($existing['state'] ?? 'active'),
        'reason' => $reason !== '' ? $reason : ($existing['reason'] ?? ''),
    ];

    file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    chmod($metaFile, 0664);

    echo json_encode([
        'ok' => true,
        'message' => 'Tenant files saved for ' . $tenant . '.',
        'tenant' => $meta[$tenant],
        'written' => $written,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
