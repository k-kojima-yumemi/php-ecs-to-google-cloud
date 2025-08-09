<?php

declare(strict_types=1);

namespace App;

use Google\Auth\FetchAuthTokenInterface;
use Google\Auth\GetQuotaProjectInterface;
use Google\Auth\GetUniverseDomainInterface;
use Google\Auth\HttpHandler\HttpClientCache;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Auth\OAuth2;
use Google\Auth\ProjectIdProviderInterface;
use Google\Auth\UpdateMetadataInterface;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Override;

class ExternalAccountCredentialsByAws implements
    FetchAuthTokenInterface,
    UpdateMetadataInterface,
    GetQuotaProjectInterface,
    GetUniverseDomainInterface,
    ProjectIdProviderInterface
{

    private const EXTERNAL_ACCOUNT_TYPE = 'external_account';
    private const CLOUD_RESOURCE_MANAGER_URL = 'https://cloudresourcemanager.UNIVERSE_DOMAIN/v1/projects/%s';

    private OAuth2 $auth;
    private ?string $quotaProject = null;
    private ?string $serviceAccountImpersonationUrl = null;
    private ?string $workforcePoolUserProject = null;
    private ?string $projectId = null;
    /** @var array|null */
    private ?array $lastImpersonatedAccessToken = null;
    private string $universeDomain;

    /**
     * @param string|string[] $scope
     * @param array $jsonKey
     */
    public function __construct(array|string $scope, array $jsonKey)
    {
        if (!array_key_exists('type', $jsonKey)) {
            throw new InvalidArgumentException('json key is missing the type field');
        }
        if ($jsonKey['type'] !== self::EXTERNAL_ACCOUNT_TYPE) {
            throw new InvalidArgumentException(sprintf('expected "%s" type but received "%s"', self::EXTERNAL_ACCOUNT_TYPE, $jsonKey['type']));
        }
        foreach (['token_url', 'audience', 'subject_token_type', 'credential_source'] as $key) {
            if (!array_key_exists($key, $jsonKey)) {
                throw new InvalidArgumentException("json key is missing the {$key} field");
            }
        }

        $this->serviceAccountImpersonationUrl = $jsonKey['service_account_impersonation_url'] ?? null;
        $this->quotaProject = $jsonKey['quota_project_id'] ?? null;
        $this->workforcePoolUserProject = $jsonKey['workforce_pool_user_project'] ?? null;
        $this->universeDomain = $jsonKey['universe_domain'] ?? GetUniverseDomainInterface::DEFAULT_UNIVERSE_DOMAIN;

        $credentialSource = $jsonKey['credential_source'];
        $aws = $credentialSource['aws'] ?? $credentialSource;
        $regionalCredVerificationUrl = $aws['regional_cred_verification_url'] ?? null;
        if (!$regionalCredVerificationUrl) {
            throw new InvalidArgumentException('credential_source.aws.regional_cred_verification_url is required');
        }
        $region = $aws['region'] ?? null;

        $subjectTokenFetcher = new AwsSdkSource(
            $jsonKey['audience'],
            $regionalCredVerificationUrl,
            $region,
        );

        $this->auth = new OAuth2([
            'tokenCredentialUri' => $jsonKey['token_url'],
            'audience' => $jsonKey['audience'],
            'scope' => $scope,
            'subjectTokenType' => $jsonKey['subject_token_type'],
            'subjectTokenFetcher' => $subjectTokenFetcher,
            'additionalOptions' => $this->workforcePoolUserProject ? ['userProject' => $this->workforcePoolUserProject] : [],
        ]);

        if (!$this->isWorkforcePool() && $this->workforcePoolUserProject) {
            throw new InvalidArgumentException('workforce_pool_user_project should not be set for non-workforce pool credentials.');
        }
    }

    #[Override]
    public function fetchAuthToken(?callable $httpHandler = null, array $headers = []): array
    {
        $stsToken = $this->auth->fetchAuthToken($httpHandler, $headers);
        if (isset($this->serviceAccountImpersonationUrl)) {
            return $this->lastImpersonatedAccessToken = $this->getImpersonatedAccessToken($stsToken['access_token'], $httpHandler);
        }
        return $stsToken;
    }

    #[Override]
    public function getCacheKey(): ?string
    {
        $scopeOrAudience = $this->auth->getAudience() ?: $this->auth->getScope();
        return $this->auth->getSubjectTokenFetcher()->getCacheKey().
            '.'.$scopeOrAudience.
            '.'.($this->serviceAccountImpersonationUrl ?? '').
            '.'.($this->auth->getSubjectTokenType() ?? '').
            '.'.($this->workforcePoolUserProject ?? '');
    }

    #[Override]
    public function getLastReceivedToken(): ?array
    {
        return $this->lastImpersonatedAccessToken ?? $this->auth->getLastReceivedToken();
    }

    #[Override]
    public function getQuotaProject(): ?string
    {
        return $this->quotaProject;
    }

    #[Override]
    public function getUniverseDomain(): string
    {
        return $this->universeDomain;
    }

    #[Override]
    public function getProjectId(?callable $httpHandler = null, ?string $accessToken = null): ?string
    {
        if (isset($this->projectId)) {
            return $this->projectId;
        }

        $projectNumber = $this->getProjectNumber() ?: $this->workforcePoolUserProject;
        if (!$projectNumber) {
            return null;
        }

        if (is_null($httpHandler)) {
            $httpHandler = HttpHandlerFactory::build(HttpClientCache::getHttpClient());
        }

        $url = str_replace('UNIVERSE_DOMAIN', $this->getUniverseDomain(), sprintf(self::CLOUD_RESOURCE_MANAGER_URL, $projectNumber));

        if (is_null($accessToken)) {
            $accessToken = $this->fetchAuthToken($httpHandler)['access_token'];
        }

        $request = new Request('GET', $url, ['authorization' => 'Bearer '.$accessToken]);
        $response = $httpHandler($request);
        $body = json_decode((string) $response->getBody(), true);
        return $this->projectId = $body['projectId'];
    }

    private function getProjectNumber(): ?string
    {
        $parts = explode('/', $this->auth->getAudience());
        $i = array_search('projects', $parts, true);
        return $i === false ? null : ($parts[$i + 1] ?? null);
    }

    private function isWorkforcePool(): bool
    {
        $regex = '#//iam\\.googleapis\\.com/locations/[^/]+/workforcePools/#';
        return preg_match($regex, $this->auth->getAudience()) === 1;
    }

    private function getImpersonatedAccessToken(string $stsToken, ?callable $httpHandler = null): array
    {
        if (!isset($this->serviceAccountImpersonationUrl)) {
            throw new InvalidArgumentException('service_account_impersonation_url must be set in JSON credentials.');
        }
        $request = new Request(
            'POST',
            $this->serviceAccountImpersonationUrl,
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$stsToken,
            ],
            (string) json_encode([
                'lifetime' => sprintf('%ss', OAuth2::DEFAULT_EXPIRY_SECONDS),
                'scope' => explode(' ', $this->auth->getScope()),
            ])
        );
        if (is_null($httpHandler)) {
            $httpHandler = HttpHandlerFactory::build(HttpClientCache::getHttpClient());
        }
        $response = $httpHandler($request);
        $body = json_decode((string) $response->getBody(), true);
        return [
            'access_token' => $body['accessToken'],
            'expires_at' => strtotime($body['expireTime']),
        ];
    }

    #[Override]
    public function updateMetadata($metadata, $authUri = null, ?callable $httpHandler = null): array
    {
        $metadataCopy = $metadata;
        if (isset($metadataCopy[UpdateMetadataInterface::AUTH_METADATA_KEY])) {
            return $metadataCopy;
        }
        $result = $this->fetchAuthToken($httpHandler);
        if (isset($result['access_token'])) {
            $metadataCopy[UpdateMetadataInterface::AUTH_METADATA_KEY] = ['Bearer '.$result['access_token']];
        } elseif (isset($result['id_token'])) {
            $metadataCopy[UpdateMetadataInterface::AUTH_METADATA_KEY] = ['Bearer '.$result['id_token']];
        }
        return $metadataCopy;
    }
}