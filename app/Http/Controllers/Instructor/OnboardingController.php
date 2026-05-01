<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;

class OnboardingController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bio' => 'required|string|max:1000',
            'level' => 'required|integer|in:1,2,3',
            'rate_per_hour' => 'required|numeric|min:600',
            'certificates' => 'required|array|min:1',
            'certificates.*' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', // Max 10MB
        ], [
            'certificates.required' => 'You must upload at least one certificate.',
            'certificates.min' => 'You must upload at least one certificate.',
        ]);

        $user = auth()->user();

        // Create or update the instructor profile
        $user->instructorProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'bio' => $validated['bio'],
                'level' => $validated['level'],
                'rate_per_hour' => $validated['rate_per_hour'],
                'status' => \App\Enums\InstructorStatus::PendingVerification,
            ]
        );

        // Upload certificates
        if ($request->hasFile('certificates')) {
            foreach ($request->file('certificates') as $file) {
                // Try to determine certificate type based on filename, otherwise default to SurfSkill
                $type = \App\Enums\CertificateType::SurfSkill;
                $filename = strtolower($file->getClientOriginalName());
                if (str_contains($filename, 'bls')) $type = \App\Enums\CertificateType::BLS;
                elseif (str_contains($filename, 'wasar')) $type = \App\Enums\CertificateType::WaSAR;
                elseif (str_contains($filename, 'isa')) $type = \App\Enums\CertificateType::ISA;
                elseif (str_contains($filename, 'sisa')) $type = \App\Enums\CertificateType::SISA;
                elseif (str_contains($filename, 'surf')) $type = \App\Enums\CertificateType::SurfSkill;

                // Store file based on environment configuration
                $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
                $path = $file->store('certificates', $disk);

                $user->certificates()->create([
                    'type' => $type,
                    'file_path' => $path,
                    'status' => \App\Enums\CertificateStatus::PendingVerification,
                ]);
            }
        }

        return redirect()->route('instructor.pending')->with('success', 'Profile and certificates submitted successfully. Please wait for Admin verification.');
    }
}
