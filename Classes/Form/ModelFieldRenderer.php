<?php

declare(strict_types=1);

namespace Hn\Agent\Form;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Renders the "model" field in the Extension Configuration form as a
 * <select> that is populated live from the provider's /models endpoint
 * (proxied via the agent_fetch_models AJAX route).
 *
 * Wired in ext_conf_template.txt via `type=user[Hn\Agent\Form\ModelFieldRenderer->render]`.
 * TYPO3's Install tool calls render() with $params containing fieldName,
 * fieldValue, and propertyName (the form input name attribute).
 *
 * Falls back gracefully: the server-rendered HTML is a plain text input
 * containing the current value, so the form stays usable even without
 * JavaScript or if the /models call fails. The JS progressively enhances
 * it into a dropdown.
 */
class ModelFieldRenderer
{
    public function render(array $params): string
    {
        $propertyName = (string)($params['propertyName'] ?? 'extConf[model]');
        $fieldValue = (string)($params['fieldValue'] ?? '');

        $ajaxUrl = GeneralUtility::makeInstance(UriBuilder::class)
            ->buildUriFromRoute('agent_fetch_models');

        $scriptUrl = PathUtility::getPublicResourceWebPath(
            'EXT:agent/Resources/Public/JavaScript/config-model-field.js'
        );

        $inputId = 'agent-model-field-' . bin2hex(random_bytes(4));

        $html = sprintf(
            '<div class="agent-model-field" data-agent-model-field '
            . 'data-ajax-url="%s" data-input-id="%s">'
            . '<input type="text" id="%s" name="%s" value="%s" class="form-control" '
            . 'placeholder="e.g. anthropic/claude-haiku-4-5" autocomplete="off" />'
            . '<div class="agent-model-field__status form-text"></div>'
            . '</div>'
            . '<script type="module" src="%s"></script>',
            htmlspecialchars((string)$ajaxUrl, ENT_QUOTES),
            htmlspecialchars($inputId, ENT_QUOTES),
            htmlspecialchars($inputId, ENT_QUOTES),
            htmlspecialchars($propertyName, ENT_QUOTES),
            htmlspecialchars($fieldValue, ENT_QUOTES),
            htmlspecialchars($scriptUrl, ENT_QUOTES),
        );

        return $html;
    }
}
