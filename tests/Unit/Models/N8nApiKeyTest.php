<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\ApiKeyStatus;
use Oriceon\N8nBridge\Models\N8nApiKey;
use Oriceon\N8nBridge\Models\N8nCredential;

covers(N8nApiKey::class);

beforeEach(function() {
    $this->credential = N8nCredential::create(['name' => 'Test Credential', 'is_active' => true]);
});

describe('N8nApiKey generation', function() {
    it('generates a key with correct prefix and active status', function() {
        [$plaintext, $model] = N8nApiKey::generate($this->credential->id);

        expect($plaintext)->toStartWith('n8br_sk_')
            ->and(strlen($plaintext))->toBeGreaterThan(20)
            ->and($model->key_prefix)->toStartWith('n8br_sk_')
            ->and($model->status)->toBe(ApiKeyStatus::Active)
            ->and($model->use_count)->toBe(0);
    });

    it('stores SHA-256 hash, never plaintext', function() {
        [$plaintext, $model] = N8nApiKey::generate($this->credential->id);

        expect($model->key_hash)->not->toBe($plaintext)
            ->and($model->key_hash)->toBe(hash('sha256', $plaintext));
    });

    it('hides key_hash from serialization', function() {
        [, $model] = N8nApiKey::generate($this->credential->id);
        expect(array_key_exists('key_hash', $model->toArray()))->toBeFalse();
    });
});

describe('N8nApiKey verification', function() {
    it('verifies a correct key', function() {
        [$plaintext, $model] = N8nApiKey::generate($this->credential->id);
        expect($model->verify($plaintext))->toBeTrue();
    });

    it('rejects an incorrect key', function() {
        [, $model] = N8nApiKey::generate($this->credential->id);
        expect($model->verify('n8br_sk_wrongkeyabcdefghijklmnop'))->toBeFalse();
    });

    it('rejects a revoked key', function() {
        [$plaintext, $model] = N8nApiKey::generate($this->credential->id);
        $model->revoke();

        expect($model->verify($plaintext))->toBeFalse()
            ->and($model->status)->toBe(ApiKeyStatus::Revoked)
            ->and($model->revoked_at)->not->toBeNull();
    });

    it('allows grace period verification within window', function() {
        [$plaintext, $model] = N8nApiKey::generate($this->credential->id);
        $model->startGracePeriod(300);

        expect($model->status)->toBe(ApiKeyStatus::Grace)
            ->and($model->verify($plaintext))->toBeTrue();
    });

    it('rejects grace key after period expires', function() {
        [$plaintext, $model] = N8nApiKey::generate($this->credential->id);
        $model->update(['status' => ApiKeyStatus::Grace, 'grace_until' => now()->subMinute()]);

        expect($model->verify($plaintext))->toBeFalse();
    });
});

describe('N8nApiKey usage tracking', function() {
    it('increments use_count and sets last_used_at on recordUsage()', function() {
        [, $model] = N8nApiKey::generate($this->credential->id);
        expect($model->use_count)->toBe(0);

        $model->recordUsage();
        $model->refresh();

        expect($model->use_count)->toBe(1)
            ->and($model->last_used_at)->not->toBeNull();
    });
});

it('belongs to the correct credential', function() {
    [, $model] = N8nApiKey::generate($this->credential->id);
    expect($model->credential->id)->toBe($this->credential->id);
});
