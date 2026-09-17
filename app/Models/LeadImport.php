<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadImport extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'file_name',
        'stored_path',
        'file_format',
        'category',
        'status',
        'total_rows',
        'processed_rows',
        'imported_count',
        'error_count',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
