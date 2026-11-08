<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'vat',
        'address',
        'status',
        'subscription_status',
        'stripe_customer_id',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Get the users for the company.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the project accesses for the company.
     */
    public function projectAccesses()
    {
        return $this->hasMany(CompanyProjectAccess::class);
    }

    /**
     * Lead categories this company is allowed to view/upload.
     */
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'company_category')
            ->withTimestamps();
    }

    /**
     * Category names granted to this company (for lead filtering).
     *
     * @return list<string>
     */
    public function allowedLeadCategoryNames(): array
    {
        return $this->categories()
            ->orderBy('name')
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the customers for the company.
     */
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Get the signup requests for the company.
     */
    public function signupRequests()
    {
        return $this->hasMany(SignupRequest::class);
    }

    /**
     * Get the subscription for the company.
     */
    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Check if company has active subscription.
     */
    public function hasActiveSubscription(): bool
    {
        // Check subscription_status first (faster)
        if ($this->subscription_status === 'active') {
            return true;
        }
        
        // Also check the subscription relationship
        if ($this->relationLoaded('subscription') && $this->subscription) {
            return $this->subscription->isActive();
        }
        
        // If relationship not loaded, check database
        return $this->subscription()->where('status', 'active')->exists();
    }

    /**
     * Check if company is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if company can access a project.
     */
    public function canAccessProject(int $projectId): bool
    {
        return $this->projectAccesses()
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Scope a query to only include active companies.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include pending companies.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
