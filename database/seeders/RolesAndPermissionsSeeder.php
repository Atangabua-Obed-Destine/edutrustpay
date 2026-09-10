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
        'Institution' => ['view', 'create', 'update', 'delete'],
        'Credential' => ['view', 'issue', 'rotate', 'revoke'],
        'Capability' => ['view', 'declare'],
        'Report' => ['view', 'export'],
        'Finding' => ['view', 'resolve'],
        'Consolidation' => ['view', 'export'],
        'User' => ['view', 'invite', 'update', 'deactivate'],
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
            'display' => 'Body Administrator',
            'description' => 'Runs the body: institutions, users, credentials and settings.',
            'permissions' => ['*'],
        ],
        'bursary_head' => [
            'display' => 'Bursary Head',
            'description' => 'The body\'s finance lead. Sees everything financial and resolves findings.',
            'permissions' => [
                'body.view', 'institution.view', 'capability.view',
                'report.view', 'report.export',
                'finding.view', 'finding.resolve',
                'consolidation.view', 'consolidation.export',
                'credential.view', 'audit.view',
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
            'description' => 'Read-only across the whole body, including findings and the audit trail.',
            'permissions' => [
                'body.view', 'institution.view', 'capability.view',
                'report.view', 'report.export',
                'finding.view',
                'consolidation.view', 'consolidation.export',
                'credential.view', 'audit.view', 'user.view',
            ],
        ],
        'institution_liaison' => [
            /*
             * Scoped to a single institution by users.institution_id. Sees their
             * own institution's reports and findings and nothing else — not the
             * consolidated position, and not their neighbours.
             */
            'display' => 'Institution Liaison',
            'description' => 'One institution only: its reports, its findings, its reporting health.',
            'permissions' => ['institution.view', 'capability.view', 'report.view', 'finding.view'],
        ],
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
                        'display_name' => Str::headline($action).' '.$group,
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
