<?php

namespace App\Http\Controllers\Concerns;

/**
 * Resolves who the acting staff member is, regardless of how they got here.
 *
 * The web portal authenticates with a session (`staff_*` keys); the mobile app
 * authenticates with a Sanctum token on a `users` row linked to a professional.
 * Controllers stay identical for both, so there is one implementation of the
 * clinical rules instead of two that drift apart.
 */
trait ResolvesStaffScope
{
    /**
     * True only on the web portal. API routes carry no session at all, so this
     * must be checked before touching the session store.
     */
    private function hasStaffSession(): bool
    {
        return request()->hasSession()
            && request()->session()->has('staff_authenticated');
    }

    /**
     * True when the logged-in staff member manages every professional.
     */
    private function isAdmin(): bool
    {
        if ($this->hasStaffSession()) {
            return session('staff_role') === 'admin';
        }

        $user = auth('sanctum')->user();

        return $user !== null && $user->isOperator();
    }

    /**
     * The professional id the caller is allowed to operate as, or null
     * for admins (who operate on everyone).
     */
    private function scopedProfessionalId(): ?string
    {
        if ($this->isAdmin()) {
            return null;
        }

        if ($this->hasStaffSession()) {
            return session('staff_professional_id');
        }

        return auth('sanctum')->user()?->professional_id;
    }

    /**
     * Display name of the acting staff member, used in records and chat.
     */
    private function staffDisplayName(): ?string
    {
        if ($this->hasStaffSession()) {
            return session('staff_name');
        }

        return auth('sanctum')->user()?->name;
    }
}
