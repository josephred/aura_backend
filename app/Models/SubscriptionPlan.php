<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'description',
        'monthly_price',
        'included_consultations',
        'discount_percentage',
        'features',
        'active',
    ];

    protected $casts = [
        'monthly_price' => 'integer',
        'included_consultations' => 'integer',
        'discount_percentage' => 'integer',
        'features' => 'array',
        'active' => 'boolean',
    ];

    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class, 'plan_id');
    }
}
