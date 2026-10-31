<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An uploaded manuscript with its cover details.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property string $author
 * @property string $title
 * @property string $assignment
 * @property string $original_name
 * @property string $file_path
 * @property int $file_size
 * @property string $file_sha256
 * @property int $page_count
 * @property int|null $word_count
 * @property int|null $character_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SubmissionStatus $status
 * @property-read Scan|null $latestScan
 */
#[Fillable([
    'user_id', 'author', 'title', 'assignment', 'original_name',
    'file_path', 'file_size', 'file_sha256', 'page_count',
    'word_count', 'character_count',
])]
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory, HasUlids;

    /**
     * Only `public_id` gets a ULID. The numeric `id` stays the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** The numeric `id` never reaches the browser. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Scan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    /**
     * The latest scan is the one with the highest `id` (AC-2).
     *
     * @return HasOne<Scan, $this>
     */
    public function latestScan(): HasOne
    {
        return $this->hasOne(Scan::class)->latestOfMany();
    }

    /**
     * Status for the list, worked out from the latest scan (AC-10).
     *
     * @return Attribute<SubmissionStatus, never>
     */
    protected function status(): Attribute
    {
        return Attribute::get(fn (): SubmissionStatus => SubmissionStatus::fromScan($this->latestScan?->status));
    }
}
