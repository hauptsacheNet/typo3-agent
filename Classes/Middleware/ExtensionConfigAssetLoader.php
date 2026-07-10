<?php

declare(strict_types=1);

namespace Hn\Agent\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Loads the model-input custom element on every backend page so the
 * Extension Configuration form (which is fetched via AJAX into the
 * Install Tool modal) can inject <hn-agent-model-input> via innerHTML
 * and have it auto-upgrade.
 *
 * `innerHTML` doesn't execute <script> tags, so the enhancement can't
 * come from an inline script inside the response — the custom element
 * must be registered *before* the response HTML lands in the DOM.
 */
final class ExtensionConfigAssetLoader implements MiddlewareInterface
{
    public function __construct(
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@hn/agent/model-input.js');
        return $handler->handle($request);
    }
}
