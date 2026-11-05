<?php

namespace PharmacyTests\Api;

class WorkflowTest extends ApiTestCase
{
    public function testConsentIsNeededBeforeDisclosure(): void
    {
        $job = $this->job($this->client()['id']);
        $candidate = $this->candidate();

        $submission = $this->create('RxSubmission', ['candidateId' => $candidate['id'], 'jobOrderId' => $job['id']]);

        $this->assertRefused(
            $this->api('PUT', "RxSubmission/{$submission['id']}", ['stage' => 'Submitted']),
            'rxConsentNeeded',
        );

        $updated = $this->update('RxSubmission', $submission['id'], ['stage' => 'Submitted', 'consentDate' => $this->days(0)]);

        $this->assertSame('Submitted', $updated['stage']);
    }

    public function testAPreDisclosureStageNeedsNoConsent(): void
    {
        $submission = $this->create('RxSubmission', [
            'candidateId' => $this->candidate()['id'],
            'jobOrderId' => $this->job($this->client()['id'])['id'],
        ]);

        $this->assertSame('Withdrawn', $this->update('RxSubmission', $submission['id'], ['stage' => 'Withdrawn'])['stage']);
    }

    private function placementData(array $client, array $candidate, array $job, int $startDay): array
    {
        return [
            'candidateId' => $candidate['id'],
            'jobOrderId' => $job['id'],
            'accountId' => $client['id'],
            'startDate' => $this->days($startDay),
        ];
    }

    public function testAPlacementCannotStartUnlessClearAtItsStartDate(): void
    {
        $client = $this->client();
        $job = $this->job($client['id']);
        $candidate = $this->clearedCandidate(expiry: $this->days(10));

        $late = $this->create('RxPlacement', $this->placementData($client, $candidate, $job, 20));
        $this->assertRefused($this->api('PUT', "RxPlacement/{$late['id']}", ['status' => 'Active']), 'rxNotCleared');

        $early = $this->create('RxPlacement', $this->placementData($client, $candidate, $job, 5));
        $this->assertSame('Active', $this->update('RxPlacement', $early['id'], ['status' => 'Active'])['status']);
    }

