<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    protected $fillable = ['category'];

    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(Report::class);
    }
}
