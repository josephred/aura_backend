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
            return redirect($this->landingFor(session('staff_role')));
        }

        return view('doctor.login');
    }

    /**
     * Admins land on the operations panel; clinical staff on the doctor portal.
     * The two areas are independent — neither embeds the other.
     */
    private function landingFor(?string $role): string
    {
        return $role === 'admin' ? '/admin' : '/doctor';
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

        $rawEmail = trim(mb_strtolower($request->input('email')));
        $unaccented = strtr($rawEmail, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $parts = explode('@', $unaccented);
        $dotless = str_replace('.', '', $parts[0]) . (isset($parts[1]) ? '@' . $parts[1] : '');
        $dottedMap = [
            'camilarivera@aura.cl' => 'camila.rivera@aura.cl',
            'camila.rivera@aura.cl' => 'camilarivera@aura.cl',
            'sebastianleyton@aura.cl' => 'sebastian.leyton@aura.cl',
            'sebastian.leyton@aura.cl' => 'sebastianleyton@aura.cl',
            'sebastián.leyton@aura.cl' => 'sebastian.leyton@aura.cl',
            'mariajosediaz@aura.cl' => 'maria.jose.diaz@aura.cl',
            'maria.jose.diaz@aura.cl' => 'mariajosediaz@aura.cl',
            'patriciajara@aura.cl' => 'patricia.jara@aura.cl',
            'patricia.jara@aura.cl' => 'patriciajara@aura.cl',
            'laboratorista@aura.cl' => 'laboratorio@aura.cl',
            'laboratorio@aura.cl' => 'laboratorista@aura.cl',
        ];

        $candidates = array_unique(array_filter([
            $rawEmail,
            $unaccented,
            $dotless,
            $dottedMap[$rawEmail] ?? null,
            $dottedMap[$unaccented] ?? null,
            $dottedMap[$dotless] ?? null,
        ]));

        $professional = Professional::whereIn('email', $candidates)->first();

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

        return redirect($this->landingFor($professional->role ?? 'professional'));
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
