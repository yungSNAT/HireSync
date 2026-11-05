<?php

namespace PharmacyTests\Api;

class InstallTest extends ApiTestCase
{
    public function testAustralianDefaultsAreApplied(): void
    {
        $settings = $this->api('GET', 'Settings')['body'];

        $this->assertSame('HireSync', $settings['applicationName']);
        $this->assertSame('Australia/Sydney', $settings['timeZone']);
        $this->assertSame('DD/MM/YYYY', $settings['dateFormat']);
        $this->assertSame(1, $settings['weekStart']);
        $this->assertSame('AUD', $settings['defaultCurrency']);
        $this->assertSame(['AUD'], $settings['currencyList']);
    }

    public function testTheMenuAndDashboardSuitARecruiter(): void
    {
        $settings = $this->api('GET', 'Settings')['body'];

        $this->assertSame(
            ['RxCandidate', 'RxJobOrder', 'RxSubmission', 'RxPlacement', 'RxShift', 'RxCredential'],
            array_slice($settings['tabList'], 0, 6),
        );
        $this->assertSame('Recruitment', $settings['dashboardLayout'][0]['name']);
    }

    public function testPhonesAndAddressesAreAustralian(): void
    {
        $labels = $this->api('GET', 'I18n')['body']['Global']['labels'];

        $this->assertSame(['au'], $this->api('GET', 'Settings')['body']['phoneNumberPreferredCountryList']);
        $this->assertSame('State', $labels['State']);
        $this->assertSame('Postcode', $labels['PostalCode']);
    }

    public function testTheClientFormShowsEveryRecruitmentField(): void
    {
        $fields = array_keys($this->api('GET', 'Metadata')['body']['entityDefs']['Account']['fields']);
        $recruitment = preg_grep('/^rx/', $fields);
        $layout = json_encode($this->api('GET', 'Account/layout/detail')['body']);

        $this->assertNotEmpty($recruitment);

        foreach ($recruitment as $field) {
            $this->assertStringContainsString("\"$field\"", $layout);
        }
    }

    public function testClientsAndContactsListTheirJobOrders(): void
    {
        $this->assertStringContainsString(
            'rxJobOrders',
            json_encode($this->api('GET', 'Account/layout/bottomPanelsDetail')['body']),
        );
        $this->assertContains('rxJobOrders', $this->api('GET', 'Contact/layout/relationships')['body']);
    }

    public function testTheDailyCheckIsScheduled(): void
    {
        $jobs = $this->api('GET', 'ScheduledJob?maxSize=200')['body']['list'];

        $this->assertContains('RxCheckCompliance', array_column($jobs, 'job'));
    }

    public function testTheSeededRolesExist(): void
    {
        $names = array_column($this->api('GET', 'Role?maxSize=50')['body']['list'], 'name');

        foreach (['Recruiter', 'Compliance officer', 'Agency manager'] as $role) {
            $this->assertContains($role, $names);
        }
    }

    public function testReinstallingKeepsWhatTheOperatorChanged(): void
    {
        $role = $this->api(
            'GET',
            'Role?where[0][type]=equals&where[0][attribute]=name&where[0][value]=Recruiter',
        )['body']['list'][0];

        $tabList = $this->api('GET', 'Settings')['body']['tabList'];
        $changed = ['applicationName' => 'Renamed', 'tabList' => ['RxShift'], 'userThemesDisabled' => false];

        $this->assertSame(200, $this->api('PUT', 'Settings', $changed)['status']);
        $this->update('Role', $role['id'], ['exportPermission' => 'yes']);

        try {
            exec(
                'cd /var/www/html && php command.php extension --file=/tmp/pharmacy-recruitment.zip 2>&1',
                $output,
                $code,
            );

            $this->assertSame(0, $code, implode("\n", $output));

            $settings = $this->api('GET', 'Settings')['body'];

            foreach ($changed as $key => $value) {
                $this->assertSame($value, $settings[$key]);
            }

            $this->assertSame('yes', $this->read('Role', $role['id'])['exportPermission']);
        } finally {
            $this->api('PUT', 'Settings', [
                'applicationName' => 'HireSync',
                'tabList' => $tabList,
                'userThemesDisabled' => true,
            ]);
            $this->update('Role', $role['id'], ['exportPermission' => 'no']);
        }
    }

    public function testAnInvalidAbnIsRejected(): void
    {
        $response = $this->api('POST', 'Account', ['name' => 'Bad ABN', 'rxAbn' => '12 345 678 901']);

        $this->assertSame(400, $response['status'], json_encode($response));
    }

    public function testNoFieldChargesACandidate(): void
    {
        $fields = array_keys($this->api('GET', 'Metadata')['body']['entityDefs']['RxCandidate']['fields']);

        $this->assertSame([], preg_grep('/fee|charge/i', $fields));
    }

    public function testTheBrandStylesheetIsLinked(): void
    {
        $page = (string) file_get_contents('http://localhost/');

        $this->assertStringContainsString('pharmacy-recruitment/css/brand.css', $page);
        $this->assertNotFalse(file_get_contents(
            'http://localhost/client/custom/modules/pharmacy-recruitment/css/brand.css',
        ));
    }
}
