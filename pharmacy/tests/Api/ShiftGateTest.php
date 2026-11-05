<?php

namespace PharmacyTests\Api;

class ShiftGateTest extends ApiTestCase
{
    private function confirmedAttempt(array $client, array $candidate, int $day = 7, array $extra = []): array
    {
        return $this->api('POST', 'RxShift', [
            ...$this->shiftData($client['id'], $candidate['id'], $day, $extra),
            'status' => 'Confirmed',
        ]);
    }

    public function testAClearedCandidateCanBeConfirmed(): void
    {
        $response = $this->confirmedAttempt($this->client(), $this->clearedCandidate());

        $this->assertSame(200, $response['status'], json_encode($response));
    }

    public function testConfirmingWithoutACandidateIsRefused(): void
    {
        $response = $this->api('POST', 'RxShift', [
            ...$this->shiftData($this->client()['id'], null),
            'status' => 'Confirmed',
        ]);

        $this->assertRefused($response, 'rxShiftNeedsCandidate');
    }

    public function testAMissingCredentialIsRefused(): void
    {
        $candidate = $this->candidate();
        $this->credential($candidate['id'], 'Right to work', null);

        $this->assertRefused($this->confirmedAttempt($this->client(), $candidate), 'rxNotCleared');
    }

    public function testAPendingCredentialDoesNotCount(): void
    {
        $candidate = $this->clearedCandidate();
        $pending = $this->candidate();
        $this->credential($pending['id'], 'Right to work', null);
        $this->credential($pending['id'], 'Professional indemnity insurance', $this->days(365));
        $this->create('RxCredential', [
            'candidateId' => $pending['id'],
            'type' => 'Ahpra general registration',
            'expiryDate' => $this->days(365),
        ]);

        $client = $this->client();

        $this->assertSame(200, $this->confirmedAttempt($client, $candidate)['status']);
        $this->assertRefused($this->confirmedAttempt($client, $pending), 'rxNotCleared');
    }

    public function testACredentialExpiringDuringTheShiftIsRefused(): void
    {
        $candidate = $this->clearedCandidate(expiry: $this->days(6));

        $this->assertRefused($this->confirmedAttempt($this->client(), $candidate, 7), 'rxNotCleared');
    }

    public function testACredentialValidThroughTheShiftDayPasses(): void
    {
        $candidate = $this->clearedCandidate(expiry: $this->days(7));

        $this->assertSame(200, $this->confirmedAttempt($this->client(), $candidate, 7)['status']);
    }

    public function testAnOvernightShiftMustBeCoveredToItsEnd(): void
    {
        $candidate = $this->clearedCandidate(expiry: $this->days(6));

        $response = $this->confirmedAttempt($this->client(), $candidate, 6, [
            'dateStart' => $this->sydneyToUtc($this->days(6) . ' 22:00:00'),
            'dateEnd' => $this->sydneyToUtc($this->days(7) . ' 06:00:00'),
        ]);

        $this->assertRefused($response, 'rxNotCleared');
    }

    public function testAnInternCannotTakeAPharmacistShift(): void
    {
        $intern = $this->clearedCandidate('Intern pharmacist');

        $this->assertRefused($this->confirmedAttempt($this->client(), $intern), 'rxNotCleared');
    }

    public function testALimitedRegistrantCannotTakeAPlainPharmacistShift(): void
    {
        $limited = $this->clearedCandidate('Pharmacist (limited registration)');

        $this->assertRefused($this->confirmedAttempt($this->client(), $limited), 'rxNotCleared');
    }

    public function testAClientsRequiredCredentialIsEnforced(): void
    {
        $client = $this->client(['rxRequiredCredentials' => ['Working with Children Check']]);
        $candidate = $this->clearedCandidate();

        $this->assertRefused($this->confirmedAttempt($client, $candidate), 'rxNotCleared');

        $this->credential($candidate['id'], 'Working with Children Check', $this->days(365));

        $this->assertSame(200, $this->confirmedAttempt($client, $candidate)['status']);
    }

