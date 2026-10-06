<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappOtp extends Model
{
    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_UPDATE_WHATSAPP = 'update_whatsapp';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'whatsapp_number',
        'purpose',
        'otp_hash',
        'expires_at',
        'verified_at',
        'attempt_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
