<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Maakt een superadmin met een willekeurig tijdelijk wachtwoord.
 * Zonder vragen in de terminal, omdat Hostinger de functies daarvoor uitzet.
 * Wijzig het wachtwoord na de eerste login via Profiel.
 */
class CreateSuperadmin extends Command
{
    protected $signature = 'crm:create-superadmin {email : E-mailadres} {name* : Naam}';

    protected $description = 'Superadmin-account aanmaken (volledige toegang tot beide bedrijven)';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $name = trim(implode(' ', $this->argument('name')));

        $validator = Validator::make(['email' => $email, 'name' => $name], [
            'email' => 'required|email|unique:central.users,email',
            'name' => 'required|max:255',
        ]);
        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        // 20 tekens met hoofd- en kleine letters, cijfers en een leesteken
        $password = Str::password(20, symbols: false).'!7aA';

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password, 'locale' => 'nl']);
        $user->is_superadmin = true;
        $user->save();
        $user->companies()->sync(Company::pluck('id'));

        $this->components->info("Superadmin {$email} aangemaakt.");
        $this->line('  Tijdelijk wachtwoord: <fg=yellow;options=bold>'.$password.'</>');
        $this->line('  Log in op het CRM, stel tweestapsverificatie in en kies daarna via Profiel een eigen wachtwoord.');

        return self::SUCCESS;
    }
}
