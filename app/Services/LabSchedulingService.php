<?php

namespace App\Services;

use App\Models\LabSchedule;
use App\Models\Professional;
use App\Models\ServiceRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Deriva los cupos libres de toma de muestras a partir de los bloques que los
 * laboratoristas publicaron, y valida que un cupo pedido siga estando libre.
 *
 * Toda la aritmética de agenda vive aquí y no en los controladores: el portal
 * del laboratorista, la API del paciente y los tests tienen que coincidir en
 * qué cupo existe y cuál ya no, o se agenda encima de una toma confirmada.
 */
class LabSchedulingService
{
    /** Estados que ocupan un cupo. Cancelada libera; completada ya pasó. */
    public const OCCUPYING_STATUSES = [
        'pending_payment', 'scheduled', 'accepted', 'en_camino', 'en_atencion',
    ];

    /**
     * Cupos disponibles para una fecha, opcionalmente acotados a una zona.
     *
     * @return list<array{
     *   schedule_id:int, professional_id:string, professional_name:string,
     *   zone:?string, starts_at:string, ends_at:string, label:string,
     *   remaining:int
     * }>
     */
    public function slotsForDate(string $date, ?string $zone = null): array
    {
        $zone = $this->normalizeZone($zone);
        $day = Carbon::createFromFormat('Y-m-d', $date, config('app.timezone'))->startOfDay();

        if ($this->outsideBookingWindow($day)) {
            return [];
        }

        $blocks = LabSchedule::query()
            ->where('active', true)
            ->whereDate('date', $day->toDateString())
            ->when($zone, fn ($q, $z) => $q->where(function ($sub) use ($z) {
                // Un bloque sin zona cubre cualquier sector del profesional.
                $sub->whereNull('zone')->orWhere('zone', $z);
            }))
            ->orderBy('start_time')
            ->get();

        if ($blocks->isEmpty()) {
            return [];
        }

        $professionals = Professional::whereIn('id', $blocks->pluck('professional_id')->unique())
            ->where('active', true)
            ->whereHas('services', fn ($q) => $q->where('clinical_services.id', 'laboratorio'))
            ->get()
            ->keyBy('id');

        $taken = $this->takenCountsForDate($day);
        $earliest = now()->addMinutes((int) config('aura.lab.min_notice_minutes'));

        $slots = [];

        foreach ($blocks as $block) {
            $professional = $professionals->get($block->professional_id);
            if ($professional === null) {
                // El bloque quedó publicado pero el prestador ya no toma
                // muestras: no se ofrece, y tampoco se borra su historial.
                continue;
            }

            foreach ($this->slotStartsOf($block, $day) as $start) {
                if ($start->lte($earliest)) {
                    continue;
                }

                $key = $this->slotKey($block->id, $start);
                $remaining = max(0, $block->capacity - ($taken[$key] ?? 0));
                if ($remaining === 0) {
                    continue;
                }

                $end = $start->copy()->addMinutes($block->slot_minutes);

                $slots[] = [
                    'schedule_id' => $block->id,
                    'professional_id' => $professional->id,
                    'professional_name' => $professional->name,
                    'zone' => $block->zone,
                    'starts_at' => $start->toIso8601String(),
                    'ends_at' => $end->toIso8601String(),
                    'label' => $start->format('H:i') . ' - ' . $end->format('H:i'),
                    'remaining' => $remaining,
                ];
            }
        }

        usort($slots, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

        return $slots;
    }

    /**
     * Fechas próximas que tienen al menos un cupo libre, para que la app pueda
     * marcar el calendario sin pedir día por día.
     *
     * @return list<string> fechas 'Y-m-d'
     */
    public function datesWithAvailability(?string $zone = null, int $days = 14): array
    {
        $zone = $this->normalizeZone($zone);
        $dates = [];
        $cursor = now()->startOfDay();
        $limit = min($days, (int) config('aura.lab.max_days_ahead'));

        for ($i = 0; $i < $limit; $i++) {
            $date = $cursor->copy()->addDays($i)->toDateString();
            if ($this->slotsForDate($date, $zone) !== []) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Comprueba que el cupo pedido exista y siga libre.
     *
     * Devuelve el bloque cuando es válido, o null. El llamador vuelve a
     * verificar dentro de la transacción: esto filtra el 99 % de los casos y
     * el recuento con bloqueo cierra la carrera del 1 % restante.
     */
    public function findOpenSlot(int $scheduleId, Carbon $startsAt): ?LabSchedule
    {
        $block = LabSchedule::where('active', true)->find($scheduleId);
        if ($block === null) {
            return null;
        }

        $day = $block->date->copy()->startOfDay();

        if (!$this->isSlotStart($block, $day, $startsAt)) {
            return null;
        }

        if ($startsAt->lte(now()->addMinutes((int) config('aura.lab.min_notice_minutes')))) {
            return null;
        }

        if ($this->outsideBookingWindow($day)) {
            return null;
        }

        $professional = Professional::where('active', true)
            ->whereHas('services', fn ($q) => $q->where('clinical_services.id', 'laboratorio'))
            ->find($block->professional_id);

        if ($professional === null) {
            return null;
        }

        return $this->remainingFor($block, $startsAt) > 0 ? $block : null;
    }

    /**
     * Cupos que quedan en un bloque a una hora dada. `$lock` toma el bloqueo de
     * fila para usarse dentro de la transacción que crea la solicitud.
     */
    public function remainingFor(LabSchedule $block, Carbon $startsAt, bool $lock = false): int
    {
        // El bloqueo se toma sobre la fila del BLOQUE, no sobre las solicitudes.
        //
        // Antes era `$query->lockForUpdate()` seguido de `->count()`, y eso
        // tenia dos problemas a la vez. Postgres rechaza la combinacion
        // ("FOR UPDATE is not allowed with aggregate functions"), asi que toda
        // reserva de laboratorio terminaba en 500 en produccion mientras los
        // tests pasaban en SQLite, que descarta la clausula de bloqueo al
        // compilar. Y aunque hubiese funcionado, estaba mal dirigido: bloqueaba
        // filas de `service_requests` que todavia no existen, de modo que dos
        // pacientes pidiendo el ultimo cupo contaban cero los dos e insertaban
        // los dos. La fila del bloque si existe, y serializa a los dos.
        if ($lock) {
            LabSchedule::whereKey($block->id)->lockForUpdate()->first();
        }

        $taken = ServiceRequest::where('lab_schedule_id', $block->id)
            ->where('scheduled_at', $startsAt->format('Y-m-d H:i:s'))
            ->whereIn('status', self::OCCUPYING_STATUSES)
            ->count();

        return max(0, $block->capacity - $taken);
    }

    /**
     * Inicios de cupo de un bloque. El último cupo debe caber completo dentro
     * del bloque: ofrecer uno que termina pasada la hora de cierre le promete
     * al paciente un tiempo que el laboratorista no comprometió.
     *
     * @return Collection<int, Carbon>
     */
    private function slotStartsOf(LabSchedule $block, Carbon $day): Collection
    {
        $duration = max(5, $block->slot_minutes);
        $cursor = $day->copy()->setTimeFromTimeString($block->start_time);
        $blockEnd = $day->copy()->setTimeFromTimeString($block->end_time);

        $starts = collect();
        // Cota dura: un bloque mal cargado (fin antes que inicio) no debe
        // colgar el proceso en un bucle infinito.
        $guard = 0;
        while ($cursor->copy()->addMinutes($duration)->lte($blockEnd) && $guard++ < 200) {
            $starts->push($cursor->copy());
            $cursor->addMinutes($duration);
        }

        return $starts;
    }

    private function isSlotStart(LabSchedule $block, Carbon $day, Carbon $startsAt): bool
    {
        $wanted = $startsAt->format('Y-m-d H:i');

        return $this->slotStartsOf($block, $day)
            ->contains(fn (Carbon $start) => $start->format('Y-m-d H:i') === $wanted);
    }

    /**
     * Tomas ya agendadas de la fecha, agrupadas por bloque y hora de inicio.
     *
     * @return array<string,int>
     */
    private function takenCountsForDate(Carbon $day): array
    {
        return ServiceRequest::query()
            ->whereNotNull('lab_schedule_id')
            ->whereIn('status', self::OCCUPYING_STATUSES)
            ->whereBetween('scheduled_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->get(['lab_schedule_id', 'scheduled_at'])
            ->reduce(function (array $carry, ServiceRequest $req) {
                $key = $this->slotKey(
                    (int) $req->lab_schedule_id,
                    Carbon::parse($req->scheduled_at),
                );
                $carry[$key] = ($carry[$key] ?? 0) + 1;

                return $carry;
            }, []);
    }

    /**
     * 'General' es lo que devuelve el resolutor de zonas cuando la dirección no
     * nombra ninguna comuna conocida. Filtrar por ese valor escondería todos los
     * bloques que sí tienen sector, así que se trata como "sin filtro": es
     * preferible ofrecer de más y que el paciente elija, a decirle que no hay
     * disponibilidad porque no supimos leer su dirección.
     */
    private function normalizeZone(?string $zone): ?string
    {
        if ($zone === null || trim($zone) === '' || $zone === 'General') {
            return null;
        }

        return $zone;
    }

    private function slotKey(int $scheduleId, Carbon $start): string
    {
        return $scheduleId . '@' . $start->format('Y-m-d H:i');
    }

    private function outsideBookingWindow(Carbon $day): bool
    {
        $maxDays = (int) config('aura.lab.max_days_ahead');

        return $day->lt(now()->startOfDay())
            || $day->gt(now()->startOfDay()->addDays($maxDays));
    }
}
