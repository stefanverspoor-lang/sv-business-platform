<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateSuperadmin extends Command
{
    protected $signature = 'crm:create-superadmin';

    protected $description = 'Superadmin-account aanmaken (volledige toegang tot beide bedrijven)';

    public function handle(): int
    {
        $name = text('Naam', required: true);
        $email = text('E-mailadres', required: true, validate: fn ($v) => Validator::make(['e' => $v], ['e' => 'email|unique:central.users,email'])->errors()->first('e') ?: null);
        $pass = password('Wachtwoord (minimaal 12 tekens, hoofd- en kleine letters en een cijfer)', required: true,
            validate: fn ($v) => Validator::make(['p' => $v], ['p' => Password::defaults()])->errors()->first('p') ?: null);

        $user = new User(['name' => $name, 'email' => $email, 'password' => $pass, 'locale' => 'nl']);
        $user->is_superadmin = true;
        $user->save();
        $user->companies()->sync(Company::pluck('id'));

        $this->components->info("Superadmin {$email} aangemaakt. Bij de eerste keer inloggen stel je tweestapsverificatie in.");

        return self::SUCCESS;
    }
}
