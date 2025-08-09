<?php

declare(strict_types=1);

use App\ExternalAccountCredentialsByAws;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Sts\StsClient;
use Google\Cloud\Storage\StorageClient;

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
    echo "StorageClient Auth via ExternalAccountCredentialsByAws fromFile".PHP_EOL;
    $jsonPath = __DIR__.'/auth.json';

    $credentials = ExternalAccountCredentialsByAws::fromFile($jsonPath);

    $storage = new StorageClient([
        'credentialsFetcher' => $credentials,
        'suppressKeyFileNotice' => true,
    ]);

    $bucketName = getenv('GCS_BUCKET');
    $bucket = $storage->bucket($bucketName);
    printf("Bucket: %s%s", $bucket->name(), PHP_EOL);
} catch (Exception $e) {
    echo "ExternalAccountCredentialsByAws Error".PHP_EOL;
    echo $e->getMessage().PHP_EOL;
}

try {
    echo "StorageClient Auth via ExternalAccountCredentialsByAws fromJson".PHP_EOL;
    $jsonPath = __DIR__.'/auth.json';
    $json = file_get_contents($jsonPath);

    $credentials = ExternalAccountCredentialsByAws::fromJson($json);

    $storage = new StorageClient([
        'credentialsFetcher' => $credentials,
        'suppressKeyFileNotice' => true,
    ]);

    $bucketName = getenv('GCS_BUCKET');
    $bucket = $storage->bucket($bucketName);
    printf("Bucket: %s%s", $bucket->name(), PHP_EOL);
} catch (Exception $e) {
    echo "ExternalAccountCredentialsByAws Error".PHP_EOL;
    echo $e->getMessage().PHP_EOL;
}
