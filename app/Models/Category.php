<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

class Category extends Model
{
    use HasFactory;

    protected static function booted()
    {
        static::addGlobalScope('company', function (Builder $query) {
            $user = auth()->user();
            if (! $user || $user->isSuperAdmin() || ! $user->company_id) {
                return;
            }

            // Clients only see categories explicitly granted to their company.
            $query->whereIn('categories.id', function ($sub) use ($user) {
                $sub->select('category_id')
                    ->from('company_category')
                    ->where('company_id', $user->company_id);
            });
        });
    }

    protected $fillable = [
        'company_id',
        'name',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_category')
            ->withTimestamps();
    }
}
