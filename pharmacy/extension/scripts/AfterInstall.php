<?php

use Espo\Core\Container;
use Espo\Core\DataManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\ScheduledJob\Populator;
use Espo\ORM\EntityManager;

/**
 * Applies Australian defaults and seeds the roles. It runs again on every upgrade, so a setting is
 * only changed while it still holds Espo's default, and a role is only created when it is absent.
 */
class AfterInstall
{
    private const SETTINGS = [
        'applicationName' => 'HireSync',
        'timeZone' => 'Australia/Sydney',
        'dateFormat' => 'DD/MM/YYYY',
        'timeFormat' => 'hh:mm a',
        'weekStart' => 1,
        'language' => 'en_GB',
        'currencyList' => ['AUD'],
        'defaultCurrency' => 'AUD',
        'baseCurrency' => 'AUD',
        'userThemesDisabled' => true,
        'tabList' => [
            'RxCandidate', 'RxJobOrder', 'RxSubmission', 'RxPlacement', 'RxShift', 'RxCredential',
            'Account', 'Contact', 'Calendar', 'Email', 'Task', 'Call', 'Meeting',
            '_delimiter_', 'Document', 'Stream', 'User',
        ],
        'quickCreateList' => ['RxCandidate', 'RxJobOrder', 'RxShift', 'Task', 'Call', 'Meeting', 'Email'],
        'globalSearchEntityList' => ['RxCandidate', 'RxJobOrder', 'Account', 'Contact'],
        'calendarEntityList' => ['RxShift', 'Meeting', 'Call', 'Task'],
        'phoneNumberPreferredCountryList' => ['au'],
    ];

    private const RECRUITMENT_SCOPES = ['RxCandidate', 'RxJobOrder', 'RxSubmission', 'RxPlacement'];

    public function run(Container $container, array $params = []): void
    {
        $factory = $container->getByClass(InjectableFactory::class);

        $this->applySettings($container->getByClass(Config::class), $factory->create(ConfigWriter::class));
        $this->seedRoles($container->getByClass(EntityManager::class));

        // Rebuilding carries the currency change through to Espo's rate tables.
        $container->getByClass(DataManager::class)->rebuild();

        $factory->create(Populator::class)->populate();
    }

    private function applySettings(Config $config, ConfigWriter $writer): void
    {
        $defaults = include 'application/Espo/Resources/defaults/config.php';

        // By value: === never matches a list of objects, and == takes an operator's false for unset.
        $holdsDefault = fn (string $key): bool =>
            json_encode($config->get($key)) === json_encode($defaults[$key] ?? null);

        foreach (self::SETTINGS as $key => $value) {
            if ($holdsDefault($key)) {
                $writer->set($key, $value);
            }
        }

        if ($holdsDefault('dashboardLayout')) {
            $writer->set('dashboardLayout', $this->dashboardLayout());
            $writer->set('dashletsOptions', $this->dashletsOptions());
        }

        $writer->save();
    }

    /**
     * @return stdClass[]
     */
    private function dashboardLayout(): array
    {
        return [
            (object) [
                'name' => 'Recruitment',
                'layout' => [
                    (object) ['id' => 'rx-unfilled', 'name' => 'Records', 'x' => 0, 'y' => 0, 'width' => 2, 'height' => 2],
                    (object) ['id' => 'rx-at-risk', 'name' => 'Records', 'x' => 2, 'y' => 0, 'width' => 2, 'height' => 2],
                    (object) ['id' => 'rx-action', 'name' => 'Records', 'x' => 0, 'y' => 2, 'width' => 2, 'height' => 2],
                    (object) ['id' => 'rx-activities', 'name' => 'Activities', 'x' => 2, 'y' => 2, 'width' => 2, 'height' => 2],
                ],
            ],
        ];
    }

    private function dashletsOptions(): stdClass
    {
        return (object) [
            'rx-unfilled' => $this->recordsDashlet('Unfilled shifts', 'RxShift', 'unfilled', 'dateStart'),
            'rx-at-risk' => $this->recordsDashlet('Shifts at risk', 'RxShift', 'atRisk', 'dateStart'),
            'rx-action' => $this->recordsDashlet(
                'Candidates needing action', 'RxCandidate', 'actionRequired', 'nextCredentialExpiry'
            ),
        ];
    }

