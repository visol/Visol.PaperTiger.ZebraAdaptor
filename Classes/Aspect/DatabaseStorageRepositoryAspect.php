<?php

declare(strict_types=1);

namespace Visol\PaperTiger\ZebraAdaptor\Aspect;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Wegmeister\DatabaseStorage\Domain\Model\DatabaseStorage;
use Wegmeister\DatabaseStorage\Domain\Repository\DatabaseStorageRepository;

/**
 * @Flow\Aspect
 */
class DatabaseStorageRepositoryAspect
{
    /**
     * Currently used storage identifier.
     *
     * @var string
     */
    protected $currentIdentifier = '';

    /**
     * List of identifiers.
     *
     * @var array<int, string>
     */
    protected $identifiers = [];

    /**
     * @Flow\Around("method(Wegmeister\DatabaseStorage\Domain\Repository\DatabaseStorageRepository->findStorageidentifiers())")
     * @return array<int, string>
     */
    public function filterStorageIdentifiersAdvice(JoinPointInterface $joinPoint): array
    {
        if ($this->identifiers !== []) {
            return $this->identifiers;
        }
        /** @var DatabaseStorageRepository $databaseStorageRepository */
        $databaseStorageRepository = $joinPoint->getProxy();
        /** @var DatabaseStorage $item */
        foreach ($databaseStorageRepository->findAll() as $item) {
            if ($this->currentIdentifier !== $item->getStorageidentifier()) {
                $this->identifiers[] = $item->getStorageidentifier();
                $this->currentIdentifier = $item->getStorageidentifier();
            }
        }
        return $this->identifiers;
    }
}
