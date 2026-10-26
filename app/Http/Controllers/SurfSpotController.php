<?php

namespace App\Http\Controllers;

use App\Models\SurfSpot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public surf-spot directory for Siargao.
 *
 * The map here is the single source of truth the booking flow reuses, so a
 * student sees the same break name and pin in search, on the profile and at
 * checkout.
 */
class SurfSpotController extends Controller
{
    public function index(Request $request): Response
    {
        $spots = SurfSpot::query()
            ->active()
            ->ordered()
            ->get()
            ->map(fn (SurfSpot $spot) => $spot->toMapPayload())
            ->values()
            ->all();

        return Inertia::render('Public/SurfSpots/Index', [
            'spots' => $spots,
            'areas' => $this->areas(),
            'mapCenter' => [
                'latitude' => 9.7950,
                'longitude' => 126.1550,
                'zoom' => 11,
            ],
            'filters' => $request->only(['area', 'difficulty']),
        ]);
    }

    /**
     * JSON feed for map widgets and the mobile client.
     */
    public function json(Request $request): JsonResponse
    {
        $query = SurfSpot::query()->active()->ordered();

        if ($request->filled('area')) {
            $query->inArea($request->string('area'));
        }

        if ($request->filled('difficulty')) {
            $query->byDifficulty($request->string('difficulty'));
        }

        return response()->json([
            'spots' => $query->get()
                ->map(fn (SurfSpot $spot) => $spot->toMapPayload())
                ->values()
                ->all(),
            'areas' => $this->areas(),
        ]);
    }

    /**
     * Distinct areas that actually have active breaks, for the filter dropdown.
     *
     * @return array<int, string>
     */
    private function areas(): array
    {
        return SurfSpot::query()
            ->active()
            ->whereNotNull('area')
            ->distinct()
            ->orderBy('area')
            ->pluck('area')
            ->all();
    }
}
