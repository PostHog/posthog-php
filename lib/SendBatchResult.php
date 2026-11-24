<?php

namespace PostHog;

/**
 * The answer to Emails::batch(): one result per recipient, and PostHog's warnings about the batch.
 */
class SendBatchResult
{
    /**
     * @internal Created by Emails::batch().
     * @param list<BatchEmailResult> $results
     * @param list<EmailWarning> $warnings
     */
    public function __construct(private array $results, private array $warnings = [])
    {
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
     * The warnings PostHog returned about the batch, each once.
     *
     * @return list<EmailWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
