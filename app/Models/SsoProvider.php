<?php

namespace App\Models;

use App\Support\AppSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * An OAuth2 / OpenID Connect login provider. Types: generic OIDC (discovery URL),
 * Google, Microsoft (Entra, tenant configurable), GitHub and Facebook (both plain OAuth2),
 * Apple (Sign in with Apple: client secret JWT, form_post callback; global providers only).
 *
 * owner_user_id null = global provider (admin); otherwise a personal provider
 * of that user (never listed for others, only logs in / links its owner).
 */
class SsoProvider extends Model
{
    public const TYPES = ['oidc', 'google', 'microsoft', 'github', 'facebook', 'apple'];

    /** Types with fixed endpoints (no discovery URL to edit). */
    public const PRESET_TYPES = ['google', 'microsoft', 'github', 'facebook', 'apple'];

    protected $fillable = [
        'name', 'slug', 'type', 'tenant', 'enabled', 'discovery_url', 'issuer', 'authorize_url', 'token_url',
        'userinfo_url', 'client_id', 'client_secret', 'apple_team_id', 'apple_key_id', 'scopes', 'token_auth', 'use_pkce', 'subject_claim',
        'email_claim', 'label_claim', 'sort', 'discovered_at',
    ];

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'client_secret' => 'encrypted',
            'use_pkce' => 'boolean',
            'sort' => 'integer',
            'discovered_at' => 'datetime',
            'owner_user_id' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Global (admin managed) providers only.
     */
    public function scopeGlobalOnly(Builder $query): void
    {
        $query->whereNull('owner_user_id');
    }

    public function isPersonal(): bool
    {
        return $this->owner_user_id !== null;
    }

    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * Complete enough to start a login.
     */
    public function isUsable(): bool
    {
        $base = $this->enabled
            && (! $this->isPersonal() || AppSettings::personalSsoAllowed())
            && (string) $this->authorize_url !== ''
            && (string) $this->token_url !== ''
            && (string) $this->client_id !== ''
            && (string) $this->client_secret !== '';

        if ($this->isApple()) {
            // Apple: no userinfo endpoint; the client secret is a JWT signed with the stored private key.
            return $base && ! $this->isPersonal()
                && (string) $this->apple_team_id !== ''
                && (string) $this->apple_key_id !== '';
        }

        return $base && ((string) $this->userinfo_url !== '' || $this->isOidc());
    }

    /**
     * Apple is OIDC (ID token, nonce) although it does not document the "openid" scope.
     */
    public function isOidc(): bool
    {
        return $this->isApple() || in_array('openid', $this->scopeList(), true);
    }

    public function isApple(): bool
    {
        return $this->type === 'apple';
    }

    public function isGithub(): bool
    {
        return $this->type === 'github';
    }

    /**
     * Button style key: the fixed provider types get their brand look, everything else the generic one.
     */
    public function brand(): string
    {
        return in_array($this->type, self::PRESET_TYPES, true) ? $this->type : 'oidc';
    }

    /**
     * @return list<string>
     */
    public function scopeList(): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', (string) $this->scopes) ?: []));
    }

    public function redirectUri(): string
    {
        return route('login.sso.callback', $this->slug);
    }

    /**
     * @return Collection<int, self>
     */
    public static function usable(): Collection
    {
        return self::query()->globalOnly()->where('enabled', true)->orderBy('sort')->orderBy('name')->get()
            ->filter(fn (self $p) => $p->isUsable())->values();
    }
}
