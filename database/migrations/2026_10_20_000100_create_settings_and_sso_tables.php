<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypted key/value settings, OAuth2 / OpenID Connect providers and the
 * links between local users and provider subjects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('sso_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            // oidc | google | microsoft | github
            $table->string('type', 16)->default('oidc');
            $table->string('tenant', 100)->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('discovery_url', 500)->nullable();
            $table->string('issuer', 500)->nullable();
            $table->string('authorize_url', 500)->nullable();
            $table->string('token_url', 500)->nullable();
            $table->string('userinfo_url', 500)->nullable();
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->string('scopes')->default('openid profile email');
            $table->string('token_auth', 16)->default('post');
            $table->boolean('use_pkce')->default(true);
            $table->string('subject_claim', 64)->default('sub');
            $table->string('email_claim', 64)->default('email');
            $table->string('label_claim', 64)->default('preferred_username');
            $table->integer('sort')->default(0);
            $table->timestamp('discovered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sso_provider_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            // Shown in the account settings (e-mail or login name at the provider).
            $table->string('label')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['sso_provider_id', 'subject']);
            $table->unique(['sso_provider_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
        Schema::dropIfExists('sso_providers');
        Schema::dropIfExists('settings');
    }
};
