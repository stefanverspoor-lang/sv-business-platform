# SV Business Platform

Intern CRM- en bedrijfsplatform voor **SV Business Support** en **YES Marketing Solutions**.
Draait op `https://crm.svbusinesssupport.com` (Hostinger Premium, gedeelde hosting).

## Techniek

| Onderdeel | Keuze |
| --- | --- |
| Taal en framework | PHP 8.3+, Laravel 13 |
| Beheerscherm | Filament 5 (Livewire), geen npm/Vite nodig op de server |
| Databases | MySQL, drie stuks: `central`, `company_sv`, `company_yes` |
| Rechten | spatie/laravel-permission met teams (team = bedrijf) |
| Inloggen | Verplichte tweestapsverificatie (TOTP) voor iedereen |
| Talen | Nederlands en Engels (`lang/en.json`, Filament-vertalingen) |

## Bedrijfsscheiding

- De centrale database bevat alleen gebruikers, bedrijven, rollen, rechten en de audit-log.
- Elk bedrijf heeft een **eigen database met een eigen databasegebruiker**.
- Bedrijfsmodellen (`Client`, `Contact`, ...) gebruiken de verbinding `tenant`
  (trait `BelongsToCompanyDatabase`). `App\Support\Tenancy\TenantManager` koppelt die
  verbinding per verzoek aan de database van het gekozen bedrijf.
- Zonder actief bedrijf wijst `tenant` naar een lege in-memory database: er kunnen dan
  geen bedrijfsgegevens worden gelezen.
- Migraties voor bedrijfsdatabases staan in `database/migrations/company` en draaien via
  `php artisan crm:migrate-companies` op beide databases.

## Rollen

Superadmin (vlag op de gebruiker, alles in beide bedrijven), Bedrijfsmanager, Supervisor,
Callcenter medewerker, Data Entry medewerker, Projectmedewerker, Administratief medewerker.
Rollen gelden per bedrijf. Rechten per module staan in `App\Support\Permissions\Roles`.
Toegang per klant loopt via `access_grants` (in de bedrijfsdatabase).

## Commando's

| Commando | Wat het doet |
| --- | --- |
| `php artisan crm:install --force` | Migraties (centraal + bedrijven), bedrijven, rollen en rechten. Veilig herhaalbaar |
| `php artisan crm:create-superadmin` | Superadmin-account aanmaken |
| `php artisan crm:migrate-companies` | Alleen de bedrijfsdatabases bijwerken |
| `php artisan crm:backup` | Back-up van alle drie de databases (ook dagelijks gepland) |
| `bash deploy.sh` | Nieuwe versie op de server zetten |

## Mappen op de server

```
~/domains/svbusinesssupport.com/
├── public_html/            website (niet in deze repository)
│   └── crm -> ../crm-app/public   (symbolische link)
├── crm-app/                deze repository + .env
└── crm-backups/            back-ups
```

## Tests

```
php artisan test
```

De tests gebruiken drie SQLite-bestanden in `database/testing/` en controleren onder meer
dat gegevens van SV en YES nooit door elkaar lopen.

## Status

- Fase 0 (inrichting Hostinger): afgerond
- Fase 1 (fundament): in deze versie
