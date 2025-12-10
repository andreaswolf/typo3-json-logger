<?php

declare(strict_types=1);

namespace a9f\TYPO3JsonLogger;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use Safe\DateTimeImmutable;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Writer\FileWriter as CoreFileWriter;
use TYPO3\CMS\Core\Log\Writer\WriterInterface;
use TYPO3\CMS\Core\SysLog\Action as SystemLogAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Taken from https://gitlab.opencode.de/bmi/government-site-builder-11/extensions/gsb_core/-/blob/main/Classes/Log/Writer/JsonFileWriter.php
 */
class JsonWriter extends CoreFileWriter
{
    private LogContext $context;

    /**
     * @param array<string, mixed> $options TODO refine this type
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);

        $this->context = GeneralUtility::makeInstance(LogContext::class);
    }

    /**
     * Writes the log record
     *
     * @param LogRecord $record Log record
     * @return WriterInterface $this
     * @throws \RuntimeException
     */
    public function writeLog(LogRecord $record)
    {
        $context = $record->getData();
        $message = $record->getMessage();

        if (!empty($context)) {
            // Fold an exception into the message, and string-ify it into context so it can be jsonified.
            if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
                $message .= $this->formatException($context['exception']);
                $context['exception'] = (string)$context['exception'];
            }
        }

        $tags = $this->context->getAll();
        $payload = [
            ...$tags,
            'date' => $this->createDateForRecordCreationValue($record),
            'level' => strtoupper($record->getLevel()),
            'requestId' => $record->getRequestId(),
            'component' => $record->getComponent(),
            'message' => $this->interpolate($message, $context),
            'context' => $context,
            ...$this->getLogTagsForActiveTracingSpan(),
        ];

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequest && $request->hasHeader('x-request-id')) {
            $requestIdHeader = $request->getHeader('x-request-id');
            $payload['X-Request-Id'] = (string)reset($requestIdHeader);
        }

        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        /* we don't want to stumble over warnings like undefined keys */
        $oldReporting = (int)ini_get('error_reporting');
        error_reporting(E_ERROR);
        $safePayload = $payload;
        try {
            $jsonString = $serializer->serialize($payload, 'json');
        } catch (\Exception $e) {
            $safePayload['context'] = [];
            if ($context['exception']) {
                $safePayload['context']['exception'] = $context['exception'];
            }
            $jsonString = $serializer->serialize($safePayload, 'json');
        }

        error_reporting($oldReporting);

        if (fwrite(self::$logFileHandles[$this->logFile], $jsonString . LF) === false) {
            if ($this->getBackendUser() instanceof BackendUserAuthentication) {
                try {
                    $this->getBackendUser()->writelog(SystemLogType::ERROR, SystemLogAction::UNDEFINED, SystemLogErrorClassification::USER_ERROR, 0, 'Could not write log record to log file', $safePayload);
                } catch (\Exception $e) {
                }
            } else {
                throw new \RuntimeException('Could not write log record to log file', 1697542908);
            }
        }

        return $this;
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        // TODO check if we can use
        return $GLOBALS['BE_USER'] ?? null;
    }

    /**
     * @return array{}|array{traceId: string, spanId: string}
     */
    private function getLogTagsForActiveTracingSpan(): array
    {
        if (!class_exists(\OpenTelemetry\Context\Context::class)) {
            return [];
        }
        $context = Context::getCurrent();
        $span = Span::fromContext($context)->getContext();

        if (!$span->isValid()) {
            return [];
        }

        return [
            'traceId' => $span->getTraceId(),
            'spanId' => $span->getSpanId(),
        ];
    }

    private function createDateForRecordCreationValue(LogRecord $record): string
    {
        try {
            return DateTimeImmutable::createFromFormat('U.u', (string)$record->getCreated())->format('Y-m-d\TH:i:s.uO');
        } catch (\Throwable) {
            // Noop for any errors, using fallback below.
        }
        return (new DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d\TH:i:s.uO');
    }
}
