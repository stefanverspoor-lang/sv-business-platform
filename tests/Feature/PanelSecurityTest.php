<?php

namespace Tests\Feature;

use App\Support\Permissions\Roles;
use Tests\TestCase;

class PanelSecurityTest extends TestCase
{
    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/sv')->assertRedirect('/login');
    }

    public function test_user_without_two_factor_must_set_it_up_first(): void
    {
        $user = $this->makeUser(companyRoles: ['sv' => Roles::SUPERVISOR]);

        $response = $this->actingAs($user)->get('/sv');

        $response->assertRedirect();
        $this->assertStringContainsString('multi-factor', $response->headers->get('Location'));
    }

    public function test_user_cannot_open_the_other_company(): void
    {
        $user = $this->makeUser(companyRoles: ['sv' => Roles::SUPERVISOR]);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->actingAs($user)->get('/yes')->assertNotFound();
    }

    public function test_deactivated_user_has_no_access(): void
    {
        $user = $this->makeUser(['is_active' => false], ['sv' => Roles::SUPERVISOR]);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->actingAs($user)->get('/sv')->assertForbidden();
    }

    public function test_interface_follows_user_language(): void
    {
        $user = $this->makeUser(['locale' => 'en'], ['sv' => Roles::SUPERVISOR]);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->actingAs($user)->get('/sv/clients')->assertOk()->assertSee('Clients');
    }
}
