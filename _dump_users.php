<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

foreach (App\Models\User::with('roles', 'instructorProfile')->get() as $u) {
    echo $u->id.' | '.$u->email.' | '.$u->role.' | '.$u->roles->pluck('name')->implode(',').' | status='.($u->instructorProfile?->status?->value ?? '-').PHP_EOL;
}

echo PHP_EOL."BOOKINGS\n";
foreach (App\Models\Booking::orderBy('id')->get() as $b) {
    echo $b->id.' | student='.$b->student_id.' | instructor='.$b->instructor_id.' | '.$b->status->value.' | pay='.($b->payment_status?->value ?? '-').' | date='.$b->date.' | '.$b->time_period?->value.PHP_EOL;
}
echo PHP_EOL."APPEALS=".App\Models\InstructorAppeal::count()." SUSPENSIONS=".App\Models\InstructorSuspension::count()." INCIDENTS=".App\Models\SafetyIncident::count()." CERT=".App\Models\Certificate::count().PHP_EOL;