    public function testAPlacementNeedsAJob(): void
    {
        $client = $this->client();
        $job = $this->job($client['id']);
        $placement = $this->create(
            'RxPlacement',
            $this->placementData($client, $this->clearedCandidate(), $job, 5),
        );

        $this->assertSame(200, $this->api('DELETE', "RxJobOrder/{$job['id']}")['status']);

        $this->assertRefused(
            $this->api('PUT', "RxPlacement/{$placement['id']}", ['status' => 'Active']),
            'rxPlacementIncomplete',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requirementSources(): iterable
    {
        yield 'job' => ['job'];
        yield 'site' => ['site'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requirementSources')]
    public function testAPlacementHonoursJobAndSiteRequirements(string $source): void
    {
        $needed = ['Immuniser certificate'];
        $client = $this->client($source === 'site' ? ['rxRequiredCredentials' => $needed] : []);
        $job = $this->job($client['id'], $source === 'job' ? ['requiredCredentials' => $needed] : []);
        $candidate = $this->clearedCandidate();

        $this->assertRefused(
            $this->api('POST', 'RxPlacement', [
                ...$this->placementData($client, $candidate, $job, 5),
                'status' => 'Active',
            ]),
            'rxNotCleared',
        );
    }

    public function testAPendingPlacementCannotJumpToCompleted(): void
    {
        $client = $this->client();
        $placement = $this->create(
            'RxPlacement',
            $this->placementData($client, $this->candidate(), $this->job($client['id']), 5),
        );

        $this->assertRefused(
            $this->api('PUT', "RxPlacement/{$placement['id']}", ['status' => 'Completed']),
            'rxNotCleared',
        );
    }

    public function testTotalsAndMargin(): void
    {
        $shift = $this->create('RxShift', $this->shiftData($this->client()['id'], null, 7, [
            'breakMinutes' => 30,
            'payRate' => 70,
            'chargeRate' => 95.5,
        ]));

        $this->assertEqualsWithDelta(7.5, $shift['paidHours'], 0.001);
        $this->assertEqualsWithDelta(525.0, $shift['payTotal'], 0.001);
        $this->assertEqualsWithDelta(716.25, $shift['chargeTotal'], 0.001);
        $this->assertEqualsWithDelta(191.25, $shift['margin'], 0.001);
    }

    public function testAMissingRateLeavesTheTotalBlank(): void
    {
        $shift = $this->create('RxShift', $this->shiftData($this->client()['id'], null, 7, ['payRate' => 70]));

        $this->assertNull($shift['chargeTotal']);
        $this->assertNull($shift['margin']);
    }

    public function testAShiftMustEndAfterItStarts(): void
    {
        $data = $this->shiftData($this->client()['id'], null);
        [$data['dateStart'], $data['dateEnd']] = [$data['dateEnd'], $data['dateStart']];

        $response = $this->api('POST', 'RxShift', $data);

        $this->assertSame(400, $response['status'], json_encode($response));
    }

    public function testATodayMorningShiftAppearsInTheDateFilters(): void
    {
        $client = $this->client();
        $shift = $this->create('RxShift', [
            ...$this->shiftData($client['id'], null, 0),
            'dateStart' => $this->sydneyToUtc($this->days(0) . ' 01:00:00'),
            'dateEnd' => $this->sydneyToUtc($this->days(0) . ' 02:00:00'),
        ]);

        $list = $this->api('GET', 'RxShift?primaryFilter=unfilled&maxSize=200');

        $this->assertContains($shift['id'], array_column($list['body']['list'], 'id'));
    }

    public function testACancelledShiftKeepsWorking(): void
    {
        $shift = $this->create('RxShift', $this->shiftData($this->client()['id'], null));

        $this->assertSame('Cancelled', $this->update('RxShift', $shift['id'], ['status' => 'Cancelled'])['status']);
    }

    public function testTheDailyCheckRemindsOncePerThreshold(): void
    {
        $candidate = $this->clearedCandidate(expiry: $this->days(20));
        $count = fn () => $this->api(
            'GET',
            'Task?where[0][type]=equals&where[0][attribute]=parentId&where[0][value]=' . $candidate['id'],
        )['body']['total'];

        $this->runDailyCheck();
        $first = $count();
        $this->runDailyCheck();

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, $count());
    }

    public function testAReminderNamesTheDateTheWayStaffReadIt(): void
    {
        $expiry = $this->days(20);
        $candidate = $this->clearedCandidate(expiry: $expiry);

        $this->runDailyCheck();

        $names = array_column($this->tasksFor($candidate['id']), 'name');
        $date = (new \DateTimeImmutable($expiry))->format('d/m/Y');

        $this->assertNotEmpty(preg_grep('#^Credential expires ' . $date . ': #', $names), json_encode($names));
    }

    public function testAReminderNotifiesItsAssignee(): void
    {
        $candidate = $this->clearedCandidate(expiry: $this->days(20));

        $this->runDailyCheck();

        // EspoCRM groups notifications about a record under that record.
        $notified = array_column($this->api('GET', 'Notification?maxSize=50')['body']['list'], 'relatedParentId');

        $this->assertContains($this->tasksFor($candidate['id'])[0]['id'], $notified);
    }

    private function tasksFor(string $candidateId): array
    {
        return $this->api(
            'GET',
            'Task?where[0][type]=equals&where[0][attribute]=parentId&where[0][value]=' . $candidateId,
        )['body']['list'];
    }

    public function testARenewalRestartsTheReminders(): void
    {
        $candidate = $this->clearedCandidate(expiry: $this->days(20));

        $this->runDailyCheck();

        $renewed = array_values(array_filter(
            $this->api('GET', "RxCandidate/{$candidate['id']}/credentials")['body']['list'],
            fn ($item) => $item['expiryDate'] !== null,
        ))[0];

        $this->assertNotNull($this->read('RxCredential', $renewed['id'])['reminderLevel']);

        $updated = $this->update('RxCredential', $renewed['id'], ['expiryDate' => $this->days(365)]);

        $this->assertNull($updated['reminderLevel']);
    }

    public function testTheDailyCheckRefreshesCandidates(): void
    {
        $candidate = $this->clearedCandidate('Pharmacy technician');
        $credential = $this->api('GET', "RxCandidate/{$candidate['id']}/credentials")['body']['list'][0];

        $this->assertSame('Clear', $this->read('RxCandidate', $candidate['id'])['complianceStatus']);

        // Its reminders are finished, so nothing but the refresh will touch this candidate.
        $this->sql(
            'UPDATE rx_credential SET expiry_date = ?, reminder_level = 7 WHERE id = ?',
            [$this->days(-1), $credential['id']],
        );
        $this->runDailyCheck();

        $this->assertSame('Blocked', $this->read('RxCandidate', $candidate['id'])['complianceStatus']);
    }

    public function testAnAtRiskShiftRaisesATask(): void
    {
        $candidate = $this->clearedCandidate();
        $shift = $this->create('RxShift', [
            ...$this->shiftData($this->client()['id'], $candidate['id'], 7),
            'status' => 'Confirmed',
        ]);
        $credential = $this->api('GET', "RxCandidate/{$candidate['id']}/credentials")['body']['list'][0];

        $this->update('RxCredential', $credential['id'], ['identifier' => 'A corrected number']);
        $this->runDailyCheck();

        $tasks = $this->api(
            'GET',
            'Task?where[0][type]=equals&where[0][attribute]=parentId&where[0][value]=' . $candidate['id'],
        )['body']['list'];

        $this->assertContains("Confirmed shift at risk: {$shift['name']}", array_column($tasks, 'name'));
    }

    public function testTheDailyCheckFlagsAndClearsAtRiskShifts(): void
    {
        $candidate = $this->clearedCandidate();
        $shift = $this->create('RxShift', [
            ...$this->shiftData($this->client()['id'], $candidate['id'], 7),
            'status' => 'Confirmed',
        ]);
        $list = $this->api('GET', "RxCandidate/{$candidate['id']}/credentials")['body']['list'];
        $insurance = array_values(array_filter(
            $list,
            fn ($item) => $item['type'] === 'Professional indemnity insurance',
        ))[0];

        $this->renew($insurance['id'], $this->days(3));
        $this->runDailyCheck();
        $this->assertTrue($this->read('RxShift', $shift['id'])['atRisk']);

        $this->renew($insurance['id'], $this->days(365));
        $this->runDailyCheck();
        $this->assertFalse($this->read('RxShift', $shift['id'])['atRisk']);
    }

    public function testAnUnverifiedChangePutsTheShiftAtRisk(): void
    {
        $candidate = $this->clearedCandidate();
        $shift = $this->create('RxShift', [
            ...$this->shiftData($this->client()['id'], $candidate['id'], 7),
            'status' => 'Confirmed',
        ]);
        $credential = $this->api('GET', "RxCandidate/{$candidate['id']}/credentials")['body']['list'][0];

        $this->update('RxCredential', $credential['id'], ['identifier' => 'A corrected number']);
        $this->runDailyCheck();

        $this->assertTrue($this->read('RxShift', $shift['id'])['atRisk']);
    }

    /**
     * Changing a date drops a credential to `Pending`, so renewing takes a second save to verify it.
     */
    private function renew(string $credentialId, string $expiry): void
    {
        $this->update('RxCredential', $credentialId, ['expiryDate' => $expiry]);
        $this->update('RxCredential', $credentialId, ['status' => 'Verified']);
    }

    public function testAFixedTermCanOutlastARegistrationYear(): void
    {
        $client = $this->client();
        $placement = $this->create('RxPlacement', [
            ...$this->placementData(
                $client,
                $this->clearedCandidate(expiry: $this->days(100)),
                $this->job($client['id']),
                5,
            ),
            'endDate' => $this->days(400),
        ]);

        $this->assertSame('Active', $this->update('RxPlacement', $placement['id'], ['status' => 'Active'])['status']);
    }

    public function testFinishingAPlacementCannotSwapItsCandidate(): void
    {
        $client = $this->client();
        $placement = $this->create('RxPlacement', [
            ...$this->placementData($client, $this->clearedCandidate(), $this->job($client['id']), 5),
            'status' => 'Active',
        ]);

        $this->assertRefused(
            $this->api('PUT', "RxPlacement/{$placement['id']}", [
                'status' => 'Completed',
                'candidateId' => $this->candidate()['id'],
            ]),
            'rxNotCleared',
        );
    }

    public function testAWrongRoleOnAJobCannotLowerAPlacementsBar(): void
    {
        $client = $this->client();
        $job = $this->job($client['id'], ['profession' => 'Pharmacy assistant']);
        $candidate = $this->candidate('Pharmacist');
        $this->credential($candidate['id'], 'Right to work', null);

        $placement = $this->create('RxPlacement', $this->placementData($client, $candidate, $job, 5));

        $this->assertRefused(
            $this->api('PUT', "RxPlacement/{$placement['id']}", ['status' => 'Active']),
            'rxNotCleared',
        );
    }

    public function testAPlacementMustBeForItsJobsClient(): void
    {
        $job = $this->job($this->client()['id']);
        $placement = $this->create(
            'RxPlacement',
            $this->placementData($this->client(), $this->clearedCandidate(), $job, 5),
        );

        $this->assertRefused(
            $this->api('PUT', "RxPlacement/{$placement['id']}", ['status' => 'Active']),
            'rxClientNotJobClient',
        );
    }

    public function testAReminderGoesToWhoeverVerifiedTheCredential(): void
    {
        $team = $this->create('Team', ['name' => 'Team ' . bin2hex(random_bytes(4))]);
        $recruiter = $this->staffLogin('Recruiter', [$team['id']]);
        $officer = $this->staffLogin('Compliance officer', [$team['id']]);

        $candidate = $this->candidate();
        $this->update('RxCandidate', $candidate['id'], [
            'assignedUserId' => $this->userId($recruiter),
            'teamsIds' => [$team['id']],
        ]);

        $verified = $this->api('POST', 'RxCredential', [
            'candidateId' => $candidate['id'],
            'type' => 'NSW Health vaccination evidence',
            'status' => 'Verified',
            'verificationMethod' => 'Original sighted',
            'expiryDate' => $this->days(20),
        ], $officer);

        $this->assertSame(200, $verified['status'], json_encode($verified));

        $this->runDailyCheck();

        $this->assertSame(
            [$this->userId($officer)],
            array_column($this->tasksFor($candidate['id']), 'assignedUserId'),
        );

        $seen = $this->api(
            'GET',
            'Task?where[0][type]=equals&where[0][attribute]=parentId&where[0][value]=' . $candidate['id'],
            null,
            $recruiter,
        );

        $this->assertSame(0, $seen['body']['total'], json_encode($seen));

        $stream = $this->api('GET', "RxCandidate/{$candidate['id']}/stream", null, $recruiter);

        $this->assertSame(200, $stream['status'], json_encode($stream));
        $this->assertStringNotContainsString('vaccination', json_encode($stream['body']));
    }

    /**
     * @param string[] $in
     * @param string[] $out
     */
    private function assertFilter(string $entityType, string $filter, array $in, array $out): void
    {
        $list = $this->api('GET', "$entityType?primaryFilter=$filter&maxSize=200");
        $ids = array_column($list['body']['list'], 'id');

        $this->assertSame($in, array_values(array_intersect($in, $ids)), $filter);
        $this->assertSame([], array_values(array_intersect($out, $ids)), $filter);
    }

    public function testTheExpiryFiltersFindWhatTheyName(): void
    {
        $insured = fn (int $days) => $this->credential(
            $this->candidate()['id'],
            'Professional indemnity insurance',
            $this->days($days),
        )['id'];

        $lapsed = $insured(-1);
        $today = $insured(0);
        $soon = $insured(10);
        $later = $insured(365);

        $this->assertFilter('RxCredential', 'expired', [$lapsed], [$today, $soon, $later]);
        $this->assertFilter('RxCredential', 'expiringSoon', [$today, $soon], [$lapsed, $later]);
    }

    public function testTheNextSevenDaysFilterStopsAtADay(): void
    {
        $client = $this->client();
        $shift = fn (int $day) => $this->create('RxShift', $this->shiftData($client['id'], null, $day))['id'];

        $inside = $shift(7);
        $outside = $shift(8);

        $this->assertFilter('RxShift', 'nextSevenDays', [$inside], [$outside]);
    }

    public function testTheRetentionFilterFindsWhatIsDue(): void
    {
        $due = $this->candidate()['id'];
        $later = $this->candidate()['id'];

        $this->update('RxCandidate', $due, ['retentionReviewDate' => $this->days(0)]);
        $this->update('RxCandidate', $later, ['retentionReviewDate' => $this->days(1)]);

        $this->assertFilter('RxCandidate', 'retentionDue', [$due], [$later]);
    }

    public function testAReminderSkipsAVerifierWhoCannotOpenThatCredential(): void
    {
        $role = $this->create('Role', [
            'name' => 'Role ' . bin2hex(random_bytes(4)),
            'data' => ['RxCredential' => ['create' => 'no', 'read' => 'own', 'edit' => 'own', 'delete' => 'no']],
        ]);
        $verifier = $this->userWith([$role['id']]);
        $candidate = $this->candidate();

        $credential = $this->create('RxCredential', [
            'candidateId' => $candidate['id'],
            'type' => 'NSW Health vaccination evidence',
            'expiryDate' => $this->days(20),
            'assignedUserId' => $this->userId($verifier),
        ]);

        $verified = $this->api('PUT', "RxCredential/{$credential['id']}", [
            'status' => 'Verified',
            'verificationMethod' => 'Original sighted',
        ], $verifier);

        $this->assertSame(200, $verified['status'], json_encode($verified));

        $this->update('RxCredential', $credential['id'], ['assignedUserId' => null]);

        $this->assertSame(403, $this->api('GET', "RxCredential/{$credential['id']}", null, $verifier)['status']);

        $this->runDailyCheck();

        $this->assertSame([], $this->tasksFor($candidate['id']));
    }

    public function testAReminderWaitsForSomeoneWhoCanOpenCredentials(): void
    {
        $recruiter = $this->staffLogin('Recruiter');
        $officer = $this->staffLogin('Compliance officer');
        $standIn = $this->userId($this->staffLogin('Compliance officer'));
        $candidate = $this->candidate();

        $verified = $this->api('POST', 'RxCredential', [
            'candidateId' => $candidate['id'],
            'type' => 'NSW Health vaccination evidence',
            'status' => 'Verified',
            'verificationMethod' => 'Original sighted',
            'expiryDate' => $this->days(20),
        ], $officer);

        $this->assertSame(200, $verified['status'], json_encode($verified));

        $id = $verified['body']['id'];

        $this->update('RxCredential', $id, ['assignedUserId' => $this->userId($recruiter)]);
        $this->update('User', $this->userId($officer), ['isActive' => false]);

        $this->runDailyCheck();

        $this->assertSame([], $this->tasksFor($candidate['id']));

        $this->update('RxCredential', $id, ['assignedUserId' => $standIn]);

        $this->runDailyCheck();

        $this->assertSame([$standIn], array_column($this->tasksFor($candidate['id']), 'assignedUserId'));
    }

    public function testWhatStaffCanStillEditIsLogged(): void
    {
        $team = $this->create('Team', ['name' => 'Team ' . bin2hex(random_bytes(4))]);
        $recruiter = $this->staffLogin('Recruiter', [$team['id']]);
        $recruiterId = $this->userId($recruiter);
        $shared = ['assignedUserId' => $recruiterId, 'teamsIds' => [$team['id']]];

        $client = $this->api('POST', 'Account', ['name' => 'Client ' . bin2hex(random_bytes(4)), ...$shared], $recruiter);

        $this->assertSame(200, $client['status'], json_encode($client));
        $this->assertSame($recruiterId, $this->read('Account', $client['body']['id'])['createdById']);

        $job = $this->job($client['body']['id'], $shared);
        $candidate = $this->candidate();
        $shift = $this->create('RxShift', $this->shiftData($client['body']['id'], $candidate['id'], 7, [
            ...$shared,
            'jobOrderId' => $job['id'],
        ]));

        $edits = [
            ["RxShift/{$shift['id']}", ['jobOrderId' => null], 'jobOrder'],
            ["RxShift/{$shift['id']}", ['profession' => 'Pharmacy assistant'], 'profession'],
            ["RxJobOrder/{$job['id']}", ['profession' => 'Pharmacy assistant'], 'profession'],
        ];

        foreach ($edits as [$path, $change, $field]) {
            $edited = $this->api('PUT', $path, $change, $recruiter);

            $this->assertSame(200, $edited['status'], json_encode($edited));
            $this->assertLogged($path, $field, $recruiterId);
        }

        $this->update('RxCandidate', $candidate['id'], ['profession' => 'Pharmacy assistant']);
        $this->assertLogged("RxCandidate/{$candidate['id']}", 'profession', $this->adminId());
    }

    private function assertLogged(string $path, string $field, string $userId): void
    {
        $notes = $this->api('GET', "$path/updateStream")['body']['list'] ?? [];

        $matching = array_filter(
            $notes,
            fn (array $note) => $note['createdById'] === $userId && in_array($field, $note['data']['fields'] ?? [], true),
        );

        $this->assertNotEmpty($matching, "$path $field: " . json_encode($notes));
    }

    public function testAPlacementsCandidateRoleNeedsRules(): void
    {
        $field = 'Admin/fieldManager/RxCandidate/profession';
        $options = $this->api('GET', $field)['body']['options'];

        $added = $this->api('PUT', $field, ['options' => [...$options, 'Pharmacy manager']]);
        $this->assertSame(200, $added['status'], json_encode($added));

        try {
            $client = $this->client();
            $candidate = $this->clearedCandidate();
            $this->update('RxCandidate', $candidate['id'], ['profession' => 'Pharmacy manager']);

            $placement = $this->create(
                'RxPlacement',
                $this->placementData($client, $candidate, $this->job($client['id']), 5),
            );

            $this->assertRefused(
                $this->api('PUT', "RxPlacement/{$placement['id']}", ['status' => 'Active']),
                'rxRoleHasNoRules',
            );
        } finally {
            $this->api('PUT', $field, ['options' => $options]);
        }
    }

    public function testAReminderSkipsAnAssigneeWhoCannotOpenCredentials(): void
    {
        $recruiter = $this->staffLogin('Recruiter');
        $officer = $this->staffLogin('Compliance officer');
        $candidate = $this->candidate();

        $verified = $this->api('POST', 'RxCredential', [
            'candidateId' => $candidate['id'],
            'type' => 'NSW Health vaccination evidence',
            'status' => 'Verified',
            'verificationMethod' => 'Original sighted',
            'expiryDate' => $this->days(20),
        ], $officer);

        $this->assertSame(200, $verified['status'], json_encode($verified));

        $this->update('RxCredential', $verified['body']['id'], ['assignedUserId' => $this->userId($recruiter)]);

        $this->runDailyCheck();

        $this->assertSame(
            [$this->userId($officer)],
            array_column($this->tasksFor($candidate['id']), 'assignedUserId'),
        );
    }
}
