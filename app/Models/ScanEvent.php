<?php

namespace App\Models;

use App\Enums\EventKind;
use Database\Factories\ScanEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One webhook delivery, recorded once per (scan, status, kind).
 * `status` stays an open string because Copyleaks values are not ours to fix.
 *
 * @property int $id
 * @property int $scan_id
 * @property EventKind $kind
 * @property string $status
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 * @property Carbon|null $handled_at
 */
#[Fillable(['scan_id', 'kind', 'status', 'payload', 'received_at', 'handled_at'])]
class ScanEvent extends Model
{
    /** @use HasFactory<ScanEventFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => EventKind::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Scan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
