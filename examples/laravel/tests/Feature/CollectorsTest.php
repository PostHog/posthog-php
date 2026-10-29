<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\DemoOrder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use Tests\TestCase;

final class CollectorsTest extends TestCase
{
    // Real commits are needed to exercise the model collector's after-commit contract.
    use DatabaseMigrations;

    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../../bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            foreach (['requests', 'pageviews', 'auth', 'models', 'jobs', 'commands', 'scheduler'] as $collector) {
                $app['config']->set("posthog.autocapture.$collector.enabled", true);
            }
            $app['config']->set('posthog.autocapture.domain_events', [
                OrderCompleted::class => ['event' => 'order_completed', 'properties' => ['amount' => 'amount']],
            ]);
        });
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    public function test_request_and_pageview_collectors_exclude_raw_query_body_and_health(): void
    {
        $fake = PostHog::fake();
        $this->withHeader('X-PostHog-Distinct-Id', 'collector-visitor')->get('/?private=do-not-collect')
            ->assertOk();
        $fake->assertCaptured('laravel_request', fn ($event) => $event['properties']['route'] === '/'
            && $event['properties']['method'] === 'GET' && $event['properties']['status_code'] === 200);
        $fake->assertCaptured('$pageview', fn ($event) => !str_contains($event['properties']['$current_url'], 'private'));
        $before = count($fake->recorded());
        $this->get('/up')->assertOk();
        self::assertCount($before, $fake->recorded());
        $this->post('/demo/capture', ['password' => 'never-collect-this'])->assertRedirect('/');
        self::assertStringNotContainsString('never-collect-this', json_encode($fake->recorded(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('do-not-collect', json_encode($fake->recorded(), JSON_THROW_ON_ERROR));
    }

    public function test_auth_collectors_use_selected_user_not_credentials(): void
    {
        $fake = PostHog::fake();
        $this->post('/demo/login')->assertRedirect('/');
        $fake->assertCaptured('laravel_auth_registered');
        $fake->assertCaptured('laravel_auth_login');
        $this->post('/demo/logout')->assertRedirect('/');
        $fake->assertCaptured('laravel_auth_logout');
    }

    public function test_model_and_domain_collectors_emit_only_allowlisted_data(): void
    {
        $fake = PostHog::fake();
        $this->withHeader('X-PostHog-Distinct-Id', 'order-owner')->post('/demo/order')->assertRedirect('/');
        $fake->assertCaptured('laravel_model_created');
        $fake->assertCaptured('order_completed', fn ($event) => $event['properties']['amount'] === 4200
            && !isset($event['properties']['order_id']));
        app(Context::class)->with(['distinctId' => 'order-owner'], function (): void {
            $order = DemoOrder::firstOrFail();
            $order->update(['status' => 'refunded']);
            $order->delete();
        });
        $fake->assertCaptured('laravel_model_updated');
        $fake->assertCaptured('laravel_model_deleted');
    }

    public function test_model_collector_drops_rolled_back_changes(): void
    {
        $fake = PostHog::fake();
        app(Context::class)->begin(['distinctId' => 'rollback-owner']);
        DB::beginTransaction();
        DemoOrder::create(['amount' => 42, 'status' => 'draft']);
        DB::rollBack();
        $fake->assertNotCaptured('laravel_model_created');
    }

    public function test_job_command_and_scheduler_collectors_are_executable(): void
    {
        $fake = PostHog::fake();
        $this->post('/demo/queue')->assertRedirect('/');
        $fake->assertCaptured('laravel_job');
        // Laravel omits Symfony command-event rerouting under runningUnitTests().
        app(\Illuminate\Contracts\Console\Kernel::class)->rerouteSymfonyCommandEvents();
        $this->artisan('demo:posthog')->assertSuccessful();
        $fake->assertCaptured('laravel_command');
        // Use a callback task to keep the fake in-process; production demo schedules a command.
        foreach (Schedule::events() as $task) {
            $task->skip(fn () => true);
        }
        Schedule::call(fn () => PostHog::capture('test_scheduled_callback', [], 'demo-scheduler'))
            ->everyMinute()->name('test-scheduled-callback');
        $this->artisan('schedule:run')->assertSuccessful();
        $fake->assertCaptured('laravel_scheduled_task');
        $fake->assertCaptured('test_scheduled_callback');
    }
}
