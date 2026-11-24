<?php

namespace PostHog\Test;

use PostHog\SendEmailResult;

final class NotedSendEmailResult extends SendEmailResult
{
    public function __construct(string $id, private string $note)
    {
        parent::__construct($id);
    }

    public function getNote(): string
    {
        return $this->note;
    }
}
