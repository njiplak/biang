<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One step of a customer's journey. See App\Support\ProductEvents. */
class ProductEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'user_id', 'workspace_id', 'properties', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
