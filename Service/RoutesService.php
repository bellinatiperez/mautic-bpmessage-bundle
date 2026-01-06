<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticBpMessageBundle\Http\BpMessageClient;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Service to manage BpMessage routes with caching.
 */
class RoutesService
{
    private const CACHE_PREFIX = 'bpmessage_routes_';
    private const DEFAULT_CACHE_TTL = 14400; // 4 hours in seconds

    private BpMessageClient $client;
    private CacheInterface $cache;
    private LoggerInterface $logger;
    private IntegrationHelper $integrationHelper;

    public function __construct(
        BpMessageClient $client,
        CacheInterface $cache,
        LoggerInterface $logger,
        IntegrationHelper $integrationHelper
    ) {
        $this->client            = $client;
        $this->cache             = $cache;
        $this->logger            = $logger;
        $this->integrationHelper = $integrationHelper;
    }

    /**
     * Get routes from cache or API.
     *
     * @param string $bookBusinessForeignId Book business foreign ID (Carteira) - string to preserve leading zeros
     * @param string $crmId                 CRM ID - string to preserve leading zeros/alphanumeric values
     * @param int    $serviceTypeId         Service type (1=WhatsApp, 2=SMS, 4=RCS)
     *
     * @return array List of routes
     */
    public function getRoutes(string $bookBusinessForeignId, string $crmId, int $serviceTypeId): array
    {
        $cacheKey = $this->getCacheKey($bookBusinessForeignId, $crmId, $serviceTypeId);
        $ttl      = $this->getCacheTtl();

        $this->logger->info('BpMessage RoutesService: Getting routes', [
            'cacheKey'              => $cacheKey,
            'ttl'                   => $ttl,
            'bookBusinessForeignId' => $bookBusinessForeignId,
            'crmId'                 => $crmId,
            'serviceTypeId'         => $serviceTypeId,
        ]);

        // Check API URL first
        $apiBaseUrl = $this->getApiBaseUrl();
        $this->logger->info('BpMessage RoutesService: API Base URL', [
            'apiBaseUrl' => $apiBaseUrl ?? 'NOT CONFIGURED',
        ]);

        if (!$apiBaseUrl) {
            $this->logger->error('BpMessage RoutesService: API Base URL not configured');

            return [];
        }

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($bookBusinessForeignId, $crmId, $serviceTypeId, $ttl, $apiBaseUrl) {
            $item->expiresAfter($ttl);

            $this->logger->info('BpMessage RoutesService: Cache miss, fetching from API', [
                'bookBusinessForeignId' => $bookBusinessForeignId,
                'crmId'                 => $crmId,
                'serviceTypeId'         => $serviceTypeId,
                'apiBaseUrl'            => $apiBaseUrl,
            ]);

            // Set API base URL
            $this->client->setBaseUrl($apiBaseUrl);

            $result = $this->client->getRoutes($bookBusinessForeignId, $crmId, $serviceTypeId);

            $this->logger->info('BpMessage RoutesService: API response', [
                'success' => $result['success'],
                'error'   => $result['error'] ?? null,
                'count'   => count($result['routes'] ?? []),
            ]);

            if (!$result['success']) {
                $this->logger->error('BpMessage RoutesService: API call failed', [
                    'error' => $result['error'],
                ]);

                // Return empty array on error, don't cache failures
                $item->expiresAfter(0);

                return [];
            }

            $this->logger->info('BpMessage RoutesService: Routes fetched successfully', [
                'count' => count($result['routes']),
            ]);

            return $result['routes'];
        });
    }

    /**
     * Get idQuotaSettings for a specific route (idServiceSettings).
     *
     * @param int    $idServiceSettings     The service settings ID to look up
     * @param string $bookBusinessForeignId Book business foreign ID (Carteira) - string to preserve leading zeros
     * @param string $crmId                 CRM ID - string to preserve leading zeros/alphanumeric values
     * @param int    $serviceTypeId         Service type (1=WhatsApp, 2=SMS, 4=RCS)
     *
     * @return int|null The idQuotaSettings for the route, or null if not found
     */
    public function getQuotaSettingsForRoute(
        int $idServiceSettings,
        string $bookBusinessForeignId,
        string $crmId,
        int $serviceTypeId
    ): ?int {
        $routes = $this->getRoutes($bookBusinessForeignId, $crmId, $serviceTypeId);

        foreach ($routes as $route) {
            if (isset($route['idServiceSettings']) && (int) $route['idServiceSettings'] === $idServiceSettings) {
                $idQuotaSettings = $route['idQuotaSettings'] ?? null;

                $this->logger->info('BpMessage RoutesService: Found idQuotaSettings for route', [
                    'idServiceSettings' => $idServiceSettings,
                    'idQuotaSettings'   => $idQuotaSettings,
                    'routeName'         => $route['name'] ?? 'unknown',
                ]);

                return $idQuotaSettings !== null ? (int) $idQuotaSettings : null;
            }
        }

        $this->logger->warning('BpMessage RoutesService: Route not found', [
            'idServiceSettings'     => $idServiceSettings,
            'bookBusinessForeignId' => $bookBusinessForeignId,
            'crmId'                 => $crmId,
            'serviceTypeId'         => $serviceTypeId,
            'availableRoutes'       => count($routes),
        ]);

        return null;
    }

    /**
     * Get route name for a specific idServiceSettings.
     *
     * @param int    $idServiceSettings     The service settings ID to look up
     * @param string $bookBusinessForeignId Book business foreign ID (Carteira) - string to preserve leading zeros
     * @param string $crmId                 CRM ID - string to preserve leading zeros/alphanumeric values
     * @param int    $serviceTypeId         Service type (1=WhatsApp, 2=SMS, 4=RCS)
     *
     * @return string|null The route name, or null if not found
     */
    public function getRouteNameByIdServiceSettings(
        int $idServiceSettings,
        string $bookBusinessForeignId,
        string $crmId,
        int $serviceTypeId
    ): ?string {
        $routes = $this->getRoutes($bookBusinessForeignId, $crmId, $serviceTypeId);

        foreach ($routes as $route) {
            if (isset($route['idServiceSettings']) && (int) $route['idServiceSettings'] === $idServiceSettings) {
                return $route['name'] ?? null;
            }
        }

        return null;
    }

    /**
     * Clear routes cache.
     *
     * @param string|null $bookBusinessForeignId If provided, clear only this specific cache
     * @param string|null $crmId                 If provided, clear only this specific cache
     * @param int|null    $serviceTypeId         If provided, clear only this specific cache
     */
    public function clearCache(
        ?string $bookBusinessForeignId = null,
        ?string $crmId = null,
        ?int $serviceTypeId = null
    ): void {
        if (null !== $bookBusinessForeignId && null !== $crmId && null !== $serviceTypeId) {
            $cacheKey = $this->getCacheKey($bookBusinessForeignId, $crmId, $serviceTypeId);
            $this->cache->delete($cacheKey);

            $this->logger->info('BpMessage RoutesService: Cache cleared for specific key', [
                'cacheKey' => $cacheKey,
            ]);
        } else {
            // Note: Symfony Cache doesn't support wildcard deletion easily
            // For full cache clear, we'd need to track all keys or use a different approach
            $this->logger->warning('BpMessage RoutesService: Full cache clear not implemented. Provide specific parameters.');
        }
    }

    /**
     * Generate cache key for routes.
     */
    private function getCacheKey(string $bookBusinessForeignId, string $crmId, int $serviceTypeId): string
    {
        return self::CACHE_PREFIX."{$bookBusinessForeignId}_{$crmId}_{$serviceTypeId}";
    }

    /**
     * Get cache TTL from integration settings.
     */
    private function getCacheTtl(): int
    {
        $integration = $this->integrationHelper->getIntegrationObject('BpMessage');

        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            return self::DEFAULT_CACHE_TTL;
        }

        $settings = $integration->getIntegrationSettings()->getFeatureSettings();

        return (int) ($settings['routes_cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
    }

    /**
     * Get API base URL from integration settings.
     */
    private function getApiBaseUrl(): ?string
    {
        $integration = $this->integrationHelper->getIntegrationObject('BpMessage');

        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            return null;
        }

        $settings = $integration->getIntegrationSettings()->getFeatureSettings();

        return $settings['api_base_url'] ?? null;
    }
}
