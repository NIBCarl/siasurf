<?php

namespace App\Services;

use App\Models\InstructorProfile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class QRCodeService
{
    /**
     * Generate a QR code for an instructor
     * Note: Using simple SVG generation without external library
     */
    public function generateInstructorQR(InstructorProfile $instructor): string
    {
        $qrData = json_encode([
            'v' => '1.0',
            'type' => 'siasurf_instructor',
            'id' => $instructor->user_id,
            'name' => $instructor->user->name,
            'level' => $instructor->level,
            'verified_at' => $instructor->created_at->format('Y-m-d'),
        ]);

        $filename = "instructors/{$instructor->user_id}/qr-code.svg";
        
        // Simple SVG QR code representation
        $svg = $this->generateSimpleSVG($qrData);
        
        Storage::disk('local')->put($filename, $svg);
        
        return $filename;
    }

    /**
     * Generate a simple SVG representation
     * In production, replace with actual QR library
     */
    private function generateSimpleSVG(string $data): string
    {
        $hash = md5($data);
        $size = 400;
        $cellSize = 20;
        
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">';
        $svg .= '<rect width="100%" height="100%" fill="white"/>';
        
        // Create a pattern based on the hash
        for ($i = 0; $i < strlen($hash); $i += 2) {
            $x = (hexdec($hash[$i]) % ($size / $cellSize)) * $cellSize;
            $y = (hexdec($hash[$i + 1]) % ($size / $cellSize)) * $cellSize;
            $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $cellSize . '" height="' . $cellSize . '" fill="black"/>';
        }
        
        // Add corner markers (QR code style)
        $markerSize = 60;
        $svg .= '<rect x="20" y="20" width="' . $markerSize . '" height="' . $markerSize . '" fill="black"/>';
        $svg .= '<rect x="' . ($size - 80) . '" y="20" width="' . $markerSize . '" height="' . $markerSize . '" fill="black"/>';
        $svg .= '<rect x="20" y="' . ($size - 80) . '" width="' . $markerSize . '" height="' . $markerSize . '" fill="black"/>';
        
        $svg .= '</svg>';
        
        return $svg;
    }

    /**
     * Get the URL for an instructor's QR code
     */
    public function getQRCodeUrl(InstructorProfile $instructor): ?string
    {
        if (!$instructor->qr_code_path) {
            return null;
        }

        return Storage::disk('local')->url($instructor->qr_code_path);
    }

    /**
     * Delete an instructor's QR code
     */
    public function deleteQRCode(InstructorProfile $instructor): void
    {
        if ($instructor->qr_code_path && Storage::disk('local')->exists($instructor->qr_code_path)) {
            Storage::disk('local')->delete($instructor->qr_code_path);
        }
    }
}