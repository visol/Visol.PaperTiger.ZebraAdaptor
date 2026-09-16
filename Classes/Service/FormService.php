<?php

namespace Visol\PaperTiger\ZebraAdaptor\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Utility\Arrays;
use Sitegeist\FusionForm\Upload\Domain\CachedUploadedFile;
use Sitegeist\Neos\SymfonyMailer\Factories\MailerFactory;
use Sitegeist\Neos\SymfonyMailer\Factories\MailFactory;
use Soundasleep\Html2Text;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;

/**
 * @Flow\Scope("singleton")
 */
class FormService
{
    #[Flow\Inject]
    protected MailerFactory $mailerFactory;

    #[Flow\Inject]
    protected MailFactory $mailFactory;

    #[Flow\InjectConfiguration(path: 'form.overrideRecipientAddress', package: 'Visol.PaperTiger.ZebraAdaptor')]
    protected ?string $overrideRecipientAddress = null;

    /**
     * @var string[]
     */
    #[Flow\InjectConfiguration(path: 'form.allFormValues.excludeFieldNames', package: 'Visol.PaperTiger.ZebraAdaptor')]
    protected array $allFormValuesExcludeFieldNames = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $additionalDataExcludedFromAllFormValues
     * @param array<string, string> $fieldLabelMap Map of field name → display label
     * @param string[] $excludeFieldNames Field names to keep out of {allFormValues}
     */
    public function replacePlaceholders(string $template, array $data, array $additionalDataExcludedFromAllFormValues = [], array $fieldLabelMap = [], array $excludeFieldNames = []): string
    {
        $combinedData = array_merge($data, $additionalDataExcludedFromAllFormValues);

        // visol Change: Placeholders in links are urlencoded when saving to the DB, so we must decode them here
        $template = urldecode($template);

        return preg_replace_callback(
            '/{([a-z0-9\\-\\.]+)}/ium',
            function (array $matches) use ($combinedData, $data, $fieldLabelMap, $excludeFieldNames) {
                $value = Arrays::getValueByPath($combinedData, $matches[1]);
                // visol Change: Process allFormValues marker
                if ($matches[1] === 'allFormValues') {
                    return $this->getAllFormValuesHtml($data, $fieldLabelMap, $excludeFieldNames);
                }
                return htmlspecialchars(strip_tags($this->stringify($value)), ENT_QUOTES);
            },
            $template
        ) ?? $template;
    }

    /**
     * Renders the {allFormValues} placeholder.
     *
     * Fields are excluded from two sources: the globally configured
     * form.allFormValues.excludeFieldNames, and $excludeFieldNames — resolved
     * per form by the caller from fieldTypes.omitted, so buttons stay out of the
     * e-mail regardless of what an editor named them.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $fieldLabelMap Map of field name → display label
     * @param string[] $excludeFieldNames
     */
    protected function getAllFormValuesHtml(array $data, array $fieldLabelMap = [], array $excludeFieldNames = []): string
    {
        $fieldIdentifiersToOmit = array_merge($excludeFieldNames, $this->allFormValuesExcludeFieldNames);
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
            $label = htmlspecialchars($fieldLabelMap[$fieldIdentifier] ?? $fieldIdentifier, ENT_QUOTES);
            $html[] = '<dt>' . $label . '</dt><dd>' . $value . '</dd>';
        }
        $html[] = '</dl>';
        return implode(PHP_EOL, $html);
    }

    protected function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        } elseif (is_int($value) || is_float($value)) {
            return (string)$value;
        } elseif ($value instanceof \Stringable) {
            return $value->__toString();
        } elseif ($value instanceof \DateTimeInterface) {
            return $value->format('d.m.Y');
        } elseif (is_array($value)) {
            return implode(', ', array_map(fn(mixed $item) => $this->stringify($item), $value));
            // visol change:  Add case for CachedUploadedFile
        } elseif ($value instanceof CachedUploadedFile) {
            return $value->getClientFilename() ?? '';
            // visol change: Add case for PersistentResource, which is what
            // FormApiController resolves an [_uploadedFileIdentifier] into.
        } elseif ($value instanceof PersistentResource) {
            return $value->getFilename();
        } else {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $formValues
     * @param array<string, mixed> $additionalData
     * @param array<string, string> $fieldLabelMap Map of field name → display label
     * @param string[] $excludeFieldNames Field names to keep out of {allFormValues}
     */
    public function sendEmail(
        array $formValues,
        string $subject,
        string $html,
        string $recipientAddress,
        string $senderAddress,
        string $replyToAddress,
        string $recipientName = '',
        string $senderName = '',
        ?string $carbonCopyAddress = '',
        ?string $blindCarbonCopyAddress = '',
        ?bool $attachUploads = false,
        ?array $additionalData = [],
        array $fieldLabelMap = [],
        array $excludeFieldNames = []
    ): void {
        // Override recipient and prefix subject if configured (non-production environments)
        if ($this->overrideRecipientAddress !== null && $this->overrideRecipientAddress !== '') {
            $recipientAddress = $this->overrideRecipientAddress;
            $recipientName = '';
            $carbonCopyAddress = null;
            $blindCarbonCopyAddress = null;
            $subject = 'TEST ' . gethostname() . ' : ' . $subject;
        }

        $mailer = $this->mailerFactory->createMailer();

        $html = $this->wrapHtml($this->replacePlaceholders($html, $formValues, $additionalData ?? [], $fieldLabelMap, $excludeFieldNames));
        $options = [
            'ignore_errors' => true,
        ];
        $text = Html2Text::convert($html, $options);

        $mail = $this->mailFactory->createMail(
            $subject,
            new Address(
                $this->replacePlaceholders($recipientAddress, $formValues),
                $this->replacePlaceholders($recipientName, $formValues)
            ),
            new Address(
                $this->replacePlaceholders($senderAddress, $formValues),
                $this->replacePlaceholders($senderName, $formValues)
            ),
            $text,
            $html,
            $this->replacePlaceholders($replyToAddress, $formValues),
            $carbonCopyAddress !== null && $carbonCopyAddress !== ''
                ? $this->replacePlaceholders($carbonCopyAddress, $formValues)
                : null,
            $blindCarbonCopyAddress !== null && $blindCarbonCopyAddress !== ''
                ? $this->replacePlaceholders($blindCarbonCopyAddress, $formValues)
                : null,
        );

        if ($attachUploads === true) {
            foreach ($formValues as $fieldIdentifier => $resource) {
                if (!$resource instanceof PersistentResource) {
                    continue;
                }
                $stream = $resource->getStream();
                if (is_resource($stream)) {
                    $content = stream_get_contents($stream);
                    if ($content !== false) {
                        $mail->addPart(new DataPart($content, $resource->getFilename(), $resource->getMediaType()));
                    }
                }
            }
        }

        $mailer->send($mail);
    }

    protected function wrapHtml(string $html): string
    {
        return '
<!doctype html>
<html>
    <head>
        <meta charset="utf-8" />
        <style>
            body {
                font-family: Calibri, Arial, Helvetica, sans-serif;
                font-size: 14px;
                padding: 20px;
            }
            dt {
                font-weight: bold;
                display: block;
            }
            dd {
                display: block;
                padding: 0;
                margin: 0 0 1em 0;
            }
        </style>
    </head>
    <body>' . $html . '
    </body>
</html>
        ';
    }
}
