<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Support\Facades\Bus;
use Orchestra\Testbench\TestCase;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\AmbientCaptureJob;

class QueueContextTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
    }

    public function testJobPayloadCarriesOnlyAllowlistedContextAndRestoresParent(): void
    {
        $fake = PostHog::fake();
        $context = $this->app->make(Context::class);
        foreach (['first', 'second'] as $id) {
            $context->begin([
                'distinctId' => $id,
                'sessionId' => 'session-' . $id,
                'groups' => ['team' => $id],
                'properties' => ['secret' => 'never-propagate'],
            ]);
            Bus::dispatchSync(new AmbientCaptureJob());
            $fake->assertCaptured('ambient job', fn ($event) => $event['distinctId'] === $id
                && $event['groups'] === ['team' => $id]
                && $event['properties'] === ['$session_id' => 'session-' . $id]);
            $this->assertSame($id, $context->data()['distinctId']);
            $this->assertSame('never-propagate', $context->data()['properties']['secret']);
        }
        $this->assertCount(2, $fake->recorded('capture'));
    }

    public function testJobPropagationCanBeDisabled(): void
    {
        $this->app['config']->set('posthog.queue_context', false);
        $fake = PostHog::fake();
        $this->app->make(Context::class)->begin(['distinctId' => 'parent']);
        Bus::dispatchSync(new AmbientCaptureJob());
        $fake->assertCaptured('ambient job', fn ($event) => $event['distinctId'] === null
            && $event['groups'] === [] && $event['properties'] === []);
    }
}
