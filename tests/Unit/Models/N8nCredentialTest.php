<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\ApiKeyStatus;
use Oriceon\N8nBridge\Models\N8nApiKey;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nTool;

covers(N8nCredential::class);

beforeEach(function () {
    $this->credential = N8nCredential::create(['name' => 'My CRM', 'is_active' => true]);
});

// ── Key generation ────────────────────────────────────────────────────────────

describe('N8nCredential::generateKey()', function () {
    it('generates a new API key', function () {
        [$plaintext, $key] = $this->credential->generateKey();

        expect($plaintext)->toStartWith('n8br_sk_')
            ->and($key)->toBeInstanceOf(N8nApiKey::class)
            ->and($key->status)->toBe(ApiKeyStatus::Active);
    });

    it('key belongs to the credential', function () {
        [, $key] = $this->credential->generateKey();

        expect($key->credential_id)->toBe($this->credential->id);
    });
});

// ── Key rotation ──────────────────────────────────────────────────────────────

describe('N8nCredential::rotateKey()', function () {
    it('creates a new key and puts old key in grace', function () {
        [, $oldKey] = $this->credential->generateKey();

        [$newPlaintext, $newKey] = $this->credential->rotateKey(300);

        expect($newKey->status)->toBe(ApiKeyStatus::Active)
            ->and($newKey->id)->not->toBe($oldKey->id)
            ->and($oldKey->fresh()->status)->toBe(ApiKeyStatus::Grace);
    });

    it('old key is still valid during grace period', function () {
        [$oldPlaintext] = $this->credential->generateKey();
        $this->credential->rotateKey(300);

        expect($this->credential->verifyKey($oldPlaintext))->toBeTrue();
    });
});

// ── Key verification ──────────────────────────────────────────────────────────

describe('N8nCredential::verifyKey()', function () {
    it('returns true for a valid active key', function () {
        [$plaintext] = $this->credential->generateKey();

        expect($this->credential->verifyKey($plaintext))->toBeTrue();
    });

    it('returns false for wrong key', function () {
        $this->credential->generateKey();

        expect($this->credential->verifyKey('n8br_sk_wrongkeyXXXXXXXXXXXXXXX'))->toBeFalse();
    });

    it('returns false for revoked key', function () {
        [$plaintext, $key] = $this->credential->generateKey();
        $key->revoke();

        expect($this->credential->verifyKey($plaintext))->toBeFalse();
    });
});

// ── Relations ─────────────────────────────────────────────────────────────────

describe('N8nCredential relations', function () {
    it('activeKey() returns the most recent active key', function () {
        [, $key1] = $this->credential->generateKey();
        [, $key2] = $this->credential->generateKey();

        $active = $this->credential->activeKey;

        expect($active?->id)->toBe($key2->id);
    });

    it('apiKeys() returns all keys', function () {
        $this->credential->generateKey();
        $this->credential->generateKey();

        expect($this->credential->apiKeys()->count())->toBe(2);
    });

    it('inboundEndpoints() returns endpoints via pivot', function () {
        $endpoint = N8nEndpoint::factory()->forCredential($this->credential)->create();

        expect($this->credential->fresh()->inboundEndpoints()->pluck('id'))->toContain($endpoint->id);
    });

    it('tools() returns tools via pivot', function () {
        $tool = N8nTool::factory()->forCredential($this->credential)->create();

        expect($this->credential->fresh()->tools()->pluck('id'))->toContain($tool->id);
    });

    it('can attach multiple credentials to the same endpoint', function () {
        $credB = N8nCredential::factory()->create();
        $endpoint = N8nEndpoint::factory()
            ->forCredential($this->credential)
            ->forCredential($credB)
            ->create();

        expect($endpoint->credentials()->count())->toBe(2);
    });
});

// ── HasPublicUuid via N8nCredential ───────────────────────────────────────────

it('auto-generates uuid on create', function () {
    expect($this->credential->uuid)->not->toBeEmpty();
});

it('getRouteKeyName() returns uuid', function () {
    expect($this->credential->getRouteKeyName())->toBe('uuid');
});

it('findByUuid() finds the correct record', function () {
    $found = N8nCredential::findByUuid($this->credential->uuid);
    expect($found?->id)->toBe($this->credential->id);
});

it('findByUuid() returns null for unknown uuid', function () {
    expect(N8nCredential::findByUuid('00000000-0000-0000-0000-000000000000'))->toBeNull();
});
