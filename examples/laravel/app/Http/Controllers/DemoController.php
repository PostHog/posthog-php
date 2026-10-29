<?php

namespace App\Http\Controllers;

use App\Models\DemoUser;
use App\Support\DemoActions;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use PostHog\Laravel\Facades\PostHog;
use RuntimeException;

final class DemoController
{
    public function dashboard(): View
    {
        return view('dashboard', [
            'enabled' => PostHog::enabled(),
            'collectorsAvailable' => class_exists(\PostHog\Laravel\AutocaptureServiceProvider::class),
            'collectors' => config('posthog.autocapture'),
        ]);
    }

    public function run(string $action, DemoActions $demo): RedirectResponse
    {
        $result = match ($action) {
            'capture' => $demo->capture(),
            'identity' => $demo->identity(),
            'flags' => $demo->flags(),
            'exception' => $demo->exception(),
            'report' => $demo->exception(report: true),
            'order' => $demo->order(),
            'queue' => $demo->queue(),
            'flush' => ['flushed' => PostHog::flush(), 'note' => 'Not a delivery receipt.'],
            default => abort(404),
        };
        return redirect()->route('dashboard')->with('result', $result);
    }

    public function login(Request $request): RedirectResponse
    {
        // LOCAL DEMO ONLY: no password. Never deploy this synthetic login in production.
        $user = DemoUser::firstOrCreate(['name' => 'Synthetic demo member']);
        if ($user->wasRecentlyCreated) {
            event(new Registered($user));
        }
        Auth::login($user);
        $request->session()->regenerate();
        PostHog::capture('demo_authenticated');
        return redirect()->route('dashboard')->with('result', ['synthetic_login' => true]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('dashboard')->with('result', ['logout' => true]);
    }

    public function failure(DemoActions $demo): never
    {
        $demo->ambient(fn () => throw new RuntimeException('Synthetic unhandled demo failure'));
    }
}
