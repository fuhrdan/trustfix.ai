<?php

namespace App\Services;

use App\Exceptions\OidcException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A narrow, standards-oriented OpenID Connect relying-party implementation.
 *
 * It supports only the Authorization Code flow with S256 PKCE and signed RSA
 * ID tokens. The intentionally small surface avoids accepting implicit-flow
 * tokens, unsigned tokens, or provider endpoints that are not discovered from
 * an explicitly configured HTTPS issuer.
 */
class OidcService
{
    public function authorizationUrl(Request $request, string $provider): string
    {
        $configuration = $this->provider($provider);
        $discovery = $this->discovery($configuration);

        $state = $this->randomToken(32);
        $nonce = $this->randomToken(32);
        $codeVerifier = $this->randomToken(64);

        $request->session()->put($this->sessionKey($provider), [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'started_at' => now()->timestamp,
        ]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $configuration['client_id'],
            'redirect_uri' => $configuration['redirect_uri'],
            'scope' => implode(' ', $configuration['scopes']),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64UrlEncode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $discovery['authorization_endpoint'].'?'.$query;
    }

    /**
     * @return array{provider:string,issuer:string,subject:string,email:string,name:string,email_verified:bool,member_id:?string,claims:array<string,mixed>}
     */
    public function resolveCallback(Request $request, string $provider): array
    {
        $configuration = $this->provider($provider);
        $pending = $request->session()->get($this->sessionKey($provider));

        if (! is_array($pending) || ! $this->validPendingState($pending)) {
            throw new OidcException('This sign-in request expired. Start again from TrustFix.', 400);
        }

        $receivedState = (string) $request->query('state', '');
        if ($receivedState === '' || ! hash_equals((string) $pending['state'], $receivedState)) {
            throw new OidcException('The sign-in response could not be verified. Start again from TrustFix.', 400);
        }

        // A valid state is single-use even if token exchange fails. This limits
        // replay and forces a fresh PKCE verifier for every retry.
        $request->session()->forget($this->sessionKey($provider));

        if ($request->query('error')) {
            throw new OidcException('The identity provider did not complete sign-in.', 401);
        }

        $code = (string) $request->query('code', '');
        if ($code === '' || strlen($code) > 4096) {
            throw new OidcException('The identity provider returned an invalid authorization response.', 400);
        }

        $discovery = $this->discovery($configuration);
        $tokens = $this->exchangeAuthorizationCode(
            $configuration,
            $discovery,
            $code,
            (string) $pending['code_verifier']
        );

        $claims = $this->verifyIdToken(
            (string) ($tokens['id_token'] ?? ''),
            $configuration,
            $discovery,
            (string) $pending['nonce']
        );

        if (! empty($discovery['userinfo_endpoint']) && ! empty($tokens['access_token'])) {
            $userInfo = $this->userInfo(
                $discovery['userinfo_endpoint'],
                (string) $tokens['access_token']
            );

            if (
                isset($userInfo['sub'])
                && ! hash_equals((string) $claims['sub'], (string) $userInfo['sub'])
            ) {
                throw new OidcException('The identity provider returned inconsistent account details.', 401);
            }

            // UserInfo can supplement profile claims, but it never overrides
            // cryptographically validated protocol claims from the ID token.
            foreach ($userInfo as $key => $value) {
                if (! in_array($key, ['iss', 'sub', 'aud', 'azp', 'nonce', 'exp', 'iat', 'nbf'], true)) {
                    $claims[$key] = $value;
                }
            }
        }

        $this->assertRequiredClaim($claims, $configuration);

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new OidcException('The identity provider did not provide a valid email address.', 422);
        }

        $emailVerified = $this->truthy($claims['email_verified'] ?? false);
        if ($configuration['require_verified_email'] && ! $emailVerified) {
            throw new OidcException('A verified email address is required to sign in with this provider.', 403);
        }

        $subject = trim((string) ($claims['sub'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > 255) {
            throw new OidcException('The identity provider returned an invalid account identifier.', 401);
        }

        $name = trim((string) ($claims['name'] ?? ''));
        if ($name === '') {
            $name = trim(implode(' ', array_filter([
                (string) ($claims['given_name'] ?? ''),
                (string) ($claims['family_name'] ?? ''),
            ])));
        }
        $name = mb_substr($name !== '' ? $name : strstr($email, '@', true), 0, 191);

        $memberId = $this->claim($claims, (string) ($configuration['member_id_claim'] ?? ''));
        if (is_scalar($memberId)) {
            $memberId = trim((string) $memberId);
            $memberId = $memberId !== '' ? mb_substr($memberId, 0, 191) : null;
        } else {
            $memberId = null;
        }

        return [
            'provider' => $provider,
            'issuer' => $configuration['issuer'],
            'subject' => $subject,
            'email' => $email,
            'name' => $name,
            'email_verified' => $emailVerified,
            'member_id' => $memberId,
            // Retain only values that are needed for eligibility/account
            // linking. Access and ID tokens are never persisted.
            'claims' => $this->safeClaims($claims, $configuration),
        ];
    }

    /** @return array<string,mixed> */
    public function provider(string $provider): array
    {
        $providers = (array) config('identity.oidc.providers', []);
        $configuration = $providers[$provider] ?? null;

        if (! config('identity.oidc.enabled') || ! is_array($configuration) || empty($configuration['enabled'])) {
            throw new OidcException('This single sign-on provider is not available.', 404);
        }

        $issuer = rtrim(trim((string) ($configuration['issuer'] ?? '')), '/');
        $clientId = trim((string) ($configuration['client_id'] ?? ''));
        $clientSecret = (string) ($configuration['client_secret'] ?? '');
        $redirectUri = trim((string) ($configuration['redirect_uri'] ?? ''));

        if ($issuer === '' || $clientId === '' || $clientSecret === '' || $redirectUri === '') {
            throw new OidcException('This single sign-on provider is not configured yet.', 503);
        }
        if (mb_strlen($issuer) > 191) {
            throw new OidcException('The single sign-on provider issuer is too long for TrustFix.', 503);
        }

        $this->assertHttpsUrl($issuer, 'issuer');
        $this->assertHttpsUrl($redirectUri, 'redirect URI');

        $scopes = array_values(array_unique(array_filter(array_map(
            static fn ($scope): string => trim((string) $scope),
            (array) ($configuration['scopes'] ?? [])
        ))));
        if (! in_array('openid', $scopes, true)) {
            throw new OidcException('The single sign-on provider is missing the required openid scope.', 503);
        }

        $algorithms = array_values(array_unique(array_map(
            static fn ($algorithm): string => strtoupper(trim((string) $algorithm)),
            (array) ($configuration['allowed_signing_algorithms'] ?? ['RS256'])
        )));

        foreach ($algorithms as $algorithm) {
            if (! in_array($algorithm, ['RS256', 'RS384', 'RS512'], true)) {
                throw new OidcException('The single sign-on provider has an unsupported ID-token algorithm.', 503);
            }
        }

        $authMethod = (string) ($configuration['token_endpoint_auth_method'] ?? 'client_secret_basic');
        if (! in_array($authMethod, ['client_secret_basic', 'client_secret_post'], true)) {
            throw new OidcException('The single sign-on provider has an unsupported token authentication method.', 503);
        }

        $configuration['issuer'] = $issuer;
        $configuration['client_id'] = $clientId;
        $configuration['client_secret'] = $clientSecret;
        $configuration['redirect_uri'] = $redirectUri;
        $configuration['scopes'] = $scopes;
        $configuration['allowed_signing_algorithms'] = $algorithms;
        $configuration['token_endpoint_auth_method'] = $authMethod;
        $configuration['require_verified_email'] = (bool) ($configuration['require_verified_email'] ?? true);

        return $configuration;
    }

    /** @return array<string,mixed> */
    private function discovery(array $configuration): array
    {
        $issuer = $configuration['issuer'];

        return Cache::remember(
            'oidc.discovery.'.hash('sha256', $issuer),
            now()->addMinutes(15),
            function () use ($issuer) {
                $response = $this->http()->get(
                    $issuer.'/.well-known/openid-configuration'
                );

                if (! $response->successful() || ! is_array($response->json())) {
                    throw new OidcException('The identity provider discovery document could not be loaded.', 503);
                }

                $discovery = $response->json();
                if (! isset($discovery['issuer']) || ! hash_equals($issuer, rtrim((string) $discovery['issuer'], '/'))) {
                    throw new OidcException('The identity provider discovery document has an unexpected issuer.', 503);
                }

                foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
                    if (empty($discovery[$field]) || ! is_string($discovery[$field])) {
                        throw new OidcException('The identity provider discovery document is incomplete.', 503);
                    }

                    $this->assertHttpsUrl($discovery[$field], $field);
                }

                if (! empty($discovery['userinfo_endpoint'])) {
                    $this->assertHttpsUrl((string) $discovery['userinfo_endpoint'], 'userinfo_endpoint');
                }

                return $discovery;
            }
        );
    }

    /** @return array<string,mixed> */
    private function exchangeAuthorizationCode(
        array $configuration,
        array $discovery,
        string $code,
        string $codeVerifier
    ): array {
        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $configuration['redirect_uri'],
            'code_verifier' => $codeVerifier,
        ];

        $request = $this->http()->asForm();
        if ($configuration['token_endpoint_auth_method'] === 'client_secret_basic') {
            $request = $request->withBasicAuth(
                $configuration['client_id'],
                $configuration['client_secret']
            );
        } else {
            $payload['client_id'] = $configuration['client_id'];
            $payload['client_secret'] = $configuration['client_secret'];
        }

        $response = $request->post($discovery['token_endpoint'], $payload);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new OidcException('The identity provider could not complete sign-in.', 401);
        }

