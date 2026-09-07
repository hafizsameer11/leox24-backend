<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted()
    {
        static::addGlobalScope('company', function (Builder $query) {
            $user = auth()->user();
            if ($user && !$user->isSuperAdmin() && $user->company_id) {
                $query->where('company_id', $user->company_id);
            }
        });
    }

    protected $fillable = [
        'company_id',
        'created_by',
        'email',
        'phone',
        'vat',
        'first_name',
        'last_name',
        'address',
        'notes',
        'title',
        'second_last_name',
        'customer_group',
        'customer_code',
        'gender',
        'date_of_birth',
        'place_of_birth',
        'branch',
        'date_added',
        'city',
        'zip_code',
        'state_province',
        'country',
        'tax_code',
        'pec_email',
        'tax_code_fe',
        'phone_secondary',
        'mobile',
        'fax',
        'privacy_date',
        'privacy_consent_processing',
        'marketing_consent',
        'profiling_consent',
        'send_sms',
        'send_mail',
        'send_newsletter',
        'billing_address',
        'billing_city',
        'billing_zip_code',
        'billing_state',
        'billing_country',
        'family_members',
        'language',
        'private_notes',
        'occupation',
        'vision_problem',
        'hobbies',
        'acquired_by',
        'promotion',
        'referred_by',
    ];

    protected $appends = ['notes_text'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_added' => 'date',
            'privacy_date' => 'date',
            'privacy_consent_processing' => 'boolean',
            'marketing_consent' => 'boolean',
            'profiling_consent' => 'boolean',
            'send_sms' => 'boolean',
            'send_mail' => 'boolean',
            'send_newsletter' => 'boolean',
        ];
    }

    /**
     * Get the company that owns the customer.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the opportunities for the customer.
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    /**
     * Get all tasks for the customer.
     */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /**
     * Get all notes for the customer.
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable');
    }

    /**
     * Get all documents for the customer.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Get the full name attribute.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name} {$this->second_last_name}");
    }

    /** Avoid collision between the legacy `notes` database column and notes() relation. */
    public function getNotesTextAttribute(): ?string
    {
        return $this->attributes['notes'] ?? null;
    }

    /**
     * Scope a query to filter by company.
     */
    public function scopeForCompany($query, ?int $companyId)
    {
        if ($companyId) {
            return $query->where('company_id', $companyId);
        }
        return $query;
    }
}
