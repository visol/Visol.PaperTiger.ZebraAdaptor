<?php

namespace Visol\PaperTiger\ZebraAdaptor\Eel\Helper;

use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Cryptography\HashService;

class FormHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var HashService
     */
    protected $hashService;

    public function appendHmac(string $value): string
    {
        return $this->hashService->appendHmac($value);
    }

    /**
     * All methods are considered safe
     *
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
