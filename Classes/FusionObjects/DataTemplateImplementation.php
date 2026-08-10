<?php

declare(strict_types=1);

namespace Visol\PaperTiger\ZebraAdaptor\FusionObjects;

/*
 * This file is part of the Visol.PaperTiger.ZebraAdaptor package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 *
 * This is an extended version of the original implementation to provide
 * a marker {allFormValues} and to add the file name as value,
 * for the latter see also https://github.com/sitegeist/Sitegeist.PaperTiger/issues/29
 */

use Neos\Utility\Arrays;
use Sitegeist\FusionForm\Upload\Domain\CachedUploadedFile;

class DataTemplateImplementation extends \Sitegeist\PaperTiger\FusionObjects\DataTemplateImplementation
{
    public function evaluate()
    {
        $template = $this->getTemplate();
        $data = $this->getData();

        return preg_replace_callback(
            '/{([a-z0-9\\-\\.]+)}/ium',
            function (array $matches) use ($data) {
                $value = Arrays::getValueByPath($data, $matches[1]);
                // visol Change: Process allFormValues marker
                if ($matches[1] === 'allFormValues') {
                    return $this->getAllFormValuesHtml($data);
                }
                return htmlspecialchars(strip_tags($this->stringify($value)), ENT_QUOTES);
            },
            $template
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function getAllFormValuesHtml(array $data): string
    {
        $fieldIdentifiersToOmit = ['submit'];
        $stringifiedData = [];
        foreach ($data as $fieldIdentifier => $value) {
            if (in_array($fieldIdentifier, $fieldIdentifiersToOmit, true)) {
                continue;
            }
            $stringifiedData[$fieldIdentifier] = $this->stringify($value);
        }
        if ($stringifiedData === []) {
            return '';
        }
        $html = [];
        $html[] = '<dl>';
        foreach ($stringifiedData as $fieldIdentifier => $value) {
            $html[] = '<dt>' . $fieldIdentifier . '</dt><dd>' . $value . '</dd>';
        }
        $html[] = '</dl>';
        return implode(PHP_EOL, $html);
    }

    public function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        } elseif (is_int($value) || is_float($value)) {
            return (string)$value;
        } elseif ($value instanceof \Stringable) {
            return $value->__toString();
        } elseif ($value instanceof \DateTimeInterface) {
            return $value->format($this->getDateTimeFormat());
        } elseif (is_array($value)) {
            return implode(', ', array_map(fn(mixed $item) => $this->stringify($item), $value));
        // visol change:  Add case for CachedUploadedFile
        } elseif ($value instanceof CachedUploadedFile) {
            return $value->getClientFilename() ?? '';
        } else {
            return '';
        }
    }
}