    public function testAJobsRequiredCredentialIsEnforced(): void
    {
        $client = $this->client();
        $job = $this->job($client['id'], ['requiredCredentials' => ['Immuniser certificate']]);
        $candidate = $this->clearedCandidate();

        $this->assertRefused(
            $this->confirmedAttempt($client, $candidate, 7, ['jobOrderId' => $job['id']]),
            'rxNotCleared',
        );
    }

    public function testANswHealthHospitalAddsItsOwnRequirements(): void
    {
        $client = $this->client(['rxClientType' => 'NSW Health hospital']);
        $candidate = $this->clearedCandidate();

        $this->assertRefused($this->confirmedAttempt($client, $candidate), 'rxNotCleared');

        $this->credential($candidate['id'], 'National police check', null, ['issueDate' => $this->days(-30)]);
        $this->credential($candidate['id'], 'Working with Children Check', $this->days(365));
        $this->credential($candidate['id'], 'NSW Health vaccination evidence', $this->days(365));

        $this->assertSame(200, $this->confirmedAttempt($client, $candidate)['status']);
    }

    public function testAManagerCanRecordAnExceptionForASite(): void
    {
        $client = $this->client(['rxClientType' => 'NSW Health hospital']);
        $candidate = $this->clearedCandidate();
        $this->credential($candidate['id'], 'National police check', null, ['issueDate' => $this->days(-30)]);
        $this->credential($candidate['id'], 'NSW Health vaccination evidence', $this->days(365));

        $this->assertRefused($this->confirmedAttempt($client, $candidate), 'rxNotCleared');

        $this->update('Account', $client['id'], [
            'rxRequiredCredentials' => ['National police check', 'NSW Health vaccination evidence'],
        ]);

        $this->assertSame(200, $this->confirmedAttempt($client, $candidate)['status']);
    }

    public function testOverlappingShiftsAreRefused(): void
    {
        $candidate = $this->clearedCandidate();
        $client = $this->client();

        $this->assertSame(200, $this->confirmedAttempt($client, $candidate)['status']);

        $this->assertRefused($this->confirmedAttempt($client, $candidate), 'rxDoubleBooked');
    }

    public function testShiftsThatOnlyTouchDoNotOverlap(): void
    {
        $candidate = $this->clearedCandidate();
        $client = $this->client();
        $this->assertSame(200, $this->confirmedAttempt($client, $candidate)['status']);

        foreach ([['17:00:00', '20:00:00'], ['05:00:00', '09:00:00']] as [$start, $end]) {
            $response = $this->confirmedAttempt($client, $candidate, 7, [
                'dateStart' => $this->sydneyToUtc($this->days(7) . " $start"),
                'dateEnd' => $this->sydneyToUtc($this->days(7) . " $end"),
            ]);

            $this->assertSame(200, $response['status'], json_encode($response));
        }
    }

    public function testACancelledShiftLeavesTheCandidateFree(): void
    {
        $candidate = $this->clearedCandidate();
        $client = $this->client();

        $first = $this->confirmedAttempt($client, $candidate)['body'];
        $this->update('RxShift', $first['id'], ['status' => 'Cancelled']);

        $this->assertSame(200, $this->confirmedAttempt($client, $candidate)['status']);
    }

    public function testChangingTheCandidateOnAConfirmedShiftIsChecked(): void
    {
        $client = $this->client();
        $shift = $this->confirmedAttempt($client, $this->clearedCandidate())['body'];
        $other = $this->candidate();

        $this->assertRefused(
            $this->api('PUT', "RxShift/{$shift['id']}", ['candidateId' => $other['id']]),
            'rxNotCleared',
        );
    }

