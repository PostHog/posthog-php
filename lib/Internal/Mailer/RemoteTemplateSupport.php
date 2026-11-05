<?php

namespace PostHog\Internal\Mailer;

use Symfony\Component\Mailer\Transport\RemoteTemplateTransportInterface;

// PostHogTransport sends a RemoteTemplateEmail on Symfony Mailer 8.2 and later, and stays loadable before 8.2.
class_alias(
    interface_exists(RemoteTemplateTransportInterface::class)
        ? RemoteTemplateTransportInterface::class
        : NoRemoteTemplates::class,
    RemoteTemplateSupport::class
);
