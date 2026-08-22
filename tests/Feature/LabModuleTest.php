<?php

namespace Tests\Feature;

use App\Mail\LabResultDelivered;
use App\Models\ClinicalService;
use App\Models\LabResult;
use App\Models\LabSchedule;
use App\Models\Professional;
use App\Models\ProfessionalEarning;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Módulo E — laboratorio.
 *
 * Cubre lo que puede romperse en silencio: que un cupo se venda dos veces, que
 * una toma agendada anule la solicitud activa del paciente, que un informe
 * clínico quede accesible para quien no es su dueño, y que la retención se
 * calcule mal o se duplique.
 */
class LabModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClinicalService::create([
            'id' => 'laboratorio',
            'title' => 'Toma de Muestras y Laboratorio',
            'short_title' => 'Laboratorio',
            'subtitle' => 'Extracción de sangre y orina.',
            'description' => 'Toma de muestras a domicilio.',
            'base_price' => 19500,
            'base_eta' => '60 - 90',
            'requires_prescription' => true,
            'icon_name' => 'FlaskConical',
            'warning_info' => 'Requiere orden médica.',
            'placeholder_text' => 'Ej. Hemograma completo',
        ]);
    }

    private function makeLabProfessional(string $id = 'prof_lab'): Professional
    {
        return Professional::forceCreate([
            'id' => $id,
            'name' => 'TM. Laboratorio',
            'specialty' => 'Tecnología Médica',
            'consultation_price' => 19500,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'provides_lab' => true,
            'email' => "$id@aura.cl",
            'password' => Hash::make('clave-segura-123'),
            'role' => 'professional',
        ]);
    }

    /** Bloque de mañana para dentro de tres días: 08:00-10:00, cupos de 30'. */
    private function makeBlock(string $professionalId, array $overrides = []): LabSchedule
    {
        return LabSchedule::create(array_merge([
            'professional_id' => $professionalId,
            'date' => now()->addDays(3)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'slot_minutes' => 30,
            'capacity' => 1,
            'active' => true,
        ], $overrides));
    }

    /** @return array{0:User,1:string} */
    private function makeUser(string $email = 'paciente@aura.cl'): array
    {
        $user = User::create([
            'name' => 'Paciente Test',
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        return [$user, $user->createToken('t')->plainTextToken];
    }

    private function staffSession(Professional $professional): array
    {
        return [
            'staff_authenticated' => true,
            'staff_professional_id' => $professional->id,
            'staff_name' => $professional->name,
            'staff_role' => $professional->role ?? 'professional',
        ];
    }

    // ---------------------------------------------------------------- E.1

    public function test_published_block_becomes_bookable_slots(): void
    {
        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        $date = now()->addDays(3)->toDateString();

        // 08:00-10:00 en cupos de 30 minutos = 4 cupos.
        $this->getJson("/api/lab/slots?date=$date")
            ->assertStatus(200)
            ->assertJsonCount(4, 'slots')
            ->assertJsonPath('slots.0.schedule_id', $block->id)
            ->assertJsonPath('slots.0.professional_name', 'TM. Laboratorio');

        $this->getJson('/api/lab/availability?days=7')
            ->assertStatus(200)
            ->assertJsonFragment(['dates' => [$date]]);
    }

    public function test_slots_hidden_when_professional_is_not_lab_enabled(): void
    {
        $professional = $this->makeLabProfessional();
        $this->makeBlock($professional->id);
        $date = now()->addDays(3)->toDateString();

        $professional->update(['provides_lab' => false]);

        $this->getJson("/api/lab/slots?date=$date")
            ->assertStatus(200)
            ->assertJsonCount(0, 'slots');
    }

    public function test_slots_respect_minimum_notice_and_booking_window(): void
    {
        $professional = $this->makeLabProfessional();

        // Bloque de hoy que ya empezó: no puede ofrecerse.
        $this->makeBlock($professional->id, [
            'date' => now()->toDateString(),
            'start_time' => '00:00',
            'end_time' => '01:00',
        ]);

        $this->getJson('/api/lab/slots?date=' . now()->toDateString())
            ->assertStatus(200)
            ->assertJsonCount(0, 'slots');

        // Más allá de la ventana permitida tampoco.
        $far = now()->addDays(60);
        $this->makeBlock($professional->id, ['date' => $far->toDateString()]);

        $this->getJson('/api/lab/slots?date=' . $far->toDateString())
            ->assertStatus(200)
            ->assertJsonCount(0, 'slots');
    }

    public function test_zone_filter_narrows_but_an_unresolved_zone_shows_everything(): void
    {
        $professional = $this->makeLabProfessional();
        $date = now()->addDays(3)->toDateString();

        // Un bloque por sector y otro sin sector definido.
        $this->makeBlock($professional->id, [
            'start_time' => '08:00', 'end_time' => '09:00', 'zone' => 'Providencia',
        ]);
        $this->makeBlock($professional->id, [
            'start_time' => '10:00', 'end_time' => '11:00', 'zone' => null,
        ]);

        // Sin filtro: los dos bloques (2 cupos cada uno).
        $this->getJson("/api/lab/slots?date=$date")
            ->assertStatus(200)->assertJsonCount(4, 'slots');

        // Con sector: el propio más el que cubre cualquiera.
        $this->getJson("/api/lab/slots?date=$date&zone=Providencia")
            ->assertStatus(200)->assertJsonCount(4, 'slots');

        // Otro sector: solo el bloque sin restricción.
        $this->getJson("/api/lab/slots?date=$date&zone=Maip%C3%BA")
            ->assertStatus(200)->assertJsonCount(2, 'slots');

        // 'General' es "no supimos leer la dirección": no debe esconder nada.
        $this->getJson("/api/lab/slots?date=$date&zone=General")
            ->assertStatus(200)->assertJsonCount(4, 'slots');
    }

    public function test_patient_books_a_slot_and_it_stops_being_offered(): void
    {
        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        [, $token] = $this->makeUser();

        $startsAt = now()->addDays(3)->setTime(8, 0);

        $response = $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234, Providencia',
            'exam_required' => 'Hemograma completo, perfil lipídico',
            'clinical_notes' => 'Ayuno de 12 horas. Presentar orden médica física.',
        ]);

        $response->assertStatus(201)
            // Sin pasarela configurada en tests, la agenda se confirma directo.
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('professional_name', 'TM. Laboratorio')
            // El precio lo pone el catálogo, no el cliente.
            ->assertJsonPath('final_price', 19500)
            ->assertJsonPath('clinical_notes', 'Ayuno de 12 horas. Presentar orden médica física.');

        // El cupo tomado desaparece de la oferta; los otros tres siguen.
        $this->getJson('/api/lab/slots?date=' . $startsAt->toDateString())
            ->assertStatus(200)
            ->assertJsonCount(3, 'slots');
    }

    public function test_same_slot_cannot_be_booked_twice(): void
    {
        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        [, $tokenA] = $this->makeUser('a@aura.cl');
        [, $tokenB] = $this->makeUser('b@aura.cl');

        $startsAt = now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s');
        $payload = [
            'schedule_id' => $block->id,
            'starts_at' => $startsAt,
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma',
        ];

        $this->withToken($tokenA)->postJson('/api/lab/requests', $payload)->assertStatus(201);
        $this->withToken($tokenB)->postJson('/api/lab/requests', $payload)->assertStatus(409);

        // Con capacidad 2 el segundo paciente sí entra.
        $block->update(['capacity' => 2]);
        $this->withToken($tokenB)->postJson('/api/lab/requests', $payload)->assertStatus(201);
    }

    public function test_a_time_outside_the_published_block_is_rejected(): void
    {
        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        [, $token] = $this->makeUser();

        // 08:15 no es inicio de cupo; 11:00 está fuera del bloque.
        foreach (['08:15', '11:00'] as $time) {
            [$h, $m] = explode(':', $time);
            $this->withToken($token)->postJson('/api/lab/requests', [
                'schedule_id' => $block->id,
                'starts_at' => now()->addDays(3)->setTime((int) $h, (int) $m)->format('Y-m-d H:i:s'),
                'patient_type' => 'self',
                'address_text' => 'Av. Providencia 1234',
                'exam_required' => 'Hemograma',
            ])->assertStatus(409);
        }
    }

    public function test_cancelling_frees_the_slot(): void
    {
        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        [, $token] = $this->makeUser();

        $startsAt = now()->addDays(3)->setTime(8, 0);
        $created = $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma',
        ])->json();

        $this->withToken($token)->postJson("/api/lab/requests/{$created['id']}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('status', 'cancelled');

        $this->getJson('/api/lab/slots?date=' . $startsAt->toDateString())
            ->assertStatus(200)
            ->assertJsonCount(4, 'slots');
    }

    public function test_scheduled_collection_does_not_hijack_or_cancel_the_active_request(): void
    {
        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        [, $token] = $this->makeUser();

        ClinicalService::create([
            'id' => 'medico',
            'title' => 'Atención Médica',
            'short_title' => 'Médico',
            'subtitle' => 'Consulta.',
            'description' => 'Consulta médica a domicilio.',
            'base_price' => 40000,
            'base_eta' => '45 - 60',
            'requires_prescription' => false,
            'icon_name' => 'UserRoundPlus',
            'warning_info' => 'No urgencias.',
            'placeholder_text' => 'Ej. fiebre',
        ]);

        $labRequest = $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma',
        ])->json();

        // La toma programada no es "la solicitud activa": falta demasiado.
        // Se comprueba vacío y no null a propósito: la JsonResponse de Symfony
        // convierte un null en `{}`, así que "sin solicitud activa" llega al
        // cliente como objeto vacío, no como null.
        $active = $this->withToken($token)->getJson('/api/bookings/active')
            ->assertStatus(200)
            ->json();
        $this->assertEmpty($active);

        // Y pedir un médico hoy no debe anularla.
        $this->withToken($token)->postJson('/api/bookings', [
            'service_id' => 'medico',
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'symptoms_description' => 'dolor de cabeza y fiebre',
            'final_price' => 40000,
            'eta_minutes' => 45,
        ])->assertStatus(201);

        $this->assertSame('scheduled', ServiceRequest::find($labRequest['id'])->status);
    }

    // ---------------------------------------------------------------- E.2

    public function test_indications_reach_the_laboratorista(): void
    {
        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        [, $token] = $this->makeUser();

        $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma completo',
            'clinical_notes' => 'Ayuno de 12 horas. Se requiere para el control del viernes.',
        ])->assertStatus(201);

        $this->withSession($this->staffSession($professional))
            ->getJson('/doctor/api/lab/collections')
            ->assertStatus(200)
            ->assertJsonPath('0.clinical_notes', 'Ayuno de 12 horas. Se requiere para el control del viernes.')
            ->assertJsonPath('0.exam_required', 'Hemograma completo')
            ->assertJsonPath('0.patient_name', 'Paciente Test');
    }

    // ---------------------------------------------------------------- E.3

    public function test_completing_a_paid_collection_records_the_retention(): void
    {
        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        [, $token] = $this->makeUser();

        $created = $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma',
        ])->json();

        ServiceRequest::where('id', $created['id'])->update(['payment_status' => 'approved']);

        $this->withSession($this->staffSession($professional))
            ->postJson("/doctor/api/bookings/{$created['id']}/status", ['status' => 'completed'])
            ->assertStatus(200);

        // 19.500 con 12,5 % = 2.437 retenidos, 17.063 al prestador.
        $earning = ProfessionalEarning::where('source_id', $created['id'])->firstOrFail();
        $this->assertSame(19500, $earning->gross_amount);
        $this->assertSame(2437, $earning->commission_amount);
        $this->assertSame(17063, $earning->net_amount);
        $this->assertSame($earning->gross_amount, $earning->commission_amount + $earning->net_amount);

        // Cerrar dos veces no duplica el devengo.
        $this->withSession($this->staffSession($professional))
            ->postJson("/doctor/api/bookings/{$created['id']}/status", ['status' => 'completed'])
            ->assertStatus(200);
        $this->assertSame(1, ProfessionalEarning::where('source_id', $created['id'])->count());

        $this->withSession($this->staffSession($professional))
            ->getJson('/doctor/api/lab/earnings')
            ->assertStatus(200)
            ->assertJsonPath('balance.pending_net', 17063)
            ->assertJsonPath('commission_bps', 1250);
    }

    public function test_unpaid_care_does_not_generate_a_payable(): void
    {
        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        [, $token] = $this->makeUser();

        $created = $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma',
        ])->json();

        $this->withSession($this->staffSession($professional))
            ->postJson("/doctor/api/bookings/{$created['id']}/status", ['status' => 'completed'])
            ->assertStatus(200);

        $this->assertSame(0, ProfessionalEarning::count());
    }

    public function test_professional_specific_commission_overrides_the_platform_rate(): void
    {
        $professional = $this->makeLabProfessional();
        $professional->forceFill(['commission_bps' => 500])->save();

        $settlement = app(SettlementService::class);
        $this->assertSame(500, $settlement->commissionBpsFor($professional->fresh()));
        $this->assertSame(1250, $settlement->commissionBpsFor(null));
    }

    // ---------------------------------------------------------------- E.4

    public function test_uploaded_result_is_emailed_stored_and_listed(): void
    {
        Storage::fake('local');
        Mail::fake();

        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        [, $token] = $this->makeUser();

        $created = $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma completo',
        ])->json();

        $this->withSession($this->staffSession($professional))
            ->post("/doctor/api/lab/collections/{$created['id']}/results", [
                'title' => 'Hemograma completo',
                'notes' => 'Sin hallazgos relevantes.',
                'file' => UploadedFile::fake()->create('informe.pdf', 120, 'application/pdf'),
            ])
            ->assertStatus(201);

        $result = LabResult::firstOrFail();
        Storage::disk('local')->assertExists($result->file_path);
        Mail::assertQueued(LabResultDelivered::class, fn ($mail) => $mail->hasTo('paciente@aura.cl'));
        $this->assertNotNull($result->fresh()->emailed_at);

        // "Mis Exámenes": histórico descargable, sin exponer la ruta interna.
        $listed = $this->withToken($token)->getJson('/api/lab/results')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'Hemograma completo')
            ->json();

        $this->assertStringContainsString("/media/lab-results/{$result->id}", $listed[0]['download_url']);
        $this->assertArrayNotHasKey('file_path', $listed[0]);
    }

    public function test_a_result_is_not_readable_by_another_patient(): void
    {
        Storage::fake('local');
        Mail::fake();

        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        [, $ownerToken] = $this->makeUser('duena@aura.cl');
        [, $strangerToken] = $this->makeUser('ajena@aura.cl');

        $created = $this->withToken($ownerToken)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma',
        ])->json();

        $this->withSession($this->staffSession($professional))
            ->post("/doctor/api/lab/collections/{$created['id']}/results", [
                'title' => 'Hemograma completo',
                'file' => UploadedFile::fake()->create('informe.pdf', 120, 'application/pdf'),
            ])->assertStatus(201);

        $result = LabResult::firstOrFail();

        // La sesión de staff usada para subir el informe persiste entre
        // peticiones dentro del mismo test. Sin limpiarla, las comprobaciones
        // de abajo pasarían por ser "personal del portal" y no probarían nada.
        $this->flushSession();

        // Y el guard de Sanctum cachea el usuario que resolvió: en producción
        // cada petición es un proceso nuevo, pero aquí comparten aplicación, así
        // que sin olvidarlo el token del segundo paciente se seguiría leyendo
        // como el del primero.
        $this->withToken($ownerToken)->get("/media/lab-results/{$result->id}")->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->withToken($strangerToken)->get("/media/lab-results/{$result->id}")->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->get("/media/lab-results/{$result->id}")->assertStatus(403);

        // Y no aparece en el histórico de quien no es su dueño.
        $this->app['auth']->forgetGuards();
        $this->withToken($strangerToken)->getJson('/api/lab/results')
            ->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function test_a_result_is_not_readable_by_an_unrelated_professional(): void
    {
        Storage::fake('local');
        Mail::fake();

        $mine = $this->makeLabProfessional('prof_lab_a');
        $other = $this->makeLabProfessional('prof_lab_b');
        $block = $this->makeBlock($mine->id);
        [, $token] = $this->makeUser();

        $created = $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma',
        ])->json();

        $this->withSession($this->staffSession($mine))
            ->post("/doctor/api/lab/collections/{$created['id']}/results", [
                'title' => 'Hemograma completo',
                'file' => UploadedFile::fake()->create('informe.pdf', 120, 'application/pdf'),
            ])->assertStatus(201);

        $result = LabResult::firstOrFail();

        // Quien hizo la toma sí; otro prestador del portal, no. Conocer el id
        // de un informe no puede bastar para descargarlo.
        $this->withSession($this->staffSession($mine))
            ->get("/media/lab-results/{$result->id}")->assertStatus(200);

        $this->flushSession();
        $this->withSession($this->staffSession($other))
            ->get("/media/lab-results/{$result->id}")->assertStatus(403);

        // La administración coordina y sí puede.
        $other->forceFill(['role' => 'admin'])->save();
        $this->flushSession();
        $this->withSession($this->staffSession($other->fresh()))
            ->get("/media/lab-results/{$result->id}")->assertStatus(200);
    }

    public function test_upload_result_is_idempotent_and_rejects_duplicate_reports(): void
    {
        Storage::fake('local');
        Mail::fake();

        $prof = $this->makeLabProfessional('prof_lab_idem');
        $block = $this->makeBlock($prof->id);
        [, $token] = $this->makeUser('paciente_idem@aura.cl');

        $created = $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Perfil Lipídico',
        ])->json();

        // 1. Primera carga exitosa -> 201
        $this->withSession($this->staffSession($prof))
            ->post("/doctor/api/lab/collections/{$created['id']}/results", [
                'title' => 'Perfil Lipídico',
                'file' => UploadedFile::fake()->create('informe1.pdf', 120, 'application/pdf'),
            ])->assertStatus(201);

        $this->assertSame(1, LabResult::where('service_request_id', $created['id'])->count());

        // 2. Reenvío accidental del formulario -> 409 Conflict sin duplicar registros ni correos
        $this->withSession($this->staffSession($prof))
            ->post("/doctor/api/lab/collections/{$created['id']}/results", [
                'title' => 'Perfil Lipídico Reintento',
                'file' => UploadedFile::fake()->create('informe2.pdf', 120, 'application/pdf'),
            ])->assertStatus(409)
            ->assertJsonPath('error', 'Esta toma ya tiene un informe cargado.');

        $this->assertSame(1, LabResult::where('service_request_id', $created['id'])->count());
    }

    // ------------------------------------------------------- Portal / agenda

    public function test_lab_portal_requires_a_staff_session(): void
    {
        $this->get('/doctor/laboratorio')->assertRedirect('/doctor/login');
        $this->getJson('/doctor/api/lab/collections')->assertStatus(401);
        $this->postJson('/doctor/api/lab/schedules', [])->assertStatus(401);
    }

    public function test_professional_publishes_and_withdraws_blocks(): void
    {
        $professional = $this->makeLabProfessional();
        $session = $this->staffSession($professional);
        $date = now()->addDays(4)->toDateString();

        $block = $this->withSession($session)->postJson('/doctor/api/lab/schedules', [
            'date' => $date,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'slot_minutes' => 30,
            'capacity' => 2,
            'zone' => 'Providencia',
        ])->assertStatus(201)
          ->assertJsonPath('slots_total', 16) // 8 cupos × capacidad 2
          ->json();

        // Un bloque superpuesto compromete dos veces la misma hora.
        $this->withSession($session)->postJson('/doctor/api/lab/schedules', [
            'date' => $date,
            'start_time' => '11:00',
            'end_time' => '13:00',
        ])->assertStatus(409);

        // Sin reservas, quitarlo lo borra.
        $this->withSession($session)->deleteJson("/doctor/api/lab/schedules/{$block['id']}")
            ->assertStatus(200)
            ->assertJsonPath('unpublished', false);

        $this->assertSame(0, LabSchedule::count());
    }

    public function test_withdrawing_a_booked_block_keeps_the_appointment(): void
    {
        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);
        [, $token] = $this->makeUser();

        $created = $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma',
        ])->json();

        $this->withSession($this->staffSession($professional))
            ->deleteJson("/doctor/api/lab/schedules/{$block->id}")
            ->assertStatus(200)
            ->assertJsonPath('unpublished', true);

        $this->assertSame(1, LabSchedule::count());
        $this->assertFalse(LabSchedule::find($block->id)->active);
        $this->assertSame('scheduled', ServiceRequest::find($created['id'])->status);

        // Ya no se ofrecen horas nuevas de ese bloque.
        $this->getJson('/api/lab/slots?date=' . now()->addDays(3)->toDateString())
            ->assertStatus(200)
            ->assertJsonCount(0, 'slots');
    }

    public function test_a_professional_cannot_touch_another_ones_lab_data(): void
    {
        $mine = $this->makeLabProfessional('prof_lab_a');
        $theirs = $this->makeLabProfessional('prof_lab_b');
        $theirBlock = $this->makeBlock($theirs->id);
        [, $token] = $this->makeUser();

        $created = $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $theirBlock->id,
            'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma',
        ])->json();

        $session = $this->staffSession($mine);

        $this->withSession($session)->getJson('/doctor/api/lab/collections')
            ->assertStatus(200)
            ->assertJsonCount(0);

        $this->withSession($session)->deleteJson("/doctor/api/lab/schedules/{$theirBlock->id}")
            ->assertStatus(403);

        $this->withSession($session)
            ->post("/doctor/api/lab/collections/{$created['id']}/results", [
                'title' => 'Informe ajeno',
                'file' => UploadedFile::fake()->create('informe.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(403);

        // Y publicar en nombre de otro tampoco.
        $this->withSession($session)->postJson('/doctor/api/lab/schedules', [
            'date' => now()->addDays(5)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'professional_id' => $theirs->id,
        ])->assertStatus(403);
    }

    public function test_only_lab_enabled_professionals_can_publish(): void
    {
        $professional = $this->makeLabProfessional();
        $professional->update(['provides_lab' => false]);

        $this->withSession($this->staffSession($professional))
            ->postJson('/doctor/api/lab/schedules', [
                'date' => now()->addDays(4)->toDateString(),
                'start_time' => '08:00',
                'end_time' => '10:00',
            ])
            ->assertStatus(422);
    }

    public function test_lab_request_requires_authentication_and_the_exam_detail(): void
    {
        $professional = $this->makeLabProfessional();
        $block = $this->makeBlock($professional->id);

        $this->postJson('/api/lab/requests', [])->assertStatus(401);

        [, $token] = $this->makeUser();
        $this->withToken($token)->postJson('/api/lab/requests', [
            'schedule_id' => $block->id,
            'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
        ])->assertStatus(422)->assertJsonValidationErrors('exam_required');
    }
}
