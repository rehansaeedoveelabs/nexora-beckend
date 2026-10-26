<?php

namespace Tests\Unit;

use App\Exceptions\InvalidSessionToken;
use App\Services\Shopify\SessionToken;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SessionTokenTest extends TestCase
{
    private const KEY = 'test-api-key';
    private const SECRET = 'test-api-secret-0123456789abcdef0123';

    private function verifier(): SessionToken
    {
        return new SessionToken(self::KEY, self::SECRET, 0);
    }

    private function token(array $overrides = [], string $secret = self::SECRET, string $alg = 'HS256'): string
    {
        $now = time();

        return JWT::encode(array_merge([
            'iss' => 'https://demo.myshopify.com/admin',
            'dest' => 'https://demo.myshopify.com',
            'aud' => self::KEY,
            'sub' => '42',
            'exp' => $now + 60,
            'nbf' => $now - 5,
            'iat' => $now - 5,
        ], $overrides), $secret, $alg);
    }

    public function test_valid_token_resolves_shop_and_user(): void
    {
        $result = $this->verifier()->verify($this->token());

        $this->assertSame('demo.myshopify.com', $result['shop']);
        $this->assertSame('42', $result['user_id']);
    }

    public static function invalidTokens(): array
    {
        $now = time();

        return [
            'expired' => [['exp' => $now - 30]],
            'not yet valid' => [['nbf' => $now + 120]],
            'wrong audience' => [['aud' => 'another-app']],
            'dest not a shopify shop' => [['dest' => 'https://evil.example.com', 'iss' => 'https://evil.example.com/admin']],
            'issuer points at another shop' => [['iss' => 'https://other.myshopify.com/admin']],
            'missing dest' => [['dest' => null]],
        ];
    }

    #[DataProvider('invalidTokens')]
    public function test_rejects_invalid_claims(array $overrides): void
    {
        $this->expectException(InvalidSessionToken::class);

        $this->verifier()->verify($this->token($overrides));
    }

    public function test_rejects_token_signed_with_another_secret(): void
    {
        $this->expectException(InvalidSessionToken::class);

        $this->verifier()->verify($this->token(secret: 'attacker-secret-0123456789abcdef01234'));
    }

    public function test_rejects_garbage(): void
    {
        $this->expectException(InvalidSessionToken::class);

        $this->verifier()->verify('not.a.jwt');
    }

    public function test_rejects_unsigned_alg_none_token(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'iss' => 'https://demo.myshopify.com/admin', 'dest' => 'https://demo.myshopify.com',
            'aud' => self::KEY, 'exp' => time() + 60,
        ])), '+/', '-_'), '=');

        $this->expectException(InvalidSessionToken::class);

        $this->verifier()->verify("{$header}.{$payload}.");
    }

    public function test_refuses_to_verify_when_credentials_missing(): void
    {
        $this->expectException(InvalidSessionToken::class);

        (new SessionToken('', ''))->verify($this->token());
    }
}
