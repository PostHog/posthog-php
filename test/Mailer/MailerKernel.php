<?php

namespace PostHog\Test\Mailer;

use PostHog\Mailer\PostHogTransportFactory;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Mailer\MailerInterface;

/**
 * A Symfony app that registers the factory the way the docs do, sets MAILER_DSN to PostHog, and answers its HTTP
 * client's requests with RecordedRequests.
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
            'http_client' => ['mock_response_factory' => RecordedRequests::class],
        ]);
        $services = $container->services();
        $services->alias('test.mailer', MailerInterface::class)->public();
        $services->set(RecordedRequests::class);
        $services->set(PostHogTransportFactory::class)
            ->parent('mailer.transport_factory.abstract')
            ->tag('mailer.transport_factory');
    }
}
