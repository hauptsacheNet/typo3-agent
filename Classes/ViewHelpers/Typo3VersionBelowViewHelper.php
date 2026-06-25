<?php

declare(strict_types=1);

namespace Hn\Agent\ViewHelpers;

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractConditionViewHelper;

final class Typo3VersionBelowViewHelper extends AbstractConditionViewHelper
{
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('major', 'int', 'Major TYPO3 version to compare against', true);
    }

    public static function verdict(array $arguments, RenderingContextInterface $renderingContext): bool
    {
        return (new Typo3Version())->getMajorVersion() < (int)$arguments['major'];
    }
}
