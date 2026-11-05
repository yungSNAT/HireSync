<?php

namespace PharmacyTests\Api;

class CredentialTest extends ApiTestCase
{
    public function testVerifyingStampsWhoAndWhen(): void
    {
        $candidate = $this->candidate();
        $credential = $this->credential($candidate['id'], 'Right to work', null);

        $this->assertSame($this->adminId(), $credential['verifiedById']);
        $this->assertNotEmpty($credential['verifiedAt']);
    }

    public function testVerifyingNeedsAMethod(): void
    {
        $response = $this->api('POST', 'RxCredential', [
            'candidateId' => $this->candidate()['id'],
            'type' => 'Identity check',
            'status' => 'Verified',
        ]);

        $this->assertRefused($response, 'rxVerificationNeedsMethod');
    }

    public function testVerifyingNeedsAnExpiryUnlessTheTypeHasNone(): void
    {
        $response = $this->api('POST', 'RxCredential', [
            'candidateId' => $this->candidate()['id'],
            'type' => 'Professional indemnity insurance',
            'status' => 'Verified',
            'verificationMethod' => 'Insurer document',
        ]);

        $this->assertRefused($response, 'rxVerificationNeedsExpiry');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function factChanges(): iterable
    {
        yield 'method removed' => [['verificationMethod' => '']];
        yield 'number' => [['identifier' => 'A different number']];
        yield 'conditions' => [['conditions' => 'A new condition']];
        yield 'verifying entity' => [['verifyingEntity' => 'Another organisation']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('factChanges')]
    public function testChangingACheckedFactNeedsVerifyingAgain(array $change): void
    {
        $credential = $this->credential($this->candidate()['id'], 'Right to work', null);

        $updated = $this->update('RxCredential', $credential['id'], $change);

        $this->assertSame('Pending', $updated['status']);
        $this->assertNull($updated['verifiedById']);
    }

    public function testMovingACredentialToAnotherPersonNeedsVerifyingAgain(): void
    {
        $credential = $this->credential($this->candidate()['id'], 'Right to work', null);

        $updated = $this->update('RxCredential', $credential['id'], ['candidateId' => $this->candidate()['id']]);

        $this->assertSame('Pending', $updated['status']);
    }

    public function testMovingACredentialReassessesThePreviousHolder(): void
    {
        $holder = $this->clearedCandidate('Pharmacy technician');
        $credential = $this->api('GET', "RxCandidate/{$holder['id']}/credentials")['body']['list'][0];

        $this->update('RxCredential', $credential['id'], ['candidateId' => $this->candidate()['id']]);

        $this->assertSame('Blocked', $this->read('RxCandidate', $holder['id'])['complianceStatus']);
    }

    public function testVerifyingAgainStampsTheNewVerifier(): void
    {
        $credential = $this->credential($this->candidate()['id'], 'Right to work', null);
        $this->update('RxCredential', $credential['id'], ['conditions' => 'A new condition']);

        $officer = $this->staffLogin('Compliance officer');
        $officerId = $this->api('GET', 'App/user', null, $officer)['body']['user']['id'];

        $response = $this->api('PUT', "RxCredential/{$credential['id']}", ['status' => 'Verified'], $officer);

        $this->assertSame(200, $response['status'], json_encode($response));
        $this->assertSame($officerId, $response['body']['verifiedById']);
    }

    public function testATimeLimitedVisaNeedsAnExpiry(): void
    {
        $candidate = $this->candidate();
        $visa = ['status' => 'Verified', 'verificationMethod' => 'VEVO', 'workRightsBasis' => 'Time-limited visa'];

        $this->assertRefused(
            $this->api('POST', 'RxCredential', ['candidateId' => $candidate['id'], 'type' => 'Right to work', ...$visa]),
            'rxRightToWorkNeedsBasis',
        );

        $this->credential($candidate['id'], 'Right to work', $this->days(100), $visa);
    }

    public function testAnOpenEndedRightToWorkNeedsAStatedBasis(): void
    {
        $response = $this->api('POST', 'RxCredential', [
            'candidateId' => $this->candidate()['id'],
            'type' => 'Right to work',
            'status' => 'Verified',
            'verificationMethod' => 'VEVO',
        ]);

        $this->assertRefused($response, 'rxRightToWorkNeedsBasis');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unacceptableChildChecks(): iterable
    {
        yield 'application only' => [['registerStatus' => 'Application in progress']];
        yield 'barred' => [['registerStatus' => 'Barred']];
        yield 'volunteer check' => [['checkType' => 'Volunteer']];
        yield 'no date of birth' => [['dateOfBirth' => null]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unacceptableChildChecks')]
    public function testOnlyACompleteClearedPaidCheckCanBeVerified(array $change): void
    {
        $response = $this->api('POST', 'RxCredential', [
            'candidateId' => $this->candidate()['id'],
            'type' => 'Working with Children Check',
            'status' => 'Verified',
            'verificationMethod' => 'OCG online verification',
            'expiryDate' => $this->days(365),
            'registerStatus' => 'Cleared',
            'checkType' => 'Paid',
            'dateOfBirth' => '1990-05-12',
            ...$change,
        ]);

        $this->assertRefused($response, 'rxChildCheckIncomplete');
    }

    public function testInsuranceBelowTheMinimumCannotBeVerified(): void
    {
        $candidate = $this->candidate();

        foreach ([null, 19999999] as $cover) {
            $response = $this->api('POST', 'RxCredential', [
                'candidateId' => $candidate['id'],
                'type' => 'Professional indemnity insurance',
                'status' => 'Verified',
                'verificationMethod' => 'Insurer document',
                'expiryDate' => $this->days(365),
                'coverAmount' => $cover,
                'coverAmountCurrency' => 'AUD',
            ]);

            $this->assertRefused($response, 'rxInsuranceBelowMinimum');
        }
    }

    public function testCredentialsCannotBeExported(): void
    {
        $response = $this->api('POST', 'Export', [
            'entityType' => 'RxCredential',
            'format' => 'csv',
            'attributeList' => ['name'],
            'params' => [],
        ]);

        $this->assertSame(403, $response['status'], json_encode($response));
    }

    public function testLeavingVerifiedClearsTheStamp(): void
    {
        $credential = $this->credential($this->candidate()['id'], 'Right to work', null);

        $updated = $this->update('RxCredential', $credential['id'], ['status' => 'Rejected']);

        $this->assertNull($updated['verifiedById']);
        $this->assertNull($updated['verifiedAt']);
    }

    public function testExpiryIsDerivedFromIssueDate(): void
    {
        $issued = '2026-03-15';

        $check = $this->credential($this->candidate()['id'], 'National police check', null, ['issueDate' => $issued]);
        $cpr = $this->credential($this->candidate()['id'], 'CPR certificate', null, ['issueDate' => $issued]);

        $this->assertSame('2029-03-15', $check['expiryDate']);
        $this->assertSame('2027-03-15', $cpr['expiryDate']);
    }

    public function testAVerifiedFixedTermRecordCannotBeOpenEnded(): void
    {
        $response = $this->api('POST', 'RxCredential', [
            'candidateId' => $this->candidate()['id'],
            'type' => 'NSW Health vaccination evidence',
            'status' => 'Verified',
            'verificationMethod' => 'Original sighted',
        ]);

        $this->assertRefused($response, 'rxVerificationNeedsExpiry');
    }

    public function testAnAuditEntryIsKeptWithoutAStream(): void
    {
        $credential = $this->credential($this->candidate()['id'], 'Professional indemnity insurance', $this->days(100));

        $this->update('RxCredential', $credential['id'], ['expiryDate' => $this->days(200)]);

        $audit = $this->api('GET', "RxCredential/{$credential['id']}/updateStream");

        $this->assertSame(200, $audit['status'], json_encode($audit));
        $this->assertGreaterThan(0, $audit['body']['total'] ?? 0);
    }

    public function testRemovingACandidateRemovesTheirCredentials(): void
    {
        $candidate = $this->candidate();
        $credential = $this->credential($candidate['id'], 'Right to work', null);

        $this->assertSame(200, $this->api('DELETE', "RxCandidate/{$candidate['id']}")['status']);

        // Admins can still open soft-deleted records, so this reads as ordinary staff.
        $officer = $this->staffLogin('Compliance officer');

        $this->assertSame(404, $this->api('GET', "RxCredential/{$credential['id']}", null, $officer)['status']);
    }

    private function candidateWhoWorkedWithAChildCheck(): array
    {
        $candidate = $this->clearedCandidate();
        $check = $this->credential($candidate['id'], 'Working with Children Check', $this->days(365));

        $shift = $this->create('RxShift', [
            ...$this->shiftData($this->client()['id'], $candidate['id'], -30),
            'status' => 'Confirmed',
        ]);

        return [$candidate, $check, $shift];
    }

    public function testAChildCheckIsHeldAfterTheWorkEnds(): void
    {
        [$candidate, $check] = $this->candidateWhoWorkedWithAChildCheck();

        $this->assertRefused($this->api('DELETE', "RxCandidate/{$candidate['id']}"), 'rxRetentionHold');
        $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');

        // Admins can still open soft-deleted records, so this reads as ordinary staff.
        $officer = $this->staffLogin('Compliance officer');

        $this->assertSame(200, $this->api('GET', "RxCandidate/{$candidate['id']}", null, $officer)['status']);
    }

    public function testAPlacementAlsoHoldsAChildCheck(): void
    {
        $candidate = $this->clearedCandidate();
        $check = $this->credential($candidate['id'], 'Working with Children Check', $this->days(365));
        $client = $this->client();

        $this->create('RxPlacement', [
            'candidateId' => $candidate['id'],
            'jobOrderId' => $this->job($client['id'])['id'],
            'accountId' => $client['id'],
            'startDate' => $this->days(-30),
            'status' => 'Active',
        ]);

        $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');
    }

    public function testAChildCheckIsReleasedAfterThePeriod(): void
    {
        $candidate = $this->clearedCandidate();
        $this->credential($candidate['id'], 'Working with Children Check', $this->days(365));

        $this->create('RxShift', [
            ...$this->shiftData($this->client()['id'], $candidate['id'], -2930),
            'status' => 'Confirmed',
        ]);

        $this->assertSame(200, $this->api('DELETE', "RxCandidate/{$candidate['id']}")['status']);
    }

    public function testCancelledWorkDoesNotHoldAChildCheck(): void
    {
        $candidate = $this->clearedCandidate();
        $this->credential($candidate['id'], 'Working with Children Check', $this->days(365));

        $shift = $this->create('RxShift', [
            ...$this->shiftData($this->client()['id'], $candidate['id'], -30),
            'status' => 'Cancelled',
        ]);

        $this->update('RxShift', $shift['id'], ['description' => 'Never happened']);

        $this->assertSame(200, $this->api('DELETE', "RxCandidate/{$candidate['id']}")['status']);
    }

    public function testAChildCheckWithNoWorkCanBeDeleted(): void
    {
        $candidate = $this->clearedCandidate();
        $this->credential($candidate['id'], 'Working with Children Check', $this->days(365));

        $this->assertSame(200, $this->api('DELETE', "RxCandidate/{$candidate['id']}")['status']);
    }

    public function testRecruitersSeeOnlyThatConditionsExist(): void
    {
        $candidate = $this->clearedCandidate();

        $this->credential($candidate['id'], 'Identity check', null, ['conditions' => 'Private wording']);

        $summary = $this->read('RxCandidate', $candidate['id'])['complianceSummary'];

        $this->assertStringContainsString('Conditions on record', $summary);
        $this->assertStringNotContainsString('Private wording', $summary);
    }

    public function testComplianceStatusFollowsTheCredentials(): void
    {
        $candidate = $this->candidate();

        $this->assertSame('Blocked', $this->read('RxCandidate', $candidate['id'])['complianceStatus']);

        $cleared = $this->clearedCandidate();
        $this->assertSame('Clear', $cleared['complianceStatus']);
        $this->assertSame($this->days(365), $cleared['nextCredentialExpiry']);

        $soon = $this->clearedCandidate(expiry: $this->days(30));
        $this->assertSame('Expiring', $soon['complianceStatus']);
    }

    public function testRemovingACredentialRefreshesTheCandidate(): void
    {
        $candidate = $this->clearedCandidate();
        $list = $this->api('GET', "RxCandidate/{$candidate['id']}/credentials")['body']['list'];

        $this->api('DELETE', "RxCredential/{$list[0]['id']}");

        $this->assertSame('Blocked', $this->read('RxCandidate', $candidate['id'])['complianceStatus']);
    }

    public function testAProfessionChangeReassessesTheCandidate(): void
    {
        $candidate = $this->clearedCandidate('Pharmacy technician');

        $this->assertSame('Clear', $candidate['complianceStatus'], json_encode($candidate));

        $updated = $this->update('RxCandidate', $candidate['id'], ['profession' => 'Pharmacist']);

        $this->assertSame('Blocked', $updated['complianceStatus']);
    }

    public function testAFixedLifeCredentialCannotDodgeItsLife(): void
    {
        $response = $this->api('POST', 'RxCredential', [
            'candidateId' => $this->candidate()['id'],
            'type' => 'CPR certificate',
            'status' => 'Verified',
            'verificationMethod' => 'Original sighted',
            'expiryDate' => '2099-01-01',
        ]);

        $this->assertRefused($response, 'rxVerificationNeedsIssueDate');
    }

    public function testACredentialCannotBeIssuedInTheFuture(): void
    {
        $response = $this->api('POST', 'RxCredential', [
            'candidateId' => $this->candidate()['id'],
            'type' => 'CPR certificate',
            'status' => 'Verified',
            'verificationMethod' => 'Original sighted',
            'issueDate' => $this->days(1),
        ]);

        $this->assertRefused($response, 'rxVerificationNeedsIssueDate');
    }

    public function testAHeldCredentialKeepsItsTypeAndHolder(): void
    {
        [, $check] = $this->candidateWhoWorkedWithAChildCheck();

        $this->assertRefused(
            $this->api('PUT', "RxCredential/{$check['id']}", ['type' => 'Identity check']),
            'rxRetentionHold',
        );
        $this->assertRefused(
            $this->api('PUT', "RxCredential/{$check['id']}", ['candidateId' => $this->candidate()['id']]),
            'rxRetentionHold',
        );

        $this->assertSame('Working with Children Check', $this->read('RxCredential', $check['id'])['type']);
    }

    public function testCancellingWorkThatHappenedKeepsTheHold(): void
    {
        [$candidate, $check, $shift] = $this->candidateWhoWorkedWithAChildCheck();

        $this->update('RxShift', $shift['id'], ['status' => 'Cancelled']);

        $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');
        $this->assertRefused($this->api('DELETE', "RxCandidate/{$candidate['id']}"), 'rxRetentionHold');
    }

    public function testDeletingWorkThatHappenedKeepsTheHold(): void
    {
        [, $check, $shift] = $this->candidateWhoWorkedWithAChildCheck();

        $this->assertSame(200, $this->api('DELETE', "RxShift/{$shift['id']}")['status']);

        $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');
    }

    public function testMovingWorkThatHappenedKeepsTheHold(): void
    {
        [, $check, $shift] = $this->candidateWhoWorkedWithAChildCheck();

        $this->update('RxShift', $shift['id'], ['candidateId' => $this->clearedCandidate()['id']]);

        $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');
    }

    public function testPostponingAShiftThatHappenedKeepsTheHold(): void
    {
        [, $check, $shift] = $this->candidateWhoWorkedWithAChildCheck();
        $later = $this->shiftData($shift['accountId'], $shift['candidateId'], 7);

        $this->update('RxShift', $shift['id'], ['dateStart' => $later['dateStart'], 'dateEnd' => $later['dateEnd']]);
        $this->update('RxShift', $shift['id'], ['status' => 'Cancelled']);

        $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');
    }

    public function testPostponingAPlacementThatStartedKeepsTheHold(): void
    {
        [, $check, $placement] = $this->candidateOnAPlacement(null);

        $this->update('RxPlacement', $placement['id'], ['startDate' => $this->days(7)]);
        $this->update('RxPlacement', $placement['id'], ['status' => 'Fallen through']);

        $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');
    }

    public function testBackdatingWorkThatHappenedKeepsTheHold(): void
    {
        [, $check, $shift] = $this->candidateWhoWorkedWithAChildCheck();
        $old = $this->shiftData($shift['accountId'], $shift['candidateId'], -3000);

        $this->update('RxShift', $shift['id'], ['dateStart' => $old['dateStart'], 'dateEnd' => $old['dateEnd']]);

        $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>}
     */
    private function candidateOnAPlacement(?string $endDate, int $startOffset = -30): array
    {
        $candidate = $this->clearedCandidate();
        $check = $this->credential($candidate['id'], 'Working with Children Check', $this->days(365));
        $client = $this->client();

        $placement = $this->create('RxPlacement', [
            'candidateId' => $candidate['id'],
            'jobOrderId' => $this->job($client['id'])['id'],
            'accountId' => $client['id'],
            'startDate' => $this->days($startOffset),
            'endDate' => $endDate,
            'status' => 'Active',
        ]);

        return [$candidate, $check, $placement];
    }

    public function testAPlacementThatStartedKeepsTheHoldWhenItFallsThrough(): void
    {
        foreach ([$this->days(300), null] as $endDate) {
            [$candidate, $check, $placement] = $this->candidateOnAPlacement($endDate);

            $this->update('RxPlacement', $placement['id'], ['status' => 'Fallen through']);

            $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');
            $this->assertSame($this->days(0), $this->read('RxCandidate', $candidate['id'])['workOnRecordUntil']);
        }
    }

    public function testDeletingAPlacementThatStartedKeepsTheHold(): void
    {
        [, $check, $placement] = $this->candidateOnAPlacement(null);

        $this->assertSame(200, $this->api('DELETE', "RxPlacement/{$placement['id']}")['status']);

        $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');
    }

    public function testOlderWorkCannotShortenTheHold(): void
    {
        [$candidate, , $shift] = $this->candidateWhoWorkedWithAChildCheck();

        $older = $this->create('RxShift', [
            ...$this->shiftData($this->client()['id'], $candidate['id'], -300),
            'status' => 'Confirmed',
        ]);

        $this->update('RxShift', $shift['id'], ['status' => 'Cancelled']);
        $kept = $this->read('RxCandidate', $candidate['id'])['workOnRecordUntil'];

        $this->update('RxShift', $older['id'], ['status' => 'Cancelled']);

        $this->assertSame($kept, $this->read('RxCandidate', $candidate['id'])['workOnRecordUntil']);
    }

    public function testABookingCancelledBeforeItStartedHoldsNothing(): void
    {
        $candidate = $this->clearedCandidate();
        $check = $this->credential($candidate['id'], 'Working with Children Check', $this->days(365));

        $shift = $this->create('RxShift', [
            ...$this->shiftData($this->client()['id'], $candidate['id'], 0),
            'dateStart' => gmdate('Y-m-d H:i:s', time() + 300),
            'dateEnd' => gmdate('Y-m-d H:i:s', time() + 7200),
            'status' => 'Confirmed',
        ]);

        $this->update('RxShift', $shift['id'], ['status' => 'Cancelled']);

        $this->assertSame(200, $this->api('DELETE', "RxCredential/{$check['id']}")['status']);
    }

    public function testAShiftCancelledAfterItStartedKeepsTheHold(): void
    {
        $candidate = $this->clearedCandidate();
        $check = $this->credential($candidate['id'], 'Working with Children Check', $this->days(365));

        $shift = $this->create('RxShift', [
            ...$this->shiftData($this->client()['id'], $candidate['id'], 0),
            'dateStart' => gmdate('Y-m-d H:i:s', time() - 300),
            'dateEnd' => gmdate('Y-m-d H:i:s', time() + 7200),
            'status' => 'Confirmed',
        ]);

        $this->update('RxShift', $shift['id'], ['status' => 'Cancelled']);

        $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');
    }

    public function testAPlacementThatNeverStartedHoldsNothing(): void
    {
        [, $check, $placement] = $this->candidateOnAPlacement(null, 7);

        $this->update('RxPlacement', $placement['id'], ['status' => 'Fallen through']);

        $this->assertSame(200, $this->api('DELETE', "RxCredential/{$check['id']}")['status']);
    }

    public function testTheHoldCannotBeEditedAway(): void
    {
        [$candidate, $check, $shift] = $this->candidateWhoWorkedWithAChildCheck();

        $this->update('RxShift', $shift['id'], ['status' => 'Cancelled']);
        $this->update('RxCandidate', $candidate['id'], ['workOnRecordUntil' => null]);

        $this->assertRefused($this->api('DELETE', "RxCredential/{$check['id']}"), 'rxRetentionHold');
    }

    public function testNoCredentialCanBeVerifiedBeforeItIsIssued(): void
    {
        $response = $this->api('POST', 'RxCredential', [
            'candidateId' => $this->candidate()['id'],
            'type' => 'Ahpra general registration',
            'status' => 'Verified',
            'verificationMethod' => 'Original sighted',
            'issueDate' => $this->days(1),
            'expiryDate' => $this->days(300),
        ]);

        $this->assertRefused($response, 'rxVerificationNeedsIssueDate');
    }
}
