<?php

namespace App\Models;

use Database\Factories\UserPreferencesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores the user's preferences (settings) used for modifying default tool behavior.
 */
class UserPreferences extends Model
{
    /** @use HasFactory<UserPreferencesFactory> */
    use HasFactory;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
