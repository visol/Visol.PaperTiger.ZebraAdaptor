<?php

namespace Visol\PaperTiger\ZebraAdaptor\Contract;

use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * Implemented by services registered under
 * Visol.PaperTiger.ZebraAdaptor.formActionHandlers. FormApiController
 * dispatches form submissions to handlers via this contract.
 */
interface FormActionHandlerInterface
{
    /**
     * @param array<string, mixed> $formValues Already cleaned of honeypots and form-identifier prefixes.
     */
    public function handleSubmission(array $formValues, string $language, NodeInterface $actionNode): void;
}
