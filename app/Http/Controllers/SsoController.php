<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SsoController extends Controller
{
    public function callback(Request $request)
    {
        // Совместимость со старым CRM→desk SSO
        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->route('hub.login');
        }

        $base = rtrim((string) config('desk.crm1_base_url'), '/');
        $token = (string) config('desk.crm1_token');

        $res = \Illuminate\Support\Facades\Http::baseUrl($base)
            ->timeout(10)
            ->withToken($token)
            ->acceptJson()
            ->post('/api/v1/desk/sso/exchange', ['code' => $code]);

        if (! $res->successful()) {
            return redirect()->route('hub.login')
                ->with('error', 'SSO не удался');
        }

        $user = $res->json('user');
        if (! is_array($user) || empty($user['id'])) {
            return redirect()->route('hub.login');
        }

        $request->session()->regenerate();
        $request->session()->put('desk_user', $user);
        $request->session()->put('hub_projects', [
            ['code' => 'desk', 'name' => 'Единое окно заказов', 'path' => '/desk'],
            ['code' => 'crm', 'name' => 'CRM Lead Control', 'path' => '/crm'],
        ]);

        return redirect()->route('desk.index');
    }
}
