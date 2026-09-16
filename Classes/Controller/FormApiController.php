<?php

namespace Visol\PaperTiger\ZebraAdaptor\Controller;

use Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\ResourceManagement\ResourceRepository;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Flow\Security\Exception\InvalidArgumentForHashGenerationException;
use Neos\Flow\Security\Exception\InvalidHashException;
use Neos\Flow\Utility\Now;
use Networkteam\Neos\ContentApi\Controller\ErrorHandlingTrait;
use Psr\Log\LoggerInterface;
use Visol\PaperTiger\ZebraAdaptor\Contract\FormActionHandlerInterface;
use Visol\PaperTiger\ZebraAdaptor\Contract\FormFieldValidatorInterface;
use Visol\PaperTiger\ZebraAdaptor\Service\FormService;
use Wegmeister\DatabaseStorage\Domain\Model\DatabaseStorage;
use Wegmeister\DatabaseStorage\Domain\Repository\DatabaseStorageRepository;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Neos\Service\LinkingService;
use Psr\Http\Message\UploadedFileInterface;

class FormApiController extends ActionController
{
    use ErrorHandlingTrait;

    #[Flow\Inject]
    protected HashService $hashService;

    #[Flow\Inject]
    protected ContextFactoryInterface $contextFactory;

    #[Flow\Inject]
    protected DatabaseStorageRepository $databaseStorageRepository;

    #[Flow\Inject]
    protected FormService $formService;

    // $objectManager is inherited (untyped) from ActionController — do not redeclare.

    /**
     * @var array<string, array{className: class-string<FormActionHandlerInterface>, successKey?: string}>
     */
    #[Flow\InjectConfiguration(path: 'formActionHandlers', package: 'Visol.PaperTiger.ZebraAdaptor')]
    protected array $formActionHandlers = [];

    /**
     * @var array<string, class-string<FormFieldValidatorInterface>>
     */
    #[Flow\InjectConfiguration(path: 'formFieldValidators', package: 'Visol.PaperTiger.ZebraAdaptor')]
    protected array $formFieldValidators = [];

    /**
     * Field NodeType names (keys, truthy values) whose presence in a submitted
     * form means the form embeds server-computed data into the cached document
     * (e.g. timeslot availability). Only such forms cause the frontend to
     * revalidate the document cache after submission — this keeps generic forms
     * (contact, newsletter) from being usable as cache-busters, and keeps this
     * package customer-agnostic (domain packages register their own types).
     *
     * @var array<string, bool>
     */
    #[Flow\InjectConfiguration(path: 'documentRevalidatingFieldTypes', package: 'Visol.PaperTiger.ZebraAdaptor')]
    protected array $documentRevalidatingFieldTypes = [];

    /**
     * Field NodeType names that carry no user data (buttons). Their submitted
     * values are kept out of the {allFormValues} e-mail placeholder. Resolved to
     * concrete field names per form so it keeps working when an editor renames
     * the button.
     *
     * @var array<string, string>
     */
    #[Flow\InjectConfiguration(path: 'fieldTypes.omitted', package: 'Visol.PaperTiger.ZebraAdaptor')]
    protected array $omittedFieldTypes = [];

    #[Flow\Inject]
    protected ResourceManager $resourceManager;

    #[Flow\Inject]
    protected ResourceRepository $resourceRepository;

    #[Flow\Inject]
    protected LinkingService $linkingService;

    /**
     * @var string
     */
    protected $defaultViewObjectName = JsonView::class;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $formApiLogger;

