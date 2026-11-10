<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Roles and permissions.
 *
 * Permission names are generated from the group map as `kebab-group.action`,
 * which is edutrustshsm's convention — so `@can('finding.resolve')` reads the
 * same way in both codebases.
 *
 * Idempotent: safe to re-run as new permissions are added.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * group => [actions]
     *
     * @var array<string, array<int, string>>
     */
    private array $groups = [
        'Body' => ['view', 'create', 'update', 'delete'],
        'Institution' => ['view', 'create', 'update', 'delete', 'note'],
        'Credential' => ['view', 'issue', 'rotate', 'revoke'],
        'Capability' => ['view', 'declare'],
        'Report' => ['view', 'export'],
        'Finding' => ['view', 'resolve'],
        'Consolidation' => ['view', 'export'],
        'User' => ['view', 'invite', 'update', 'deactivate'],
        'Role' => ['view', 'manage'],
        'Compliance' => ['view', 'manage', 'follow_up', 'waive'],
        // Separate because view reads lines that name people, and compare does not.
        'Daybook' => ['view', 'compare'],
        // Its own permission because the list names people (number, name and post).
        'Staff' => ['view'],
        'Audit' => ['view'],
        'Settings' => ['view', 'update'],
    ];

    /**
     * role => [permission names, or ['*'] for everything]
     *
     * @var array<string, array{display: string, description: string, permissions: array<int, string>}>
     */
    private array $roles = [
        'body_admin' => [
            'display' => 'Administrator',
            'description' => 'Runs this console: its institutions, people, connection keys and settings.',
            'permissions' => ['*'],
        ],
        'bursary_head' => [
            'display' => 'Bursary Head',
            'description' => 'The finance lead. Sees everything about the money and answers what needs looking into.',
            'permissions' => [
                'institution.note',
                'body.view', 'institution.view', 'capability.view',
                'report.view', 'report.export',
                'finding.view', 'finding.resolve',
                'consolidation.view', 'consolidation.export',
                'credential.view', 'audit.view',
                'compliance.view',
                'daybook.view', 'daybook.compare',
                'staff.view',
            ],
        ],
        'education_secretary' => [
            'display' => 'Education Secretary',
            'description' => 'Oversees the schools: their reports and what needs looking into. A starting point you can copy and adjust.',
            'permissions' => [
                'institution.note',
                'body.view', 'institution.view', 'capability.view',
                'report.view', 'report.export',
                'finding.view', 'finding.resolve',
                'consolidation.view', 'consolidation.export',
                'audit.view',
                'daybook.view', 'daybook.compare',
                'staff.view',
                'compliance.view', 'compliance.manage', 'compliance.follow_up', 'compliance.waive',
            ],
        ],
        'auditor' => [
            /*
             * Read-only, but sees EVERYTHING including findings (brief §10).
             *
             * Deliberately has no finding.resolve: an auditor who can close the
             * findings they are auditing is not an auditor. They can see and
             * export; answering a finding belongs to the people accountable for
             * the money.
             */
            'display' => 'Auditor',
            'description' => 'Sees everything, including what needs looking into and the record of who did what, and changes nothing.',
            'permissions' => [
                'body.view', 'institution.view', 'capability.view',
                'report.view', 'report.export',
                'finding.view',
                'consolidation.view', 'consolidation.export',
                'credential.view', 'audit.view', 'user.view',
                'compliance.view',
                'daybook.view', 'daybook.compare',
                'staff.view',
            ],
        ],
        'institution_liaison' => [
            /*
             * Scoped to a single institution by users.institution_id. Sees their
             * own institution's reports and findings and nothing else — not the
             * consolidated position, and not their neighbours.
             */
            'display' => 'Institution Liaison',
            'description' => 'One institution only: its reports, what needs looking into there, and whether it is sending.',
            'permissions' => ['institution.view', 'capability.view', 'report.view', 'finding.view'],
        ],
    ];

    /**
     * What each permission lets a person do, in the words the role form shows.
     *
     * @var array<string, string>
     */
    private array $labels = [
        'body.view' => 'Open this console',
        'body.create' => 'Add parts, such as deaneries',
        'body.update' => 'Change its name and settings',
        'body.delete' => 'Remove parts',
        'institution.view' => 'See institutions and their figures',
        'institution.create' => 'Add institutions',
        'institution.update' => 'Change institutions',
        'institution.delete' => 'Remove institutions',
        'institution.note' => 'Write notes on a school',
        'credential.view' => 'See connection keys',
        'credential.issue' => 'Issue connection keys',
        'credential.rotate' => 'Replace connection keys',
        'credential.revoke' => 'Revoke connection keys',
        'capability.view' => 'See what each system sends',
        'capability.declare' => 'Set what a system is expected to send',
        'report.view' => 'Read monthly reports',
        'report.export' => 'Print or download reports',
        'finding.view' => 'See what needs looking into',
        'finding.resolve' => 'Answer what needs looking into',
        'consolidation.view' => 'See everything added up',
        'consolidation.export' => 'Print or download the totals',
        'user.view' => 'See people',
        'user.invite' => 'Invite people',
        'user.update' => 'Change people\'s roles',
        'user.deactivate' => 'Deactivate people',
        'role.view' => 'See roles',
        'role.manage' => 'Make and change roles',
        'compliance.view' => 'See whether rules are followed',
        'compliance.manage' => 'Set the rules',
        'compliance.follow_up' => 'Record a follow-up on a rule',
        'compliance.waive' => 'Allow an exception to a rule',
        'daybook.view' => 'Read daybooks, names included',
        'daybook.compare' => 'Compare daybooks across institutions',
        'staff.view' => 'See the staff list, names included',
        'audit.view' => 'See who did what',
        'settings.view' => 'See settings',
        'settings.update' => 'Change settings',
    ];

    public function run(): void
    {
        $permissionIds = [];

        foreach ($this->groups as $group => $actions) {
            foreach ($actions as $action) {
                $name = Str::kebab($group).'.'.$action;

                $permission = Permission::updateOrCreate(
                    ['name' => $name],
                    [
                        'display_name' => $this->labels[$name] ?? Str::headline($action).' '.$group,
                        'group_name' => $group,
                    ]
                );

                $permissionIds[$name] = $permission->id;
            }
        }

        foreach ($this->roles as $name => $spec) {
            $role = Role::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $spec['display'],
                    'description' => $spec['description'],
                    'is_system' => true,
                ]
            );

            $ids = $spec['permissions'] === ['*']
                ? array_values($permissionIds)
                : array_values(array_intersect_key($permissionIds, array_flip($spec['permissions'])));

            $role->permissions()->sync($ids);
        }

        $this->command?->info(sprintf(
            'Seeded %d permissions across %d roles.',
            count($permissionIds),
            count($this->roles)
        ));
    }
}