    public function testMovingAConfirmedShiftPastAnExpiryIsChecked(): void
    {
        $candidate = $this->clearedCandidate(expiry: $this->days(10));
        $shift = $this->confirmedAttempt($this->client(), $candidate, 7)['body'];

        $this->assertRefused(
            $this->api('PUT', "RxShift/{$shift['id']}", [
                'dateStart' => $this->sydneyToUtc($this->days(20) . ' 09:00:00'),
                'dateEnd' => $this->sydneyToUtc($this->days(20) . ' 17:00:00'),
            ]),
            'rxNotCleared',
        );
    }

    public function testAnOpenShiftCannotJumpToCompleted(): void
    {
        $shift = $this->create('RxShift', $this->shiftData($this->client()['id'], $this->candidate()['id']));

        $this->assertRefused(
            $this->api('PUT', "RxShift/{$shift['id']}", ['status' => 'Completed']),
            'rxNotCleared',
        );
    }

    public function testCompletingAConfirmedShiftIsNotCheckedAgain(): void
    {
        $candidate = $this->clearedCandidate(expiry: $this->days(7));
        $shift = $this->confirmedAttempt($this->client(), $candidate, 7)['body'];

        foreach ($this->api('GET', "RxCandidate/{$candidate['id']}/credentials")['body']['list'] as $credential) {
            $this->update('RxCredential', $credential['id'], ['status' => 'Rejected']);
        }

        $this->assertSame('Completed', $this->update('RxShift', $shift['id'], ['status' => 'Completed'])['status']);
    }

    public function testAMassUpdateCannotConfirmPastTheGate(): void
    {
        $shift = $this->create('RxShift', $this->shiftData($this->client()['id'], $this->candidate()['id']));

        $this->api('POST', 'MassAction', [
            'entityType' => 'RxShift',
            'action' => 'update',
            'params' => ['ids' => [$shift['id']]],
            'data' => ['status' => 'Confirmed'],
        ]);

        $this->assertSame('Open', $this->read('RxShift', $shift['id'])['status']);
    }

    public function testAnInvalidRoleIsRejected(): void
    {
        $response = $this->api(
            'POST',
            'RxShift',
            $this->shiftData($this->client()['id'], null, 7, ['profession' => 'Not a role']),
        );

        $this->assertSame(400, $response['status'], json_encode($response));
    }

    public function testARoleAddedWithoutRulesFailsClosed(): void
    {
        $field = 'Admin/fieldManager/RxCandidate/profession';
        $options = $this->api('GET', $field)['body']['options'];

        $added = $this->api('PUT', $field, ['options' => [...$options, 'Pharmacy manager']]);
        $this->assertSame(200, $added['status'], json_encode($added));

        try {
            $candidate = $this->candidate('Pharmacy manager');
            $this->credential($candidate['id'], 'Right to work', null);

            $this->assertRefused(
                $this->confirmedAttempt($this->client(), $candidate, 7, ['profession' => 'Pharmacy manager']),
                'rxRoleHasNoRules',
            );
        } finally {
            $this->api('PUT', $field, ['options' => $options]);
        }
    }

    public function testARecruiterCannotConfirmAnUnclearedCandidate(): void
    {
        $team = $this->create('Team', ['name' => 'Team ' . bin2hex(random_bytes(4))]);
        $recruiter = $this->staffLogin('Recruiter', [$team['id']]);
        $shared = ['teamsIds' => [$team['id']]];

        $candidate = $this->candidate();
        $this->update('RxCandidate', $candidate['id'], $shared);

        $shift = $this->create(
            'RxShift',
            $this->shiftData($this->client($shared)['id'], $candidate['id'], 7, $shared),
        );

        $this->assertRefused(
            $this->api('PUT', "RxShift/{$shift['id']}", ['status' => 'Confirmed'], $recruiter),
            'rxNotCleared',
        );
    }

    public function testFinishingAShiftCannotSwapItsCandidate(): void
    {
        $shift = $this->confirmedAttempt($this->client(), $this->clearedCandidate())['body'];

        $this->assertRefused(
            $this->api('PUT', "RxShift/{$shift['id']}", ['status' => 'Completed', 'candidateId' => $this->candidate()['id']]),
            'rxNotCleared',
        );
    }