    public function submitAction(): void
    {
        $submitId = bin2hex(random_bytes(13));

        $arguments = $this->request->getArguments();

        $this->formApiLogger->info('Form submission received.', ['id' => $submitId]);

        // STEP 1:
        // * Turn the form data to an associative array
        // * Extract the field holding the form identifier and its HMAC for validation
        $formData = [];
        $formIdentifierWithHmac = '';
        $language = '';
        foreach ($arguments as $key => $argument) {
            if ($argument['name'] === '__form') {
                $formIdentifierWithHmac = $argument['value'];
                unset($arguments[$key]);
            } elseif ($argument['name'] === '__language') {
                $language = $argument['value'];
            } else {
                // TODO: We need to property-map the data, e.g. to turn a string "true" into a boolean true
                if (str_ends_with($argument['name'], '[_upload]')) {
                    unset($arguments[$key]);
                } elseif (str_ends_with($argument['name'], '[_uploadedFileIdentifier]')) {
                    try {
                        $resourceIdentifier = $this->hashService->validateAndStripHmac($argument['value']);
                    } catch (\Exception $e) {
                        $this->formApiLogger->error(
                            'HMAC validation for uploaded file failed. Form submission aborted. ' . $e->getMessage(),
                            ['id' => $submitId, 'fileIdentifierWithHmac' => $argument['value']]
                        );
                        throw new Exception('HMAC validation failed: ' . $e->getMessage(), 1722026920);
                    }

                    $resource = $this->resourceRepository->findByIdentifier($resourceIdentifier);
                    $fieldName = str_replace('[_uploadedFileIdentifier]', '', $argument['name']);
                    if (str_ends_with($fieldName, '[]')) {
                        $multiFieldName = substr($fieldName, 0, -2);
                        $formData[$multiFieldName][] = $resource;
                    } else {
                        $formData[$fieldName] = $resource;
                    }
                } elseif (str_ends_with($argument['name'], '[]')) {
                    $formData[$argument['name']][] = $argument['value'];
                } else {
                    $formData[$argument['name']] = $argument['value'];
                }
            }
        }

        // STEP 2:
        // We ensure that the form identifier was not tampered with
        try {
            $formIdentifier = $this->hashService->validateAndStripHmac($formIdentifierWithHmac);
        } catch (\Exception $e) {
            $this->formApiLogger->error(
                'HMAC validation failed. Form submission aborted. ' . $e->getMessage(),
                ['id' => $submitId, 'formIdentifierWithHmac' => $formIdentifierWithHmac]
            );
            throw new Exception('HMAC validation failed: ' . $e->getMessage(), 1722026920);
        }

        // STEP 3:
        // We exit if we don't have a language
        if ($language === '') {
            $this->formApiLogger->error('No language parameter given. Form submission aborted.', ['id' => $submitId]);
            throw new Exception('No language parameter given.', 1722026921);
        }

        // STEP 4:
        // We validate and remove the Honeypot fields
        // This must run before form identifier filtering, because honeypot fields
        // use their own node identifier as prefix, not the form identifier.
        foreach ($formData as $fieldName => $value) {
            if (str_ends_with($fieldName, '[one]')) {
                if ($value !== '' && $value !== null) {
                    $this->formApiLogger->error('Honeypot field one was filled, must be empty. Form submission aborted.', ['id' => $submitId]);
                    throw new Exception('Honeypot field one was filled, must be empty.', 1724251321);
                }
                unset($formData[$fieldName]);
            }
            if (str_ends_with($fieldName, '[two]')) {
                $this->validateTimestampWithHmac($value, 'two', $submitId);
                unset($formData[$fieldName]);
            }
            if (str_ends_with($fieldName, '[three]')) {
                $this->validateTimestampWithHmac($value, 'three', $submitId);
                unset($formData[$fieldName]);
            }
        }

        // STEP 5:
        // We throw out all (potentially tampered) data that doesn't belong to the given form node
        foreach ($formData as $fieldName => $value) {
            if (!str_starts_with($fieldName, $formIdentifier)) {
                unset($formData[$fieldName]);
            }
        }

        // STEP 6:
        // Per-field server-side validation via the formFieldValidators registry.
        // Domain packages register validators in Settings.yaml keyed by field
        // NodeType name; we dispatch each submitted field through its handler
        // (if any) and short-circuit the whole submission on the first non-empty
        // error array so actions never run on invalid data.
        $contextConfiguration = [
            'workspaceName' => 'live',
            'currentDateTime' => new Now(),
            'dimensions' => [
                'language' => [$language],
            ],
            'invisibleContentShown' => false,
            'removedContentShown' => false,
            'inaccessibleContentShown' => false,
        ];
        $context = $this->contextFactory->create($contextConfiguration);
        $formNodeQuery = new FlowQuery([$context->getCurrentSiteNode()]);
        $formNode = $formNodeQuery->find('#' . $formIdentifier)->get(0);

        $cleanedFormValues = $this->getFormValues($formData, $formIdentifier);
        $fieldErrors = [];

        if ($formNode instanceof NodeInterface && $this->formFieldValidators !== []) {
            $formFieldQuery = new FlowQuery([$formNode]);
            $formFields = $formFieldQuery->find('[instanceof Sitegeist.PaperTiger:Field]')->get();

            foreach ($formFields as $fieldNode) {
                /** @var NodeInterface $fieldNode */
                $fieldNodeTypeName = $fieldNode->getNodeType()->getName();
                if (!isset($this->formFieldValidators[$fieldNodeTypeName])) {
                    continue;
                }

                $fieldName = $fieldNode->getProperty('name');
                if (!is_string($fieldName) || $fieldName === '') {
                    continue;
                }

                $prefixedFieldName = $formIdentifier . '[' . $fieldName . ']';
                // Multi-value fields (Checkboxes, RadioButtons, ...) submit under the [] suffix
                $value = $formData[$prefixedFieldName] ?? $formData[$prefixedFieldName . '[]'] ?? null;

                try {
                    $validatorClass = $this->formFieldValidators[$fieldNodeTypeName];
                    $validator = $this->objectManager->get($validatorClass);
                    if (!$validator instanceof FormFieldValidatorInterface) {
                        throw new Exception(
                            sprintf('Form field validator %s must implement %s.', $validatorClass, FormFieldValidatorInterface::class),
                            1747569300
                        );
                    }
                    $errorsForField = $validator->validate($fieldNode, $value, $cleanedFormValues, $formIdentifier);
                    if ($errorsForField !== []) {
                        $fieldErrors[$prefixedFieldName] = array_values($errorsForField);
                    }
                } catch (\Throwable $e) {
                    $this->formApiLogger->error(
                        sprintf('Form field validator for %s failed. %s', $fieldNodeTypeName, $e->getMessage()),
                        ['id' => $submitId]
                    );
                    // A broken validator must not silently let through data the legacy form rejected.
                    $fieldErrors[$prefixedFieldName] = ['Validation failed.'];
                }
            }
        }

        if ($fieldErrors !== []) {
            $this->formApiLogger->info('Form submission rejected by field validators.', [
                'id' => $submitId,
                'fieldErrors' => array_keys($fieldErrors),
            ]);
            $this->response->setStatusCode(422);
            $this->view->assign('value', [
                'data' => [
                    'status' => 'invalid',
                    'fieldErrors' => $fieldErrors,
                ],
            ]);
            return;
        }

        // STEP 7:
        // The form is valid, so we can now execute the form actions
        // TODO: This code needs to be refactored including all helper methods
        $data = [];
        $errors = [];

        $formActionQuery = new FlowQuery([$formNode]);
        $formActions = $formActionQuery->find('[instanceof Sitegeist.PaperTiger:Action]')->get();

        foreach ($formActions as $formAction) {
            /** @var NodeInterface $formAction */

            // MESSAGE
            if ($formAction->getNodeType()->getName() === 'Sitegeist.PaperTiger:Action.Message') {
                $message = $formAction->getProperty('message');
                $data['message'] = $message;
                $this->formApiLogger->info('Message action executed.', ['id' => $submitId]);
            }

            // DATABASE STORAGE
            if ($formAction->getNodeType()->getName() === 'Visol.PaperTiger.ZebraAdaptor:Action.DatabaseStorage') {
                $identifier = $formAction->getProperty('formIdentifier');
                if ($identifier === null || $identifier === '') {
                    $this->formApiLogger->warning('DatabaseStorage action executed without formIdentifier property. Using __undefined__ as identifier.', ['id' => $submitId]);
                    $identifier = '__undefined__';
                }
                try {
                    /*
                     * The same fields the e-mail leaves out of {allFormValues}: a
                     * field type registered under fieldTypes.omitted submits a value
                     * but carries no user data, so there is nothing about it worth
                     * keeping. The captcha is the one that shows — its solution is
                     * several kilobytes of base64, spent the moment it was verified,
                     * and it ended up in every row of the DatabaseStorage module and
                     * every CSV export next to the name and e-mail an editor is
                     * actually there to read.
                     */
                    $properties = $this->getFormValues($formData, $formIdentifier);
                    foreach ($this->getNonDataFieldNames($formNode) as $nonDataFieldName) {
                        unset($properties[$nonDataFieldName]);
                    }

                    $dbStorage = new DatabaseStorage();
                    $dbStorage->setStorageidentifier($identifier)->setProperties($properties)->setDateTime(new \DateTime());
                    $this->databaseStorageRepository->add($dbStorage);
                    $this->formApiLogger->info('DatabaseStorage action executed with identifier: ' . $identifier, ['id' => $submitId]);
                } catch (\Throwable $e) {
                    $this->formApiLogger->error('DatabaseStorage action failed. ' . $e->getMessage(), ['id' => $submitId]);
                    $errors[] = ['action' => 'DatabaseStorage'];
                }
            }

            // EMAIL
            if ($formAction->getNodeType()->getName() === 'Sitegeist.PaperTiger:Action.Email') {
                $this->formApiLogger->info('Email action executed.', ['id' => $submitId]);
                $subject = $formAction->getProperty('subject');
                $html = $formAction->getProperty('html');
                $recipientAddress = $formAction->getProperty('recipientAddress');
                $recipientName = $formAction->getProperty('recipientName');
                $senderAddress = $formAction->getProperty('senderAddress');
                $senderName = $formAction->getProperty('senderName');
                $replyToAddress = $formAction->getProperty('replyToAddress');
                $carbonCopyAddress = $formAction->getProperty('carbonCopyAddress');
                $blindCarbonCopyAddress = $formAction->getProperty('blindCarbonCopyAddress');
                $attachUploads = $formAction->getProperty('attachUploads');
                $formValues = $this->getFormValues($formData, $formIdentifier);
                $fieldLabelMap = $this->getFieldLabelMap($formNode);
                $excludeFieldNames = $this->getNonDataFieldNames($formNode);

                try {
                    $this->formService->sendEmail(
                        formValues:             $formValues,
                        subject:                $subject,
                        html:                   $html,
                        recipientAddress:       $recipientAddress,
                        senderAddress:          $senderAddress,
                        replyToAddress:         $replyToAddress,
                        recipientName:          $recipientName,
                        senderName:             $senderName,
                        carbonCopyAddress:      $carbonCopyAddress,
                        blindCarbonCopyAddress: $blindCarbonCopyAddress,
                        attachUploads:          $attachUploads,
                        fieldLabelMap:          $fieldLabelMap,
                        excludeFieldNames:      $excludeFieldNames
                    );
                    $this->formApiLogger->info(
                        'Email sent with subject: ' . $subject,
                        ['id' => $submitId, 'recipientAddress' => $recipientAddress]
                    );
                } catch (\Throwable $e) {
                    $this->formApiLogger->error('Email could not be sent. ' . $e->getMessage(), ['id' => $submitId]);
                    $errors[] = ['action' => 'Email'];
                }
            }

            // REDIRECT
            if ($formAction->getNodeType()->getName() === 'Sitegeist.PaperTiger:Action.Redirect') {
                $redirectUri = trim((string) $formAction->getProperty('uri'));
                if ($redirectUri !== '') {
                    if ($this->linkingService->hasSupportedScheme($redirectUri)) {
                        $controllerContext = $this->getControllerContext();
                        $resolvedUri = $this->linkingService->resolveNodeUri(
                            $redirectUri,
                            $formNode,
                            $controllerContext,
                            false
                        );
                        if ($resolvedUri !== null) {
                            $redirectUri = $resolvedUri;
                        } else {
                            $this->formApiLogger->warning('Could not resolve node URI for redirect.', ['id' => $submitId, 'uri' => $redirectUri]);
                            $redirectUri = null;
                        }
                    }
                    if ($redirectUri !== null && $redirectUri !== '') {
                        $data['redirectUri'] = $redirectUri;
                    }
                }
                $this->formApiLogger->info('Redirect action executed.', ['id' => $submitId, 'uri' => $redirectUri]);
            }

            // REGISTERED HANDLERS — domain packages register handlers in Settings.yaml
            // under Visol.PaperTiger.ZebraAdaptor.formActionHandlers keyed by NodeType name.
            $nodeTypeName = $formAction->getNodeType()->getName();
            if (isset($this->formActionHandlers[$nodeTypeName])) {
                $config = $this->formActionHandlers[$nodeTypeName];
                $className = $config['className'];
                $successKey = $config['successKey'] ?? null;

                $this->formApiLogger->info("Executing form action $nodeTypeName via $className::handleSubmission.", ['id' => $submitId]);
                try {
                    $handler = $this->objectManager->get($className);
                    if (!$handler instanceof FormActionHandlerInterface) {
                        throw new Exception(
                            sprintf('Form action handler %s must implement %s.', $className, FormActionHandlerInterface::class),
                            1747569200
                        );
                    }
                    $handler->handleSubmission(
                        $this->getFormValues($formData, $formIdentifier),
                        $language,
                        $formAction
                    );
                    if ($successKey !== null) {
                        $data[$successKey] = true;
                    }
                    $this->formApiLogger->info("Form action $nodeTypeName executed.", ['id' => $submitId]);
                } catch (\Throwable $e) {
                    $this->formApiLogger->error("Form action $nodeTypeName failed. " . $e->getMessage(), ['id' => $submitId]);
                    $errors[] = ['action' => $nodeTypeName];
                }
            }
        }

        if ($errors !== []) {
            $data['errors'] = $errors;
        }

        // Signal the frontend to revalidate the document cache only when this
        // form actually embeds server-computed data into the document (e.g. a
        // timeslot field whose availability changes on submission). This is
        // server-authoritative so a generic form cannot be abused to bust the
        // Next.js cache.
        foreach (array_keys(array_filter($this->documentRevalidatingFieldTypes)) as $fieldType) {
            if (count((new FlowQuery([$formNode]))->find('[instanceof ' . $fieldType . ']')->get()) > 0) {
                $data['revalidateDocument'] = true;
                break;
            }
        }

        $this->view->assign('value', ['data' => $data]);

        $this->formApiLogger->info('Form submission successfully executed.', ['id' => $submitId]);
    }

