<?php

namespace Database\Factories;

use App\Enums\ArtifactKind;
use App\Models\Scan;
use App\Models\ScanArtifact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanArtifact>
 */
class ScanArtifactFactory extends Factory
{
    protected $model = ScanArtifact::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scan_id' => Scan::factory(),
            'kind' => ArtifactKind::Summary,
            'source_id' => '',
            'path' => 'scans/'.fake()->uuid().'/summary.json.gz',
            'sha256' => hash('sha256', fake()->uuid()),
            'size_bytes' => fake()->numberBetween(100, 100_000),
        ];
    }
}
