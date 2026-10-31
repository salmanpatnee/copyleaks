<?php

namespace Database\Factories;

use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates rows only. Nothing here calls Copyleaks.
 *
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $publicId = Str::lower((string) Str::ulid());

        return [
            'public_id' => $publicId,
            'user_id' => null,
            'author' => fake()->name(),
            'title' => fake()->sentence(4),
            'assignment' => fake()->words(3, true),
            'original_name' => 'manuscript.pdf',
            'file_path' => "submissions/{$publicId}/manuscript.pdf",
            'file_size' => fake()->numberBetween(10_000, 5_000_000),
            'file_sha256' => hash('sha256', fake()->unique()->uuid()),
            'page_count' => fake()->numberBetween(1, 30),
            'word_count' => null,
            'character_count' => null,
        ];
    }
}
