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
| `php artisan crm:create-superadmin e-mail Voornaam Achternaam` | Superadmin aanmaken met een tijdelijk wachtwoord |
| `php artisan crm:migrate-companies` | Alleen de bedrijfsdatabases bijwerken |
| `php artisan crm:backup` | Back-up van alle drie de databases (ook dagelijks gepland) |
| `php artisan crm:reset-data` | Testgegevens wissen: klanten, leads, dossiers, projecten, uren, offertes, facturen, uitgaven (met back-up vooraf). Mailboxen, e-mail, instellingen, btw-tarieven en bankrekeningen blijven. Factuurnummers beginnen weer bij 0001 |
| `php artisan crm:retention --dry-run` | Laat zien wat de AVG-bewaartermijnen zouden opruimen (zonder `--dry-run`: uitvoeren; gebeurt ook dagelijks) |
| `bash deploy.sh` | Nieuwe versie op de server zetten |

## Mappen op de server

```
~/domains/svbusinesssupport.com/
├── public_html/            website (niet in deze repository)
│   └── crm -> ../crm-app/public   (symbolische link)
├── crm-app/                deze repository + .env
    └── storage/app/backups/   back-ups (laatste 30 per database)
```

## Tests

```
vendor/bin/phpunit
```

De tests gebruiken drie SQLite-bestanden in `database/testing/` en controleren onder meer
dat gegevens van SV en YES nooit door elkaar lopen.

## E-mail

- Mailboxen voeg je toe onder **Beheer > Mailboxen** (per bedrijf). Standaard ingesteld op
  Hostinger: IMAP `imap.hostinger.com:993` (SSL) en SMTP `smtp.hostinger.com:465` (SSL).
- Het wachtwoord van de mailbox wordt versleuteld opgeslagen met de `APP_KEY`.
- `crm:tick` haalt elke 5 minuten nieuwe mail op (Postvak IN en Verzonden), maximaal 25
  berichten per map per keer. Berichten worden in de mailbox zelf niet als gelezen gemarkeerd.
- Mail van een bekend e-mailadres (klant of contactpersoon) wordt automatisch gekoppeld en
  toegewezen aan de accountmanager; anders aan de standaardpersoon van de mailbox.
- Vanuit het CRM verstuurde mail gaat via SMTP en komt ook in de map Verzonden te staan.
- HTML-mail wordt opgeschoond (geen scripts, formulieren of externe afbeeldingen) en in een
  afgeschermd kader getoond.

- Handtekening per mailbox (tekst met {naam} en {email}, plus logo), per bericht uit te zetten.
- Spam wordt ook opgehaald en staat in het tabblad Spam ("Geen spam" zet hem terug).
- E-mail verwijderen (los of in bulk), standaard ook uit de mailbox (naar de prullenbak).

## Taken

- Herinneringen verschijnen als melding (belletje rechtsboven), verstuurd door `crm:tick`.
- Herhalende taken: na afronden wordt de volgende automatisch aangemaakt.

## Telemarketing en pre-incasso

- Opdrachtgevers zijn gewone klanten. Campagnes (telemarketing) en dossiers (pre-incasso)
  horen altijd bij één opdrachtgever.
- Import via Excel (.xlsx) of CSV, met voorbeeldbestanden. Kolommen worden op naam herkend
  (NL en EN); onbekende kolommen worden als extra informatie bewaard. Dubbele leads (zelfde
  nummer in de campagne) en dossiers (zelfde dossiernummer bij de opdrachtgever) worden
  overgeslagen. Elk importbestand staat in het importlogboek.
- Telefoonnummers worden omgezet naar internationaal formaat (`App\Support\Calling\PhoneNumber`).
- **Belscherm**: wachtrij per medewerker (terugbelafspraken eerst, dan nooit gebeld, dan het
  langst geleden). Het gekozen record wordt 15 minuten vergrendeld. BEL NU is een `tel:`-link
  (opent het standaard belprogramma, CloudTalk Desktop); "Via CloudTalk" gebruikt `ct+tel:`.
- Gespreksresultaten zijn per bedrijf aan te passen (Beheer). Standaardresultaten kun je
  uitzetten, niet verwijderen.
- Betalingsafspraken worden alleen vastgelegd; het CRM markeert nooit zelf iets als betaald.
  Pas de betaalimport van de opdrachtgever bepaalt of een dossier volledig betaald is
  (afgesproken bedrag, of het openstaande bedrag zonder afspraak). Deelbetalingen tellen niet
  als geslaagd. Dan pas wordt de commissie berekend volgens het commissiemodel van de
  opdrachtgever dat gold op de betaaldatum.
- Rapportages per opdrachtgever, campagne, medewerker en periode; export naar Excel en mailen
  vanuit het CRM.

## Websiteprojecten, data-entry en uren

- Websiteprojecten per klant met de statussen uit de briefing, domeinnamen, hosting,
  onderhoudsafspraken, doorlopende kosten per maand, wijzigingsverzoeken en support, taken,
  documenten, e-mail en uren. Websites blijven op de hosting van de klant.
- Inloggegevens van domein en hosting staan versleuteld in de database (`encrypted`-cast met
  de `APP_KEY`), komen nooit terug in een formulier en zijn alleen te zien via
  "Inloggegevens" (recht `credentials.view`); elke keer bekijken staat in de audit-log.
