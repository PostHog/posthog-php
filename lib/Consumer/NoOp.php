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
     * @return boolean false because the SDK is disabled and the event was not captured
     */
    public function capture(array $message)
    {
        return false;
    }

    /**
     * Tags properties about the user.
     *
     * @param array $message
     * @return boolean false because the SDK is disabled and the identify was not captured
     */
    public function identify(array $message)
    {
        return false;
    }

    /**
     * Aliases from one user id to another
     *
     * @param array $message
     * @return boolean false because the SDK is disabled and the alias was not captured
     */
    public function alias(array $message)
    {
        return false;
    }

    /**
     * Queue a raw message.
     *
     * @param mixed $item
     * @return boolean false because the SDK is disabled and the message was not queued
     */
    public function enqueue($item)
    {
        return false;
    }

    /**
     * Flush queued messages.
     *
     * @return boolean false because the SDK is disabled and nothing was flushed
     */
    public function flush()
    {
        return false;
    }
}
