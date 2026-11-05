<?php

namespace PharmacyTests\Api;

class AccessTest extends ApiTestCase
{
    /**
     * @return array{0: array{0: string, 1: string}, 1: array<string, mixed>}
     */
    private function recruiterWithCandidate(): array
    {
        $team = $this->create('Team', ['name' => 'Team ' . bin2hex(random_bytes(4))]);
        $recruiter = $this->staffLogin('Recruiter', [$team['id']]);

        $candidate = $this->candidate();
        $this->update('RxCandidate', $candidate['id'], ['teamsIds' => [$team['id']]]);
        $this->credential($candidate['id'], 'Right to work', null);

        return [$recruiter, $candidate];
    }

    public function testRecruitersCannotReadCredentials(): void
    {
        [$recruiter, $candidate] = $this->recruiterWithCandidate();

        $this->assertSame(200, $this->api('GET', "RxCandidate/{$candidate['id']}", null, $recruiter)['status']);
        $this->assertSame(403, $this->api('GET', 'RxCredential', null, $recruiter)['status']);
        $this->assertSame(
            403,
            $this->api('GET', "RxCandidate/{$candidate['id']}/credentials", null, $recruiter)['status'],
        );
    }

    public function testRecruitersCannotReadEvidenceFiles(): void
    {
        [$recruiter, $candidate] = $this->recruiterWithCandidate();

        $attachment = $this->create('Attachment', [
            'name' => 'evidence.pdf',
            'type' => 'application/pdf',
            'role' => 'Attachment',
            'relatedType' => 'RxCredential',
            'field' => 'evidence',
            'file' => 'data:application/pdf;base64,' . base64_encode('%PDF-1.4 test'),
        ]);
        $credential = $this->credential($candidate['id'], 'Identity check', null, ['evidenceId' => $attachment['id']]);

        $this->assertSame($attachment['id'], $credential['evidenceId']);

        $this->assertSame(403, $this->api('GET', "Attachment/file/{$attachment['id']}", null, $recruiter)['status']);

        // The attachment's owner is the administrator, so an officer reaches it only through the credential.
        $officer = $this->staffLogin('Compliance officer');
        $download = "../../?entryPoint=download&id={$attachment['id']}";

        $this->assertSame(200, $this->api('GET', "Attachment/file/{$attachment['id']}", null, $officer)['status']);
        $this->assertSame('%PDF-1.4 test', $this->api('GET', $download, null, $officer)['raw']);
        $this->assertSame(403, $this->api('GET', $download, null, $recruiter)['status']);
    }

    public function testRecruitersOnlySeeTheirTeamsCandidates(): void
    {
        [$recruiter] = $this->recruiterWithCandidate();
        $other = $this->candidate();

        $this->assertSame(403, $this->api('GET', "RxCandidate/{$other['id']}", null, $recruiter)['status']);
    }

    public function testRecruitersCannotExport(): void
    {
        [$recruiter] = $this->recruiterWithCandidate();

        $response = $this->api('POST', 'Export', [
            'entityType' => 'RxCandidate',
            'format' => 'csv',
            'params' => ['searchParams' => (object) []],
            'attributeList' => ['name'],
        ], $recruiter);

        $this->assertSame(403, $response['status'], json_encode($response));
    }

    public function testRecruitersCannotLiftAClientsOrJobsRequirements(): void
    {
        $team = $this->create('Team', ['name' => 'Team ' . bin2hex(random_bytes(4))]);
        $recruiter = $this->staffLogin('Recruiter', [$team['id']]);

        $client = $this->client(['rxRequiredCredentials' => ['Immuniser certificate'], 'teamsIds' => [$team['id']]]);
        $job = $this->job($client['id'], ['requiredCredentials' => ['CPR certificate'], 'teamsIds' => [$team['id']]]);

        $this->api('PUT', "Account/{$client['id']}", ['rxRequiredCredentials' => []], $recruiter);
        $this->api('PUT', "RxJobOrder/{$job['id']}", ['requiredCredentials' => []], $recruiter);

        $this->assertSame(['Immuniser certificate'], $this->read('Account', $client['id'])['rxRequiredCredentials']);
        $this->assertSame(['CPR certificate'], $this->read('RxJobOrder', $job['id'])['requiredCredentials']);
    }

    public function testAUserWithoutARoleSeesNothing(): void
    {
        $nobody = $this->userWith([]);

        foreach (['RxCandidate', 'RxCredential', 'RxShift', 'RxPlacement'] as $entityType) {
            $this->assertSame(403, $this->api('GET', $entityType, null, $nobody)['status'], $entityType);
        }
    }

    public function testComplianceOfficersManageCredentialsButNotShifts(): void
    {
        $officer = $this->staffLogin('Compliance officer');
        $candidate = $this->candidate();

        $created = $this->api('POST', 'RxCredential', [
            'candidateId' => $candidate['id'],
            'type' => 'Right to work',
        ], $officer);

        $this->assertSame(200, $created['status'], json_encode($created));

        $shift = $this->api('POST', 'RxShift', $this->shiftData($this->client()['id'], null), $officer);

        $this->assertSame(403, $shift['status'], json_encode($shift));
    }

    public function testSeededRolesGrantCalendarAndActivities(): void
    {
        $acl = $this->api('GET', 'App/user', null, $this->staffLogin('Recruiter'))['body']['acl']['table'] ?? [];

        $this->assertNotFalse($acl['Calendar'] ?? false);
        $this->assertNotFalse($acl['Activities'] ?? false);
    }
}