    public function testACompletedShiftCannotTakeAnUnclearedCandidate(): void
    {
        $shift = $this->confirmedAttempt($this->client(), $this->clearedCandidate())['body'];

        $this->update('RxShift', $shift['id'], ['status' => 'Completed']);

        $this->assertRefused(
            $this->api('PUT', "RxShift/{$shift['id']}", ['candidateId' => $this->candidate()['id']]),
            'rxNotCleared',
        );
    }

    public function testAWrongRoleOnAShiftCannotLowerTheBar(): void
    {
        $candidate = $this->candidate('Pharmacist');
        $this->credential($candidate['id'], 'Right to work', null);

        $this->assertRefused(
            $this->confirmedAttempt($this->client(), $candidate, 7, ['profession' => 'Pharmacy assistant']),
            'rxNotCleared',
        );
    }

    public function testTheJobsRoleCountsToo(): void
    {
        $client = $this->client();
        $job = $this->job($client['id'], ['profession' => 'Pharmacist']);

        $this->assertRefused(
            $this->confirmedAttempt($client, $this->clearedCandidate('Pharmacy technician'), 7, [
                'profession' => 'Pharmacy technician',
                'jobOrderId' => $job['id'],
            ]),
            'rxNotCleared',
        );
    }

    public function testAShiftMustBeForItsJobsClient(): void
    {
        $job = $this->job($this->client(['rxRequiredCredentials' => ['Immuniser certificate']])['id']);

        $this->assertRefused(
            $this->confirmedAttempt($this->client(), $this->clearedCandidate(), 7, ['jobOrderId' => $job['id']]),
            'rxClientNotJobClient',
        );
    }

    public function testADeletedJobCannotDropItsRequirements(): void
    {
        $client = $this->client();
        $job = $this->job($client['id'], ['requiredCredentials' => ['Immuniser certificate']]);
        $shift = $this->create('RxShift', $this->shiftData($client['id'], $this->clearedCandidate()['id'], 7, [
            'jobOrderId' => $job['id'],
        ]));

        $this->assertSame(200, $this->api('DELETE', "RxJobOrder/{$job['id']}")['status']);

        $this->assertRefused(
            $this->api('PUT', "RxShift/{$shift['id']}", ['status' => 'Confirmed']),
            'rxLinkedRecordDeleted',
        );
    }

    public function testADeletedClientCannotDropItsRequirements(): void
    {
        $client = $this->client(['rxRequiredCredentials' => ['Immuniser certificate']]);
        $shift = $this->create('RxShift', $this->shiftData($client['id'], $this->clearedCandidate()['id']));

        $this->assertSame(200, $this->api('DELETE', "Account/{$client['id']}")['status']);

        $this->assertRefused(
            $this->api('PUT', "RxShift/{$shift['id']}", ['status' => 'Confirmed']),
            'rxLinkedRecordDeleted',
        );
    }

    public function testARefusalNamesRoleCredentialsButNotSiteOnes(): void
    {
        $site = $this->confirmedAttempt(
            $this->client(['rxRequiredCredentials' => ['NSW Health vaccination evidence']]),
            $this->clearedCandidate(),
        );

        $this->assertRefused($site, 'rxNotCleared');
        $this->assertStringNotContainsString('vaccination', (string) json_encode($site));
        $this->assertStringContainsString('ask compliance', (string) json_encode($site));

        $role = $this->confirmedAttempt($this->client(), $this->candidate());

        $this->assertStringContainsString('Ahpra general registration', (string) json_encode($role));
        $this->assertStringNotContainsString('ask compliance', (string) json_encode($role));
    }

