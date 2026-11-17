<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 2 (bedrijfsdatabase): e-mail. Elk bedrijf heeft eigen mailboxen;
 * berichten van SV en YES staan dus ook in gescheiden databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username');
            $table->text('password'); // versleuteld
            $table->string('imap_host')->default('imap.hostinger.com');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 10)->default('ssl');
            $table->string('smtp_host')->default('smtp.hostinger.com');
            $table->unsignedSmallInteger('smtp_port')->default(465);
            $table->string('smtp_encryption', 10)->default('ssl');
            $table->string('sent_folder')->default('INBOX.Sent');
            $table->date('sync_since')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('default_assignee')->nullable();
            $table->json('sync_state')->nullable(); // laatste UID per map
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->string('folder');
            $table->unsignedBigInteger('uid')->nullable();
            $table->string('direction', 3); // in / out
            $table->string('message_id', 500)->nullable()->index();
            $table->string('in_reply_to', 500)->nullable();
            $table->string('thread_key', 64)->index();
            $table->string('subject', 500)->nullable();
            $table->string('from_email')->nullable()->index();
            $table->string('from_name')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->boolean('has_attachments')->default(false);
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->timestamps();
            $table->unique(['mailbox_id', 'folder', 'uid']);
        });

        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        // Koppeling van e-mail aan klant, contactpersoon (later ook lead, project, dossier)
        Schema::create('email_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->constrained()->cascadeOnDelete();
            $table->morphs('linkable');
            $table->boolean('is_automatic')->default(false);
            $table->unsignedBigInteger('linked_by')->nullable();
            $table->timestamps();
            $table->unique(['email_message_id', 'linkable_type', 'linkable_id'], 'email_links_unique');
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('locale', 5)->default('nl');
            $table->string('subject');
            $table->longText('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('email_links');
        Schema::dropIfExists('email_attachments');
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('mailboxes');
    }
};
