<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Services\FcmService;
use App\Services\MercadoPagoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Post-deployment health check.
 *
 * Written for the hosting migration: it exercises the paths that silently break
 * when a server changes — writable storage, upload limits, execution timeouts,
 * database reachability and pending migrations, and the absolute URLs handed to
 * the app. Run it on the new host before shipping anything else:
 *
 *   php artisan aura:doctor
 */
class DiagnoseDeployment extends Command
{
    protected $signature = 'aura:doctor {--keep-test-file : Do not delete the file written during the test}';

    protected $description = 'Verifica almacenamiento, subidas, base de datos e integraciones tras un cambio de servidor';

    private int $failures = 0;
    private int $warnings = 0;

    public function handle(): int
    {
        $this->line('');
        $this->info('AURA · diagnóstico de despliegue');
        $this->line(str_repeat('─', 60));

        $this->checkEnvironment();
        $this->checkStorage();
        $this->checkUploadLimits();
        $this->checkExecutionLimits();
        $this->checkDatabase();
        $this->checkAttachments();
        $this->checkIntegrations();

        $this->line(str_repeat('─', 60));

        if ($this->failures > 0) {
            $this->error("Problemas críticos: {$this->failures} · Advertencias: {$this->warnings}");
            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->warn("Sin problemas críticos. Advertencias: {$this->warnings}");
            return self::SUCCESS;
        }

        $this->info('Todo en orden.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------- helpers

    private function ok(string $message): void
    {
        $this->line("  <fg=green>✓</> $message");
    }

    private function warn2(string $message, string $fix = ''): void
    {
        $this->warnings++;
        $this->line("  <fg=yellow>!</> $message");
        if ($fix !== '') {
            $this->line("      <fg=gray>→ $fix</>");
        }
    }

    /**
     * No se llama `fail()`: `Illuminate\Console\Command` ya declara un
     * `fail()` público, y redeclararlo en privado es un error fatal de PHP que
     * tumba *todo* artisan, no solo este comando.
     */
    private function bad(string $message, string $fix = ''): void
    {
        $this->failures++;
        $this->line("  <fg=red>✗</> $message");
        if ($fix !== '') {
            $this->line("      <fg=gray>→ $fix</>");
        }
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("<options=bold>$title</>");
    }

    /** Turns "8M" / "512K" into bytes. */
    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    // -------------------------------------------------------------- checks

    private function checkEnvironment(): void
    {
        $this->section('Entorno');

        $this->ok('PHP ' . PHP_VERSION);
        $this->ok('Entorno: ' . app()->environment());

        if (config('app.debug') && app()->environment('production')) {
            $this->bad(
                'APP_DEBUG=true en producción: expone rutas, credenciales y trazas.',
                'Poner APP_DEBUG=false y ejecutar php artisan config:cache',
            );
        }

        // The most common breakage after a move: APP_URL still points at the
        // old server, so every absolute link handed to the app is unreachable.
        $appUrl = (string) config('app.url');
        if ($appUrl === '' || str_contains($appUrl, 'localhost') || str_contains($appUrl, 'ngrok')) {
            $this->bad(
                // `«{$appUrl}»` con llaves: sin ellas PHP interpreta «$appUrl»
                // como la variable `$appUrl»` (los bytes altos son válidos en
                // un identificador) y este comando reventaría justo cuando
                // detecta el problema que vino a buscar.
                "APP_URL apunta a «{$appUrl}».",
                'Los enlaces de audio y recetas que recibe la app se construyen con esta '
                . 'URL. Debe ser el dominio real del servidor nuevo.',
            );
        } else {
            $this->ok("APP_URL: $appUrl");
        }

        if (empty(config('app.key'))) {
            $this->bad('APP_KEY vacía.', 'php artisan key:generate');
        }
    }

    private function checkStorage(): void
    {
        $this->section('Almacenamiento');

        // Real round trip on the private disk: this is where symptom voice
        // notes and prescriptions live.
        $probe = 'diagnostico/aura-doctor-' . now()->format('Ymd-His') . '.txt';
        $payload = 'aura-doctor ' . now()->toIso8601String();

        try {
            Storage::disk('local')->put($probe, $payload);

            if (!Storage::disk('local')->exists($probe)) {
                $this->bad('Se escribió en el disco privado pero el archivo no aparece.');
            } elseif (Storage::disk('local')->get($probe) !== $payload) {
                $this->bad('El archivo escrito en el disco privado no se lee igual.');
            } else {
                $this->ok('Disco privado: escritura, lectura y borrado correctos');
            }

            if (!$this->option('keep-test-file')) {
                Storage::disk('local')->delete($probe);
            }
        } catch (\Throwable $e) {
            $this->bad(
                'No se pudo escribir en el disco privado: ' . $e->getMessage(),
                'Revisar permisos de storage/app/private (típicamente 775 y dueño del usuario web)',
            );
        }

        foreach (['storage/framework', 'storage/logs', 'bootstrap/cache'] as $path) {
            if (!is_writable(base_path($path))) {
                $this->bad(
                    "$path no es escribible.",
                    'chmod -R 775 ' . $path . ' y ajustar el dueño al usuario del servidor web',
                );
            }
        }

        // Legacy: attachments used to be public and reached through this link.
        $link = public_path('storage');
        if (is_link($link) && !file_exists($link)) {
            $this->warn2(
                'El symlink public/storage está roto (apunta a una ruta del servidor anterior).',
                'Los adjuntos nuevos ya no lo usan. Si quedan archivos antiguos: '
                . 'php artisan storage:link && php artisan media:make-private',
            );
        }
    }

    private function checkUploadLimits(): void
    {
        $this->section('Límites de subida');

        $uploadMax = $this->toBytes((string) ini_get('upload_max_filesize'));
        $postMax = $this->toBytes((string) ini_get('post_max_size'));

        // A booking can carry a prescription (up to 10 MB) plus a voice note
        // (up to 5 MB) in a single multipart request.
        $needed = 16 * 1024 * 1024;

        $this->line('  upload_max_filesize: ' . ini_get('upload_max_filesize'));
        $this->line('  post_max_size:       ' . ini_get('post_max_size'));

        if ($uploadMax < 10 * 1024 * 1024) {
            $this->bad(
                'upload_max_filesize es menor a 10 MB: las recetas se rechazarán.',
                'Subirlo a 16M en php.ini o .user.ini del hosting',
            );
        } elseif ($postMax < $needed) {
            $this->bad(
                'post_max_size no cubre receta + nota de voz en la misma solicitud.',
                'Subirlo a 20M como mínimo',
            );
        } else {
            $this->ok('Suficiente para receta (10 MB) + nota de voz (5 MB)');
        }

        if (!empty(ini_get('upload_tmp_dir')) && !is_writable((string) ini_get('upload_tmp_dir'))) {
            $this->bad('upload_tmp_dir no es escribible: fallará toda subida.');
        }
    }

    private function checkExecutionLimits(): void
    {
        $this->section('Tiempos de ejecución');

        $maxExecution = (int) ini_get('max_execution_time');
        $this->line('  max_execution_time: ' . ($maxExecution === 0 ? 'sin límite' : $maxExecution . 's'));

        // streamStatus holds an SSE loop open for 50 seconds.
        if ($maxExecution > 0 && $maxExecution < 60) {
            $this->warn2(
                "El seguimiento en vivo (SSE) mantiene la conexión 50 s y el límite es {$maxExecution}s: se cortará.",
                'Subir max_execution_time a 60+ o migrar el tiempo real a WebSockets',
            );
        }
    }

    private function checkDatabase(): void
    {
        $this->section('Base de datos');

        try {
            DB::connection()->getPdo();
            $this->ok('Conexión establecida (' . config('database.default') . ')');
        } catch (\Throwable $e) {
            $this->bad('Sin conexión a la base de datos: ' . $e->getMessage());
            return;
        }

        if (config('database.default') === 'sqlite' && app()->environment('production')) {
            $this->warn2(
                'Producción está corriendo sobre SQLite.',
                'Configurar DB_CONNECTION=mysql en el servidor nuevo',
            );
        }

        // Pending migrations are the other classic post-migration breakage:
        // the code expects columns the new database does not have yet.
        try {
            $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
                ->keys()
                ->diff(app('migrator')->getRepository()->getRan())
                ->count();

            if ($pending > 0) {
                $this->bad(
                    "Hay $pending migraciones sin ejecutar.",
                    'php artisan migrate --force',
                );
            } else {
                $this->ok('Migraciones al día');
            }
        } catch (\Throwable $e) {
            $this->warn2('No se pudo verificar el estado de migraciones: ' . $e->getMessage());
        }

        // Writing a state and reading it back is the actual concern raised
        // after the migration.
        try {
            $counts = ServiceRequest::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            if ($counts->isEmpty()) {
                $this->line('  <fg=gray>Sin solicitudes registradas todavía.</>');
            } else {
                foreach ($counts as $status => $total) {
                    $this->line("  <fg=gray>$status: $total</>");
                }
            }
        } catch (\Throwable $e) {
            $this->bad('No se pudieron leer los estados de solicitudes: ' . $e->getMessage());
        }
    }

    private function checkAttachments(): void
    {
        $this->section('Adjuntos clínicos');

        try {
            $withAudio = ServiceRequest::whereNotNull('symptom_audio_url')->count();
            $withRx = ServiceRequest::whereNotNull('prescription_file')->count();
        } catch (\Throwable $e) {
            $this->bad('No se pudieron consultar los adjuntos: ' . $e->getMessage());
            return;
        }

        $this->line("  Notas de voz registradas: $withAudio");
        $this->line("  Recetas registradas:      $withRx");

        // Files still stored as absolute URLs come from before attachments
        // moved to the private disk. After a host change those links usually
        // point at a domain that no longer serves them.
        $legacy = ServiceRequest::where(function ($query) {
            $query->where('symptom_audio_url', 'like', 'http%')
                ->orWhere('prescription_file', 'like', 'http%');
        })->count();

        if ($legacy > 0) {
            $this->warn2(
                "$legacy adjuntos siguen guardados como URL absoluta del servidor anterior.",
                'php artisan media:make-private --dry-run  (y luego sin --dry-run)',
            );
        }

        // Every private path must actually exist on this disk.
        $missing = 0;
        ServiceRequest::where(function ($query) {
            $query->whereNotNull('symptom_audio_url')->orWhereNotNull('prescription_file');
        })->chunk(200, function ($requests) use (&$missing) {
            foreach ($requests as $request) {
                foreach ([$request->symptom_audio_url, $request->prescription_file] as $path) {
                    if (empty($path) || str_starts_with((string) $path, 'http')) {
                        continue;
                    }
                    if (!Storage::disk('local')->exists($path)) {
                        $missing++;
                    }
                }
            }
        });

        if ($missing > 0) {
            $this->bad(
                "$missing adjuntos referenciados en la base de datos no existen en disco.",
                'Los archivos no se copiaron en la migración. Recuperarlos del servidor anterior.',
            );
        } elseif ($withAudio + $withRx > 0) {
            $this->ok('Todos los adjuntos referenciados existen en disco');
        }
    }

    private function checkIntegrations(): void
    {
        $this->section('Integraciones');

        if (app(MercadoPagoService::class)->isConfigured()) {
            $this->ok('Mercado Pago configurado');
        } elseif (app()->environment('production')) {
            $this->bad(
                'Mercado Pago sin configurar en producción.',
                'Sin token las solicitudes se rechazan con 503. Definir MERCADOPAGO_ACCESS_TOKEN.',
            );
        } else {
            $this->warn2('Mercado Pago sin configurar (fuera de producción se activa sin cobrar)');
        }

        if (app(FcmService::class)->isConfigured()) {
            $this->ok('Notificaciones push configuradas');
        } else {
            $this->warn2(
                'FCM sin configurar: no salen notificaciones.',
                'Verificar que la ruta de FIREBASE_CREDENTIALS exista en el servidor nuevo',
            );
        }

        if (config('queue.default') === 'sync') {
            $this->warn2('La cola está en modo sync: todo corre dentro del request.');
        }
    }
}
