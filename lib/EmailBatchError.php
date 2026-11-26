<?php

namespace PostHog;

/**
 * PostHog refused a call of Emails::batch() as a whole, or its answer was lost.
 *
 * The code, message and other fields are the request's error. getResults() holds every recipient: `failed`, or
 * `unconfirmed` where an attempt may have queued it.
 */
class EmailBatchError extends EmailError
{
    /**
     * @internal Created by Emails::batch().
     * @param list<BatchEmailResult> $results
     * @param list<EmailWarning> $warnings
     */
    public function __construct(EmailError $error, private array $results, private array $warnings = [])
    {
        parent::__construct(
            $error->getErrorCode(),
            $error->getMessage(),
            $error->getStatusCode(),
            why: $error->getWhy(),
            fix: $error->getFix(),
            docsUrl: $error->getDocsUrl(),
            field: $error->getField(),
            retryAfterMs: $error->getRetryAfterMs(),
            previous: $error,
            messageId: $error->getMessageId()
        );
    }

    /**
     * One result per recipient, in the order of `recipients`.
     *
     * @return list<BatchEmailResult>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * The warnings PostHog returned about the batch.
     *
     * @return list<EmailWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
