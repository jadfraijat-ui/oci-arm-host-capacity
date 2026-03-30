<?php
declare(strict_types=1);


// useful when script is being executed by cron user
$pathPrefix = ''; // e.g. /usr/share/nginx/oci-arm-host-capacity/

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

/*
 * No need to modify any value in this file anymore!
 * Copy .env.example to .env and adjust there instead.
 *
 * README.md now has all the information.
 */
$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null, // null or '' or 'jYtI:PHX-AD-1' or ['jYtI:PHX-AD-1','jYtI:PHX-AD-2']
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
);

$availabilityDomainEnv = trim((string) getenv('OCI_AVAILABILITY_DOMAIN'));
$configuredAvailabilityDomains = array_values(array_filter(array_map('trim', explode(',', $availabilityDomainEnv))));
if ($configuredAvailabilityDomains !== []) {
    $config->availabilityDomains = $configuredAvailabilityDomains;
}

$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

$hostname = (string) getenv('OCI_HOSTNAME');
if ($hostname) {
    $config->setHostname($hostname);
}

$assignPublicIp = getenv('OCI_ASSIGN_PUBLIC_IP');
if ($assignPublicIp !== false && strtolower($assignPublicIp) !== 'true') {
    $config->setAssignPublicIp(false);
}

$ipv6Enabled = getenv('OCI_IPV6_ENABLED');
if ($ipv6Enabled && strtolower($ipv6Enabled) === 'true') {
    $config->setIpv6Enabled(true);
    $ipv6SubnetId = (string) getenv('OCI_IPV6_SUBNET_ID');
    if ($ipv6SubnetId) {
        $config->setIpv6SubnetId($ipv6SubnetId);
    }
}

$api = new OciApi();
if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}
if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(new TooManyRequestsWaiter((int) getenv('TOO_MANY_REQUESTS_TIME_WAIT')));
}
$notifier = null;

$shape = trim((string) getenv('OCI_SHAPE')) ?: 'VM.Standard.A1.Flex';
$faultDomainEnv = trim((string) getenv('OCI_FAULT_DOMAIN'));
$faultDomains = array_values(array_filter(array_map('trim', explode(',', $faultDomainEnv))));
if ($faultDomains === []) {
    $faultDomains = [null];
}

$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}

$instances = $api->getInstances($config);

$existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
if ($existingInstances) {
    echo "$existingInstances\n";
    return;
}

if (!empty($config->availabilityDomains)) {
    if (is_array($config->availabilityDomains)) {
        $availabilityDomains = $config->availabilityDomains;
    } else {
        $availabilityDomains = [ $config->availabilityDomains ];
    }
} else {
    $availabilityDomains = $api->getAvailabilityDomains($config);
}

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    foreach ($faultDomains as $faultDomain) {
        try {
            $instanceDetails = $api->createInstance($config, $shape, getenv('OCI_SSH_PUBLIC_KEY'), $availabilityDomain, $faultDomain);
        } catch(ApiCallException $e) {
            $message = $e->getMessage();
            echo "$message\n";
//            if ($notifier->isSupported()) {
//                $notifier->notify($message);
//            }

            if (
                $e->getCode() === 500 &&
                strpos($message, 'InternalError') !== false &&
                strpos($message, 'Out of host capacity') !== false
            ) {
                // trying next placement option
                sleep(16);
                continue;
            }

            // current config is broken
            return;
        }

        // success
        $message = json_encode($instanceDetails, JSON_PRETTY_PRINT);
        echo "$message\n";
        if ($notifier !== null && method_exists($notifier, 'isSupported') && $notifier->isSupported()) {
            $notifier->notify($message);
        }

        return;
    }
}
