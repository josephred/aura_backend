<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves clinical attachments (prescriptions, symptom voice notes).
 *
 * These are health data: they live on the private disk and are streamed only
 * to the patient who owns the request or to authenticated portal staff. The
 * previous public-URL approach meant anyone holding the link could read a
 * patient's prescription or listen to their symptoms.
 */
class ClinicalMediaController extends Controller
{
    /** Attachment kinds and the column that stores their path. */
    private const KINDS = [
        'prescription' => 'prescription_file',
        'symptom-audio' => 'symptom_audio_url',
    ];

    public function show(Request $request, string $bookingId, string $kind): Response
    {
        $column = self::KINDS[$kind] ?? null;
        abort_if($column === null, 404);

        $serviceRequest = ServiceRequest::find($bookingId);
        abort_if($serviceRequest === null, 404);
        abort_unless($this->mayAccess($request, $serviceRequest), 403);

        $path = $serviceRequest->{$column};
        abort_if(empty($path), 404);

        // Legacy rows may still hold an absolute public URL from before the
        // move to the private disk; keep them readable instead of 404-ing.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return redirect($path);
        }

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            null,
            ['Cache-Control' => 'private, max-age=0, no-store'],
        );
    }

    /**
     * The owning patient (API token) or any signed-in portal staff member.
     */
    private function mayAccess(Request $request, ServiceRequest $serviceRequest): bool
    {
        if ($request->hasSession() && $request->session()->get('staff_authenticated')) {
            return true;
        }

        // The app authenticates with a Sanctum bearer token; this route is not
        // behind the sanctum guard, so resolve it explicitly.
        $user = auth('sanctum')->user();

        return $user !== null && (int) $serviceRequest->user_id === (int) $user->id;
    }
}