- 30 dagen voor het verlopen van een domeinnaam komt er een melding (via `crm:tick`).
- Data-entry-opdrachten met medewerkers, aantal records, werkinstructies, bestanden en
  oplevering. Voortgang (records, fouten, correcties) wordt per medewerker met de uren
  geregistreerd. De prijs komt uit "Diensten en prijzen" van de klant.
- Urenregistratie voor iedereen; eigen uren zijn 14 dagen zelf aan te passen.
- Rapportages: productiviteit data-entry en urenoverzicht, per medewerker en periode.

## Facturatie en financiën

- Meerdere bankrekeningen (Facturatie → Bankrekeningen), per factuur te kiezen; bij een
  valuta wordt de rekening in die valuta voorgesteld. Betaalgegevens (rekeninghouder, bank,
  IBAN/rekeningnummer, BIC/SWIFT, extra regels) staan op de factuur.
- Factuur met alleen "Geen btw": geen btw-kolom, geen subtotaal/btw-regels en geen
  btw-nummers, alleen het nettobedrag.

- Offertes, facturen en creditnota's per bedrijf, in het Nederlands of Engels en in elke valuta
  (EUR, IDR, USD ...), met de wisselkoers naar de basisvaluta vastgelegd per document.
- Btw per regel met instelbare tarieven (ook 0%, vrijgesteld, verlegd en "geen btw"); de
  tekst die op de factuur moet (bijv. "Btw verlegd") komt er automatisch op.
- Een factuur is een concept tot "definitief maken" of "versturen". Dan pas krijgt hij een
  nummer (doorlopend per jaar, zonder gaten), worden klant- en bedrijfsgegevens vastgelegd en
  wordt de PDF bewaard. Daarna wijzigt hij niet meer; corrigeren gaat met een creditnota,
  die automatisch wordt verrekend met wat er nog open stond.
- PDF met dompdf (geen externe programma's nodig, werkt op Hostinger).
- Versturen per e-mail vanuit het CRM met de PDF als bijlage; handmatige betalingen.
- Terugkerende facturen (bijv. onderhoud per maand), dagelijks gemaakt door `crm:tick`:
  als concept, definitief of meteen gemaild.
- Nog niet gefactureerde uren, data-entry-records en pre-incassocommissie zet je met één knop
  op een conceptfactuur; ze kunnen niet twee keer gefactureerd worden.
- Uitgaven en inkoopfacturen (met bon), overige inkomsten, categorieën, wisselkoersen.
- Financieel overzicht: omzet, kosten, resultaat, btw-saldo, openstaande posten, per klant en
  per maand; exports naar Excel en CSV voor de accountant. Geen vervanging van een
  wettelijke boekhouding.

## Dashboard, rapportages en AVG

- Dashboard per bedrijf: jouw taken, ongelezen e-mail en belwachtrij; omzet, uitgaven,
  openstaande en achterstallige facturen, klanten en projecten; belresultaten van deze maand;
  grafiek omzet/kosten over 12 maanden; te laat betaalde facturen en je eigen taken. Wat je
  ziet hangt af van je rechten.
- Totaaloverzicht (alleen superadmin): alle bedrijven naast elkaar met totalen. Elk bedrijf
  wordt los uit zijn eigen database gelezen; er worden geen gegevens samengevoegd.
- Rapportagecentrum: pre-incasso, telemarketing, data-entry, uren, klanten, diensten en
  campagnes, medewerkers en (superadmin) bedrijven, over elke periode. Exporteren naar Excel,
  CSV of PDF en handmatig mailen met het rapport als bijlage.
- Snelheid: samengestelde indexen op de wachtrij-, rapport- en dashboardvelden; getest met
  50.000 leads, 30.000 dossiers en 100.000 belpogingen (wachtrij < 0,2 s, rapporten < 0,4 s).
- Beveiliging: beveiligingsheaders (o.a. geen inbedden in andere sites, geen MIME-sniffing,
  HSTS), verplichte tweestapsverificatie, rechten per rol en per bedrijf, CSV-exports beveiligd
  tegen formule-injectie, geen API.
- AVG: knop "Anonimiseren (AVG)" bij een lead of dossier (wist naam, nummers, e-mail, adres en
  notities; cijfers blijven). Bewaartermijnen in `config/crm.php` (aan te passen via `.env`):
  afgeronde leads na 12 maanden, afgesloten dossiers na 24 maanden geanonimiseerd,
  importbestanden na 90 dagen en rapport-exports na 30 dagen verwijderd, audit-log na 24
  maanden ingekort. Bij "niet meer bellen" blijft alleen het telefoonnummer bewaard.

## Status

- Fase 0 (inrichting Hostinger): afgerond
- Fase 1 (fundament): afgerond
- Fase 2 (klantprofiel, documenten, taken, e-mail): afgerond
- Fase 3 (telemarketing en pre-incasso): afgerond
- Fase 4 (websiteprojecten, data-entry, urenregistratie): afgerond
- Fase 5 (facturatie en financiën): afgerond
- Fase 6 (dashboard, rapportages, snelheid, beveiliging en AVG): in deze versie
