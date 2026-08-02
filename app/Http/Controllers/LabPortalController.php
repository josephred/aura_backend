<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesStaffScope;
use App\Mail\LabResultDelivered;
use App\Models\Dependent;
use App\Models\LabResult;
use App\Models\LabSchedule;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\LabSchedulingService;
use App\Services\SettlementService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Área de laboratorio del prestador.
 *
 * Sirve tanto al portal web (sesión de staff) como a la app del profesional
 * (token Sanctum): el alcance lo resuelve `ResolvesStaffScope`, igual que en el
 * resto del área clínica, así que las reglas existen una sola vez.
 */
class LabPortalController extends Controller
{
    use ResolvesStaffScope;

    public function index()
    {
        return view('doctor.lab', [
            'staffName' => session('staff_name', 'Equipo Aura'),
            'staffRole' => session('staff_role', 'professional'),
            'staffProfessionalId' => session('staff_professional_id'),
        ]);
    }

    /**
     * Bloques publicados por el prestador (o por todos, si es admin).
     */
    public function schedules(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        $from = isset($validated['from'])
            ? Carbon::createFromFormat('Y-m-d', $validated['from'])
            : now()->startOfDay();
        $to = isset($validated['to'])
            ? Carbon::createFromFormat('Y-m-d', $validated['to'])
            : $from->copy()->addDays(30);

        $blocks = LabSchedule::query()
            ->when($this->scopedProfessionalId(), fn ($q, $pid) => $q->where('professional_id', $pid))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (LabSchedule $block) => $this->presentBlock($block));

