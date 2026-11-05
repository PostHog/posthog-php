<?php

namespace PostHog\Internal\Mailer;

/**
 * Stands in for RemoteTemplateTransportInterface on Symfony Mailer before 8.2, which has no RemoteTemplateEmail.
 */
interface NoRemoteTemplates
{
}
