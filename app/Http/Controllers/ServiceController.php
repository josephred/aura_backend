<?php

namespace App\Http\Controllers;

use App\Models\ClinicalService;
use Illuminate\Http\JsonResponse;

class ServiceController extends Controller
{
    /**
     * Display a listing of the clinical services.
     */
    public function index(): JsonResponse
    {
        return response()->json(ClinicalService::all());
    }
}
