<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\WaiverService;

class EnsureWaiverSigned
{
    protected WaiverService $waiverService;

    public function __construct(WaiverService $waiverService)
    {
        $this->waiverService = $waiverService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $booking = $request->route('booking');

        if ($booking) {
            $missingTypes = $this->waiverService->getMissingWaiverTypes($booking);

            if (!empty($missingTypes)) {
                $firstMissing = $missingTypes[0];

                if ($firstMissing === 'liability') {
                    return redirect()->route('bookings.waiver', $booking)
                        ->with('error', 'You must sign the liability waiver before proceeding.');
                }

                if ($firstMissing === 'parental_consent') {
                    return redirect()->route('bookings.parental-consent', $booking)
                        ->with('error', 'Parental consent is required for students under 18.');
                }
            }
        }

        return $next($request);
    }
}
