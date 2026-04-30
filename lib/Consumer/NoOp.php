<?php

namespace PostHog\Consumer;

use PostHog\Consumer;

class NoOp extends Consumer
{
    protected $type = "NoOp";

    /**
     * Define getter method for consumer type
     *
     * @return string
     */
    public function getConsumer()
    {
        return $this->type;
    }

    public function __destruct()
    {
        // No queued work to flush.
    }

    /**
     * Captures a user action
     *
     * @param array $message
     * @return boolean whether the capture call succeeded
     */
    public function capture(array $message)
    {
        return true;
    }

    /**
     * Tags properties about the user.
     *
     * @param array $message
     * @return boolean whether the identify call succeeded
     */
    public function identify(array $message)
    {
        return true;
    }

    /**
     * Aliases from one user id to another
     *
     * @param array $message
     * @return boolean whether the alias call succeeded
     */
    public function alias(array $message)
    {
        return true;
    }

    /**
     * Queue a raw message.
     *
     * @param mixed $item
     * @return boolean whether call has succeeded
     */
    public function enqueue($item)
    {
        return true;
    }

    /**
     * Flush queued messages.
     *
     * @return boolean true if flushed successfully
     */
    public function flush()
    {
        return true;
    }
}
