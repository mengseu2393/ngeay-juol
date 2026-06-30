<?php

namespace App\Models;

use App\Enums\ChatRoomType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatRoom extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'created_by_id',
        'type',
        'related_type',
        'related_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ChatRoomType::class,
            'archived_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** Polymorphic context (uses the morph map aliases, not FQCNs). */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_room_participants')
            ->withPivot(['last_read_at', 'is_muted'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }
}
