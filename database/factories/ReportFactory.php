<?php

namespace Database\Factories;

use App\Enums\ReportKind;
use App\Models\Report;
use App\Models\Scan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scan_id' => Scan::factory(),
            'kind' => ReportKind::Similarity,
            'path' => 'reports/'.fake()->numberBetween(1, 999).'/similarity-1-'.fake()->numerify('##########').'.pdf',
            'sha256' => hash('sha256', fake()->uuid()),
            'size_bytes' => fake()->numberBetween(10_000, 1_000_000),
            'page_count' => fake()->numberBetween(1, 30),
            'renderer_version' => '1',
            'built_at' => now(),
        ];
    }
}
