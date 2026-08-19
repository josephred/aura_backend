<?php

namespace App\Http\Controllers;

use App\Models\LabResult;
use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
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

    /**
     * Mints a short-lived signed link for opening the attachment in external viewers.
     */
    public function signedLink(Request $request, string $bookingId, string $kind): JsonResponse
    {
        $column = self::KINDS[$kind] ?? null;
        abort_if($column === null, 404);

        $serviceRequest = ServiceRequest::find($bookingId);
        abort_if($serviceRequest === null, 404);
        abort_unless($this->mayAccess($request, $serviceRequest), 403);

        $path = $serviceRequest->{$column};
        abort_if(empty($path), 404);

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return response()->json(['url' => $path]);
        }

        $signedUrl = URL::temporarySignedRoute(
            'media.booking.show',
            now()->addMinutes(15),
            ['bookingId' => $bookingId, 'kind' => $kind],
        );

        return response()->json(['url' => $signedUrl]);
    }

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
     * Streams a lab report (E.4).
     *
     * Same rule as any other clinical attachment: the owning patient or portal
     * staff, never an anonymous link holder. The PDF is also emailed to the
     * patient, but that copy is an attachment — this route is what backs the
     * "Mis Exámenes" download, and it checks who is asking every time.
     */
    public function labResult(Request $request, string $resultId): Response
    {
        $result = LabResult::find($resultId);
        abort_if($result === null, 404);

        $serviceRequest = ServiceRequest::find($result->service_request_id);
        abort_if($serviceRequest === null, 404);

        // A valid, unexpired signature is the app opening the PDF in the system
        // viewer, which cannot carry the bearer token. The signature covers the
        // whole URL, so it grants access to this report and nothing else.
        $signed = $request->hasValidSignature();
        abort_unless($signed || $this->mayAccessLabResult($request, $serviceRequest), 403);

        abort_unless(Storage::disk('local')->exists($result->file_path), 404);

        return Storage::disk('local')->response(
            $result->file_path,
            $result->file_name,
            ['Cache-Control' => 'private, max-age=0, no-store'],
        );
    }

    /**
     * Regla más estrecha que la de los adjuntos: un informe de laboratorio lo
     * lee su paciente, el prestador que efectivamente hizo esa toma, o la
     * administración.
     *
     * `mayAccess()` acepta a cualquier miembro del portal porque un profesional
     * de guardia tiene que poder abrir la receta de una solicitud que aún nadie
     * tomó. Un resultado ya emitido no tiene ese problema: pertenece a una toma
     * que ya está asignada, así que no hay razón para que cualquier prestador
     * del portal pueda descargarlo con solo conocer el id.
     */
    private function mayAccessLabResult(Request $request, ServiceRequest $serviceRequest): bool
    {
        if ($request->hasSession() && $request->session()->get('staff_authenticated')) {
            if ($request->session()->get('staff_role') === 'admin') {
                return true;
            }

            return !empty($serviceRequest->professional_id)
                && $serviceRequest->professional_id === $request->session()->get('staff_professional_id');
        }

        $user = auth('sanctum')->user();

        return $user !== null && (int) $serviceRequest->user_id === (int) $user->id;
    }

    /**
     * The owning patient (API token), assigned professional, or authorized staff.
     */
    private function mayAccess(Request $request, ServiceRequest $serviceRequest): bool
    {
        if ($request->hasValidSignature()) {
            return true;
        }

        if ($request->hasSession() && $request->session()->get('staff_authenticated')) {
            if ($request->session()->get('staff_role') === 'admin') {
                return true;
            }

            $staffProfId = $request->session()->get('staff_professional_id');
            if (!empty($serviceRequest->professional_id)) {
                return $serviceRequest->professional_id === $staffProfId;
            }

            return true;
        }

        // The app authenticates with a Sanctum bearer token; this route is not
        // behind the sanctum guard, so resolve it explicitly.
        $user = auth('sanctum')->user();
        if ($user !== null) {
            if ((int) $serviceRequest->user_id === (int) $user->id) {
                return true;
            }

            if ($user->isOperator()) {
                return true;
            }

            if ($user->isStaff()) {
                if (!empty($serviceRequest->professional_id)) {
                    return $serviceRequest->professional_id === $user->professional_id;
                }
                return true;
            }
        }

        return false;
    }
}
