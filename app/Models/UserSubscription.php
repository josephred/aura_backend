<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubscription extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'plan_id',
        'status',
        'mercadopago_preapproval_id',
        'starts_at',
        'expires_at',
        'next_billing_date',
        'consultations_used',
        'auto_renew',
        'payment_method_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'next_billing_date' => 'datetime',
        'consultations_used' => 'integer',
        'auto_renew' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasRemainingConsultations(): bool
    {
        if (!$this->isActive() || !$this->plan) {
            return false;
        }

        return $this->consultations_used < $this->plan->included_consultations;
    }
}
