<?php

namespace Tests\Feature;

use App\Jobs\DemoJob;
use App\Models\DemoOrder;
use App\Models\DemoUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PostHog\Client;
use PostHog\EvaluatedFlagRecord;
use PostHog\FeatureFlagEvaluations;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\Testing\PostHogFake;
use PostHog\UnresolvedFlagReason;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_needs_no_key_browser_sdk_or_build_assets(): void
    {
        $fake = PostHog::fake();
        config(['posthog.api_key' => 'project-must-not-render', 'posthog.secret_key' => 'secret-must-not-render']);
        $this->get('/')->assertOk()
            ->assertSee('PostHog Laravel demo')
            ->assertSee('POSTHOG_COLLECT_SCHEDULER')
            ->assertSee('avoid duplicate')
            ->assertDontSee('project-must-not-render')
            ->assertDontSee('secret-must-not-render')
            ->assertDontSee('<script', false);
        $fake->assertNothingCaptured();
    }

    public function test_capture_uses_explicit_and_header_ambient_identity_and_groups(): void
    {
        $fake = PostHog::fake();
        $this->withHeaders([
            'X-PostHog-Distinct-Id' => 'header-visitor',
            'X-PostHog-Session-Id' => 'demo-session',
        ])->post('/demo/capture')->assertRedirect('/');
        $fake->assertCaptured('demo_explicit', fn ($event) => $event['distinctId'] === 'demo-explicit'
            && $event['groups'] === ['team' => 'demo-team']);
        $fake->assertCaptured('demo_ambient', fn ($event) => $event['distinctId'] === 'header-visitor'
            && $event['properties']['$session_id'] === 'demo-session'
            && $event['properties']['component'] === 'laravel-demo');
        $fake->assertCapturedCount(2);
        $fake->assertNotCaptured('$pageview');
        self::assertSame([], app(Context::class)->data());
    }

    public function test_auth_identity_beats_analytics_headers_but_not_explicit_api_identity(): void
    {
        $fake = PostHog::fake();
        $user = DemoUser::create(['name' => 'Synthetic member']);
        $this->actingAs($user)->withHeader('X-PostHog-Distinct-Id', 'untrusted')
            ->post('/demo/capture')->assertRedirect('/');
        $fake->assertCaptured('demo_ambient', fn ($event) => $event['distinctId'] === (string) $user->id);
        $fake->assertCaptured('demo_explicit', fn ($event) => $event['distinctId'] === 'demo-explicit');
    }

    public function test_identity_form_records_identify_alias_and_group_messages(): void
    {
        $fake = PostHog::fake();
        $this->post('/demo/identity')->assertRedirect('/');
        $fake->assertIdentified(fn ($message) => $message['distinctId'] === 'demo-member'
            && $message['properties']['plan'] === 'demo');
        $fake->assertAliased(fn ($message) => $message['alias'] === 'demo-visitor');
        $fake->assertGroupIdentified(fn ($message) => $message['groupKey'] === 'demo-team');
    }

    public function test_flag_form_uses_variant_payload_snapshot_and_unresolved_reasons(): void
    {
        $fake = PostHog::fake();
        $snapshot = new FeatureFlagEvaluations('demo-visitor', [
            'demo-checkout' => new EvaluatedFlagRecord('demo-checkout', true, null, null, null, null, null, true),
            'demo-layout' => new EvaluatedFlagRecord('demo-layout', true, 'compact', ['color' => 'blue'], null, null, null, true),
        ], [], $fake->client(), unresolvedFlags: ['needs-plan' => UnresolvedFlagReason::MISSING_CONTEXT]);
        $fake->flags($snapshot);
        $this->post('/demo/flags')->assertRedirect('/')->assertSessionHas('result', fn ($result) =>
            $result['enabled'] === true && $result['variant'] === 'compact'
            && $result['payload'] === ['color' => 'blue']
            && $result['unresolved'] === ['needs-plan' => UnresolvedFlagReason::MISSING_CONTEXT]);
        $fake->assertCaptured('demo_flag_decision', fn ($event) =>
            $event['flags']->getEventProperties()['$feature/demo-layout'] === 'compact'
            && $event['flags']->getEventProperties()['$active_feature_flags'] === ['demo-checkout', 'demo-layout']);
    }

    public function test_pennant_form_reads_boolean_and_variant_without_mutating_remote_flags(): void
    {
        $fake = PostHog::fake();
        $fake->flags(new FeatureFlagEvaluations('demo-visitor', [
            'demo-checkout' => new EvaluatedFlagRecord('demo-checkout', true, null, null, null, null, null, true),
            'demo-layout' => new EvaluatedFlagRecord('demo-layout', true, 'compact', null, null, null, null, true),
        ], [], $fake->client()));
        $this->post('/demo/pennant')->assertRedirect('/')->assertSessionHas('result', [
            'enabled' => true, 'variant' => 'compact', 'read_only' => true,
        ]);
        $fake->assertNothingCaptured();
    }

    public function test_manual_and_report_endpoints_capture_handled_exceptions_without_network(): void
    {
        $fake = PostHog::fake();
        $this->post('/demo/exception')->assertRedirect('/');
        $fake->assertExceptionCaptured(fn ($message) => $message['options']['handled'] === true
            && $message['properties']['component'] === 'manual-demo');
        $this->post('/demo/report')->assertRedirect('/');
        self::assertCount(2, $fake->recorded('captureException'));
        $fake->assertExceptionCaptured(fn ($message) => $message['distinctId'] === 'demo-visitor'
            && $message['options']['handled'] === true);
    }

    public function test_failing_endpoint_reports_unhandled_and_cleans_context(): void
    {
        $fake = PostHog::fake();
        $this->post('/demo/failure')->assertStatus(500);
        $fake->assertExceptionCaptured(fn ($message) => $message['options']['handled'] === false
            && $message['distinctId'] === 'demo-visitor');
        self::assertSame([], app(Context::class)->data());
    }

    public function test_order_form_persists_synthetic_data_and_dispatches_allowlisted_event(): void
    {
        PostHog::fake();
        \Illuminate\Support\Facades\Event::fake([\App\Events\OrderCompleted::class]);
        $this->post('/demo/order')->assertRedirect('/');
        $this->assertDatabaseHas('demo_orders', ['amount' => 4200, 'status' => 'completed']);
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\OrderCompleted::class, fn ($event) => $event->amount === 4200);
    }

    public function test_queue_form_dispatches_job_and_sync_execution_records_scoped_payload(): void
    {
        $fake = PostHog::fake();
        $this->withHeader('X-PostHog-Distinct-Id', 'must-not-leak')->post('/demo/queue')->assertRedirect('/');
        $fake->assertCaptured('demo_job_completed', fn ($message) => $message['distinctId'] === 'demo-job-owner'
            && $message['properties']['rows'] === 3 && $message['groups'] === ['team' => 'demo-team']);
        $fake->assertCaptured('demo_job_ambient', fn ($message) => $message['distinctId'] === 'must-not-leak'
            && !isset($message['properties']['component']));
        self::assertSame([], app(Context::class)->data());
    }

    public function test_queue_job_object_does_not_copy_request_context(): void
    {
        PostHog::fake();
        Queue::fake();
        $this->withHeader('X-PostHog-Distinct-Id', 'private-request-identity')->post('/demo/queue')->assertRedirect('/');
        Queue::assertPushed(DemoJob::class, fn (DemoJob $job) => $job->analyticsId === 'demo-job-owner'
            && !str_contains(serialize($job), 'private-request-identity'));
    }

    public function test_database_queue_metadata_contains_only_bounded_analytics_context(): void
    {
        PostHog::fake();
        config(['queue.default' => 'database']);
        $this->withHeaders([
            'X-PostHog-Distinct-Id' => 'queued-visitor', 'X-PostHog-Session-Id' => 'queued-session',
        ])->post('/demo/queue')->assertRedirect('/');
        $payload = json_decode(\Illuminate\Support\Facades\DB::table('jobs')->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('queued-visitor', $payload['_posthog']['distinctId']);
        self::assertSame('queued-session', $payload['_posthog']['sessionId']);
        self::assertSame(['team' => 'demo-team'], $payload['_posthog']['groups']);
        self::assertArrayNotHasKey('component', $payload['_posthog']['properties']);
        self::assertSame(['$session_id' => 'queued-session'], $payload['_posthog']['properties']);
    }

    public function test_queue_context_propagation_can_be_disabled(): void
    {
        PostHog::fake();
        config(['queue.default' => 'database', 'posthog.queue_context' => false]);
        $this->withHeader('X-PostHog-Distinct-Id', 'must-not-copy')->post('/demo/queue')->assertRedirect('/');
        $payload = json_decode(\Illuminate\Support\Facades\DB::table('jobs')->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('_posthog', $payload);
    }

    public function test_synthetic_login_and_logout_are_executable(): void
    {
        $fake = PostHog::fake();
        $this->post('/demo/login')->assertRedirect('/');
        $this->assertAuthenticated();
        $fake->assertCaptured('demo_authenticated', fn ($event) => $event['distinctId'] === (string) DemoUser::first()->id);
        $this->post('/demo/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_flush_failure_is_visible_without_breaking_response(): void
    {
        $fake = \Mockery::mock(PostHogFake::class, [$this->app])->makePartial();
        $fake->shouldReceive('flush')->andReturn(false);
        PostHog::swap($fake);
        $this->post('/demo/flush')->assertRedirect('/')->assertSessionHas('result.flushed', false);
    }

    public function test_disabled_sdk_allows_demo_and_flag_routes_without_live_network(): void
    {
        self::assertFalse(PostHog::enabled());
        $this->post('/demo/capture')->assertRedirect('/')->assertSessionHas('result.explicit_accepted', false);
        $this->post('/demo/flags')->assertRedirect('/')->assertSessionHas('result.keys', []);
        $this->post('/demo/report')->assertRedirect('/');
        self::assertInstanceOf(Client::class, PostHog::client());
    }

    public function test_artisan_demo_and_scheduled_command_are_repeatable(): void
    {
        $fake = PostHog::fake();
        app(\Illuminate\Contracts\Console\Kernel::class)->rerouteSymfonyCommandEvents();
        $this->artisan('demo:posthog', ['--flags' => true])->assertSuccessful();
        $this->artisan('demo:posthog')->assertSuccessful();
        $this->artisan('demo:scheduled')->assertSuccessful();
        $fake->assertCaptured('demo_scheduled_tick', fn ($event) => $event['distinctId'] === 'demo-scheduler');
        $fake->assertCaptured('demo_job_completed');
        self::assertSame(2, DemoOrder::count());
    }

    public function test_unknown_demo_action_is_not_executable(): void
    {
        $fake = PostHog::fake();
        $this->post('/demo/not-a-demo')->assertNotFound();
        $fake->assertNothingCaptured();
    }
}
