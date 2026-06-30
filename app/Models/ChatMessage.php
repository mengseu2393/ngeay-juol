<?php

namespace App\Models;

use App\Enums\ChatMessageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'chat_room_id',
        'user_id',
        'type',
        'body',
        'file_url',
        'is_read',
    ];

    protected function casts(): array
    {
        return [
            'type' => ChatMessageType::class,
            'is_read' => 'boolean',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'chat_room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