    private function recordsDashlet(string $title, string $entityType, string $filter, string $sortBy): stdClass
    {
        return (object) [
            'title' => $title,
            'entityType' => $entityType,
            'primaryFilter' => $filter,
            'sortBy' => $sortBy,
            'sortDirection' => 'asc',
            'displayRecords' => 10,
            'expandedLayout' => (object) [
                'rows' => [[(object) ['name' => 'name', 'link' => true]]],
            ],
        ];
    }

    private function seedRoles(EntityManager $entityManager): void
    {
        foreach ($this->roles() as $name => $role) {
            $exists = $entityManager->getRDBRepository('Role')->where(['name' => $name])->findOne();

            if (!$exists) {
                $entityManager->createEntity('Role', ['name' => $name, ...$role]);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function roles(): array
    {
        $teamRecord = $this->level('yes', 'team', 'team', 'no', 'team');
        $activity = $this->level('yes', 'team', 'team', 'own', 'team');
        $clientRecord = $this->level('yes', 'all', 'team', 'no', 'team');
        $readAll = $this->level('no', 'all', 'no', 'no', 'all');
        $full = $this->level('yes', 'all', 'all', 'all', 'all');

        $shared = [
            'Task' => $activity,
            'Call' => $activity,
            'Meeting' => $activity,
            'Email' => $this->level('yes', 'team', 'own', 'own'),
            'Document' => $this->level('yes', 'team', 'team', 'own'),
            'Calendar' => true,
            'Activities' => true,
        ];

        return [
            'Recruiter' => [
                'data' => (object) [
                    ...$shared,
                    ...array_fill_keys(self::RECRUITMENT_SCOPES, $teamRecord),
                    'RxShift' => $this->level('yes', 'team', 'team', 'no'),
                    'Account' => $clientRecord,
                    'Contact' => $clientRecord,
                ],
                // A recruiter could otherwise lift a requirement to get a shift confirmed.
                'fieldData' => (object) [
                    'Account' => (object) ['rxRequiredCredentials' => $this->readOnlyField()],
                    'RxJobOrder' => (object) ['requiredCredentials' => $this->readOnlyField()],
                ],
                ...$this->permissions(assignment: 'team', export: 'no', privacy: 'no', audit: 'no'),
            ],
            'Compliance officer' => [
                'data' => (object) [
                    ...$shared,
                    ...array_fill_keys(self::RECRUITMENT_SCOPES, $readAll),
                    'RxShift' => $this->level('no', 'all', 'no', 'no'),
                    'RxCredential' => $this->level('yes', 'all', 'all', 'no'),
                    'Account' => $readAll,
                    'Contact' => $readAll,
                ],
                ...$this->permissions(assignment: 'all', export: 'no', privacy: 'yes', audit: 'yes'),
            ],
            'Agency manager' => [
                'data' => (object) [
                    ...$shared,
                    ...array_fill_keys(self::RECRUITMENT_SCOPES, $full),
                    'RxShift' => $this->level('yes', 'all', 'all', 'all'),
                    'RxCredential' => $this->level('yes', 'all', 'all', 'all'),
                    'Account' => $full,
                    'Contact' => $full,
                ],
                ...$this->permissions(assignment: 'all', export: 'yes', privacy: 'yes', audit: 'yes'),
            ],
        ];
    }

    private function level(string $create, string $read, string $edit, string $delete, ?string $stream = null): stdClass
    {
        $level = ['create' => $create, 'read' => $read, 'edit' => $edit, 'delete' => $delete];

        return (object) ($stream ? [...$level, 'stream' => $stream] : $level);
    }

    private function readOnlyField(): stdClass
    {
        return (object) ['read' => 'yes', 'edit' => 'no'];
    }

    /**
     * @return array<string, string>
     */
    private function permissions(string $assignment, string $export, string $privacy, string $audit): array
    {
        return [
            'assignmentPermission' => $assignment,
            'userPermission' => 'team',
            'messagePermission' => 'team',
            'mentionPermission' => 'team',
            'userCalendarPermission' => 'team',
            'portalPermission' => 'no',
            'groupEmailAccountPermission' => 'no',
            'followerManagementPermission' => 'no',
            'lockPermission' => 'no',
            'massUpdatePermission' => 'yes',
            'exportPermission' => $export,
            'dataPrivacyPermission' => $privacy,
            'auditPermission' => $audit,
        ];
    }
}
