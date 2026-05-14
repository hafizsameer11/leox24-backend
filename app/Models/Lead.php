<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;

class Lead extends Model
{
    use HasFactory;

    protected static function booted()
    {
        static::addGlobalScope('company', function (Builder $query) {
            $user = auth()->user();
            if ($user && !$user->isSuperAdmin()) {
                if ($user->company_id) {
                    $query->where('company_id', $user->company_id);
                } else {
                    // If user has no company_id, only show leads with null company_id
                    $query->whereNull('company_id');
                }
            }
        });
    }

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'source',
        'status',
        'category',
        'file_name',
        'file_format',
        'file_headers',
        'file_records',
        'raw_attributes',
        'value',
        'assigned_to',
    ];

    protected $casts = [
        'file_headers' => 'array',
        'file_records' => 'array',
        'raw_attributes' => 'array',
        'value' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }
}
