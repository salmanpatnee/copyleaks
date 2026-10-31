<?php

namespace App\Models;

use App\Enums\ArtifactKind;
use Database\Factories\ScanArtifactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A raw Copyleaks file, stored unchanged. Insert only (AC-5).
 *
 * `source_id` is '' for summary and text so the unique rule works.
 *
 * @property int $id
 * @property int $scan_id
 * @property ArtifactKind $kind
 * @property string $source_id
 * @property string $path
 * @property string $sha256
 * @property int $size_bytes
 * @property Carbon|null $created_at
 */
#[Fillable(['scan_id', 'kind', 'source_id', 'path', 'sha256', 'size_bytes'])]
class ScanArtifact extends Model
{
    /** @use HasFactory<ScanArtifactFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $attributes = [
        'source_id' => '',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ArtifactKind::class,
        ];
    }

    /** @return BelongsTo<Scan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /**
     * There is no update path for raw artifacts.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Scan artifacts are insert only and cannot be updated.');
    }
}
