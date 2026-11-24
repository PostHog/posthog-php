<?php

namespace PostHog;

/**
 * A request of Emails::batch() failed as a whole, so the batch stopped.
 *
 * The code, message and other fields are that request's error. getResults() holds every recipient: those of
 * earlier requests as PostHog answered them, those of the failed request as `failed`, or `unconfirmed` where an
 * earlier attempt may have queued them, and the rest as `not_attempted`.
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
     * The warnings PostHog returned about the requests that went out, each once.
     *
     * @return list<EmailWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
