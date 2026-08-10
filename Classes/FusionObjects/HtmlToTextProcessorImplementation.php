<?php

declare(strict_types=1);

namespace Visol\PaperTiger\ZebraAdaptor\FusionObjects;

/*
 * This file is part of the Visol.PaperTiger.ZebraAdaptor package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Fusion\FusionObjects\AbstractFusionObject;
use Soundasleep\Html2Text;

/**
 * Convert HTML to Text
 * This can be used to generate a plain text version of an HTML e-mail
 */
class HtmlToTextProcessorImplementation extends AbstractFusionObject
{
    /**
     * @return mixed
     */
    public function getValue()
    {
        $options = [
            'ignore_errors' => true,
        ];
        return Html2Text::convert($this->fusionValue('value'), $options);
    }

    /**
     * Just return the processed value
     *
     * @return mixed
     */
    public function evaluate()
    {
        return $this->getValue();
    }
}
