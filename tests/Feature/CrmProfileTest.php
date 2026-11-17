<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Document;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Task;
use App\Support\Mail\HtmlCleaner;
use App\Support\Mail\MessageStore;
use App\Support\TaskReminders;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 2: klantprofiel, taken, documenten en e-mail.
 */
class CrmProfileTest extends TestCase
{
    public function test_new_tables_live_only_in_the_company_database(): void
    {
        $this->inCompany($this->sv);
        $client = Client::create(['name' => 'SV-klant']);
        Task::create(['title' => 'Bellen', 'taskable_type' => 'client', 'taskable_id' => $client->id]);
        Activity::record($client, \App\Enums\ActivityType::Note, 'Notitie');

        foreach (['tasks', 'activities'] as $table) {
            $this->assertSame(1, DB::connection('company_sv')->table($table)->count(), $table);
            $this->assertSame(0, DB::connection('company_yes')->table($table)->count(), $table);
        }
        $this->assertFalse(DB::connection('central')->getSchemaBuilder()->hasTable('tasks'));
        $this->assertFalse(DB::connection('central')->getSchemaBuilder()->hasTable('email_messages'));
    }

    public function test_completing_a_recurring_task_creates_the_next_one_once(): void
    {
        $this->inCompany($this->sv);
        $due = Carbon::parse('2026-01-31 10:00');
        $task = Task::create(['title' => 'Maandrapport', 'recurrence' => 'monthly', 'due_at' => $due, 'remind_at' => $due->copy()->subHour()]);

        $task->update(['status' => TaskStatus::Done]);
        $this->assertNotNull($task->fresh()->completed_at);

        $next = Task::where('recurring_parent_id', $task->id)->sole();
        $this->assertSame(TaskStatus::New, $next->status);
        $this->assertSame('2026-02-28 10:00', $next->due_at->format('Y-m-d H:i'), 'geen overloop naar maart');
        $this->assertSame('2026-02-28 09:00', $next->remind_at->format('Y-m-d H:i'));

        // Heropenen en opnieuw afronden: geen tweede kopie.
        $task->update(['status' => TaskStatus::InProgress]);
        $this->assertNull($task->fresh()->completed_at);
        $task->update(['status' => TaskStatus::Done]);
        $this->assertSame(1, Task::where('recurring_parent_id', $task->id)->count());
    }

    public function test_users_without_tasks_view_all_only_see_their_own_tasks(): void
    {
        $agent = $this->makeUser(companyRoles: ['sv' => $this->companyRole('CALLCENTER')]);
        $manager = $this->makeUser(companyRoles: ['sv' => $this->companyRole('COMPANY_MANAGER')]);

        $this->actingAs($agent);
        $this->inCompany($this->sv);
        Task::create(['title' => 'Van agent', 'assigned_to' => $agent->id]);
        Task::create(['title' => 'Van manager', 'assigned_to' => $manager->id]);

        $this->assertSame(['Van agent'], Task::visibleTo($agent)->pluck('title')->all());

        $this->actingAs($manager);
        $this->inCompany($this->sv);
        $this->assertSame(2, Task::visibleTo($manager)->count());
    }

    public function test_task_reminder_is_sent_once(): void
    {
        $user = $this->makeUser(companyRoles: ['sv' => $this->companyRole('CALLCENTER')]);
        $this->inCompany($this->sv);
        $task = Task::create(['title' => 'Herinner mij', 'assigned_to' => $user->id, 'remind_at' => now()->subMinute()]);

        app(TaskReminders::class)->send($this->sv);
        app(TaskReminders::class)->send($this->sv);

        $this->assertNotNull($task->fresh()->reminded_at);
        $this->assertSame(1, DB::connection('central')->table('notifications')->where('notifiable_id', $user->id)->count());
    }

    public function test_documents_follow_the_visibility_of_their_client(): void
    {
        $agent = $this->makeUser(companyRoles: ['sv' => $this->companyRole('CALLCENTER')]);
        $this->inCompany($this->sv);
        $own = Client::create(['name' => 'Eigen klant', 'account_manager_id' => $agent->id]);
        $other = Client::create(['name' => 'Andere klant']);
        $doc = fn (Client $c) => $c->documents()->create(['disk' => 'local', 'path' => 'x', 'original_name' => 'x.pdf', 'title' => 'x', 'category' => 'other', 'size' => 1]);

        $this->assertTrue($agent->can('view', $doc($own)));
        $this->assertFalse($agent->can('view', $doc($other)));
    }

    public function test_incoming_email_is_linked_to_the_client_and_assigned_to_its_account_manager(): void
    {
        $manager = $this->makeUser(companyRoles: ['sv' => $this->companyRole('COMPANY_MANAGER')]);
        $this->inCompany($this->sv);
        $mailbox = Mailbox::create(['name' => 'Info', 'email' => 'info@svbusinesssupport.com', 'username' => 'info@svbusinesssupport.com', 'password' => 'geheim']);
        $client = Client::create(['name' => 'Klant BV', 'account_manager_id' => $manager->id]);
        $contact = $client->contacts()->create(['first_name' => 'Anna', 'last_name' => 'Smit', 'email' => 'anna@klant.nl']);

        $email = app(MessageStore::class)->storeSent($mailbox, [
            'direction' => 'in', 'is_read' => false, 'subject' => 'Vraag', 'from_email' => 'anna@klant.nl',
            'to' => [['email' => 'info@svbusinesssupport.com', 'name' => null]], 'body_text' => 'Hallo',
        ]);
        app(MessageStore::class)->autoLink($email, $mailbox);

        $this->assertTrue($client->emails()->whereKey($email->id)->exists());
        $this->assertTrue($contact->emails()->whereKey($email->id)->exists());
        $this->assertSame($manager->id, $email->fresh()->assigned_to);
        $this->assertSame(1, Activity::where('client_id', $client->id)->where('type', 'email_in')->count());
        $this->assertNotSame('geheim', DB::connection('company_sv')->table('mailboxes')->value('password'));
    }

    public function test_email_visibility_without_emails_view_all(): void
    {
        $agent = $this->makeUser(companyRoles: ['sv' => $this->companyRole('CALLCENTER')]);
        $this->inCompany($this->sv);
        $mailbox = Mailbox::create(['name' => 'Info', 'email' => 'info@svbusinesssupport.com', 'username' => 'x', 'password' => 'x']);
        $base = ['mailbox_id' => $mailbox->id, 'folder' => 'INBOX', 'direction' => 'in', 'thread_key' => 't'];
        EmailMessage::create($base + ['uid' => 1, 'subject' => 'Voor agent', 'assigned_to' => $agent->id]);
        EmailMessage::create($base + ['uid' => 2, 'subject' => 'Niet voor agent']);

        $this->assertSame(['Voor agent'], EmailMessage::visibleTo($agent)->pluck('subject')->all());
    }

    public function test_email_html_is_cleaned(): void
    {
        $html = HtmlCleaner::clean('<p onclick="x()">Hoi</p><script>alert(1)</script><img src="https://track.example/p.gif"><a href="javascript:alert(1)">x</a><form><input></form>');

        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('track.example', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('Hoi', $html);
    }

    public function test_thread_key_ignores_reply_prefixes(): void
    {
        $this->assertSame(
            EmailMessage::threadKeyFor('Offerte', ['a@b.nl']),
            EmailMessage::threadKeyFor('Re: AW: Offerte', ['a@b.nl']),
        );
    }
}
