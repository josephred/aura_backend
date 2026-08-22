<?php

namespace App\Http\Controllers;

use App\Models\Professional;
use App\Models\ServiceRating;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RatingController extends Controller
{
    /**
     * Rate a completed care visit.
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $booking = ServiceRequest::find($id);

        if (!$booking) {
            return response()->json(['error' => 'Atención no encontrada'], 404);
        }

        if ((string) $booking->user_id !== (string) $user->id) {
            return response()->json(['error' => 'No autorizado para calificar esta atención'], 403);
        }

        if ($booking->status !== 'completed') {
            return response()->json([
                'error' => 'Solo se pueden calificar atenciones finalizadas.',
            ], 422);
        }

        $validated = $request->validate([
            'rating' => 'nullable|integer|min:1|max:5',
            'stars' => 'nullable|integer|min:1|max:5',
            'feedback' => 'nullable|string|max:1000',
            'comment' => 'nullable|string|max:1000',
        ]);

        $stars = $validated['rating'] ?? $validated['stars'] ?? null;
        if ($stars === null) {
            return response()->json(['error' => 'Debes indicar una calificación de 1 a 5 estrellas.'], 422);
        }

        $feedback = $validated['feedback'] ?? $validated['comment'] ?? null;

        $rating = DB::transaction(function () use ($booking, $user, $stars, $feedback) {
            $serviceRating = ServiceRating::updateOrCreate(
                [
                    'booking_id' => $booking->id,
                    'user_id' => $user->id,
                ],
                [
                    'professional_id' => $booking->professional_id,
                    'rating' => $stars,
                    'feedback' => $feedback,
                ]
            );

            if ($booking->professional_id) {
                $professional = Professional::find($booking->professional_id);
                if ($professional) {
                    $avg = ServiceRating::where('professional_id', $professional->id)->avg('rating');
                    $count = ServiceRating::where('professional_id', $professional->id)->count();

                    $professional->forceFill([
                        'rating_avg' => $avg !== null ? round((float) $avg, 2) : null,
                        'rating_count' => (int) $count,
                    ])->save();
                }
            }

            return $serviceRating;
        });

        return response()->json([
            'success' => true,
            'message' => '¡Gracias por calificar la atención!',
            'rating' => $rating,
        ]);
    }
}
