<?php

namespace App\Modules\Connectors\Mail365;

use App\Modules\Connectors\Core\ConnectorConfig;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\MailboxListHeader;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Symfony Mailer transport sending through Microsoft Graph with the
 * organisme's microsoft365_mail configuration (see TenantMailer).
 *
 * The From address is always the configured mailbox (Graph sends as it);
 * its name is the configured from_name, else the message's. Reply-To and
 * all recipients are kept: those of the envelope that are neither To nor Cc
 * travel as Bcc. Up to GraphMailClient::MIME_LIMIT once encoded, the MIME
 * message goes as is; larger messages go as a draft with upload sessions.
 */
final class GraphTransport extends AbstractTransport
{
    public function __construct(private readonly GraphMailClient $client, private readonly ConnectorConfig $config)
    {
        parent::__construct();
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($message instanceof Message) {
            $message = clone $message;
            $envelope ??= Envelope::create($message);
            $headers = $message->getHeaders();
            $current = $headers->get('From');
            $name = (string) ($this->config->settings['from_name'] ?? '');
            if ($name === '' && $current instanceof MailboxListHeader) {
                $name = ($current->getAddresses()[0] ?? null)?->getName() ?? '';
            }
            $from = new Address(GraphMailClient::mailbox($this->config), $name);
            $headers->remove('From');
            $headers->remove('Sender');
            $headers->addMailboxListHeader('From', [$from]);
            $envelope = new Envelope($from, $envelope->getRecipients());
        }

        return parent::send($message, $envelope);
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        $hidden = $this->hiddenRecipients($original, $message->getEnvelope()->getRecipients());
        $mime = $message->toString();

        if (4 * (int) ceil(strlen($mime) / 3) <= GraphMailClient::MIME_LIMIT || ! $original instanceof Email) {
            if ($hidden !== []) {
                $mime = (new MailboxListHeader('Bcc', $hidden))->toString()."\r\n".$mime;
            }
            $this->client->sendMime($this->config, $mime);

            return;
        }

        $email = clone $original;
        $email->bcc(...$hidden);
        $this->client->sendLarge($this->config, $email);
    }

    /**
     * Recipients of the envelope that are neither To nor Cc (the Bcc header
     * is dropped from the MIME message): Graph reads recipients from the
     * message, so they are given back as Bcc.
     *
     * @param  list<Address>  $recipients
     * @return list<Address>
     */
    private function hiddenRecipients(RawMessage $message, array $recipients): array
    {
        if (! $message instanceof Message) {
            return [];
        }
        $visible = [];
        foreach (['To', 'Cc'] as $name) {
            $header = $message->getHeaders()->get($name);
            foreach ($header instanceof MailboxListHeader ? $header->getAddresses() : [] as $address) {
                $visible[strtolower($address->getAddress())] = true;
            }
        }

        return array_values(array_filter($recipients, fn (Address $a): bool => ! isset($visible[strtolower($a->getAddress())])));
    }

    public function __toString(): string
    {
        return 'microsoft-graph';
    }
}
