<?php

declare(strict_types=1);

use App\ExternalAccountCredentialsByAws;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Sts\StsClient;
use Google\Cloud\Storage\StorageClient;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\FirebaseException;
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
    echo "StorageClient Auth via ExternalAccountCredentialsByAws fromFile".PHP_EOL;
    $jsonPath = __DIR__.'/auth.json';

    $credentials = ExternalAccountCredentialsByAws::fromFile($jsonPath);

    $storage = new StorageClient([
        'credentialsFetcher' => $credentials,
        'suppressKeyFileNotice' => true,
    ]);

    $bucketName = getenv('GCS_BUCKET');
    $bucket = $storage->bucket($bucketName);
    $info = $bucket->info();

    printf("Bucket: %s %s%s", $info["name"], $info["location"], PHP_EOL);
} catch (Exception $e) {
    echo "ExternalAccountCredentialsByAws Error".PHP_EOL;
    echo $e->getMessage().PHP_EOL;
}

try {
    echo "StorageClient Auth via ExternalAccountCredentialsByAws fromJson".PHP_EOL;
    $jsonPath = __DIR__.'/auth.json';
    $json = file_get_contents($jsonPath);
    if ($json === false) {
        throw new InvalidArgumentException("Unable to read json file");
    }

    $credentials = ExternalAccountCredentialsByAws::fromJson($json);

    $storage = new StorageClient([
        'credentialsFetcher' => $credentials,
        'suppressKeyFileNotice' => true,
    ]);

    $bucketName = getenv('GCS_BUCKET');
    $bucket = $storage->bucket($bucketName);
    $info = $bucket->info();

    printf("Bucket: %s %s%s", $info["name"], $info["location"], PHP_EOL);
} catch (Exception $e) {
    echo "ExternalAccountCredentialsByAws Error".PHP_EOL;
    echo $e->getMessage().PHP_EOL;
}

try {
    echo "Kreait\Firebase\Factory Auth via ExternalAccountCredentialsByAws".PHP_EOL;
    $factory = new Factory();
    $jsonPath = __DIR__.'/auth.json';
    $credentials = ExternalAccountCredentialsByAws::fromFile($jsonPath, Factory::API_CLIENT_SCOPES);

    // Inject credentials into private property via reflection
    $ref = new \ReflectionClass($factory);
    $prop = $ref->getProperty('googleAuthTokenCredentials');
    $prop->setValue($factory, $credentials);

    // auth
    $auth = $factory->createAuth();
    $users = $auth->listUsers();
    $userCount = 0;
    /** @var UserRecord $user */
    foreach ($users as $user) {
        printf("User: %s%s", $user->email, PHP_EOL);
        $userCount++;
    }
    printf("UserCount: %d%s", $userCount, PHP_EOL);

    // storage
    $storage = $factory->createStorage();
    $bucketName = getenv('GCS_BUCKET');
    $bucket = $storage->getBucket($bucketName);
    $info = $bucket->info();
    printf("Bucket: %s %s%s", $info["name"], $info["location"], PHP_EOL);
} catch (Exception $e) {
    echo "Kreait\Firebase\Factory Auth Error".PHP_EOL;
    echo $e->getMessage().PHP_EOL;
} catch (FirebaseException $e) {
    echo "Kreait\Firebase\Factory FirebaseException".PHP_EOL;
    echo $e->getMessage().PHP_EOL;
}
