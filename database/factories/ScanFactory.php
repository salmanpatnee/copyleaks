<?php

namespace Database\Factories;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates rows only. Nothing here calls Copyleaks.
 *
 * @extends Factory<Scan>
 */
class ScanFactory extends Factory
{
    protected $model = Scan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'copyleaks_scan_id' => Str::lower((string) Str::ulid()),
            'copyleaks_export_id' => null,
            'status' => ScanStatus::Submitting,
            'failure_reason' => null,
            'webhook_token_hash' => hash('sha256', Str::random(32)),
            'ai_requested' => false,
            'similarity_percent' => null,
            'ai_percent' => null,
            'is_sandbox' => true,
            'submitted_at' => null,
            'completed_at' => null,
            'remote_deleted_at' => null,
        ];
    }

    public function waiting(): static
    {
        return $this->state(fn () => [
            'status' => ScanStatus::Waiting,
            'submitted_at' => now(),
        ]);
    }

    public function fetching(): static
    {
        return $this->state(fn () => [
            'status' => ScanStatus::Fetching,
            'submitted_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ScanStatus::Completed,
            'submitted_at' => now()->subMinute(),
            'completed_at' => now(),
            'similarity_percent' => fake()->randomFloat(2, 0, 100),
        ]);
    }

    public function failed(string $reason = 'Copyleaks error'): static
    {
        return $this->state(fn () => [
            'status' => ScanStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }
}
