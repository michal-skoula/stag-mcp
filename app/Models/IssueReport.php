<?php

namespace App\Models;

use Database\Factories\IssueReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A problem reported from the chat. Written by the `database` destination.
 */
class IssueReport extends Model
{
    /** @use HasFactory<IssueReportFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'tool', 'description', 'error', 'arguments', 'occurred_at'];

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
            'arguments' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
