<?php

namespace App\Http\Controllers;

use App\Models\Professional;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffAuthController extends Controller
{
    /**
     * Show the staff login form for the doctor portal.
     */
    public function showLogin()
    {
        if (session('staff_authenticated')) {
            return redirect('/doctor');
        }

        return view('doctor.login');
    }

    /**
     * Authenticate a professional (or admin) account and start the session.
     */
    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $professional = Professional::where('email', $request->input('email'))->first();

        if (!$professional
            || empty($professional->password)
            || !Hash::check($request->input('password'), $professional->password)) {
            return back()->withErrors([
                'email' => 'Credenciales incorrectas.',
            ])->onlyInput('email');
        }

        $professional->update(['last_login_at' => now()]);

        $request->session()->regenerate();
        $request->session()->put('staff_authenticated', true);
        $request->session()->put('staff_professional_id', $professional->id);
        $request->session()->put('staff_name', $professional->name);
        $request->session()->put('staff_role', $professional->role ?? 'professional');

        return redirect('/doctor');
    }

    /**
     * End the staff session.
     */
    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget([
            'staff_authenticated', 'staff_professional_id', 'staff_name', 'staff_role',
        ]);
        $request->session()->regenerate();

        return redirect('/doctor/login');
    }
}
