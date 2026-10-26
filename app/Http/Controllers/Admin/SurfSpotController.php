<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SkillLevel;
use App\Http\Controllers\Controller;
use App\Models\SurfSpot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SISA maintains the surf-spot registry so the coordinates students see are the
 * real Siargao breaks. Accuracy is a data problem, not a code problem, so admins
 * can correct a pin without a deploy.
 */
class SurfSpotController extends Controller
{
    public function index(Request $request): Response
    {
        $query = SurfSpot::query();

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('area', 'like', "%{$search}%");
            });
        }

        if ($request->filled('difficulty')) {
            $query->byDifficulty($request->string('difficulty'));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->string('status') === 'active');
        }

        return Inertia::render('Admin/SurfSpots/Index', [
            'spots' => $query->ordered()->paginate(25)->withQueryString(),
            'filters' => $request->only(['search', 'difficulty', 'status']),
            'areas' => SurfSpot::query()
                ->whereNotNull('area')
                ->distinct()
                ->orderBy('area')
                ->pluck('area')
                ->all(),
            'mapSpots' => SurfSpot::query()
                ->ordered()
                ->get()
                ->map(fn (SurfSpot $spot) => $spot->toMapPayload())
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        SurfSpot::create($validated);

        return back()->with('success', 'Surf spot added to the Siargao registry.');
    }

    public function update(Request $request, SurfSpot $surfSpot): RedirectResponse
    {
        $validated = $this->validated($request, $surfSpot);

        $surfSpot->update($validated);

        return back()->with('success', "{$surfSpot->name} updated.");
    }

    public function destroy(SurfSpot $surfSpot): RedirectResponse
    {
        // A break that already has lessons attached must not be deleted, or the
        // booking history loses its location.
        if ($surfSpot->bookings()->exists()) {
            $surfSpot->update(['is_active' => false]);

            return back()->with(
                'success',
                "{$surfSpot->name} has bookings attached, so it was deactivated instead of deleted."
            );
        }

        $surfSpot->delete();

        return back()->with('success', 'Surf spot removed.');
    }

    private function validated(Request $request, ?SurfSpot $spot = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'area' => ['required', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'difficulty' => ['required', Rule::in(array_column(SkillLevel::cases(), 'value'))],
            'break_type' => ['nullable', 'string', 'max:60'],
            'best_tide' => ['nullable', 'string', 'max:60'],
            'hazard_notes' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['required', 'numeric', 'between:9.55,10.15'],
            'longitude' => ['required', 'numeric', 'between:125.90,126.35'],
            'is_active' => ['boolean'],
            'is_safe_zone' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        // FR 3.5 - a break can only be offered to beginners if SISA marked it safe.
        if (
            ($validated['difficulty'] ?? null) === SkillLevel::Beginner->value
            && ($validated['is_safe_zone'] ?? false) === false
        ) {
            $validated['is_safe_zone'] = true;
        }

        $validated['region'] = $validated['region'] ?? 'Siargao Island';
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        return $validated;
    }
}
