<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\Client;
use PostHog\HttpClient;
use PostHog\HttpResponse;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\QueueContext;
use RuntimeException;

class AnonymousIdentityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.sdk.consumer', 'noop');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }

    private function request(array $headers = [], bool $started = true): Request
    {
        $request = Request::create('/', server: $headers);
        $session = new Store('test', new ArraySessionHandler(120));
        if ($started) {
            $session->start();
        }
        $request->setLaravelSession($session);
        $this->app->make(Context::class)->fromRequest($request);
        return $request;
    }

    public function testFallbackIsOptInAndUsesADedicatedStableRandomId(): void
    {
        $request = $this->request();
        $context = $this->app->make(Context::class);
        $this->assertNull($context->data()['distinctId'] ?? null);
        $this->assertFalse($request->session()->has('_posthog_anonymous_id'));
        $this->app['config']->set('posthog.anonymous_id', true);
        $id = $context->data()['distinctId'] ?? null;
        $this->assertIsString($id);
        $this->assertTrue(Str::isUuid($id, version: 4));
        $this->assertNotSame($request->session()->getId(), $id);
        $this->assertSame($id, $request->session()->get('_posthog_anonymous_id'));
        $this->assertSame($id, $context->data()['distinctId']);
        $request->session()->regenerate();
        $this->assertSame($id, $context->data()['distinctId']);
        $context->end();
        $this->assertSame([], $context->data());
    }

    #[DataProvider('strongerIdentities')]
    public function testExistingIdentityWinsWithoutCreatingAnAnonymousId(string $source): void
    {
        $this->app['config']->set('posthog.anonymous_id', true);
        $request = $this->request($source === 'header' ? ['HTTP_X_POSTHOG_DISTINCT_ID' => 'preferred'] : []);
        $context = $this->app->make(Context::class);
        if ($source === 'auth' || $source === 'context') {
            $request->setUserResolver(fn () => new GenericUser([
                'id' => $source === 'context' ? 'authenticated-other' : 'preferred',
            ]));
        }
        if ($source === 'resolver') {
            $this->app->bind('identity', fn () => fn () => 'preferred');
            $this->app['config']->set('posthog.distinct_id', 'identity');
        }
        if ($source === 'context') {
            $context->with(['distinctId' => 'preferred'], function () use ($context) {
                $this->assertSame('preferred', $context->data()['distinctId']);
            });
        } else {
            $this->assertSame('preferred', $context->data()['distinctId']);
        }
        $this->assertFalse($request->session()->has('_posthog_anonymous_id'));
    }

    public static function strongerIdentities(): array
    {
        return [['context'], ['resolver'], ['auth'], ['header']];
    }

    public function testFallbackDoesNotPinIdentityBeforeLoginOrOverrideExplicitCaptures(): void
    {
        $this->app['config']->set('posthog.anonymous_id', true);
        $fake = PostHog::fake();
        $request = $this->request(['HTTP_X_POSTHOG_SESSION_ID' => 'frontend-session']);
        PostHog::capture('anonymous');
        $id = $fake->recorded('capture')[0]['message']['distinctId'];
        $this->assertIsString($id);
        PostHog::capture('explicit', [], 'explicit-user');
        $request->setUserResolver(fn () => new GenericUser(['id' => 'logged-in']));
        PostHog::capture('authenticated');
        $request->setUserResolver(fn () => null);
        PostHog::capture('anonymous again');
        $messages = array_column($fake->recorded('capture'), 'message');
        $this->assertSame([$id, 'explicit-user', 'logged-in', $id], array_column($messages, 'distinctId'));
        $this->assertSame('frontend-session', $messages[0]['properties']['$session_id']);
        $this->assertSame([
            'distinctId' => $id, 'sessionId' => 'frontend-session',
            'properties' => ['$session_id' => 'frontend-session'],
        ], QueueContext::payload()['_posthog']);
        $this->assertSame([], $fake->recorded('identify'));
        $this->assertSame([], $fake->recorded('alias'));
    }

    public function testStatelessUnstartedDisabledAndFreshContextsDoNotCreateIdentity(): void
    {
        $this->app['config']->set('posthog.anonymous_id', true);
        $context = $this->app->make(Context::class);
        $context->fromRequest(Request::create('/api'));
        $this->assertNull($context->data()['distinctId'] ?? null);
        $request = $this->request(started: false);
        $this->assertNull($context->data()['distinctId'] ?? null);
        $this->assertFalse($request->session()->isStarted());
        $request->session()->start();
        $this->app['config']->set('posthog.enabled', false);
        $this->assertNull($context->data()['distinctId'] ?? null);
        $this->app['config']->set('posthog.enabled', true);
        $this->app['config']->set('posthog.api_key', '   ');
        $this->assertNull($context->data()['distinctId'] ?? null);
        $this->assertFalse($request->session()->has('_posthog_anonymous_id'));
        $this->app['config']->set('posthog.api_key', 'project-key');
        PostHog::withContext([], function () use ($context) {
            $this->assertNull($context->data()['distinctId'] ?? null);
        }, ['fresh' => true]);
        $this->assertFalse($request->session()->has('_posthog_anonymous_id'));
        $context->begin();
        $this->assertSame([], $context->data());
    }

    public function testFlagsAndRealCapturesShareTheVisitorIdWithoutInventingStatelessIdentities(): void
    {
        $this->app['config']->set('posthog.anonymous_id', true);
        $request = $this->request();
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())->method('sendRequest')->willReturnCallback(function ($path, $payload) use ($request) {
            $this->assertSame($request->session()->get('_posthog_anonymous_id'), json_decode($payload, true)['distinct_id']);
            return new HttpResponse(json_encode(['flags' => ['checkout' => ['enabled' => true, 'variant' => null]]]), 200);
        });
        $events = [];
        $sdk = new Client('project-key', [
            'consumer' => 'noop',
            'before_send' => function ($event) use (&$events) {
                $events[] = $event;
                return null;
            },
        ], $http, loadFeatureFlags: false);
        $manager = $this->app->make(\PostHog\Laravel\PostHog::class);
        (new \ReflectionProperty(\PostHog\Laravel\PostHog::class, 'client'))->setValue($manager, $sdk);
        $flags = $manager->evaluateFlags();
        $this->assertSame($flags, $manager->evaluateFlags());
        $this->assertTrue($flags->isEnabled('checkout'));
        $manager->capture('anonymous');
        $manager->captureException(new RuntimeException('handled'));
        $id = $request->session()->get('_posthog_anonymous_id');
        $this->assertSame([$id, $id, $id], array_column($events, 'distinct_id'));
        $this->app->make(Context::class)->fromRequest(Request::create('/stateless'));
        $this->assertSame([], $manager->evaluateFlags()->getKeys());
    }

    public function testSessionFailuresDoNotBreakCapture(): void
    {
        $this->app['config']->set('posthog.anonymous_id', true);
        $request = $this->request();
        $session = $this->createStub(Store::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('get')->willThrowException(new RuntimeException('session unavailable'));
        $request->setLaravelSession($session);
        $fake = PostHog::fake();
        PostHog::capture('best effort');
        $fake->assertCaptured('best effort', fn ($event) => $event['distinctId'] === null);
    }

    public function testWebMiddlewarePersistsIdentityBeforeSessionSaveAndLateCollectors(): void
    {
        $this->app['config']->set('posthog.anonymous_id', true);
        $this->app['config']->set('posthog.autocapture.requests.enabled', true);
        $fake = PostHog::fake();
        Route::middleware('web')->get('/anonymous', fn () => 'ok');
        $this->get('/anonymous')->assertOk();
        $messages = array_column($fake->recorded('capture'), 'message');
        $this->assertCount(1, $messages);
        $id = $messages[0]['distinctId'];
        $this->assertTrue(Str::isUuid($id, version: 4));
        $session = $this->app['session.store'];
        $restored = new Store('restored', $session->getHandler(), $session->getId());
        $restored->start();
        $this->assertSame($id, $restored->get('_posthog_anonymous_id'));
        $this->assertSame([], $this->app->make(Context::class)->data());
        $this->withCookie(config('session.cookie'), $session->getId())->get('/anonymous')->assertOk();
        $messages = array_column($fake->recorded('capture'), 'message');
        $this->assertSame([$id, $id], array_column($messages, 'distinctId'));
        // Testbench reuses the store object between simulated requests; clear its in-memory attributes.
        $session->flush();
        $this->withCookie(config('session.cookie'), str_repeat('b', 40))->get('/anonymous')->assertOk();
        $messages = array_column($fake->recorded('capture'), 'message');
        $this->assertCount(3, $messages);
        $this->assertNotSame($id, $messages[2]['distinctId']);
        $request = Request::create('/next');
        $request->setLaravelSession($restored);
        $this->app->make(Context::class)->fromRequest($request);
        $this->assertSame($id, $this->app->make(Context::class)->data()['distinctId']);
        $restored->invalidate();
        $restored->start();
        $this->app->make(Context::class)->fromRequest(Request::create('/stateless'));
        $this->assertNull($this->app->make(Context::class)->data()['distinctId'] ?? null);
        $next = Request::create('/new-session');
        $next->setLaravelSession($restored);
        $this->app->make(Context::class)->fromRequest($next);
        $this->assertNotSame($id, $this->app->make(Context::class)->data()['distinctId']);
    }

    #[DataProvider('shortCircuitResponses')]
    public function testWebMiddlewarePreparesIdentityWhenRoutingStopsEarly(string $path, int $status): void
    {
        $this->app['config']->set('posthog.anonymous_id', true);
        $this->app['config']->set('posthog.autocapture.requests.enabled', true);
        $fake = PostHog::fake();
        Route::get('/login', fn () => 'login')->name('login');
        Route::middleware(['web', 'auth'])->get('/protected', fn () => 'unreachable');
        Route::bind('missing', fn () => abort(404));
        Route::middleware('web')->get('/missing/{missing}', fn () => 'unreachable');
        $this->get($path)->assertStatus($status);
        $fake->assertCaptured('laravel_request', fn ($event) =>
            Str::isUuid($event['distinctId'], version: 4) && $event['properties']['status_code'] === $status);
        $session = $this->app['session.store'];
        $restored = new Store('restored', $session->getHandler(), $session->getId());
        $restored->start();
        $this->assertSame($fake->recorded('capture')[0]['message']['distinctId'], $restored->get('_posthog_anonymous_id'));
    }

    public static function shortCircuitResponses(): array
    {
        return [['/protected', 302], ['/missing/123', 404]];
    }

    public function testWebRequestsWithFrontendIdentityDoNotCreateAnAnonymousId(): void
    {
        $this->app['config']->set('posthog.anonymous_id', true);
        $fake = PostHog::fake();
        Route::middleware('web')->get('/frontend', function () {
            PostHog::capture('frontend');
            return 'ok';
        });
        $this->get('/frontend', ['X-PostHog-Distinct-Id' => 'frontend-id'])->assertOk();
        $fake->assertCaptured('frontend', fn ($event) => $event['distinctId'] === 'frontend-id');
        $this->assertFalse($this->app['session.store']->has('_posthog_anonymous_id'));
    }
}
