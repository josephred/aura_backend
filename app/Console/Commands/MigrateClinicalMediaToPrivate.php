<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Moves prescriptions and symptom voice notes uploaded before the switch to
 * authenticated media out of the world-readable `storage/app/public` folder.
 *
 * Run once after deploying:
 *   php artisan media:make-private
 *   php artisan media:make-private --delete-originals
 */
class MigrateClinicalMediaToPrivate extends Command
{
    protected $signature = 'media:make-private
        {--delete-originals : Remove the public copy once it has been moved}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Move clinical attachments from the public disk to the private disk';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;
        $skipped = 0;

        $columns = [
            'prescription_file' => 'prescriptions',
            'symptom_audio_url' => 'symptom-audio',
        ];

        $requests = ServiceRequest::where(function ($query) {
            $query->whereNotNull('prescription_file')
                ->orWhereNotNull('symptom_audio_url');
        })->get();

        foreach ($requests as $request) {
            $changes = [];

            foreach ($columns as $column => $folder) {
                $value = $request->{$column};
                if (empty($value)) {
                    continue;
                }

                // Already a private path (no scheme): nothing to do.
                if (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')) {
                    continue;
                }

                $relative = $this->publicRelativePath($value, $folder);
                if ($relative === null || !Storage::disk('public')->exists($relative)) {
                    $this->warn("No se encontró el archivo público de {$request->id} ($column): $value");
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("[dry-run] {$request->id}: $column -> $relative");
                    $moved++;
                    continue;
                }

                Storage::disk('local')->put(
                    $relative,
                    Storage::disk('public')->get($relative),
                );

                if ($this->option('delete-originals')) {
                    Storage::disk('public')->delete($relative);
                }

                $changes[$column] = $relative;
                $moved++;
            }

            if ($changes !== [] && !$dryRun) {
                $request->forceFill($changes)->save();
            }
        }

        $this->info("Archivos migrados: $moved · omitidos: $skipped");
        if ($dryRun) {
            $this->comment('Modo dry-run: no se escribió nada.');
        }

        return self::SUCCESS;
    }

    /**
     * Turns a stored public URL back into its path relative to the disk root.
     */
    private function publicRelativePath(string $url, string $folder): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $position = strpos($path, "/$folder/");
        if ($position === false) {
            return null;
        }

        return ltrim(substr($path, $position), '/');
    }
}
