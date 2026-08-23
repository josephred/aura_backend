<?php

namespace Tests\Feature;

use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * REQ-08: Perfil médico autogestionable y hoja de vida.
 */
class StaffProfileTest extends TestCase
{
    use RefreshDatabase;

    private function makeProfessional(string $id = 'prof_test'): Professional
    {
        return Professional::forceCreate([
            'id' => $id,
            'name' => 'Dr. Matías Soto',
            'specialty' => 'Medicina Familiar',
            'bio' => 'Atención integral a domicilio.',
            'consultation_price' => 25000,
            'consultation_duration_minutes' => 30,
            'registration_number' => 'RN-44551',
            'years_of_experience' => 8,
            'phone' => '+56 9 8888 7777',
            'photo_url' => 'https://aura.cl/photos/dr-matias.jpg',
            'duty_status' => 'disponible',
            'coverage_zones' => 'Las Condes, Providencia',
            'active' => true,
        ]);
    }

    private function staffUser(string $role = 'doctor_provider', ?string $professionalId = 'prof_test'): array
    {
        $user = User::create([
            'name' => 'Dr. Matías Soto',
            'email' => 'matias@aura.cl',
            'password' => bcrypt('password123'),
        ]);
        $user->forceFill([
            'role' => $role,
            'professional_id' => $professionalId,
        ])->save();

        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_staff_can_view_extended_profile(): void
    {
        $professional = $this->makeProfessional();
        [, $token] = $this->staffUser('doctor_provider', $professional->id);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/staff/profile');

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Dr. Matías Soto')
            ->assertJsonPath('specialty', 'Medicina Familiar')
            ->assertJsonPath('bio', 'Atención integral a domicilio.')
            ->assertJsonPath('registration_number', 'RN-44551')
            ->assertJsonPath('years_of_experience', 8)
            ->assertJsonPath('phone', '+56 9 8888 7777')
            ->assertJsonPath('photo_url', 'https://aura.cl/photos/dr-matias.jpg');
    }

    public function test_staff_can_update_own_profile_resume(): void
    {
        $professional = $this->makeProfessional();
        [, $token] = $this->staffUser('doctor_provider', $professional->id);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/staff/profile', [
                'bio' => 'Especialista en geriatría y cuidados paliativos.',
                'registration_number' => 'RN-99887',
                'years_of_experience' => 10,
                'phone' => '+56 9 5555 4444',
                'coverage_zones' => ['Ñuñoa', 'Providencia', 'Santiago'],
                'photo_url' => 'https://aura.cl/photos/new-avatar.png',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('profile.bio', 'Especialista en geriatría y cuidados paliativos.')
            ->assertJsonPath('profile.registration_number', 'RN-99887')
            ->assertJsonPath('profile.years_of_experience', 10)
            ->assertJsonPath('profile.phone', '+56 9 5555 4444')
            ->assertJsonPath('profile.photo_url', 'https://aura.cl/photos/new-avatar.png');

        $professional->refresh();
        $this->assertSame('Especialista en geriatría y cuidados paliativos.', $professional->bio);
        $this->assertSame('RN-99887', $professional->registration_number);
        $this->assertSame(10, $professional->years_of_experience);
        $this->assertSame('+56 9 5555 4444', $professional->phone);
    }

    public function test_staff_can_upload_profile_photo(): void
    {
        Storage::fake('public');
        $professional = $this->makeProfessional();
        [, $token] = $this->staffUser('doctor_provider', $professional->id);

        $photo = UploadedFile::fake()->create('doctor_photo.jpg', 100, 'image/jpeg');

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->post('/api/staff/profile', [
                'photo' => $photo,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $professional->refresh();
        $this->assertNotNull($professional->photo_url);
        $this->assertStringStartsWith('/storage/professionals/photos/', $professional->photo_url);

        $relativePath = str_replace('/storage/', '', $professional->photo_url);
        Storage::disk('public')->assertExists($relativePath);
    }

    public function test_protected_fields_cannot_be_mass_assigned(): void
    {
        $professional = $this->makeProfessional();
        $professional->forceFill([
            'role' => 'doctor_provider',
            'commission_bps' => 1250,
            'rating_avg' => 4.5,
            'rating_count' => 5,
        ])->save();

        [, $token] = $this->staffUser('doctor_provider', $professional->id);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/staff/profile', [
                'bio' => 'Biografía legítima.',
                'role' => 'admin',
                'commission_bps' => 0,
                'rating_avg' => 5.0,
                'rating_count' => 100,
                'password' => 'injected_password',
            ]);

        $professional->refresh();
        $this->assertSame('Biografía legítima.', $professional->bio);
        // Protected fields must not change
        $this->assertSame('doctor_provider', $professional->role);
        $this->assertSame(1250, $professional->commission_bps);
        $this->assertEquals(4.5, $professional->rating_avg);
        $this->assertSame(5, $professional->rating_count);
    }

    public function test_admin_can_update_professional_curriculum(): void
    {
        $professional = $this->makeProfessional('prof_managed');
        [, $adminToken] = $this->staffUser('operator_admin', null);

        $response = $this->withHeaders(['Authorization' => "Bearer $adminToken"])
            ->postJson('/api/staff/admin/professionals/prof_managed', [
                'bio' => 'Actualizado por administración.',
                'registration_number' => 'SIS-778899',
                'years_of_experience' => 15,
                'coverage_zones' => 'Vitacura, Lo Barnechea',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $professional->refresh();
        $this->assertSame('Actualizado por administración.', $professional->bio);
        $this->assertSame('SIS-778899', $professional->registration_number);
        $this->assertSame(15, $professional->years_of_experience);
    }

    public function test_unauthenticated_or_patient_cannot_access_profile_updates(): void
    {
        $this->postJson('/api/staff/profile', ['bio' => 'Hacker'])
            ->assertStatus(401);

        $patient = User::create([
            'name' => 'Paciente',
            'email' => 'paciente@aura.cl',
            'password' => bcrypt('password123'),
        ]);
        $patientToken = $patient->createToken('p')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer $patientToken"])
            ->postJson('/api/staff/profile', ['bio' => 'Hacker'])
            ->assertStatus(403);
    }

    public function test_staff_duty_returns_provides_lab_true_for_lab_professionals(): void
    {
        $labProf = Professional::forceCreate([
            'id' => 'prof_duty_lab',
            'name' => 'TM. Laboratorio Test',
            'specialty' => 'Tecnología Médica',
            'consultation_price' => 19500,
            'consultation_duration_minutes' => 30,
            'active' => true,
        ]);
        \App\Models\ClinicalService::firstOrCreate(
            ['id' => 'laboratorio'],
            [
                'title' => 'Laboratorio',
                'short_title' => 'Lab',
                'subtitle' => 'Exámenes',
                'description' => 'Lab',
                'base_price' => 19500,
                'base_eta' => '24h',
                'requires_prescription' => false,
                'icon_name' => 'biotech',
                'warning_info' => 'Info',
                'placeholder_text' => 'Placeholder',
            ]
        );
        $labProf->services()->sync(['laboratorio']);

        [, $labToken] = $this->staffUser('doctor_provider', $labProf->id);

        $this->withHeaders(['Authorization' => "Bearer $labToken"])
            ->getJson('/api/staff/duty')
            ->assertStatus(200)
            ->assertJsonPath('provides_lab', true);
    }

    public function test_staff_duty_returns_provides_lab_false_for_non_lab_professionals(): void
    {
        $doctorProf = Professional::forceCreate([
            'id' => 'prof_duty_doc',
            'name' => 'Dr. General Test',
            'specialty' => 'Medicina General',
            'consultation_price' => 25000,
            'consultation_duration_minutes' => 30,
            'active' => true,
        ]);
        $docUser = User::create([
            'name' => 'Dr. General Test',
            'email' => 'doc_test@aura.cl',
            'password' => bcrypt('password123'),
        ]);
        $docUser->forceFill([
            'role' => 'doctor_provider',
            'professional_id' => $doctorProf->id,
        ])->save();
        $docToken = $docUser->createToken('doc')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer $docToken"])
            ->getJson('/api/staff/duty')
            ->assertStatus(200)
            ->assertJsonPath('provides_lab', false);
    }
}
