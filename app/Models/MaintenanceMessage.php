<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceMessage extends Model
{
    protected $fillable = [
        'request_id',
        'sender_id',
        'body',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'request_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
