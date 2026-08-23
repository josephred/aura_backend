<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ClinicalService;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ActiveBookingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeServices(): void
    {
        ClinicalService::create([
            'id' => 'medico',
            'title' => 'Médico a domicilio',
            'short_title' => 'Médico',
            'subtitle' => 'Consulta general',
            'description' => 'Servicio médico',
            'base_price' => 25000,
            'base_eta' => '30 - 45',
            'requires_prescription' => false,
            'icon_name' => 'medical_services',
            'warning_info' => 'Servicio no apto para urgencias.',
            'placeholder_text' => 'Ej. fiebre alta y tos seca',
        ]);

        ClinicalService::create([
            'id' => 'kine_motora',
            'title' => 'Kinesiología Motora',
            'short_title' => 'Kinesiología',
            'subtitle' => 'Rehabilitación motora',
            'description' => 'Servicio kinesiológico',
            'base_price' => 30000,
            'base_eta' => '45 - 60',
            'requires_prescription' => false,
            'icon_name' => 'accessibility_new',
            'warning_info' => 'Sesión a domicilio.',
            'placeholder_text' => 'Ej. dolor lumbar y rigidez',
        ]);
    }

    private function makeProfessional(string $id, string $name, string $specialty): Professional
    {
        $prof = Professional::forceCreate([
            'id' => $id,
            'name' => $name,
            'specialty' => $specialty,
            'consultation_price' => 20000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'email' => "$id@aura.cl",
            'password' => Hash::make('secret1234'),
            'role' => 'professional',
            'duty_status' => 'disponible',
            'coverage_zones' => 'Providencia',
        ]);

        $prof->services()->sync(ClinicalService::pluck('id')->all());

        return $prof;
    }

    private function makePatient(string $email = 'paciente@aura.cl'): User
    {
        return User::create([
            'name' => 'Paciente Prueba',
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);
    }

    private function asPatient(User $patient): array
    {
        return ['Authorization' => 'Bearer ' . $patient->createToken('test')->plainTextToken];
    }

    public function test_user_can_retrieve_all_active_bookings_with_assigned_professionals(): void
    {
        $this->makeServices();
        $doc = $this->makeProfessional('prof_doc', 'Dra. Camila Rivera', 'Medicina General');
        $kine = $this->makeProfessional('prof_kine', 'Klga. María José Díaz', 'Kinesiología');

        $patient = $this->makePatient('paciente1@aura.cl');
        $otherPatient = $this->makePatient('paciente2@aura.cl');

        // Solicitud 1: En atención con el médico
        ServiceRequest::create([
            'id' => 'req_med_activa',
            'user_id' => $patient->id,
            'service_id' => 'medico',
            'status' => 'en_atencion',
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'zone' => 'Providencia',
            'payment_method' => 'mercadopago',
            'final_price' => 25000,
            'start_time' => '10:00',
            'eta_minutes' => 30,
            'current_step' => 3,
            'professional_id' => $doc->id,
        ]);

        // Solicitud 2: En camino con kinesiólogo
        ServiceRequest::create([
            'id' => 'req_kine_activa',
            'user_id' => $patient->id,
            'service_id' => 'kine_motora',
            'status' => 'en_camino',
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'zone' => 'Providencia',
            'payment_method' => 'mercadopago',
            'final_price' => 30000,
            'start_time' => '10:30',
            'eta_minutes' => 45,
            'current_step' => 2,
            'professional_id' => $kine->id,
        ]);

        // Solicitud 3: Completada (no debe aparecer)
        ServiceRequest::create([
            'id' => 'req_completada',
            'user_id' => $patient->id,
            'service_id' => 'medico',
            'status' => 'completed',
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'zone' => 'Providencia',
            'payment_method' => 'mercadopago',
            'final_price' => 25000,
            'start_time' => '08:00',
            'eta_minutes' => 30,
            'current_step' => 4,
            'professional_id' => $doc->id,
        ]);

        // Solicitud 4: Cancelada (no debe aparecer)
        ServiceRequest::create([
            'id' => 'req_cancelada',
            'user_id' => $patient->id,
            'service_id' => 'medico',
            'status' => 'cancelled',
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'zone' => 'Providencia',
            'payment_method' => 'mercadopago',
            'final_price' => 25000,
            'start_time' => '07:00',
            'eta_minutes' => 30,
            'current_step' => 0,
            'professional_id' => null,
        ]);

        // Solicitud 5: Activa de otro paciente (no debe aparecer)
        ServiceRequest::create([
            'id' => 'req_otro_paciente',
            'user_id' => $otherPatient->id,
            'service_id' => 'medico',
            'status' => 'accepted',
            'patient_type' => 'self',
            'address_text' => 'Calle 5678, Providencia',
            'zone' => 'Providencia',
            'payment_method' => 'mercadopago',
            'final_price' => 25000,
            'start_time' => '10:15',
            'eta_minutes' => 30,
            'current_step' => 1,
            'professional_id' => $doc->id,
        ]);

        $response = $this->withHeaders($this->asPatient($patient))
            ->getJson('/api/bookings/active-all')
            ->assertStatus(200)
            ->json();

        $this->assertCount(2, $response);

        $ids = collect($response)->pluck('id')->all();
        $this->assertContains('req_med_activa', $ids);
        $this->assertContains('req_kine_activa', $ids);
        $this->assertNotContains('req_completada', $ids);
        $this->assertNotContains('req_cancelada', $ids);
        $this->assertNotContains('req_otro_paciente', $ids);

        // Validar que viene cargada la relación del profesional con sus datos
        $medReq = collect($response)->firstWhere('id', 'req_med_activa');
        $this->assertNotNull($medReq['professional']);
        $this->assertSame('Dra. Camila Rivera', $medReq['professional']['name']);

        $kineReq = collect($response)->firstWhere('id', 'req_kine_activa');
        $this->assertNotNull($kineReq['professional']);
        $this->assertSame('Klga. María José Díaz', $kineReq['professional']['name']);
    }

    public function test_creating_second_booking_does_not_cancel_assigned_or_in_progress_bookings(): void
    {
        $this->makeServices();
        $doc = $this->makeProfessional('prof_doc', 'Dra. Camila Rivera', 'Medicina General');
        $patient = $this->makePatient();

        // 1. Solicitud inicial ya tomada por un profesional
        $primera = ServiceRequest::create([
            'id' => 'req_primera_atencion',
            'user_id' => $patient->id,
            'service_id' => 'medico',
            'status' => 'accepted',
            'patient_type' => 'self',
            'address_text' => 'Av. Suecia 100, Providencia',
            'zone' => 'Providencia',
            'payment_method' => 'mercadopago',
            'final_price' => 25000,
            'start_time' => '11:00',
            'eta_minutes' => 30,
            'current_step' => 1,
            'professional_id' => $doc->id,
            'is_scheduled' => false,
        ]);

        // 2. El paciente crea una segunda solicitud simultánea (ej. Kinesiología)
        $this->withHeaders($this->asPatient($patient))
            ->postJson('/api/bookings', [
                'service_id' => 'kine_motora',
                'patient_type' => 'self',
                'address_text' => 'Av. Suecia 100, Providencia',
                'symptoms_description' => 'Dolor lumbar agudo y dificultad para caminar',
                'eta_minutes' => 45,
            ])
            ->assertStatus(201);

        // 3. Comprobar que la primera solicitud NO fue cancelada
        $primeraRefrescada = $primera->fresh();
        $this->assertSame('accepted', $primeraRefrescada->status);
        $this->assertSame('prof_doc', $primeraRefrescada->professional_id);

        // 4. Comprobar que /api/bookings/active-all devuelve ambas
        $actives = $this->withHeaders($this->asPatient($patient))
            ->getJson('/api/bookings/active-all')
            ->assertStatus(200)
            ->json();

        $this->assertCount(2, $actives);
    }

    public function test_creating_second_booking_cancels_previous_unassigned_pending_requests(): void
    {
        $this->makeServices();
        $patient = $this->makePatient();

        // Solicitud previa sin asignar y sin pagar
        $pendiente = ServiceRequest::create([
            'id' => 'req_pendiente_vieja',
            'user_id' => $patient->id,
            'service_id' => 'medico',
            'status' => 'pending_payment',
            'patient_type' => 'self',
            'address_text' => 'Av. Suecia 100, Providencia',
            'zone' => 'Providencia',
            'payment_method' => 'mercadopago',
            'final_price' => 25000,
            'start_time' => '11:00',
            'eta_minutes' => 30,
            'current_step' => 0,
            'professional_id' => null,
            'is_scheduled' => false,
        ]);

        // El paciente crea una nueva solicitud
        $this->withHeaders($this->asPatient($patient))
            ->postJson('/api/bookings', [
                'service_id' => 'kine_motora',
                'patient_type' => 'self',
                'address_text' => 'Av. Suecia 100, Providencia',
                'symptoms_description' => 'Molestias cervicales y mareos leves',
                'eta_minutes' => 45,
            ])
            ->assertStatus(201);

        // La pendiente sin asignar sí se cancela
        $this->assertSame('cancelled', $pendiente->fresh()->status);
    }

    public function test_unread_summary_returns_unread_counts_for_multiple_active_bookings(): void
    {
        $this->makeServices();
        $doc = $this->makeProfessional('prof_doc', 'Dra. Camila Rivera', 'Medicina General');
        $patient = $this->makePatient();

        $req1 = ServiceRequest::create([
            'id' => 'req_chat_1',
            'user_id' => $patient->id,
            'service_id' => 'medico',
            'status' => 'accepted',
            'patient_type' => 'self',
            'address_text' => 'Av. Suecia 100, Providencia',
            'zone' => 'Providencia',
            'payment_method' => 'mercadopago',
            'final_price' => 25000,
            'start_time' => '11:00',
            'eta_minutes' => 30,
            'current_step' => 1,
            'professional_id' => $doc->id,
        ]);

        $req2 = ServiceRequest::create([
            'id' => 'req_chat_2',
            'user_id' => $patient->id,
            'service_id' => 'kine_motora',
            'status' => 'en_camino',
            'patient_type' => 'self',
            'address_text' => 'Av. Suecia 100, Providencia',
            'zone' => 'Providencia',
            'payment_method' => 'mercadopago',
            'final_price' => 30000,
            'start_time' => '11:30',
            'eta_minutes' => 45,
            'current_step' => 2,
            'professional_id' => $doc->id,
        ]);

        // Mensajes req_1: 1 system + 2 provider + 2 patient
        ChatMessage::create([
            'id' => 'm_sys_1',
            'service_request_id' => $req1->id,
            'sender' => 'system',
            'text' => 'Dra. Camila Rivera tomó tu solicitud.',
            'timestamp' => '11:01',
        ]);
        ChatMessage::create([
            'id' => 'm_prov_1',
            'service_request_id' => $req1->id,
            'sender' => 'provider',
            'text' => 'Hola, voy en camino.',
            'timestamp' => '11:02',
        ]);
        ChatMessage::create([
            'id' => 'm_prov_2',
            'service_request_id' => $req1->id,
            'sender' => 'provider',
            'text' => '¿Funciona el citófono?',
            'timestamp' => '11:03',
        ]);
        ChatMessage::create([
            'id' => 'm_pat_1',
            'service_request_id' => $req1->id,
            'sender' => 'patient',
            'text' => 'Sí, dpto 402.',
            'timestamp' => '11:04',
        ]);

        // Mensajes req_2: 1 provider
        ChatMessage::create([
            'id' => 'm_prov_3',
            'service_request_id' => $req2->id,
            'sender' => 'provider',
            'text' => 'Preparando equipos kinesiológicos.',
            'timestamp' => '11:05',
        ]);

        $resumen = $this->withHeaders($this->asPatient($patient))
            ->getJson('/api/bookings/unread-summary')
            ->assertStatus(200)
            ->json();

        $map = collect($resumen)->pluck('from_provider', 'booking_id');

        // req_1 tiene 3 mensajes no escritos por el paciente (1 system + 2 provider)
        $this->assertSame(3, $map['req_chat_1']);
        // req_2 tiene 1 mensaje
        $this->assertSame(1, $map['req_chat_2']);
    }
}
