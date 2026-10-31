<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Database\Factories\ScanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One try at asking Copyleaks. A retry is a new scan.
 *
 * `status` is not fillable: it only changes through ScanTransitioner (AC-11).
 *
 * @property int $id
 * @property int $submission_id
 * @property string $copyleaks_scan_id
 * @property string|null $copyleaks_export_id
 * @property ScanStatus $status
 * @property string|null $failure_reason
 * @property string $webhook_token_hash
 * @property bool $ai_requested
 * @property string|null $similarity_percent
 * @property string|null $ai_percent
 * @property bool $is_sandbox
 * @property Carbon|null $submitted_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $remote_deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'submission_id', 'copyleaks_scan_id', 'copyleaks_export_id',
    'webhook_token_hash', 'ai_requested', 'is_sandbox',
    'similarity_percent', 'ai_percent', 'remote_deleted_at',
])]
#[Hidden(['webhook_token_hash'])]
class Scan extends Model
{
    /** @use HasFactory<ScanFactory> */
    use HasFactory;

    /** A new scan always starts as `submitting`, before the Copyleaks call. */
    protected $attributes = [
        'status' => 'submitting',
        'ai_requested' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'ai_requested' => 'boolean',
            'is_sandbox' => 'boolean',
            'similarity_percent' => 'decimal:2',
            'ai_percent' => 'decimal:2',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'remote_deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return HasMany<ScanEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ScanEvent::class);
    }

    /** @return HasMany<ScanArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(ScanArtifact::class);
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
