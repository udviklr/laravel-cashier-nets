<?php

namespace Udviklr\CashierNets;

use Illuminate\Database\Eloquent\Model;

/**
 * @property \Illuminate\Support\Carbon|null $processed_at
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Determine if the event has been processed.
     */
    public function processed(): bool
    {
        return $this->processed_at !== null;
    }
}
