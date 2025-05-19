<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'telephone',
        'password',
        'address_id',
        'role_id'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function calculatePoints()
    {
        $pendingReports = $this->reports()->whereHas('status', function($query) {
            $query->where('status', 'pendente');
        })->count();

        $inProgressReports = $this->reports()->whereHas('status', function($query) {
            $query->where('status', 'em resolução');
        })->count();

        $resolvedReports = $this->reports()->whereHas('status', function($query) {
            $query->where('status', 'resolvido');
        })->count();

        $totalPoints = ($pendingReports * 1) + ($inProgressReports * 5) + ($resolvedReports * 10);

        // Atualiza os pontos do usuário
        $this->points = $totalPoints;
        $this->save();

        return $totalPoints;
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }
}
