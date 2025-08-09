<?php

declare(strict_types=1);

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Sts\StsClient;
use Google\Auth\ApplicationDefaultCredentials;
use Google\Cloud\Storage\StorageClient;
use Kreait\Firebase\Factory;

require_once "vendor/autoload.php";

echo "Hello World!".PHP_EOL;

try {
    echo "AWS Auth".PHP_EOL;
    $client = new StsClient([
        'version' => 'latest',
    ]);
    $identityResult = $client->getCallerIdentity();
    echo sprintf('UserId: %s%s', $identityResult->get('UserId'), PHP_EOL);
    echo sprintf('Account: %s%s', $identityResult->get('Account'), PHP_EOL);
    echo sprintf('Arn: %s%s', $identityResult->get('Arn'), PHP_EOL);

} catch (AwsException $e) {
    echo $e->getMessage().PHP_EOL;
} catch (CredentialsException $e) {
    echo 'Credential Error'.PHP_EOL;
    echo $e->getMessage().PHP_EOL;
}

try {
    echo "ApplicationDefaultCredentials Auth".PHP_EOL;
    $auth = ApplicationDefaultCredentials::getCredentials(Factory::API_CLIENT_SCOPES);
    $token = $auth->fetchAuthToken();
    print_r($token);
} catch (Exception $e) {
    echo $e->getMessage().PHP_EOL;
}

try {
    echo "Kreait\Firebase\Factory Auth".PHP_EOL;
    $client = new Factory()->createStorage();
    foreach ($client->getStorageClient()->buckets() as $bucket) {
        printf('Bucket: %s'.PHP_EOL, $bucket->getName());
    }
} catch (Exception $e) {
    echo "Kreait\Firebase\Factory Error".PHP_EOL;
    throw $e;
}

try {
    echo "StorageClient Auth".PHP_EOL;
    $client = new StorageClient(['suppressKeyFileNotice' => true]);
    $bucket = $client->bucket("koma-yumemi-resources");
    printf("Bucket: %s%s", $bucket->name(), PHP_EOL);
} catch (Exception $e) {
    echo $e->getMessage().PHP_EOL;
}
