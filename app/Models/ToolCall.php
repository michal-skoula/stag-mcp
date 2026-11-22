<?php

namespace App\Models;

use Database\Factories\ToolCallFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One MCP tool call. See the telemetry config for what is and is not stored.
 */
class ToolCall extends Model
{
    /** @use HasFactory<ToolCallFactory> */
    use HasFactory;

    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'tool', 'failed', 'duration_ms'];

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
            'failed' => 'boolean',
            'duration_ms' => 'float',
        ];
    }
}
