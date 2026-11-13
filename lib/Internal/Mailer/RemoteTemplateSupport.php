<?php

namespace PostHog\Internal\Mailer;

use Symfony\Component\Mailer\Transport\RemoteTemplateTransportInterface;

// PostHogTransport sends a RemoteTemplateEmail on Symfony Mailer 8.2 and later, and stays loadable before 8.2.
// Both branches declare the interface, so Composer's classmap finds it.
if (interface_exists(RemoteTemplateTransportInterface::class)) {
    /**
     * @internal
     */
    interface RemoteTemplateSupport extends RemoteTemplateTransportInterface
    {
    }
} else {
    /**
     * @internal
     */
    // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses -- one name, declared once per Symfony line.
    interface RemoteTemplateSupport
    {
    }
}
