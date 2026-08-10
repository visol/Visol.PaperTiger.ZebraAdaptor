<?php

namespace Visol\PaperTiger\ZebraAdaptor\Contract;

use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * Implemented by services registered under
 * Visol.PaperTiger.ZebraAdaptor.formFieldValidators. FormApiController
 * dispatches each submitted field to its registered validator (keyed by the
 * field's NodeType name) before executing form actions.
 *
 * Return an empty array for valid input. Any non-empty array short-circuits
 * the submission with HTTP 422 and the messages surface inline in the FE.
 */
interface FormFieldValidatorInterface
{
    /**
     * @param array<string, mixed> $allFormValues Cleaned form values (honeypots and form-identifier prefixes removed), keyed by the field's identifier.
     * @return string[] Human-readable error messages (empty array = valid).
     */
    public function validate(NodeInterface $fieldNode, mixed $value, array $allFormValues, string $formIdentifier): array;
}