    /**
     * Hard server-side cap on a single uploaded file. Matches the legacy
     * MultiFileUpload MAX_MB=128 and the largest selectable value in
     * Upload.yaml's allowedFilesize selector.
     */
    private const HARD_UPLOAD_LIMIT_BYTES = 128_000_000;

    public function uploadAction(): void
    {
        $uploadId = bin2hex(random_bytes(8));

        // Honeypot validation — mirrors submitAction. Required because the
        // upload endpoint is publicly reachable and writes a PersistentResource
        // on every successful call.
        try {
            $honeypotOne = $this->getStringArgument('_hp_one');
            if ($honeypotOne !== '') {
                $this->formApiLogger->error('Honeypot field one was filled, must be empty. Upload aborted.', ['id' => $uploadId]);
                throw new Exception('Honeypot field one was filled, must be empty.', 1747569010);
            }
            $honeypotTwo = $this->getStringArgument('_hp_two');
            $honeypotThree = $this->getStringArgument('_hp_three');
            $this->validateTimestampWithHmac($honeypotTwo, 'two', $uploadId);
            $this->validateTimestampWithHmac($honeypotThree, 'three', $uploadId);
        } catch (Exception $e) {
            $this->response->setStatusCode(400);
            $this->view->assign('value', ['error' => 'Honeypot validation failed.']);
            return;
        }

        $files = $this->request->getHttpRequest()->getUploadedFiles();
        $uploadedFile = $this->findFirstUploadedFile($files);
        if ($uploadedFile === null) {
            $this->formApiLogger->warning('Upload received but no file found in request.', [
                'id' => $uploadId,
                'fieldNames' => array_keys($files),
            ]);
            $this->response->setStatusCode(400);
            $this->view->assign('value', ['error' => 'No file received.']);
            return;
        }

        if ($uploadedFile->getSize() > self::HARD_UPLOAD_LIMIT_BYTES) {
            $this->formApiLogger->error('Upload exceeds hard server limit.', [
                'id' => $uploadId,
                'name' => $uploadedFile->getClientFilename(),
                'size' => $uploadedFile->getSize(),
                'limit' => self::HARD_UPLOAD_LIMIT_BYTES,
            ]);
            $this->response->setStatusCode(413);
            $this->view->assign('value', ['error' => 'File too large.']);
            return;
        }

        try {
            $uploadInfo = [
                'name' => $uploadedFile->getClientFilename(),
                'tmp_name' => $uploadedFile->getStream()->getMetadata('uri'),
            ];

            $resource = $this->resourceManager->importUploadedResource($uploadInfo, 'form');

            $resourceIdentifier = $this->persistenceManager->getIdentifierByObject($resource);
            $resourceIdentifierWithHmac = $this->hashService->appendHmac($resourceIdentifier);

            $this->view->assign('value', [
                'identifier' => $resourceIdentifierWithHmac,
                'name' => $resource->getFilename()
            ]);
            $this->formApiLogger->info('File uploaded.', [
                'id' => $uploadId,
                'name' => $resource->getFilename(),
            ]);
        } catch (\Throwable $e) {
            $this->formApiLogger->error('File upload failed. ' . $e->getMessage(), [
                'id' => $uploadId,
                'name' => $uploadedFile->getClientFilename(),
            ]);
            $this->response->setStatusCode(500);
            $this->view->assign('value', ['error' => 'Upload failed.']);
        }
    }

