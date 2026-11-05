<?php

namespace PharmacyTests\Api;

/**
 * A relationship route writes the foreign key without saving the record, so it passes no hook.
 * These links can only change by saving the record, which is where the rules live.
 */
class RelationshipTest extends ApiTestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function links(): iterable
    {
        yield 'a candidate\'s shifts' => ['RxCandidate', 'shifts', 'shift', 'candidateId'];
        yield 'a candidate\'s placements' => ['RxCandidate', 'placements', 'placement', 'candidateId'];
        yield 'a candidate\'s credentials' => ['RxCandidate', 'credentials', 'credential', 'candidateId'];
        yield 'a candidate\'s submissions' => ['RxCandidate', 'submissions', 'submission', 'candidateId'];
        yield 'a client\'s shifts' => ['Account', 'rxShifts', 'shift', 'accountId'];
        yield 'a client\'s placements' => ['Account', 'rxPlacements', 'placement', 'accountId'];
        yield 'a job\'s shifts' => ['RxJobOrder', 'shifts', 'shift', 'jobOrderId'];
        yield 'a job\'s placements' => ['RxJobOrder', 'placements', 'placement', 'jobOrderId'];
        yield 'a job\'s submissions' => ['RxJobOrder', 'submissions', 'submission', 'jobOrderId'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('links')]
    public function testALinkCannotChangeThroughARelationship(
        string $parentType,
        string $link,
        string $record,
        string $attribute,
    ): void {
        $work = $this->workForOneCandidate();
        $held = $work[$record];
        $entityType = $work['types'][$record];

        $other = match ($parentType) {
            'RxCandidate' => $this->candidate(),
            'Account' => $this->client(),
            'RxJobOrder' => $this->job($work['client']['id']),
        };

        $this->assertSame(403, $this->api('POST', "$parentType/{$other['id']}/$link", ['id' => $held['id']])['status']);
        $this->assertSame($held[$attribute], $this->read($entityType, $held['id'])[$attribute]);

        $this->assertSame(403, $this->api('DELETE', "$parentType/{$held[$attribute]}/$link", ['id' => $held['id']])['status']);
        $this->assertSame($held[$attribute], $this->read($entityType, $held['id'])[$attribute]);
    }

    public function testCandidatesCannotBeMergedPastTheirChecks(): void
    {
        $kept = $this->candidate();
        $merged = $this->clearedCandidate();

        $response = $this->api('POST', 'Action', [
            'entityType' => 'RxCandidate',
            'action' => 'merge',
            'id' => $kept['id'],
            'data' => ['sourceIdList' => [$merged['id']], 'attributes' => ['lastName' => $kept['lastName']]],
        ]);

        $this->assertSame(403, $response['status'], json_encode($response));
        $this->assertSame(200, $this->api('GET', "RxCandidate/{$merged['id']}")['status']);
        $this->assertSame(0, $this->api('GET', "RxCandidate/{$kept['id']}/credentials")['body']['total']);
    }

    public function testACreatedRecordStillJoinsItsParent(): void
    {
        $candidate = $this->candidate();
        $shift = $this->create('RxShift', $this->shiftData($this->client()['id'], $candidate['id']));

        $listed = $this->api('GET', "RxCandidate/{$candidate['id']}/shifts")['body']['list'];

        $this->assertSame([$shift['id']], array_column($listed, 'id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function workForOneCandidate(): array
    {
        $client = $this->client();
        $job = $this->job($client['id']);
        $candidate = $this->clearedCandidate();

        return [
            'client' => $client,
            'types' => [
                'shift' => 'RxShift',
                'placement' => 'RxPlacement',
                'credential' => 'RxCredential',
                'submission' => 'RxSubmission',
            ],
            'shift' => $this->create('RxShift', [
                ...$this->shiftData($client['id'], $candidate['id'], 7, ['jobOrderId' => $job['id']]),
                'status' => 'Confirmed',
            ]),
            'placement' => $this->create('RxPlacement', [
                'candidateId' => $candidate['id'],
                'jobOrderId' => $job['id'],
                'accountId' => $client['id'],
                'startDate' => $this->days(30),
                'status' => 'Active',
            ]),
            'credential' => $this->api('GET', "RxCandidate/{$candidate['id']}/credentials")['body']['list'][0],
            'submission' => $this->create('RxSubmission', [
                'candidateId' => $candidate['id'],
                'jobOrderId' => $job['id'],
            ]),
        ];
    }
}
