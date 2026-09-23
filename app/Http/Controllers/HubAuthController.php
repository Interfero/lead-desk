<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class HubAuthController extends Controller
{
    public function showLogin(Request $request)
    {
        // Не кэшировать форму — иначе iOS Safari шлёт просроченный CSRF → 419
        $request->session()->regenerateToken();

        if ($request->session()->has('desk_user')) {
            return redirect()->route('hub.home');
        }

        return response()
            ->view('hub.login')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $base = rtrim((string) config('desk.crm1_base_url'), '/');
        $token = (string) config('desk.crm1_token');

        $res = Http::baseUrl($base)
            ->timeout(15)
            ->withToken($token)
            ->acceptJson()
            ->post('/api/v1/desk/auth/login', $validated);

        if ($res->status() === 422 || $res->status() === 403) {
            $msg = $res->json('message')
                ?? data_get($res->json('errors'), 'email.0')
                ?? 'Неверный email или пароль';
            throw ValidationException::withMessages(['email' => [$msg]]);
        }

        if (! $res->successful()) {
            throw ValidationException::withMessages([
                'email' => ['Ошибка входа ('.$res->status().')'],
            ]);
        }

        $user = $res->json('user');
        $projects = $res->json('projects') ?? [];
        if (! is_array($user) || empty($user['id'])) {
            throw ValidationException::withMessages(['email' => ['Пустой ответ сервера']]);
        }

        $request->session()->regenerate();
        $request->session()->put('desk_user', $user);
        $request->session()->put('hub_projects', $projects);

        return redirect()->route('hub.home');
    }

    public function home(Request $request)
    {
        $user = $request->session()->get('desk_user');
        $projects = $request->session()->get('hub_projects', []);

        return view('hub.home', compact('user', 'projects'));
    }

    public function openCrm(Request $request)
    {
        $user = $request->session()->get('desk_user');
        if (! $user) {
            return redirect()->route('hub.login');
        }

        $base = rtrim((string) config('desk.crm1_base_url'), '/');
        $token = (string) config('desk.crm1_token');

        $res = Http::baseUrl($base)
            ->timeout(10)
            ->withToken($token)
            ->acceptJson()
            ->post('/api/v1/desk/sso/issue', [
                'user_id' => $user['id'],
                'target' => 'crm',
            ]);

        if (! $res->successful()) {
            return redirect()->route('hub.home')
                ->with('error', 'Не удалось открыть CRM: '.mb_substr($res->body(), 0, 200));
        }

        return redirect()->away($res->json('redirect'));
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('hub.login');
    }
}
