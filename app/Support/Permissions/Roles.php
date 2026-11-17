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
        'contracts.manage',   // contracten en prijsafspraken beheren
        'documents.upload',
        'documents.delete',
        'tasks.view_all',     // alle taken zien (anders alleen eigen taken)
        'tasks.manage',       // taken aan anderen toewijzen en alle taken wijzigen
        'emails.view_all',    // alle e-mail van het bedrijf zien (anders alleen toegewezen)
        'emails.send',
        'mailboxes.manage',
        'templates.manage',
        'users.manage',       // gebruikers van het eigen bedrijf beheren
        'audit.view',
    ];

    /** @return array<string, array<int, string>> */
    public static function defaults(): array
    {
        return [
            self::COMPANY_MANAGER => self::PERMISSIONS,
            self::SUPERVISOR => ['clients.view_all', 'clients.view', 'clients.create', 'clients.update',
                'documents.upload', 'tasks.view_all', 'tasks.manage', 'emails.view_all', 'emails.send', 'templates.manage'],
            self::CALLCENTER => ['clients.view', 'emails.send'],
            self::DATA_ENTRY => ['clients.view'],
            self::PROJECT => ['clients.view', 'documents.upload', 'emails.send'],
            self::ADMINISTRATION => ['clients.view_all', 'clients.view', 'clients.create', 'clients.update',
                'contracts.manage', 'documents.upload', 'emails.view_all', 'emails.send', 'templates.manage'],
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
