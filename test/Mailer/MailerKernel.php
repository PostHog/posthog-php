<?php

namespace PostHog\Test\Mailer;

use Closure;
use PostHog\Mailer\PostHogTransportFactory;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Mailer\MailerInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * A Symfony app that registers the factory the way the docs do and sets MAILER_DSN to PostHog.
 */
final class MailerKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle()];
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir() . '/posthog-php-mailer-kernel-' . getmypid();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'mailer' => ['dsn' => 'posthog+api://phs_kernel@default'],
        ]);
        $services = $container->services();
        $services->alias('test.mailer', MailerInterface::class)->public();
        $services->set('posthog.test.send_request', Closure::class)
            ->factory([Closure::class, 'fromCallable'])
            ->args([[RecordedRequests::class, 'send']]);
        $services->set(PostHogTransportFactory::class)
            ->parent('mailer.transport_factory.abstract')
            ->arg('$sendRequest', service('posthog.test.send_request'))
            ->tag('mailer.transport_factory');
    }
}
