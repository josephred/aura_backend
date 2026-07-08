<?php

namespace App\Http\Controllers\Concerns;

trait ResolvesStaffScope
{
    /**
     * True when the logged-in staff member manages every professional.
     */
    private function isAdmin(): bool
    {
        return session('staff_role') === 'admin';
    }

    /**
     * The professional id the session is allowed to operate as, or null
     * for admins (who can operate on everyone).
     */
    private function scopedProfessionalId(): ?string
    {
        return $this->isAdmin() ? null : session('staff_professional_id');
    }
}
