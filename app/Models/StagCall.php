<?php

namespace App\Models;

use Database\Factories\StagCallFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to a STAG web service. The endpoint is the path only.
 */
class StagCall extends Model
{
    /** @use HasFactory<StagCallFactory> */
    use HasFactory;

    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'tool', 'method', 'endpoint', 'status', 'duration_ms'];

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('telemetry.retention_days')));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'float',
        ];
    }
}
