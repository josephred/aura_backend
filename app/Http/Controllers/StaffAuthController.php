<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
     * Validate the portal access key and start a staff session.
     */
    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'access_key' => 'required|string',
        ]);

        $configuredKey = config('services.doctor_portal.access_key');

        if (empty($configuredKey) || !hash_equals($configuredKey, $request->input('access_key'))) {
            return back()->withErrors([
                'access_key' => 'Clave de acceso incorrecta.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('staff_authenticated', true);

        return redirect('/doctor');
    }

    /**
     * End the staff session.
     */
    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('staff_authenticated');
        $request->session()->regenerate();

        return redirect('/doctor/login');
    }
}
