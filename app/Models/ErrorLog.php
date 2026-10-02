<?php

namespace App\Models;

use App\Enums\ErrorLogType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A failure someone hit — written by ErrorLogService from the exception handler,
 * read by super admins in /admin → Error Log. Kept 30 days (model:prune).
 */
class ErrorLog extends Model
{
    use MassPrunable;

    protected $fillable = [
        'type',
        'message',
        'exception_class',
        'file',
        'line',
        'trace',
        'method',
        'url',
        'route_name',
        'user_id',
        'ip',
        'user_agent',
        'context',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ErrorLogType::class,
            'line' => 'integer',
            'context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(30));
    }

    // --- Relationships ---

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
