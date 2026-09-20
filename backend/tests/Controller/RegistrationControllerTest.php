<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Tests\AuthenticationTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function register(KernelBrowser $client, string $email, array $overrides = []): array
    {
        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()],
            content: json_encode($this->registrationPayload($email, overrides: $overrides)),
        );

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    public function testRegisterWithValidDataReturns201(): void
    {
        $client = static::createClient();

        $data = $this->register($client, 'new.user@example.com', ['firstName' => 'New', 'lastName' => 'User']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('new.user@example.com', $data['email']);
        self::assertSame('New', $data['firstName']);
        self::assertTrue($data['active']);
        self::assertArrayNotHasKey('passwordHash', $data);
        self::assertArrayNotHasKey('password', $data);
        self::assertSame([], $data['joinedTeams'], 'A classic sign-up joins no team.');
    }

    public function testRegisterStoresPhoneAsE164(): void
    {
        $client = static::createClient();

        $data = $this->register($client, 'phone.user@example.com', ['phone' => '0470 12 34 56']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('+32470123456', $data['phone'], 'A national Belgian number is read with the default region.');

        $stored = static::getContainer()->get(Connection::class)->fetchOne("SELECT phone_e164 FROM users WHERE email = 'phone.user@example.com'");
        self::assertSame('+32470123456', $stored);
    }

    public function testNonBelgianPhoneNumbersAreAcceptedInInternationalFormat(): void
    {
        $client = static::createClient();

        $data = $this->register($client, 'fr.user@example.com', ['phone' => '+33 6 12 34 56 78']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('+33612345678', $data['phone']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPhones(): iterable
    {
        yield 'letters' => ['not a phone'];
        yield 'too short' => ['+32 12'];
        yield 'impossible number' => ['+32 999 99 99 99 99 99'];
        yield 'national without region match' => ['12345'];
        yield 'too long' => [str_repeat('1', 41)];
    }

    #[DataProvider('invalidPhones')]
    public function testInvalidPhoneIsRejectedByTheBackend(string $phone): void
    {
        $client = static::createClient();

        $data = $this->register($client, 'bad.phone@example.com', ['phone' => $phone]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('phone', $data['violations']);
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('bad.phone@example.com'));
    }

    public function testPhoneIsRequired(): void
    {
        $client = static::createClient();

        $data = $this->register($client, 'missing@example.com', ['phone' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['phone'], array_keys($data['violations']));
    }

    /**
     * D115: the institution is not part of a person's identity. A payload
     * carrying exactly the fields the contract lists must be enough.
     */
    public function testRegistrationNeedsNoHospitalAndTheDatabaseHasNoneToOffer(): void
    {
        $client = static::createClient();
        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'hospitals'"), 'There is no hospital referential.');

        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()],
            content: json_encode([
                'email' => 'contract@example.com',
                'plainPassword' => 'correct-horse-battery',
                'firstName' => 'Contract',
                'lastName' => 'Test',
                'phone' => '+32470123456',
            ]),
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayNotHasKey('primaryHospital', $data);
        self::assertArrayNotHasKey('primaryHospitalStableId', $data);
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'users' AND column_name LIKE '%hospital%'"), 'users carries no institution column.');
    }

    /**
     * D116: a client built against the retired contract must be told the
     * field is gone, not left believing the hospital was stored.
     */
    public function testARetiredHospitalFieldIsRejectedNotSilentlyIgnored(): void
    {
        $client = static::createClient();

        $data = $this->register($client, 'legacy.client@example.com', ['primaryHospitalStableId' => '0199a8b0-0000-7000-8000-000000000000']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $data['error']);
        self::assertSame(['primaryHospitalStableId' => 'This field is not accepted.'], $data['violations']);
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('legacy.client@example.com'), 'Nothing is created by a rejected request.');
    }

    public function testEveryUnknownFieldIsReportedAndPrivilegedFieldsCannotBeSmuggledIn(): void
    {
        $client = static::createClient();

        $data = $this->register($client, 'smuggler@example.com', ['active' => false, 'roles' => ['ROLE_ADMIN'], 'emailVerifiedAt' => '2020-01-01', 'foo' => 'bar']);

        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(['active', 'roles', 'emailVerifiedAt', 'foo'], array_keys($data['violations']));
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('smuggler@example.com'));
    }

    public function testAnUnknownFieldIsRejectedBeforeAnInvitationIsTouched(): void
    {
        $client = static::createClient();

        $data = $this->register($client, 'token.legacy@example.com', ['invitationToken' => str_repeat('d', 64), 'primaryHospitalStableId' => 'x']);

        self::assertResponseStatusCodeSame(422, 'Not a 404 about the token: the body itself is invalid.');
        self::assertArrayHasKey('primaryHospitalStableId', $data['violations']);
    }

    public function testRegisterWithAlreadyUsedEmailReturns409EvenWithADifferentCase(): void
    {
        $client = static::createClient();

        $this->register($client, 'duplicate@example.com', ['firstName' => 'First']);
        self::assertResponseStatusCodeSame(201);

        $data = $this->register($client, 'Duplicate@Example.com', ['firstName' => 'First']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('email_already_used', $data['error']);
    }

    public function testEmailIsStoredNormalizedAndLoginIsCaseInsensitive(): void
    {
        $client = static::createClient();

        $data = $this->register($client, '  Mixed.Case@Example.COM ');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('mixed.case@example.com', $data['email']);

        $this->loginUser($client, 'MIXED.case@example.com', 'correct-horse-battery');
    }

    public function testDatabaseRefusesTwoAccountsDifferingOnlyByEmailCase(): void
    {
        $client = static::createClient();
        $this->register($client, 'db.guard@example.com');
        $connection = static::getContainer()->get(Connection::class);

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->executeStatement(
            "INSERT INTO users (stable_id, email, first_name, last_name, password_hash, active, created_at, updated_at) VALUES (gen_random_uuid(), 'DB.Guard@example.com', 'X', 'Y', 'h', true, NOW(), NOW())",
        );
    }

    public function testDatabaseRefusesAMalformedPhone(): void
    {
        $client = static::createClient();
        $this->register($client, 'db.phone@example.com');

        $this->expectException(DriverException::class);
        static::getContainer()->get(Connection::class)->executeStatement("UPDATE users SET phone_e164 = '0470 12 34 56' WHERE email = 'db.phone@example.com'");
    }

    public function testRegisterWithWeakPasswordReturns422(): void
    {
        $client = static::createClient();

        $data = $this->register($client, 'weak.password@example.com', ['plainPassword' => '123']);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('plainPassword', $data['violations']);
    }

    public function testOverlongNamesAreRejectedInsteadOfFailingInTheDatabase(): void
    {
        $client = static::createClient();

        $data = $this->register($client, 'long.name@example.com', ['firstName' => str_repeat('a', 101)]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('firstName', $data['violations']);
    }

    public function testRegisterWithAnUnknownInvitationTokenIs404(): void
    {
        $client = static::createClient();

        $data = $this->register($client, 'token.user@example.com', ['invitationToken' => str_repeat('c', 64)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('invitation_not_found', $data['error']);
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('token.user@example.com'));
    }
}