    /**
     * Best-effort cancellation of a previously uploaded file. Called by the
     * client when the user removes a file from the upload field before
     * submitting the form. Honeypot-gated identically to uploadAction.
     *
     * Safe under Flow's resource deduplication: ResourceManager::deleteResource()
     * uses a sha1+collection refcount and only removes the physical file when
     * no other PersistentResource references the same content. Two users
     * uploading identical files therefore cannot delete each other's data —
     * each holds a distinct row, and only the row whose HMAC matches is removed.
     *
     * Scoped to the 'form' collection to prevent any chance of misuse against
     * other collections (assets etc.) — though the HMAC scope already prevents
     * arbitrary identifier forgery.
     *
     * Idempotent: missing resources return 200 with ok=true.
     */
    public function cancelUploadAction(): void
    {
        $cancelId = bin2hex(random_bytes(8));

        try {
            $honeypotOne = $this->getStringArgument('_hp_one');
            if ($honeypotOne !== '') {
                $this->formApiLogger->error('Honeypot field one was filled, must be empty. Cancel aborted.', ['id' => $cancelId]);
                throw new Exception('Honeypot field one was filled, must be empty.', 1747569011);
            }
            $honeypotTwo = $this->getStringArgument('_hp_two');
            $honeypotThree = $this->getStringArgument('_hp_three');
            $this->validateTimestampWithHmac($honeypotTwo, 'two', $cancelId);
            $this->validateTimestampWithHmac($honeypotThree, 'three', $cancelId);
        } catch (Exception $e) {
            $this->response->setStatusCode(400);
            $this->view->assign('value', ['error' => 'Honeypot validation failed.']);
            return;
        }

        $identifierWithHmac = $this->getStringArgument('identifier');
        if ($identifierWithHmac === '') {
            $this->response->setStatusCode(400);
            $this->view->assign('value', ['error' => 'Identifier missing.']);
            return;
        }

        try {
            $resourceIdentifier = $this->hashService->validateAndStripHmac($identifierWithHmac);
        } catch (InvalidArgumentForHashGenerationException | InvalidHashException $e) {
            $this->formApiLogger->error('HMAC validation for cancel upload failed.', [
                'id' => $cancelId,
                'identifierWithHmac' => $identifierWithHmac,
            ]);
            $this->response->setStatusCode(400);
            $this->view->assign('value', ['error' => 'Invalid identifier.']);
            return;
        }

        $resource = $this->resourceRepository->findByIdentifier($resourceIdentifier);
        if (!$resource instanceof PersistentResource) {
            $this->view->assign('value', ['ok' => true, 'alreadyDeleted' => true]);
            return;
        }

        if ($resource->getCollectionName() !== 'form') {
            $this->formApiLogger->warning('Refused cancel for resource not in form collection.', [
                'id' => $cancelId,
                'collection' => $resource->getCollectionName(),
            ]);
            $this->response->setStatusCode(403);
            $this->view->assign('value', ['error' => 'Resource not in form collection.']);
            return;
        }

        $this->resourceManager->deleteResource($resource);
        $this->formApiLogger->info('Cancelled upload, resource deleted.', [
            'id' => $cancelId,
            'name' => $resource->getFilename(),
        ]);
        $this->view->assign('value', ['ok' => true]);
    }

