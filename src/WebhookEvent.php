<?php

namespace Udviklr\CashierNets;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $processed_at
 * @property string $source
 */
class WebhookEvent extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'nets_webhook_events';

    /**
     * The attributes that are not mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $attributes = ['source' => 'webhook'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Determine if the event has been processed.
     */
    public function processed(): bool
    {
        return $this->processed_at !== null;
    }
}
