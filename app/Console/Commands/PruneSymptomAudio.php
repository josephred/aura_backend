<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Prunes orphaned or expired symptom audio notes from local disk storage.
 *
 * Symptom voice notes are retained while the care episode is active and for a
 * reasonable clinical retention window (default 30 days). Once the request is
 * completed or cancelled past this window, the audio file is deleted from
 * disk to prevent indefinite storage accumulation and uphold privacy standards.
 */
class PruneSymptomAudio extends Command
{
    protected $signature = 'aura:prune-symptom-audio {--days=30 : Number of days of retention for completed/cancelled requests}';

    protected $description = 'Prune old symptom voice notes from storage';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $requests = ServiceRequest::whereNotNull('symptom_audio_url')
            ->whereIn('status', ['completed', 'cancelled'])
            ->where('updated_at', '<', $cutoff)
            ->get();

        $deletedCount = 0;

        foreach ($requests as $request) {
            $path = $request->symptom_audio_url;
            if (!empty($path) && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
            $request->update(['symptom_audio_url' => null]);
            $deletedCount++;
        }

        $this->info("Audios de síntomas purgados: $deletedCount (retención: $days días)");

        return self::SUCCESS;
    }
}