    /**
     * A fixed offset would put one side of each clock change on the wrong day.
     */
    public function testALocalDayEndsAtLocalMidnightEitherSideOfAClockChange(): void
    {
        $sydney = new \DateTimeZone('Australia/Sydney');
        $from = time() + 3 * 86400;
        $changes = array_slice($sydney->getTransitions($from, $from + 400 * 86400), 1, 2);

        $this->assertCount(2, $changes);

        foreach ($changes as $change) {
            foreach (['-1 day', '+1 day'] as $side) {
                $day = (new \DateTimeImmutable('@' . $change['ts']))->setTimezone($sydney)->modify($side);
                $expiry = $day->format('Y-m-d');
                $next = $day->modify('+1 day')->format('Y-m-d');

                $candidate = $this->clearedCandidate('Pharmacist', $expiry);
                $client = $this->client()['id'];

                $this->create('RxShift', [
                    ...$this->shiftData($client, $candidate['id']),
                    'dateStart' => $this->sydneyToUtc("$expiry 19:00:00"),
                    'dateEnd' => $this->sydneyToUtc("$expiry 23:30:00"),
                    'status' => 'Confirmed',
                ]);

                $this->assertRefused($this->api('POST', 'RxShift', [
                    ...$this->shiftData($client, $candidate['id']),
                    'dateStart' => $this->sydneyToUtc("$expiry 23:40:00"),
                    'dateEnd' => $this->sydneyToUtc("$next 00:30:00"),
                    'status' => 'Confirmed',
                ]), 'rxNotCleared');
            }
        }
    }

    public function testOnlyAManagerCanChangeACandidatesRole(): void
    {
        $team = $this->create('Team', ['name' => 'Team ' . bin2hex(random_bytes(4))]);
        $recruiter = $this->staffLogin('Recruiter', [$team['id']]);
        $manager = $this->staffLogin('Agency manager');

        $created = $this->api('POST', 'RxCandidate', [
            'lastName' => 'Test ' . bin2hex(random_bytes(4)),
            'profession' => 'Pharmacy assistant',
            'assignedUserId' => $this->userId($recruiter),
            'teamsIds' => [$team['id']],
        ], $recruiter);
        $this->assertSame(200, $created['status'], json_encode($created));

        $candidate = $this->candidate();
        $path = "RxCandidate/{$candidate['id']}";
        $this->update('RxCandidate', $candidate['id'], ['teamsIds' => [$team['id']]]);

        $this->assertRefused(
            $this->api('PUT', $path, ['profession' => 'Pharmacy assistant'], $recruiter),
            'rxCandidateRoleLocked',
        );
        $this->assertSame('Pharmacist', $this->read('RxCandidate', $candidate['id'])['profession']);

        $unchanged = $this->api('PUT', $path, ['profession' => 'Pharmacist', 'description' => 'Note'], $recruiter);
        $this->assertSame(200, $unchanged['status'], json_encode($unchanged));

        $changed = $this->api('PUT', $path, ['profession' => 'Pharmacy assistant'], $manager);
        $this->assertSame(200, $changed['status'], json_encode($changed));
    }

    public function testEveryRoleOnAShiftNeedsRules(): void
    {
        $field = 'Admin/fieldManager/RxCandidate/profession';
        $options = $this->api('GET', $field)['body']['options'];

        $added = $this->api('PUT', $field, ['options' => [...$options, 'Pharmacy manager']]);
        $this->assertSame(200, $added['status'], json_encode($added));

        try {
            $client = $this->client();
            $cleared = $this->clearedCandidate();
            $job = $this->job($client['id'], ['profession' => 'Pharmacy manager']);

            $this->assertRefused(
                $this->confirmedAttempt($client, $cleared, 7, ['jobOrderId' => $job['id']]),
                'rxRoleHasNoRules',
            );

            $unmapped = $this->clearedCandidate();
            $this->update('RxCandidate', $unmapped['id'], ['profession' => 'Pharmacy manager']);

            $this->assertRefused($this->confirmedAttempt($this->client(), $unmapped), 'rxRoleHasNoRules');
        } finally {
            $this->api('PUT', $field, ['options' => $options]);
        }
    }
}
