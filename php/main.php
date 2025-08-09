<?php

declare(strict_types=1);

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Sts\StsClient;
use Kreait\Firebase\Factory;

require_once "vendor/autoload.php";

echo "Hello World!".PHP_EOL;

try {
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
    $client = new Factory()->createStorage();
    foreach ($client->getStorageClient()->buckets() as $bucket) {
        printf('Bucket: %s' . PHP_EOL, $bucket->getName());
    }
} catch (Exception $e) {
    echo $e->getMessage().PHP_EOL;
}
