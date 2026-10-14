<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function show()
    {
        return Inertia::render('Login', ['setup' => app()->environment('local') && ! User::exists()]);
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($d)) {
            return back()->withErrors(['email' => 'Проверьте почту и пароль.']);
        }
        $r->session()->regenerate();

        return redirect('/app');
    }

    public function setup(Request $r)
    {
        abort_unless(app()->environment('local'), 403);

        return Cache::lock('initial-admin', 10)->block(3, function () use ($r) {
            abort_if(User::exists(), 403);
            $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email', 'password' => 'required|string|min:12|max:200']);
            $u = User::create([...$d, 'role' => 'admin']);
            Artisan::call('kb:initialize', ['--user' => $u->id]);
            Auth::login($u);
            $r->session()->regenerate();

            return redirect('/app');
        });
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }
}
