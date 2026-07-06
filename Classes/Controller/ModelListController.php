<?php

declare(strict_types=1);

namespace Hn\Agent\Controller;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Backs the model-slug autocomplete in the extension configuration UI:
 * proxies /v1/models on the configured OpenAI-compatible provider and
 * caches the result for an hour so opening the config panel isn't a
 * per-focus provider roundtrip.
 *
 * Wired in Configuration/Backend/AjaxRoutes.php:
 *  - typo3_agent_config_models → listAction
 */
#[AsController]
class ModelListController
{
    private const CACHE_LIFETIME = 3600;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly FrontendInterface $cache,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $config = $this->extensionConfiguration->get('agent');
        } catch (\Throwable $e) {
            return new JsonResponse(['models' => [], 'error' => 'Extension configuration unavailable.']);
        }

        $apiUrl = trim((string)($config['apiUrl'] ?? ''));
        $apiKey = trim((string)($config['apiKey'] ?? ''));
        if ($apiUrl === '' || $apiKey === '') {
            return new JsonResponse(['models' => [], 'error' => 'apiUrl or apiKey not configured.']);
        }

        $cacheId = 'agent_models_' . md5($apiUrl);
        $cached = $this->cache->get($cacheId);
        if (is_array($cached)) {
            return new JsonResponse(['models' => $cached, 'cached' => true]);
        }

        $endpoint = rtrim($apiUrl, '/') . '/models';
        try {
            $response = $this->requestFactory->request($endpoint, 'GET', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                ],
                'connect_timeout' => 5,
                'timeout' => 15,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            return new JsonResponse(['models' => [], 'error' => 'Provider unreachable: ' . $e->getMessage()]);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return new JsonResponse([
                'models' => [],
                'error' => 'Provider returned HTTP ' . $status,
            ]);
        }

        $data = json_decode((string)$response->getBody(), true);
        $models = $this->extractModels($data);
        $this->cache->set($cacheId, $models, ['agent_models'], self::CACHE_LIFETIME);
        return new JsonResponse(['models' => $models, 'cached' => false]);
    }

    /**
     * OpenAI/OpenRouter shape: {"data": [{"id": "openai/gpt-4o", ...}, ...]}.
     * Also tolerates a bare list, so drop-in providers that skip the `data`
     * envelope still work.
     *
     * @return list<string>
     */
    private function extractModels(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $list = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $ids = [];
        foreach ($list as $entry) {
            if (is_array($entry) && isset($entry['id']) && is_string($entry['id']) && $entry['id'] !== '') {
                $ids[] = $entry['id'];
            }
        }
        sort($ids, SORT_NATURAL);
        return array_values(array_unique($ids));
    }
}
