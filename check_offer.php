<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$signal = DB::table('video_signals')
    ->where('appointment_id', 'apt_1783781488_nclt')
    ->where('type', 'offer')
    ->orderBy('id', 'desc')
    ->first();

if ($signal) {
    echo "ID: " . $signal->id . PHP_EOL;
    echo "Type: " . $signal->type . PHP_EOL;
    echo "Payload (raw): " . $signal->payload . PHP_EOL;
    $decoded = json_decode($signal->payload, true);
    echo "Decoded sdp type: " . gettype($decoded['sdp'] ?? null) . PHP_EOL;
    echo "SDP snippet: " . substr($decoded['sdp'] ?? '', 0, 50) . PHP_EOL;
} else {
    echo "No offer signal found!" . PHP_EOL;
}