    /**
     * Recursively find the first uploaded file in the PSR-7 uploaded-files tree.
     * The client may name the multipart field anything (e.g. "file" or the
     * file's own name), and bracket syntax produces nested arrays. We match
     * against UploadedFileInterface because getUploadedFiles() may return a
     * non-Flow implementation (e.g. Guzzle's).
     *
     * @param array<int|string, mixed> $files
     */
    protected function findFirstUploadedFile(array $files): ?UploadedFileInterface
    {
        foreach ($files as $entry) {
            if ($entry instanceof UploadedFileInterface) {
                return $entry;
            }
            if (is_array($entry)) {
                $found = $this->findFirstUploadedFile($entry);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * @return array<string, string> Map of field name → display label
     */
    protected function getFieldLabelMap(NodeInterface $formNode): array
    {
        $fieldQuery = new FlowQuery([$formNode]);
        $fields = $fieldQuery->find('[instanceof Sitegeist.PaperTiger:Field]')->get();
        $map = [];
        foreach ($fields as $field) {
            /** @var NodeInterface $field */
            $name = $field->getProperty('name');
            $label = $field->getProperty('label');
            if (is_string($name) && $name !== '') {
                $map[$name] = (is_string($label) && $label !== '') ? $label : $name;
            }
        }
        return $map;
    }

    /**
     * Field names of nodes whose NodeType is registered under
     * Visol.PaperTiger.ZebraAdaptor.fieldTypes.omitted — buttons and anything
     * else that submits a value but is not user-entered data.
     *
     * @return string[]
     */
    protected function getNonDataFieldNames(NodeInterface $formNode): array
    {
        $omittedTypes = array_values($this->omittedFieldTypes);
        if ($omittedTypes === []) {
            return [];
        }

        $fieldQuery = new FlowQuery([$formNode]);
        $fields = $fieldQuery->find('[instanceof Sitegeist.PaperTiger:Field]')->get();
        $names = [];
        foreach ($fields as $field) {
            /** @var NodeInterface $field */
            if (!in_array($field->getNodeType()->getName(), $omittedTypes, true)) {
                continue;
            }
            $name = $field->getProperty('name');
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    protected function getFormValues(array $formData, string $formIdentifier): array
    {
        // TODO: We might use this twice, use a first-level cache
        $formValues = [];
        foreach ($formData as $prefixedFieldName => $value) {
            $fieldName = $this->extractFieldNameWithoutPrefix($prefixedFieldName, $formIdentifier);
            $formValues[$fieldName] = $value;
        }
        return $formValues;
    }

    protected function extractFieldNameWithoutPrefix(string $fieldName, string $formIdentifier): string
    {
        // Example: b80fcd7b-63c8-4a29-9db9-af8b9478fa5d[text] -> [text]
        // Example: b80fcd7b-63c8-4a29-9db9-af8b9478fa5d[regions][] -> [regions][]
        $fieldName = str_replace($formIdentifier, '', $fieldName);

        // Remove all brackets
        $fieldName = str_replace('[', '', $fieldName);
        $fieldName = str_replace(']', '', $fieldName);

        return $fieldName;
    }

    /**
     * Reads a request argument as a string. ActionRequest::getArgument() is
     * typed string|array, so a plain (string) cast trips PHPStan; this wrapper
     * returns the argument only when it is actually a string and falls back to
     * an empty string otherwise — the safe default for honeypot and identifier
     * checks, which all treat an empty string as "missing".
     */
    private function getStringArgument(string $name): string
    {
        if (!$this->request->hasArgument($name)) {
            return '';
        }
        $value = $this->request->getArgument($name);
        return is_string($value) ? $value : '';
    }

    /**
     * Validates a honeypot field value as a signed timestamp.
     * Matches the validation logic of Sitegeist\PaperTiger\Validation\Validator\TimestampWithHmacValidator.
     *
     * @throws Exception
     */
    protected function validateTimestampWithHmac(mixed $value, string $fieldLabel, string $submitId): void
    {
        $minimumAge = 10;
        $maximumAge = 86400;

        if ($value === '' || $value === null) {
            $this->formApiLogger->error("Honeypot field $fieldLabel is empty, must contain a signed timestamp. Form submission aborted.", ['id' => $submitId]);
            throw new Exception("Honeypot field $fieldLabel is empty, must contain a signed timestamp.", 1724251321);
        }

        try {
            $timestamp = $this->hashService->validateAndStripHmac($value);
            $age = time() - (int)$timestamp;
            if ($age > $maximumAge) {
                $this->formApiLogger->error("Honeypot field $fieldLabel timestamp is too old. Form submission aborted.", ['id' => $submitId, 'age' => $age]);
                throw new Exception("Honeypot field $fieldLabel timestamp is too old.", 1724251322);
            }
            if ($age < $minimumAge) {
                $this->formApiLogger->error("Honeypot field $fieldLabel timestamp is too young. Form submission aborted.", ['id' => $submitId, 'age' => $age]);
                throw new Exception("Honeypot field $fieldLabel timestamp is too young.", 1724251323);
            }
        } catch (InvalidArgumentForHashGenerationException | InvalidHashException $e) {
            $this->formApiLogger->error("Honeypot field $fieldLabel HMAC validation failed. Form submission aborted.", ['id' => $submitId]);
            throw new Exception("Honeypot field $fieldLabel HMAC did not match.", 1724251324);
        }
    }
}
