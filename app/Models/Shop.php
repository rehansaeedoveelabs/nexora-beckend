<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    protected $fillable = [
        'domain',
        'name',
        'plan_name',
        'currency',
        'timezone',
        'access_token',
        'access_token_expires_at',
        'refresh_token',
        'refresh_token_expires_at',
        'scopes',
        'installed_at',
        'uninstalled_at',
        'locations_synced_at',
    ];

    // Never serialize credentials, even by accident.
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'locations_synced_at' => 'datetime',
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function isInstalled(): bool
    {
        return $this->uninstalled_at === null && $this->refresh_token !== null;
    }

    /**
     * True when we hold a refresh token that can still mint access tokens.
     */
    public function hasUsableGrant(): bool
    {
        return $this->isInstalled()
            && ($this->refresh_token_expires_at === null || $this->refresh_token_expires_at->isFuture());
    }

    public function forgetTokens(): void
    {
        $this->forceFill([
            'access_token' => null,
            'access_token_expires_at' => null,
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
        ])->save();
    }
}
