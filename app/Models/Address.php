<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Address extends Model
{
    protected $fillable = [
        'street',
        'number',
        'city',
        'cp'
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
