<?php

namespace Database\Factories;

use App\Enums\EventKind;
use App\Models\Scan;
use App\Models\ScanEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanEvent>
 */
class ScanEventFactory extends Factory
{
    protected $model = ScanEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scan_id' => Scan::factory(),
            'kind' => EventKind::Status,
            'status' => 'completed',
            'payload' => ['status' => 'completed'],
            'received_at' => now(),
            'handled_at' => null,
        ];
    }
}
