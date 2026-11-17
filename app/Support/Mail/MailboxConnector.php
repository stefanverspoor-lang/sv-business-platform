<?php

namespace App\Support\Mail;

use App\Models\Mailbox;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

/**
 * Maakt de IMAP-verbinding met een mailbox (zonder PHP-IMAP-extensie,
 * via een gewone versleutelde socketverbinding).
 */
class MailboxConnector
{
    public function connect(Mailbox $mailbox): Client
    {
        $manager = new ClientManager([
            'options' => [
                'fetch' => IMAP::FT_PEEK,          // niet automatisch als gelezen markeren
                'fetch_order' => 'asc',
                'soft_fail' => true,
                'rfc822' => true,
                'debug' => false,
            ],
        ]);

        $client = $manager->make([
            'host' => $mailbox->imap_host,
            'port' => $mailbox->imap_port,
            'encryption' => $mailbox->imap_encryption === 'none' ? false : $mailbox->imap_encryption,
            'validate_cert' => $mailbox->imap_encryption !== 'none',
            'username' => $mailbox->username,
            'password' => $mailbox->password,
            'protocol' => 'imap',
            'timeout' => 30,
        ]);

        $client->connect();

        return $client;
    }
}
