<?php

declare(strict_types=1);

namespace Hn\Agent\Configuration;

/**
 * Custom renderer for the `model` field in ext_conf_template.txt:
 * emits a <hn-agent-model-input> custom element that upgrades to a text
 * input + <datalist> and pulls model suggestions from the AJAX route
 * typo3_agent_config_models on first focus.
 *
 * The custom-element JS module is loaded on every backend page by
 * ExtensionConfigAssetLoader — inline scripts would be blocked by CSP and
 * innerHTML-inserted <script> tags don't execute anyway.
 */
class ModelFieldRenderer
{
    /**
     * Callback signature dictated by AstConstantCommentVisitor:
     * $params = ['fieldName' => string, 'fieldValue' => string].
     * The returned HTML is rendered raw into the Extension Configuration form.
     */
    public function render(array $params): string
    {
        $name = (string)($params['fieldName'] ?? '');
        $value = (string)($params['fieldValue'] ?? '');

        $nameAttr = htmlspecialchars($name, ENT_QUOTES);
        $valueAttr = htmlspecialchars($value, ENT_QUOTES);
        $listIdAttr = htmlspecialchars('em-agent-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name) . '-models', ENT_QUOTES);

        return <<<HTML
<hn-agent-model-input name="{$nameAttr}" value="{$valueAttr}" list-id="{$listIdAttr}"></hn-agent-model-input>
HTML;
    }
}
