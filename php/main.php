<?php

declare(strict_types=1);

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Sts\StsClient;
use Google\Auth\ApplicationDefaultCredentials;
use Google\Cloud\Storage\StorageClient;
use Kreait\Firebase\Factory;
use App\ExternalAccountCredentialsByAws;

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
/*
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
}*/

try {
    echo "StorageClient Auth via ExternalAccountCredentialsByAws".PHP_EOL;
    $jsonPath = __DIR__ . '/auth.json';
    if (!is_file($jsonPath)) {
        throw new Exception("auth.json not found: " . $jsonPath);
    }
    $jsonKey = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($jsonKey)) {
        throw new Exception('auth.json is not a valid JSON object');
    }

    // Use a broad scope for testing. Adjust as needed.
    $scope = 'https://www.googleapis.com/auth/cloud-platform';
    $credentials = new ExternalAccountCredentialsByAws($scope, $jsonKey);

    $storage = new StorageClient([
        'credentialsFetcher' => $credentials,
        'suppressKeyFileNotice' => true,
    ]);

    $bucketName = getenv('GCS_BUCKET') ?: 'koma-yumemi-resources';
    $bucket = $storage->bucket($bucketName);
    printf("Bucket: %s%s", $bucket->name(), PHP_EOL);
} catch (Exception $e) {
    echo "ExternalAccountCredentialsByAws Error" . PHP_EOL;
    echo $e->getMessage() . PHP_EOL;
}
