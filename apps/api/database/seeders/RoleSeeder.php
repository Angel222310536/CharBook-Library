<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ===== 20+ permisos granulares =====
        $permissions = [
            // Contenido
            'books.view', 'books.create', 'books.update', 'books.delete',
            'categories.manage', 'authors.manage',

            // Usuarios
            'users.view', 'users.suspend', 'users.ban', 'users.assign_roles',
            'users.manage', // legacy compatibility con seeder anterior

            // Moderación
            'reports.view', 'reports.resolve',
            'content.hide', 'content.delete',

            // Creator program
            'creator_applications.review',
            'creator_works.review',
            'creator_works.publish',

            // Comunidades
            'salons.approve', 'salons.moderate',

            // Sistema
            'permissions.manage',
            'finance.view', 'finance.manage',
            'system.config', 'system.logs',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // ===== 5 roles =====

        // SUPER ADMIN: todo
        $super = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $super->syncPermissions($permissions);

        // ADMIN: todo excepto manage permissions + system
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions(array_diff($permissions, [
            'permissions.manage',
            'system.config',
            'system.logs',
        ]));

        // MODERATOR: solo moderación
        $moderator = Role::firstOrCreate(['name' => 'moderator', 'guard_name' => 'web']);
        $moderator->syncPermissions([
            'books.view',
            'users.view', 'users.suspend',
            'reports.view', 'reports.resolve',
            'content.hide', 'content.delete',
            'creator_applications.review',
            'creator_works.review',
            'salons.moderate',
        ]);

        // CREATOR: como reader + publicar obras
        $creator = Role::firstOrCreate(['name' => 'creator', 'guard_name' => 'web']);
        $creator->syncPermissions([
            'books.view',
        ]);

        // READER: base
        $reader = Role::firstOrCreate(['name' => 'reader', 'guard_name' => 'web']);
        $reader->syncPermissions(['books.view']);

        $this->command->info('5 roles + ' . count($permissions) . ' permisos sincronizados.');
    }
}
