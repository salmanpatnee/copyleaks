<?php

namespace App\Models;

use App\Enums\ManuscriptFallbackReason;
use App\Enums\ManuscriptMode;
use App\Enums\ReportKind;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A finished PDF. At most one per (scan, kind). A rebuild replaces the row.
 *
 * @property int $id
 * @property int $scan_id
 * @property ReportKind $kind
 * @property string $path
 * @property string $sha256
 * @property int $size_bytes
 * @property int $page_count
 * @property string $renderer_version
 * @property ManuscriptMode|null $manuscript_mode
 * @property ManuscriptFallbackReason|null $manuscript_fallback_reason
 * @property Carbon $built_at
 */
#[Fillable(['scan_id', 'kind', 'path', 'sha256', 'size_bytes', 'page_count', 'renderer_version', 'manuscript_mode', 'manuscript_fallback_reason', 'built_at'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ReportKind::class,
            'manuscript_mode' => ManuscriptMode::class,
            'manuscript_fallback_reason' => ManuscriptFallbackReason::class,
            'built_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Scan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