        return response()->json($blocks);
    }

    /**
     * Publica un bloque de disponibilidad.
     */
    public function storeSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'slot_minutes' => 'nullable|integer|between:10,240',
            'capacity' => 'nullable|integer|between:1,20',
            'zone' => 'nullable|string|max:120',
            // Solo un admin puede publicar en nombre de otro prestador.
            'professional_id' => 'nullable|string|exists:professionals,id',
        ]);

        $professionalId = $this->resolveTargetProfessional($validated['professional_id'] ?? null);
        if ($professionalId === null) {
            return response()->json(['error' => 'Sin permiso sobre este profesional'], 403);
        }

        $professional = Professional::find($professionalId);
        if ($professional === null || !$professional->provides_lab) {
            return response()->json([
                'error' => 'Este prestador no está habilitado para toma de muestras.',
            ], 422);
        }

        // Un bloque que se solapa con otro del mismo día genera cupos duplicados
        // y compromete al profesional dos veces a la misma hora.
        if ($this->overlaps($professionalId, $validated['date'], $validated['start_time'], $validated['end_time'])) {
            return response()->json([
                'error' => 'Ese horario se superpone con otro bloque ya publicado.',
            ], 409);
        }

        $block = LabSchedule::create([
            'professional_id' => $professionalId,
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'slot_minutes' => $validated['slot_minutes'] ?? 30,
            'capacity' => $validated['capacity'] ?? 1,
            'zone' => $validated['zone'] ?? null,
            'active' => true,
        ]);

        return response()->json($this->presentBlock($block), 201);
    }

    /**
     * Retira un bloque. Los cupos ya reservados no se tocan: la toma agendada
     * sigue en pie y el bloque solo deja de ofrecer horas nuevas.
     */
    public function destroySchedule(int $blockId): JsonResponse
    {
        $block = LabSchedule::find($blockId);
        if ($block === null) {
            return response()->json(['error' => 'Bloque no encontrado'], 404);
        }

        $scopedId = $this->scopedProfessionalId();
        if ($scopedId !== null && $block->professional_id !== $scopedId) {
            return response()->json(['error' => 'Sin permiso sobre este bloque'], 403);
        }

        $booked = ServiceRequest::where('lab_schedule_id', $block->id)
            ->whereIn('status', LabSchedulingService::OCCUPYING_STATUSES)
            ->count();

        if ($booked > 0) {
            // Despublicar en vez de borrar deja la trazabilidad de por qué esa
            // toma existe, sin seguir ofreciendo el horario.
            $block->update(['active' => false]);

            return response()->json([
                'success' => true,
                'unpublished' => true,
                'message' => "El bloque tiene $booked toma(s) agendada(s): se retiró de la oferta "
                    . 'pero las tomas ya reservadas se mantienen.',
            ]);
        }

        $block->delete();

        return response()->json(['success' => true, 'unpublished' => false]);
    }

    /**
     * Tomas de muestra agendadas al prestador.
     */
    public function collections(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'include_past' => 'nullable|boolean',
        ]);

        $query = ServiceRequest::query()
            ->where('is_scheduled', true)
            ->when($this->scopedProfessionalId(), fn ($q, $pid) => $q->where('professional_id', $pid));

        if (isset($validated['date'])) {
            $day = Carbon::createFromFormat('Y-m-d', $validated['date']);
            $query->whereBetween('scheduled_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
        } elseif (empty($validated['include_past'])) {
            // Por defecto, la jornada de hoy en adelante: lo pasado se consulta
            // explícitamente para no arrastrar meses de historial en cada carga.
            $query->where('scheduled_at', '>=', now()->startOfDay());
        }

        $collections = $query->orderBy('scheduled_at')
            ->limit(200)
            ->get()
            ->map(function (ServiceRequest $req) {
                $user = User::find($req->user_id);
                $dependent = $req->dependent_id ? Dependent::find($req->dependent_id) : null;

                return [
                    'id' => $req->id,
                    'status' => $req->status,
                    'scheduled_at' => $req->scheduled_at?->toIso8601String(),
                    'address_text' => $req->address_text,
                    'zone' => $req->zone,
                    'patient_name' => $dependent
                        ? "{$dependent->name} ({$dependent->relationship})"
                        : ($user?->name ?? 'Paciente'),
                    'patient_email' => $user?->email,
                    'exam_required' => $req->exam_required,
                    // E.2 — lo que el laboratorista necesita leer antes de salir.
                    'clinical_notes' => $req->clinical_notes,
                    'prescription_name' => $req->prescription_name,
                    'prescription_file' => $req->prescription_url,
                    'final_price' => (int) $req->final_price,
                    'payment_status' => $req->payment_status,
                    'results' => $req->labResults()->orderByDesc('issued_at')->get()
                        ->map(fn (LabResult $r) => [
                            'id' => $r->id,
                            'title' => $r->title,
                            'file_name' => $r->file_name,
                            'issued_at' => $r->issued_at?->toIso8601String(),
                            'emailed_at' => $r->emailed_at?->toIso8601String(),
                            'email_error' => $r->email_error,
                            'download_url' => $r->download_url,
                        ])->values(),
                ];
            });

        return response()->json($collections);
    }

    /**
     * E.4 — carga del informe: se guarda en el disco privado, se registra y se
     * envía por correo al paciente.
     */
    public function uploadResult(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:160',
            'notes' => 'nullable|string|max:1000',
            'file' => 'required|file|mimes:pdf|max:20480',
        ]);

        $serviceRequest = ServiceRequest::where('is_scheduled', true)->find($id);
        if ($serviceRequest === null) {
            return response()->json(['error' => 'Toma de muestras no encontrada'], 404);
        }

        $scopedId = $this->scopedProfessionalId();
        if ($scopedId !== null && $serviceRequest->professional_id !== $scopedId) {
            return response()->json(['error' => 'Sin permiso sobre esta toma'], 403);
        }

        $path = $request->file('file')->store('lab-results', 'local');

        $result = LabResult::create([
            'id' => 'labres_' . now()->timestamp . '_' . Str::lower(Str::random(6)),
            'service_request_id' => $serviceRequest->id,
            'user_id' => $serviceRequest->user_id,
            'title' => $validated['title'],
            'notes' => $validated['notes'] ?? null,
            'file_path' => $path,
            'file_name' => $this->safeFileName($validated['title']),
            'file_size' => $request->file('file')->getSize(),
            'uploaded_by_professional_id' => $scopedId,
            'issued_at' => now(),
        ]);

        $this->emailResult($serviceRequest, $result);

        app(\App\Services\FcmService::class)->notifyUser(
            $serviceRequest->user_id,
            'Resultados disponibles',
            "Ya puedes ver {$result->title} en Mis Exámenes.",
            ['booking_id' => $serviceRequest->id, 'type' => 'lab_result'],
        );

        return response()->json($result->fresh(), 201);
    }

    /**
     * E.3 — saldo pendiente de dispersión del prestador.
     */
    public function earnings(SettlementService $settlement): JsonResponse
    {
        $professionalId = $this->scopedProfessionalId();
        if ($professionalId === null) {
            return response()->json(['error' => 'Solo disponible para prestadores'], 422);
        }

        $professional = Professional::find($professionalId);

        return response()->json([
            'commission_bps' => $settlement->commissionBpsFor($professional),
            'balance' => $settlement->pendingBalance($professionalId),
        ]);
    }

    /**
     * Envía el informe y deja registrado el resultado del envío.
     *
     * Un fallo de correo no puede tumbar la carga: el PDF ya está guardado y
     * disponible en la app. Se anota el error para poder reintentarlo.
     */
    private function emailResult(ServiceRequest $serviceRequest, LabResult $result): void
    {
        $user = User::find($serviceRequest->user_id);
        if ($user === null || empty($user->email)) {
            $result->update(['email_error' => 'El paciente no tiene correo registrado.']);

            return;
        }

        try {
            Mail::to($user->email)->send(new LabResultDelivered(
                $result,
                $user->name ?? 'paciente',
                $serviceRequest->exam_required,
            ));

            $result->update(['emailed_at' => now(), 'email_error' => null]);
        } catch (\Throwable $e) {
            Log::warning('Lab result email failed', [
                'result' => $result->id,
                'error' => $e->getMessage(),
            ]);
            $result->update(['email_error' => Str::limit($e->getMessage(), 200)]);
        }
    }

    /**
     * Nombre de archivo derivado del título, sin caracteres que rompan la
     * cabecera de descarga.
     */
    private function safeFileName(string $title): string
    {
        $slug = Str::slug($title);

        return ($slug !== '' ? $slug : 'informe') . '-' . now()->format('Y-m-d') . '.pdf';
    }

    /**
     * Quién queda como dueño del bloque: uno mismo, o el prestador indicado
     * cuando quien publica es un administrador.
     */
    private function resolveTargetProfessional(?string $requested): ?string
    {
        $scopedId = $this->scopedProfessionalId();

        if ($scopedId !== null) {
            // Un prestador solo publica su propia agenda.
            return ($requested === null || $requested === $scopedId) ? $scopedId : null;
        }

        // Admin: debe decir explícitamente para quién publica.
        return $requested;
    }

    private function overlaps(string $professionalId, string $date, string $start, string $end): bool
    {
        return LabSchedule::where('professional_id', $professionalId)
            ->whereDate('date', $date)
            ->where('active', true)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();
    }

    private function presentBlock(LabSchedule $block): array
    {
        $booked = ServiceRequest::where('lab_schedule_id', $block->id)
            ->whereIn('status', LabSchedulingService::OCCUPYING_STATUSES)
            ->count();

        $slotCount = $this->slotCount($block);

        return [
            'id' => $block->id,
            'professional_id' => $block->professional_id,
            'date' => $block->date->toDateString(),
            'start_time' => $block->start_time,
            'end_time' => $block->end_time,
            'slot_minutes' => $block->slot_minutes,
            'capacity' => $block->capacity,
            'zone' => $block->zone,
            'active' => $block->active,
            'slots_total' => $slotCount * $block->capacity,
            'slots_booked' => $booked,
        ];
    }

    private function slotCount(LabSchedule $block): int
    {
        $start = Carbon::createFromFormat('H:i', $block->start_time);
        $end = Carbon::createFromFormat('H:i', $block->end_time);
        $minutes = max(0, $start->diffInMinutes($end, false));

        return (int) floor($minutes / max(5, $block->slot_minutes));
    }
}
