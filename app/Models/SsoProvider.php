<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * An OAuth2 / OpenID Connect login provider. Types: generic OIDC (discovery URL),
 * Google, Microsoft (Entra, tenant configurable) and GitHub (plain OAuth2).
 */
class SsoProvider extends Model
{
    public const TYPES = ['oidc', 'google', 'microsoft', 'github'];

    protected $fillable = [
        'name', 'slug', 'type', 'tenant', 'enabled', 'discovery_url', 'issuer', 'authorize_url', 'token_url',
        'userinfo_url', 'client_id', 'client_secret', 'scopes', 'token_auth', 'use_pkce', 'subject_claim',
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
        ];
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
        return $this->enabled
            && (string) $this->authorize_url !== ''
            && (string) $this->token_url !== ''
            && (string) $this->client_id !== ''
            && (string) $this->client_secret !== ''
            && ((string) $this->userinfo_url !== '' || $this->isOidc());
    }

    public function isOidc(): bool
    {
        return in_array('openid', $this->scopeList(), true);
    }

    public function isGithub(): bool
    {
        return $this->type === 'github';
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
        return self::query()->where('enabled', true)->orderBy('sort')->orderBy('name')->get()
            ->filter(fn (self $p) => $p->isUsable())->values();
    }
}