        $tokens = $response->json();
        if (empty($tokens['id_token']) || ! is_string($tokens['id_token'])) {
            throw new OidcException('The identity provider did not return an ID token.', 401);
        }

        return $tokens;
    }

    /** @return array<string,mixed> */
    private function verifyIdToken(
        string $idToken,
        array $configuration,
        array $discovery,
        string $expectedNonce
    ): array {
        [$header, $claims, $signature, $signedContent] = $this->parseJwt($idToken);
        $algorithm = strtoupper((string) ($header['alg'] ?? ''));

        if (! in_array($algorithm, $configuration['allowed_signing_algorithms'], true)) {
            throw new OidcException('The identity provider used an unapproved ID-token algorithm.', 401);
        }

        $kid = (string) ($header['kid'] ?? '');
        if ($kid === '') {
            throw new OidcException('The identity provider ID token is missing a signing key identifier.', 401);
        }

        $jwk = $this->signingKey($discovery['jwks_uri'], $kid, $algorithm);
        $verification = openssl_verify(
            $signedContent,
            $signature,
            $this->rsaJwkToPem($jwk),
            match ($algorithm) {
                'RS256' => OPENSSL_ALGO_SHA256,
                'RS384' => OPENSSL_ALGO_SHA384,
                'RS512' => OPENSSL_ALGO_SHA512,
            }
        );

        if ($verification !== 1) {
            throw new OidcException('The identity provider ID token signature could not be verified.', 401);
        }

        if (! isset($claims['iss']) || ! hash_equals($configuration['issuer'], rtrim((string) $claims['iss'], '/'))) {
            throw new OidcException('The identity provider ID token has an unexpected issuer.', 401);
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        if (! in_array($configuration['client_id'], $audiences, true)) {
            throw new OidcException('The identity provider ID token was not issued for TrustFix.', 401);
        }
        if (count($audiences) > 1 && ! hash_equals($configuration['client_id'], (string) ($claims['azp'] ?? ''))) {
            throw new OidcException('The identity provider ID token is missing an authorized party.', 401);
        }

        $now = now()->timestamp;
        $leeway = max(0, (int) config('identity.oidc.clock_skew_seconds', 60));
        if (! isset($claims['exp']) || ! is_numeric($claims['exp']) || (int) $claims['exp'] < ($now - $leeway)) {
            throw new OidcException('The identity provider ID token has expired.', 401);
        }
        if (isset($claims['iat']) && (! is_numeric($claims['iat']) || (int) $claims['iat'] > ($now + $leeway))) {
            throw new OidcException('The identity provider ID token has an invalid issue time.', 401);
        }
        if (isset($claims['nbf']) && (! is_numeric($claims['nbf']) || (int) $claims['nbf'] > ($now + $leeway))) {
            throw new OidcException('The identity provider ID token is not active yet.', 401);
        }
        if (! isset($claims['nonce']) || ! hash_equals($expectedNonce, (string) $claims['nonce'])) {
            throw new OidcException('The identity provider ID token nonce could not be verified.', 401);
        }

        return $claims;
    }

    /** @return array<string,mixed> */
    private function signingKey(string $jwksUri, string $kid, string $algorithm): array
    {
        $jwks = Cache::remember(
            'oidc.jwks.'.hash('sha256', $jwksUri),
            now()->addHour(),
            function () use ($jwksUri) {
                $response = $this->http()->get($jwksUri);
                if (! $response->successful() || ! is_array($response->json('keys'))) {
                    throw new OidcException('The identity provider signing keys could not be loaded.', 503);
                }

                return $response->json();
            }
        );

        foreach ((array) ($jwks['keys'] ?? []) as $key) {
            if (
                is_array($key)
                && hash_equals($kid, (string) ($key['kid'] ?? ''))
                && ($key['kty'] ?? null) === 'RSA'
                && (! isset($key['use']) || $key['use'] === 'sig')
                && (! isset($key['alg']) || strtoupper((string) $key['alg']) === $algorithm)
            ) {
                return $key;
            }
        }

        throw new OidcException('The identity provider signing key was not found.', 401);
    }

    /** @return array<string,mixed> */
    private function userInfo(string $endpoint, string $accessToken): array
    {
        $response = $this->http()->withToken($accessToken)->get($endpoint);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new OidcException('The identity provider user information could not be verified.', 401);
        }

        return $response->json();
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:string,3:string} */
    private function parseJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new OidcException('The identity provider returned a malformed ID token.', 401);
        }

        $header = $this->jsonDecode($this->base64UrlDecode($parts[0]));
        $claims = $this->jsonDecode($this->base64UrlDecode($parts[1]));
        $signature = $this->base64UrlDecode($parts[2]);

        return [$header, $claims, $signature, $parts[0].'.'.$parts[1]];
    }

    /** @return array<string,mixed> */
    private function jsonDecode(string $json): array
    {
        $value = json_decode($json, true);
        if (! is_array($value) || array_is_list($value)) {
            throw new OidcException('The identity provider returned a malformed ID token.', 401);
        }

        return $value;
    }

    /** @param array<string,mixed> $jwk */
    private function rsaJwkToPem(array $jwk): string
    {
        if (empty($jwk['n']) || empty($jwk['e'])) {
            throw new OidcException('The identity provider signing key is malformed.', 401);
        }

        $modulus = $this->base64UrlDecode((string) $jwk['n']);
        $exponent = $this->base64UrlDecode((string) $jwk['e']);
        $rsaKey = $this->asn1Sequence(
            $this->asn1Integer($modulus).$this->asn1Integer($exponent)
        );
        $algorithmIdentifier = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $subjectPublicKeyInfo = $this->asn1Sequence(
            $algorithmIdentifier."\x03".$this->asn1Length(strlen($rsaKey) + 1)."\x00".$rsaKey
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private function asn1Sequence(string $value): string
    {
        return "\x30".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        $value = $value === '' ? "\x00" : $value;
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xFF).$encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)).$encoded;
    }

    private function assertRequiredClaim(array $claims, array $configuration): void
    {
        $path = trim((string) ($configuration['required_claim'] ?? ''));
        if ($path === '') {
            return;
        }

        $actual = $this->claim($claims, $path);
        $expected = trim((string) ($configuration['required_claim_value'] ?? ''));

        if ($expected === '') {
            if (! $this->truthy($actual)) {
                throw new OidcException('This account is not eligible for TrustFix single sign-on.', 403);
            }

            return;
        }

        $values = is_array($actual) ? $actual : [$actual];
        foreach ($values as $value) {
            if (is_scalar($value) && hash_equals($expected, (string) $value)) {
                return;
            }
        }

        throw new OidcException('This account is not eligible for TrustFix single sign-on.', 403);
    }

    private function validPendingState(array $pending): bool
    {
        if (
            empty($pending['state'])
            || empty($pending['nonce'])
            || empty($pending['code_verifier'])
            || ! isset($pending['started_at'])
        ) {
            return false;
        }

        return ((int) $pending['started_at']) >= (
            now()->timestamp - max(60, (int) config('identity.oidc.state_ttl_seconds', 600))
        );
    }

    private function claim(array $claims, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $value = $claims;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function safeClaims(array $claims, array $configuration): array
    {
        $safe = [];
        foreach (['email', 'email_verified', 'name', 'given_name', 'family_name'] as $claim) {
            if (array_key_exists($claim, $claims)) {
                $safe[$claim] = $claims[$claim];
            }
        }

        foreach (['required_claim', 'member_id_claim'] as $configKey) {
            $path = trim((string) ($configuration[$configKey] ?? ''));
            if ($path !== '') {
                $safe[$path] = $this->claim($claims, $path);
            }
        }

        return $safe;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'true'
            || $value === 'yes';
    }

    private function randomToken(int $bytes): string
    {
        return $this->base64UrlEncode(random_bytes($bytes));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9\-_]/', $value)) {
            throw new OidcException('The identity provider returned malformed encoded data.', 401);
        }

        $decoded = base64_decode(
            strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4),
            true
        );
        if ($decoded === false) {
            throw new OidcException('The identity provider returned malformed encoded data.', 401);
        }

        return $decoded;
    }

    private function assertHttpsUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new OidcException('The OIDC '.$label.' must be an HTTPS URL.', 503);
        }
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(10)
            ->connectTimeout(5)
            ->withoutRedirecting();
    }

    private function sessionKey(string $provider): string
    {
        return 'oidc.pending.'.$provider;
    }
}
