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
$ociConfigText = trim((string) ($_POST['oci_config'] ?? ''));
$privateKeyText = trim((string) ($_POST['private_key_text'] ?? ''));
$publicKeyText = trim((string) ($_POST['public_key_text'] ?? ''));

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

$readShellConfigValue = static function (string $path, string $key): string {
    if (! is_file($path)) {
        return '';
    }

    $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=\s*(["\']?)(.*?)\1\s*$/m';
    $contents = (string) file_get_contents($path);

    return preg_match($pattern, $contents, $matches) === 1
        ? trim((string) $matches[2])
        : '';
};

$tenantBase = rtrim((string) (
    getenv('OCI_TENANT_ROOT')
    ?: $readShellConfigValue($root . '/config.sh', 'CONFIG_DIR')
    ?: ($root . '/tenants')
), '/');
$tenantRoot = $tenantBase . '/' . $tenant;
$metaFile = $root . '/public/tenant-meta.json';
$configShFile = $root . '/config.sh';

if (! is_dir($tenantRoot) && ! mkdir($tenantRoot, 0775, true) && ! is_dir($tenantRoot)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not create tenant directory.']);
    exit;
}

$saveUpload = static function (string $field, string $destination, array $allowedExtensions = [], int $mode = 0660): ?string {
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

    chmod($destination, $mode);

    return basename($destination);
};

$readUpload = static function (string $field, array $allowedExtensions = []): ?string {
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

    $contents = file_get_contents((string) $file['tmp_name']);

    return is_string($contents) ? $contents : null;
};

$writeTextFile = static function (string $destination, string $contents, int $mode): string {
    file_put_contents($destination, rtrim($contents) . PHP_EOL);
    chmod($destination, $mode);

    return basename($destination);
};

$normalizeOciConfig = static function (string $contents, string $keyFilePath): array {
    $values = [];

    foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
            continue;
        }

        if (preg_match('/^\[[^\]]+\]$/', $line) === 1) {
            continue;
        }

        if (! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = strtolower(trim($key));
        $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
        $value = trim($value, "\"'");

        if ($key !== '') {
            $values[$key] = $value;
        }
    }

    foreach (['user', 'fingerprint', 'tenancy', 'region'] as $requiredKey) {
        if (trim((string) ($values[$requiredKey] ?? '')) === '') {
            throw new RuntimeException('OCI config is missing required key: ' . $requiredKey . '.');
        }
    }

    $values['key_file'] = $keyFilePath;
    $ordered = ['user', 'fingerprint', 'tenancy', 'region', 'key_file'];
    $lines = ['[DEFAULT]'];

    foreach ($ordered as $key) {
        $lines[] = $key . '=' . $values[$key];
        unset($values[$key]);
    }

    foreach ($values as $key => $value) {
        if ($value !== '') {
            $lines[] = $key . '=' . $value;
        }
    }

    return [
        'config' => implode(PHP_EOL, $lines) . PHP_EOL,
        'values' => $values + [
            'key_file' => $keyFilePath,
        ],
        'region' => $lines[4] ?? '',
    ];
};

$appendTenantToConfig = static function (string $path, string $tenant): bool {
    if (! is_file($path) || ! is_writable($path)) {
        return false;
    }

    $contents = (string) file_get_contents($path);
    if (preg_match('/^TENANTS=(["\'])(.*?)\1/m', $contents, $matches) !== 1) {
        return false;
    }

    $quote = $matches[1];
    $tenants = preg_split('/\s+/', trim((string) $matches[2])) ?: [];

    if (in_array($tenant, $tenants, true)) {
        return true;
    }

    $tenants = array_values(array_unique(array_filter([...$tenants, $tenant])));
    $replacement = 'TENANTS=' . $quote . implode(' ', $tenants) . $quote;
    $updated = preg_replace('/^TENANTS=(["\']).*?\1/m', $replacement, $contents, 1);

    if (! is_string($updated) || $updated === $contents) {
        return false;
    }

    file_put_contents($path, $updated);

    return true;
};

$normalizeTenantFiles = static function (string $tenantRoot): void {
    $helper = '/usr/local/sbin/oracle-retry-normalize-tenant';

    if (! is_executable($helper)) {
        return;
    }

    $command = 'sudo -n ' . escapeshellarg($helper) . ' ' . escapeshellarg($tenantRoot) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('Tenant files were saved, but ownership normalization failed: ' . trim(implode("\n", $output)));
    }
};

try {
    $written = [];

    if ($privateKeyText !== '') {
        $written['private_key'] = $writeTextFile($tenantRoot . '/key.pem', $privateKeyText, 0640);
    } else {
        $result = $saveUpload('private_key', $tenantRoot . '/key.pem', ['pem'], 0640);
        if ($result !== null) {
            $written['private_key'] = $result;
        }
    }

    if ($publicKeyText !== '') {
        $written['public_key'] = $writeTextFile($tenantRoot . '/public-key.pem', $publicKeyText, 0644);
    } else {
        $result = $saveUpload('public_key', $tenantRoot . '/public-key.pem', ['pem'], 0644);
        if ($result !== null) {
            $written['public_key'] = $result;
        }
    }

    if ($ociConfigText === '') {
        $ociConfigText = trim((string) ($readUpload('config_file', ['config', 'conf', 'txt']) ?? ''));
    }

    $parsedRegion = '';
    if ($ociConfigText !== '') {
        $normalizedConfig = $normalizeOciConfig($ociConfigText, $tenantRoot . '/key.pem');
        $written['config_file'] = $writeTextFile($tenantRoot . '/config.config', $normalizedConfig['config'], 0640);
        if (preg_match('/^region=(.+)$/m', $normalizedConfig['config'], $matches) === 1) {
            $parsedRegion = trim((string) $matches[1]);
        }
    } else {
        $result = $saveUpload('config_file', $tenantRoot . '/config.config', ['config', 'conf', 'txt'], 0600);
        if ($result !== null) {
            $contents = (string) file_get_contents($tenantRoot . '/config.config');
            $normalizedConfig = $normalizeOciConfig($contents, $tenantRoot . '/key.pem');
            $written['config_file'] = $writeTextFile($tenantRoot . '/config.config', $normalizedConfig['config'], 0640);
            if (preg_match('/^region=(.+)$/m', $normalizedConfig['config'], $matches) === 1) {
                $parsedRegion = trim((string) $matches[1]);
            }
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
        'region' => $region !== '' ? $region : ($parsedRegion !== '' ? $parsedRegion : ($existing['region'] ?? '')),
        'state' => $state !== '' ? $state : ($existing['state'] ?? 'active'),
        'reason' => $reason !== '' ? $reason : ($existing['reason'] ?? ''),
    ];

    file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    chmod($metaFile, 0664);

    $configUpdated = $appendTenantToConfig($configShFile, $tenant);
    $normalizeTenantFiles($tenantRoot);

    echo json_encode([
        'ok' => true,
        'message' => 'Tenant files saved for ' . $tenant . '.',
        'tenant' => $meta[$tenant],
        'written' => $written,
        'tenant_root' => $tenantRoot,
        'config_updated' => $configUpdated,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
