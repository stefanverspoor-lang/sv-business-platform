<?php

namespace App\Support\Permissions;

/**
 * De rollen uit de briefing, met hun standaardrechten per module.
 *
 * De superadmin is geen rol maar een vlag op de gebruiker (is_superadmin)
 * en heeft altijd volledige toegang tot beide bedrijven.
 * Rollen gelden per bedrijf: iemand kan bij SV supervisor zijn en bij YES
 * callcenter medewerker.
 */
final class Roles
{
    public const COMPANY_MANAGER = 'company_manager';
    public const SUPERVISOR = 'supervisor';
    public const CALLCENTER = 'callcenter_agent';
    public const DATA_ENTRY = 'data_entry_agent';
    public const PROJECT = 'project_member';
    public const ADMINISTRATION = 'administration';

    /** Alle rechten die het systeem kent, per module. */
    public const PERMISSIONS = [
        'clients.view_all',   // alle klanten zien (anders alleen toegewezen klanten)
        'clients.view',
        'clients.create',
        'clients.update',
        'clients.delete',
        'users.manage',       // gebruikers van het eigen bedrijf beheren
        'audit.view',
    ];

    /** @return array<string, array<int, string>> */
    public static function defaults(): array
    {
        return [
            self::COMPANY_MANAGER => self::PERMISSIONS,
            self::SUPERVISOR => ['clients.view_all', 'clients.view', 'clients.create', 'clients.update'],
            self::CALLCENTER => ['clients.view'],
            self::DATA_ENTRY => ['clients.view'],
            self::PROJECT => ['clients.view'],
            self::ADMINISTRATION => ['clients.view_all', 'clients.view', 'clients.create', 'clients.update'],
        ];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::COMPANY_MANAGER => __('Bedrijfsmanager'),
            self::SUPERVISOR => __('Supervisor'),
            self::CALLCENTER => __('Callcenter medewerker'),
            self::DATA_ENTRY => __('Data Entry medewerker'),
            self::PROJECT => __('Projectmedewerker'),
            self::ADMINISTRATION => __('Administratief medewerker'),
        ];
    }

    public static function label(string $role): string
    {
        return self::labels()[$role] ?? $role;
    }
}
