<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\WaiverType;
use App\Models\Booking;
use App\Models\InstructorProfile;
use App\Models\User;
use App\Models\Waiver;
use Illuminate\Support\Facades\Hash;

// Unverified student for the VerifyEmail screen
$unverified = User::firstOrCreate(
    ['email' => 'unverified@siasurf.test'],
    [
        'name' => 'Sam Unverified',
        'password' => Hash::make('password'),
        'role' => 'student',
        'email_verified_at' => null,
    ]
);
$unverified->assignRole('student');

// Minor booking: liability signed, parental consent still missing
$minor = Booking::where('student_age', '<', 18)->first();
if ($minor) {
    Waiver::firstOrCreate(
        ['booking_id' => $minor->id, 'type' => WaiverType::Liability->value],
        [
            'signed_by' => $minor->student->name,
            'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            'pdf_path' => 'waivers/demo-'.$minor->id.'.pdf',
            'signed_at' => now()->subHour(),
            'retention_until' => now()->addYears(7),
        ]
    );
    echo "minor booking: {$minor->id}\n";
}

echo "USERS\n";
foreach (User::with('instructorProfile')->orderBy('id')->get() as $u) {
    echo $u->id.' | '.$u->email.' | '.$u->role.' | status='.($u->instructorProfile?->status?->value ?? '-')."\n";
}

echo "BOOKINGS\n";
foreach (Booking::orderBy('id')->get() as $b) {
    echo $b->id.' | student='.$b->student_id.' | instructor='.$b->instructor_id.' | '.$b->status->value.' | age='.$b->student_age.' | waiver='.($b->waiver()->exists() ? 'y' : 'n')."\n";
}

echo "INCIDENTS\n";
foreach (App\Models\SafetyIncident::orderBy('id')->get() as $i) {
    echo $i->id.' | '.$i->status->value.' | instructor='.$i->instructor_id."\n";
}

echo "APPEALS\n";
foreach (App\Models\InstructorAppeal::orderBy('id')->get() as $a) {
    echo $a->id.' | '.$a->status->value.' | instructor='.$a->instructor_id."\n";
}
