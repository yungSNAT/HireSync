<?php

namespace PharmacyTests\Api;

use PHPUnit\Framework\TestCase;

/**
 * Drives the installed CRM through its REST API, so every rule is proved the way a browser or an
 * import would meet it. Each test makes its own candidate, so tests share only the empty database.
 */
abstract class ApiTestCase extends TestCase
{
    protected const STAFF_PASSWORD = 'Test-staff-1';

    private const BASE = 'http://localhost/api/v1/';

    /** What each type needs before it can be verified. */
    private const VERIFICATION_DETAILS = [
        'Right to work' => ['workRightsBasis' => 'Australian citizen'],
        'Working with Children Check' => [
            'registerStatus' => 'Cleared',
            'checkType' => 'Paid',
            'dateOfBirth' => '1990-05-12',
        ],
        'Professional indemnity insurance' => ['coverAmount' => 20000000, 'coverAmountCurrency' => 'AUD'],
    ];

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: mixed, raw: string, reason: ?string}
     */
    protected function api(string $method, string $path, ?array $body = null, ?array $login = null): array
    {
        [$user, $password] = $login ?? ['admin', getenv('ADMIN_PASSWORD')];

        $curl = curl_init(self::BASE . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Espo-Authorization: ' . base64_encode("$user:$password"),
            ],
            CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
        ]);

        $response = curl_exec($curl);
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);

        preg_match('/^X-Status-Reason: (.*)$/mi', $headers, $reason);

        return [
            'status' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'body' => json_decode(substr($response, $headerSize), true),
            'raw' => substr($response, $headerSize),
            'reason' => isset($reason[1]) ? trim($reason[1]) : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function create(string $entityType, array $data): array
    {
        $response = $this->api('POST', $entityType, $data);

        $this->assertSame(200, $response['status'], "Creating $entityType: " . json_encode($response));

        return $response['body'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function update(string $entityType, string $id, array $data): array
    {
        $response = $this->api('PUT', "$entityType/$id", $data);

        $this->assertSame(200, $response['status'], "Updating $entityType: " . json_encode($response));

        return $response['body'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function read(string $entityType, string $id): array
    {
        $response = $this->api('GET', "$entityType/$id");

        $this->assertSame(200, $response['status'], "Reading $entityType: " . json_encode($response));

        return $response['body'];
    }

    protected function assertRefused(array $response, string $label): void
    {
        $this->assertSame(403, $response['status'], json_encode($response));
        $this->assertStringContainsString($label, (string) json_encode($response));
    }

    protected function days(int $offset): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Australia/Sydney')))
            ->modify("$offset days")
            ->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    protected function candidate(string $profession = 'Pharmacist'): array
    {
        return $this->create('RxCandidate', [
            'lastName' => 'Test ' . bin2hex(random_bytes(4)),
            'profession' => $profession,
            'assignedUserId' => $this->adminId(),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function credential(string $candidateId, string $type, ?string $expiry, array $extra = []): array
    {
        return $this->create('RxCredential', [
            'candidateId' => $candidateId,
            'type' => $type,
            'expiryDate' => $expiry,
            'status' => 'Verified',
            'verificationMethod' => 'Original sighted',
            ...self::VERIFICATION_DETAILS[$type] ?? [],
            ...$extra,
        ]);
    }

    /**
     * A candidate holding everything a role needs, valid for a year unless an expiry is given.
     *
     * @return array<string, mixed>
     */
    protected function clearedCandidate(string $profession = 'Pharmacist', ?string $expiry = null): array
    {
        $candidate = $this->candidate($profession);
        $expiry ??= $this->days(365);

        $registration = [
            'Pharmacist' => 'Ahpra general registration',
            'Pharmacist (limited registration)' => 'Ahpra limited registration',
            'Intern pharmacist' => 'Ahpra provisional registration',
        ][$profession] ?? null;

        if ($registration) {
            $this->credential($candidate['id'], $registration, $expiry);
            $this->credential($candidate['id'], 'Professional indemnity insurance', $expiry);
        }

        $this->credential($candidate['id'], 'Right to work', null);

        return $this->read('RxCandidate', $candidate['id']);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function client(array $extra = []): array
    {
        return $this->create('Account', ['name' => 'Varrock Apothecary ' . bin2hex(random_bytes(4)), ...$extra]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function job(string $accountId, array $extra = []): array
    {
        return $this->create('RxJobOrder', [
            'name' => 'Job ' . bin2hex(random_bytes(4)),
            'accountId' => $accountId,
            'jobType' => 'Locum',
            'profession' => 'Pharmacist',
            'assignedUserId' => $this->adminId(),
            ...$extra,
        ]);
    }

    /**
     * @return array<string, mixed> The payload for a shift, 09:00 to 17:00 Sydney on the given day.
     */
    protected function shiftData(string $accountId, ?string $candidateId, int $dayOffset = 7, array $extra = []): array
    {
        return [
            'accountId' => $accountId,
            'candidateId' => $candidateId,
            'profession' => 'Pharmacist',
            'dateStart' => $this->sydneyToUtc($this->days($dayOffset) . ' 09:00:00'),
            'dateEnd' => $this->sydneyToUtc($this->days($dayOffset) . ' 17:00:00'),
            'assignedUserId' => $this->adminId(),
            ...$extra,
        ];
    }

    protected function sydneyToUtc(string $localDateTime): string
    {
        return (new \DateTimeImmutable($localDateTime, new \DateTimeZone('Australia/Sydney')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    protected function adminId(): string
    {
        static $id = null;

        return $id ??= $this->api('GET', 'App/user')['body']['user']['id'];
    }

    /**
     * @return array{0: string, 1: string} A login for a new user holding one seeded role.
     */
    protected function staffLogin(string $roleName, array $teamIds = []): array
    {
        $roles = $this->api('GET', 'Role?where[0][type]=equals&where[0][attribute]=name&where[0][value]=' . rawurlencode($roleName));

        $this->assertSame(1, $roles['body']['total'] ?? null, "Seeded role $roleName is missing");

        return $this->userWith([$roles['body']['list'][0]['id']], $teamIds);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function userWith(array $roleIds, array $teamIds = []): array
    {
        $name = 'user' . bin2hex(random_bytes(4));

        $this->create('User', [
            'userName' => $name,
            'lastName' => $name,
            'type' => 'regular',
            'password' => self::STAFF_PASSWORD,
            'passwordConfirm' => self::STAFF_PASSWORD,
            'rolesIds' => $roleIds,
            'teamsIds' => $teamIds,
            'defaultTeamId' => $teamIds[0] ?? null,
        ]);

        return [$name, self::STAFF_PASSWORD];
    }

    /**
     * @param array{0: string, 1: string} $login
     */
    protected function userId(array $login): string
    {
        return $this->api('GET', 'App/user', null, $login)['body']['user']['id'];
    }

    /**
     * For what only the passing of time can do, such as a date going stale overnight.
     *
     * @param list<mixed> $params
     */
    protected function sql(string $statement, array $params): void
    {
        $db = (require '/var/www/html/data/config-internal.php')['database'];

        (new \PDO("mysql:host={$db['host']};dbname={$db['dbname']}", $db['user'], $db['password']))
            ->prepare($statement)
            ->execute($params);
    }

    protected function runDailyCheck(): void
    {
        exec('cd /var/www/html && php command.php run-job RxCheckCompliance 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
    }
}
