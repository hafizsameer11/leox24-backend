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
            if (! $user || $user->isSuperAdmin()) {
                return;
            }

            if (! $user->company_id) {
                $query->whereNull('company_id');

                return;
            }

            $allowedCategories = Company::query()
                ->whereKey($user->company_id)
                ->first()
                ?->allowedLeadCategoryNames() ?? [];

            // Clients see leads in their granted categories (including shared
            // uploads with null company_id), plus leads uploaded for their company.
            $query->where(function (Builder $inner) use ($user, $allowedCategories) {
                $inner->where('company_id', $user->company_id);
                if ($allowedCategories !== []) {
                    $inner->orWhereIn('category', $allowedCategories);
                }
            });
        });
    }

    protected $fillable = [
        'company_id',
        'lead_import_id',
        'name',
        'email',
        'phone',
        'mobile',
        'age',
        'gender',
        'country',
        'city',
        'date_of_birth',
        'intention',
        'source',
        'status',
        'bulk_email_status',
        'bulk_email_sent_at',
        'bulk_email_error',
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
        'date_of_birth' => 'date:Y-m-d',
        'value' => 'decimal:2',
        'bulk_email_sent_at' => 'datetime',
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
