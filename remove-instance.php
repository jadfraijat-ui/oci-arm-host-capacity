<?php

declare(strict_types=1);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'message' => 'POST only.',
    ]);
    exit;
}

$name = trim((string) ($_POST['name'] ?? ''));
if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'A valid instance name is required.',
    ]);
    exit;
}

$baseDir = __DIR__;
$instancesDir = $baseDir . '/instances';
$archiveDir = $instancesDir . '/completed';
$summaryFile = $instancesDir . '/.configs.txt';

if (!is_dir($instancesDir)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Instance directory is missing.',
    ]);
    exit;
}

$matchedFile = null;
foreach (glob($instancesDir . '/*.json') ?: [] as $file) {
    if (!is_file($file)) {
        continue;
    }
    if (str_starts_with(basename($file), 'example-')) {
        continue;
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    $instanceName = is_array($decoded) ? (string) ($decoded['name'] ?? '') : '';
    if ($instanceName === $name || pathinfo($file, PATHINFO_FILENAME) === $name) {
        $matchedFile = $file;
        break;
    }
}

if ($matchedFile === null) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'message' => 'Instance config was not found.',
    ]);
    exit;
}

if (!is_dir($archiveDir) && !mkdir($archiveDir, 0775, true) && !is_dir($archiveDir)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Archive directory could not be created.',
    ]);
    exit;
}

$archivedBasename = date('Ymd-His') . '-' . basename($matchedFile);
$archivedPath = $archiveDir . '/' . $archivedBasename;
if (!rename($matchedFile, $archivedPath)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'The instance config could not be archived.',
    ]);
    exit;
}

$summaryLines = [];
foreach (glob($instancesDir . '/*.json') ?: [] as $file) {
    if (!is_file($file)) {
        continue;
    }
    if (str_starts_with(basename($file), 'example-')) {
        continue;
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)) {
        continue;
    }

    $instanceName = trim((string) ($decoded['name'] ?? ''));
    $hostname = trim((string) ($decoded['hostname'] ?? $instanceName));
    $ocpus = $decoded['ocpus'] ?? null;
    $memory = $decoded['memory'] ?? null;
    $bootSize = $decoded['boot_size'] ?? null;
    $dualStack = !empty($decoded['network']['dual_stack']) ? 'true' : 'false';
    $assignPublicIp = !empty($decoded['network']['assign_public_ip']) ? 'true' : 'false';

    if ($instanceName === '' || $ocpus === null || $memory === null || $bootSize === null) {
        continue;
    }

    $summaryLines[] = implode(':', [
        $instanceName,
        (string) $ocpus,
        (string) $memory,
        (string) $bootSize,
        $hostname,
        $assignPublicIp,
        $dualStack,
    ]);
}

file_put_contents($summaryFile, implode(PHP_EOL, $summaryLines) . (count($summaryLines) ? PHP_EOL : ''));

echo json_encode([
    'ok' => true,
    'message' => 'Instance archived and removed from the retry queue.',
    'name' => $name,
    'archived_to' => 'instances/completed/' . $archivedBasename,
]);
