<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Oriceon\N8nBridge\Models\N8nApiKey;
use Oriceon\N8nBridge\Models\N8nCredential;

/**
 * Central authentication service for all /n8n/* endpoints.
 *
 * All modules — Inbound, Tools, Queue Progress — call this service
 * instead of implementing their own key-checking logic.
 *
 * Flow:
 *   1. Extract key from X-N8N-Key header (or Authorization: Bearer)
 *   2. Find a credential whose active/grace key matches (hash_equals)
 *   3. Optionally verify the request IP against credential's allowed_ips
 *   4. Return the authenticated N8nCredential, or null if auth fails
 *
 * The resolved credential is cached per-key for 60 seconds to avoid
 * hitting the DB on every checkpoint POST or rapid tool call.
 */
final class CredentialAuthService
{
    private const int CACHE_TTL = 60; // seconds

    /**
     * Resolve and authenticate the credential from the request.
     *
     * Returns the authenticated N8nCredential, or null if:
     *   - No key header present
     *   - No credential found with a matching key
     *   - Matched credential is inactive
     *
     * Does NOT check IP — call verifyIp() separately so controllers
     * can return the correct 403 vs. 401.
     */
    #[\NoDiscard]
    public function fromRequest(Request $request): ?N8nCredential
    {
        $plaintext = $this->extractKey($request);

        if ($plaintext === null || $plaintext === '') {
            return null;
        }

        $cacheKey = 'n8n_credential_auth:' . hash('sha256', $plaintext);

        $credentialId = Cache::remember($cacheKey, self::CACHE_TTL, static function() use ($plaintext) {
            $keyHash = hash('sha256', $plaintext);

            // Direct lookup by hash — avoids loading all credentials
            $apiKey = N8nApiKey::query()
                ->where('key_hash', $keyHash)
                ->whereIn('status', ['active', 'grace'])
                ->with('credential:id,is_active')
                ->first();

            if ($apiKey === null || ! $apiKey->credential?->is_active) {
                return;
            }

            if ( ! $apiKey->verify($plaintext)) {
                return;
            }

            $apiKey->recordUsage();

            return $apiKey->credential_id;
        });

        if ($credentialId === null) {
            return null;
        }

        return N8nCredential::find($credentialId);
    }

    /**
     * Verify the request IP against the credential's allowed_ips list.
     * Returns true if the credential has no IP restriction.
     */
    public function verifyIp(Request $request, N8nCredential $credential): bool
    {
        if (empty($credential->allowed_ips)) {
            return true;
        }

        return in_array($request->ip(), $credential->allowed_ips, true);
    }

    /**
     * Full authentication and IP check in one call.
     * Returns [N8nCredential|null, error_reason|null].
     *
     * @return array{0: N8nCredential|null, 1: string|null}
     */
    #[\NoDiscard]
    public function authenticate(Request $request): array
    {
        $plaintext = $this->extractKey($request);

        if ($plaintext === null || $plaintext === '') {
            return [null, 'missing_key'];
        }

        $credential = $this->fromRequest($request);

        if ($credential === null) {
            Log::warning('n8n-bridge: failed authentication attempt', [
                'ip'         => $request->ip(),
                'key_prefix' => substr($plaintext, 0, 12),
                'uri'        => $request->getRequestUri(),
            ]);

            return [null, 'invalid_key'];
        }

        if ( ! $this->verifyIp($request, $credential)) {
            Log::warning('n8n-bridge: IP not allowed', [
                'ip'            => $request->ip(),
                'credential_id' => $credential->id,
                'allowed_ips'   => $credential->allowed_ips,
            ]);

            return [null, 'ip_not_allowed'];
        }

        return [$credential, null];
    }

    /**
     * Invalidate the auth cache for a specific plaintext key.
     * Call this after key rotation or revocation.
     */
    public function invalidateCache(string $plaintext): void
    {
        Cache::forget('n8n_credential_auth:' . hash('sha256', $plaintext));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function extractKey(Request $request): ?string
    {
        // Prefer X-N8N-Key header (explicit, no ambiguity)
        if ($key = $request->header('X-N8N-Key')) {
            return $key;
        }

        // Fallback: Authorization: Bearer <key>
        $auth = $request->header('Authorization', '');

        return str_starts_with($auth, 'Bearer ')
            ? trim(substr($auth, 7)) ?: null
            : null;
    }
}
