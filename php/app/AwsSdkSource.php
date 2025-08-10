<?php

declare(strict_types=1);

namespace App;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\CredentialsInterface;
use Aws\Signature\SignatureV4;
use Google\Auth\ExternalAccountCredentialSourceInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Promise\PromiseInterface;
use Override;

/**
 * External account credential source backed by AWS SDK for PHP.
 *
 * This generates a subject token for Google STS by creating a SigV4-signed
 * AWS STS GetCallerIdentity request using the default AWS credential provider chain.
 */
class AwsSdkSource implements ExternalAccountCredentialSourceInterface
{
    private string $audience;
    private string $regionalCredVerificationUrl;
    private ?string $region;
    /** @var callable|null */
    private $credentialProvider;
    /** @var array<string,mixed> */
    private array $providerConfig;

    public function __construct(
        string $audience,
        string $regionalCredVerificationUrl,
        ?string $region = null,
        ?callable $credentialProvider = null,
        array $providerConfig = []
    )
    {
        $this->audience = $audience;
        $this->regionalCredVerificationUrl = $regionalCredVerificationUrl;
        $this->region = $region;
        $this->credentialProvider = $credentialProvider;
        $this->providerConfig = $providerConfig;
    }

    #[Override]
    public function fetchSubjectToken(?callable $httpHandler = null): string
    {
        $region = $this->resolveRegion();

        // Resolve AWS credentials from provided provider or default chain (env, shared config, SSO, IMDS, etc.)
        $provider = $this->credentialProvider ?? CredentialProvider::defaultProvider($this->providerConfig);
        $result = $provider();
        if ($result instanceof PromiseInterface) {
            $credentials = $result->wait();
        } else {
            // Assume credentials object
            /** @var CredentialsInterface $credentials */
            $credentials = $result;
        }

        // Build the request exactly as the STS server expects.
        // The regionalCredVerificationUrl should include the GetCallerIdentity action and version query params.
        $url = str_replace('{region}', $region, $this->regionalCredVerificationUrl);
        $request = new Request('POST', $url);

        // Sign the request with SigV4 for service "sts"
        $signer = new SignatureV4('sts', $region);
        $signed = $signer->signRequest($request, $credentials);

        // Collect headers into the format expected by Google STS
        $formattedHeaders = [];
        foreach ($signed->getHeaders() as $key => $values) {
            if (!$values) {
                continue;
            }
            $formattedHeaders[] = ['key' => $key, 'value' => $values[0]];
        }
        // Inject x-goog-cloud-target-resource into header list
        $formattedHeaders[] = ['key' => 'x-goog-cloud-target-resource', 'value' => $this->audience];

        $subjectRequest = [
            'headers' => $formattedHeaders,
            'method' => 'POST',
            'url' => (string) $signed->getUri(),
        ];

        return urlencode(json_encode($subjectRequest) ?: '');
    }

    #[Override]
    public function getCacheKey(): ?string
    {
        return 'aws-sdk.'.$this->resolveRegion().'.'.$this->regionalCredVerificationUrl;
    }

    private static function inferRegion(string $url): ?string
    {
        // Extract region from hosts like sts.us-east-1.amazonaws.com
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if (preg_match('/^sts\.([a-z0-9-]+)\.amazonaws\.com$/', (string) $host, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private function resolveRegion(): string
    {
        return $this->region
            ?? self::inferRegion($this->regionalCredVerificationUrl)
            ?? (getenv('AWS_REGION') ?: getenv('AWS_DEFAULT_REGION') ?: 'us-east-1');
    }
}


