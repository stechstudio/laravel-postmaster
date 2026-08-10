<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use STS\Postmaster\Attachments\AttachmentStatus;
use STS\Postmaster\Attachments\AttachmentStore;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Models\EmailActivity;
use Symfony\Component\Mime\Email;
use Workbench\App\Models\Tenant;

/**
 * Sample data so the dashboard has something to show under `composer serve`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $providers = ['SendGrid', 'Postmark', 'Mailgun', 'SES', 'Resend'];
        $subjects  = [
            'Your receipt from Acme', 'Welcome to Acme', 'Reset your password',
            'Your order has shipped', 'Weekly digest', 'Invoice #10428',
            'Action required on your account', 'Your trial is ending soon',
        ];
        $statuses = [
            EmailEvent::STATUS_DELIVERED, EmailEvent::STATUS_DELIVERED, EmailEvent::STATUS_DELIVERED,
            EmailEvent::STATUS_OPENED, EmailEvent::STATUS_OPENED, EmailEvent::STATUS_CLICKED,
            EmailEvent::STATUS_SENT, EmailEvent::STATUS_DEFERRED,
            EmailEvent::STATUS_BOUNCED, EmailEvent::STATUS_COMPLAINED,
        ];
        $names   = ['alice', 'bob', 'carol', 'dave', 'erin', 'frank', 'grace', 'heidi', 'ivan', 'judy'];
        $domains = ['example.com', 'acme.test', 'mail.dev', 'fastmail.example'];

        $tenantIds = collect(['Acme Corp', 'Globex', 'Initech', 'Umbrella Co'])
            ->map(fn ($name) => Tenant::create(['name' => $name])->getKey())
            ->all();

        // Seed addresses first so the message timeline activity gets the
        // highest IDs and dominates the overview's "Recent activity" feed
        // (which orders by id DESC). The manual-suppression activity entries
        // are still here — they just sit further down the feed.
        $this->seedAddresses($names, $domains);

        foreach (range(1, 90) as $i) {
            $sentAt    = now()->subDays(rand(0, 13))->subMinutes(rand(0, 1439));
            $status    = $statuses[array_rand($statuses)];
            $subject   = $subjects[array_rand($subjects)];
            $isBounce  = $status === EmailEvent::STATUS_BOUNCED;
            $providerId = 'wb-'.$i.'-'.bin2hex(random_bytes(4));

            // Shared columns across this submission's per-recipient rows.
            $shared = [
                'provider'            => $providers[array_rand($providers)],
                'provider_message_id' => $providerId,
                'subject'             => $subject,
                'from_address'        => 'hello@acme.test',
                'status'              => $status,
                'bounce_type'         => $isBounce ? EmailEvent::BOUNCE_HARD : null,
                'tenant_id'           => $tenantIds[array_rand($tenantIds)],
                'sent_at'             => $sentAt,
                'last_event_at'       => $status === EmailEvent::STATUS_SENT ? null : $sentAt->copy()->addMinutes(rand(2, 240)),
                'tags'                => $this->tagsFor($subject),
                'html_body'           => $this->messageBody($i),
                'created_at'          => $sentAt,
                'updated_at'          => $sentAt,
            ];

            // Roughly 15% of messages get a Cc; ~10% get a Bcc. Most stay
            // single-recipient (the realistic transactional case).
            $envelope = [['to', $names[array_rand($names)].rand(1, 99).'@'.$domains[array_rand($domains)]]];

            if ($i % 7 === 0) {
                $envelope[] = ['cc', $names[array_rand($names)].rand(100, 199).'@'.$domains[array_rand($domains)]];
            }

            if ($i % 11 === 0) {
                $envelope[] = ['bcc', 'audit'.rand(1, 5).'@acme.test'];
            }

            $primary = null;

            foreach ($envelope as [$role, $address]) {
                $row = EmailMessage::create($shared + [
                    'to_address'     => $address,
                    'recipient_role' => $role,
                ]);

                $primary = $primary ?? $row;

                $timeline = [[EmailEvent::STATUS_SENT, $sentAt]];

                if ($status !== EmailEvent::STATUS_SENT) {
                    $timeline[] = [
                        $isBounce ? EmailEvent::STATUS_BOUNCED : EmailEvent::STATUS_DELIVERED,
                        $sentAt->copy()->addMinutes(3),
                    ];
                }

                if (in_array($status, [EmailEvent::STATUS_OPENED, EmailEvent::STATUS_CLICKED], true)) {
                    $timeline[] = [$status, $sentAt->copy()->addMinutes(rand(20, 200))];
                }

                foreach ($timeline as [$eventStatus, $occurredAt]) {
                    EmailActivity::create([
                        'email_message_id' => $row->getKey(),
                        'provider'         => $row->provider,
                        'status'           => $eventStatus,
                        'bounce_type'      => $eventStatus === EmailEvent::STATUS_BOUNCED ? EmailEvent::BOUNCE_HARD : null,
                        'occurred_at'      => $occurredAt,
                        'created_at'       => $occurredAt,
                    ]);
                }
            }
        }

        $this->seedSandboxed();
        $this->seedAttachments();
    }

    /**
     * Messages carrying attachments, so `composer serve` shows the attachment
     * card and the embedded-image preview with real bytes behind them.
     *
     * These go through AttachmentStore rather than writing rows by hand, so
     * the demo data lands on the same content-addressed paths real capture
     * uses — and the pruned / evicted states are produced by the same
     * forget() the prune command calls, rather than faked.
     *
     * Four messages, one per state worth seeing: everything working, an
     * attachment whose bytes were evicted, one too large to have been stored,
     * and an embedded image that has since been pruned.
     */
    protected function seedAttachments(): void
    {
        $store = app(AttachmentStore::class);
        $logo  = (string) file_get_contents(__DIR__.'/../../../resources/images/postmaster-hat.png');

        // 1. The happy path: two real attachments plus an embedded logo, all
        //    still stored. Carries a Cc as well, so the "envelope siblings
        //    share one attachment set" behaviour is visible.
        $message = $this->demoMessage(
            'Invoice #10428 — with attachments',
            $this->bodyWithLogo('Your invoice is attached, along with this month&rsquo;s usage summary.'),
            'alice@example.com',
            'billing@acme.test',
        );

        $email = new Email;
        $email->attach($this->samplePdf('Invoice 10428'), 'invoice-10428.pdf', 'application/pdf');
        $email->attach($this->sampleCsv(), 'usage-summary.csv', 'text/csv');
        $email->embed($logo, 'logo.png', 'image/png');

        $store->store($email, $message->provider_message_id, true);

        // 2. One attachment reclaimed by the disk budget. The row survives, so
        //    the card still reports what the email carried.
        $message = $this->demoMessage(
            'Your statement — attachment evicted',
            $this->bodyWithLogo('Your November statement was attached to this message.'),
            'bob@example.com',
        );

        $email = new Email;
        $email->attach($this->samplePdf('Statement November'), 'statement-november.pdf', 'application/pdf');
        $email->attach($this->sampleCsv(), 'transactions.csv', 'text/csv');
        $email->embed($logo, 'logo.png', 'image/png');

        $store->store($email, $message->provider_message_id, true);

        $store->forget(
            $message->attachments()->where('filename', 'statement-november.pdf')->first(),
            AttachmentStatus::Evicted,
        );

        // 3. Too large to store. Recorded as metadata so the card can say so,
        //    which is the whole point of keeping the row.
        $message = $this->demoMessage(
            'Quarterly report — attachment too large',
            $this->bodyWithLogo('The full quarterly export is attached.'),
            'carol@acme.test',
        );

        // Squeeze the cap so the real oversize path runs — but only around
        // the zip. Sharing the call with the logo would push that over the
        // cap too, turning an oversize-attachment demo into a broken-image
        // one.
        $email = new Email;
        $email->attach(str_repeat('.', 64), 'q4-export.zip', 'application/zip');

        $cap = config('postmaster.persistence.attachments.max_size');
        config(['postmaster.persistence.attachments.max_size' => 32]);
        $store->store($email, $message->provider_message_id, true);
        config(['postmaster.persistence.attachments.max_size' => $cap]);

        $email = new Email;
        $email->embed($logo, 'logo.png', 'image/png');

        $store->store($email, $message->provider_message_id, true);

        // 4. An embedded image whose bytes are gone: the preview says so
        //    rather than leaving a broken icon.
        $message = $this->demoMessage(
            'Acme newsletter — embedded image pruned',
            $this->bodyWithLogo('Here&rsquo;s what shipped at Acme this month.'),
            'dave@mail.dev',
        );

        $email = new Email;
        $email->embed($logo, 'logo.png', 'image/png');

        $store->store($email, $message->provider_message_id, true);

        $store->forget($message->attachments()->first(), AttachmentStatus::Pruned);
    }

    /**
     * One delivered message with a seeded timeline, for the attachment demos.
     * Returns the primary (To) row.
     */
    protected function demoMessage(string $subject, string $body, string $to, ?string $cc = null): EmailMessage
    {
        $sentAt     = now()->subHours(rand(2, 60));
        $providerId = 'wb-att-'.bin2hex(random_bytes(5));

        $shared = [
            'provider'            => 'SendGrid',
            'provider_message_id' => $providerId,
            'subject'             => $subject,
            'from_address'        => 'hello@acme.test',
            'status'              => EmailEvent::STATUS_DELIVERED,
            'sent_at'             => $sentAt,
            'last_event_at'       => $sentAt->copy()->addMinutes(4),
            'tags'                => ['billing'],
            'html_body'           => $body,
            'created_at'          => $sentAt,
            'updated_at'          => $sentAt,
        ];

        $envelope = [['to', $to]];

        if ($cc !== null) {
            $envelope[] = ['cc', $cc];
        }

        $primary = null;

        foreach ($envelope as [$role, $address]) {
            $row = EmailMessage::create($shared + [
                'to_address'     => $address,
                'recipient_role' => $role,
            ]);

            $primary = $primary ?? $row;

            foreach ([[EmailEvent::STATUS_SENT, $sentAt], [EmailEvent::STATUS_DELIVERED, $sentAt->copy()->addMinutes(4)]] as [$status, $at]) {
                EmailActivity::create([
                    'email_message_id' => $row->getKey(),
                    'provider'         => 'SendGrid',
                    'status'           => $status,
                    'occurred_at'      => $at,
                    'created_at'       => $at,
                ]);
            }
        }

        return $primary;
    }

    /**
     * A body that embeds the logo by content id — the reference the preview
     * resolves into an inline data URI.
     */
    protected function bodyWithLogo(string $lead): string
    {
        return '<div style="font-family:sans-serif;font-size:15px;line-height:1.5;color:#111">'
            .'<img src="cid:logo.png" alt="Acme" width="56" style="margin-bottom:12px">'
            .'<p>'.$lead.'</p>'
            .'<p style="color:#888;font-size:13px;margin-top:24px">Sent with Postmaster.</p>'
            .'</div>';
    }

    /**
     * A genuinely valid one-page PDF, so the dashboard's download actually
     * opens instead of handing back a file that only looks like one.
     */
    protected function samplePdf(string $title): string
    {
        $text = 'BT /F1 16 Tf 24 60 Td ('.str_replace(['(', ')'], '', $title).') Tj ET';

        $objects = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            '<</Type/Page/Parent 2 0 R/MediaBox[0 0 320 120]/Contents 4 0 R'
                .'/Resources<</Font<</F1 5 0 R>>>>>>',
            '<</Length '.strlen($text).">>stream\n".$text."\nendstream",
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];

        $pdf     = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $startXref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<</Size ".(count($objects) + 1)."/Root 1 0 R>>\n"
            ."startxref\n".$startXref."\n%%EOF\n";
    }

    protected function sampleCsv(): string
    {
        $rows = ["date,description,quantity,amount"];

        foreach (['API requests', 'Outbound email', 'Storage (GB-months)', 'Support'] as $n => $item) {
            $rows[] = now()->subMonth()->format('Y-m-d').",{$item},".(($n + 1) * 1250).','.number_format(($n + 1) * 12.5, 2);
        }

        return implode("\n", $rows)."\n";
    }

    /**
     * A few sandboxed messages — recorded but never actually sent — so the
     * dashboard's Release action has something to act on under `composer
     * serve`. They carry stored content (Release needs it) and no provider,
     * matching what sandbox delivery produces. One has a Cc so the "release
     * all envelope siblings together" behavior is visible.
     */
    protected function seedSandboxed(): void
    {
        $samples = [
            ['subject' => 'Reset your password',   'to' => 'release-demo@example.com', 'cc' => null],
            ['subject' => 'Your receipt from Acme', 'to' => 'sandbox-alice@acme.test', 'cc' => 'sandbox-cc@acme.test'],
            ['subject' => 'Welcome to Acme',        'to' => 'sandbox-bob@mail.dev',    'cc' => null],
        ];

        foreach ($samples as $n => $sample) {
            $sentAt     = now()->subHours(rand(1, 48));
            $providerId = 'sandboxed-'.\Illuminate\Support\Str::uuid()->toString();

            $shared = [
                'provider'            => null,   // never reached a provider
                'provider_message_id' => $providerId,
                'subject'             => $sample['subject'],
                'from_address'        => 'hello@acme.test',
                'status'              => EmailEvent::STATUS_SANDBOXED,
                'tenant_id'           => null,
                'sent_at'             => $sentAt,
                'last_event_at'       => null,
                'tags'                => $this->tagsFor($sample['subject']),
                'html_body'           => '<p style="font-family:sans-serif">This message was '
                    .'intercepted by sandbox delivery — recorded, but never sent. Use '
                    .'<strong>Release</strong> to send it for real.</p>',
                'created_at'          => $sentAt,
                'updated_at'          => $sentAt,
            ];

            $envelope = [['to', $sample['to']]];

            if ($sample['cc']) {
                $envelope[] = ['cc', $sample['cc']];
            }

            foreach ($envelope as [$role, $address]) {
                $row = EmailMessage::create($shared + [
                    'to_address'     => $address,
                    'recipient_role' => $role,
                ]);

                EmailActivity::create([
                    'email_message_id' => $row->getKey(),
                    'provider'         => null,
                    'status'           => EmailEvent::STATUS_SANDBOXED,
                    'occurred_at'      => $sentAt,
                    'created_at'       => $sentAt,
                ]);
            }
        }
    }

    /**
     * Build a sample HTML body. Roughly every third message carries remote
     * images (a hotlinked photo and logo) so the message preview's
     * "Show images" bar can be seen in action under `composer serve`.
     */
    /**
     * Sample tags for a subject, so the dashboard's tag filter has something
     * to show under `composer serve`.
     *
     * @return array<int, string>
     */
    protected function tagsFor(string $subject): array
    {
        return match ($subject) {
            'Your receipt from Acme'          => ['billing', 'receipt'],
            'Invoice #10428'                  => ['billing'],
            'Welcome to Acme'                 => ['onboarding'],
            'Your trial is ending soon'       => ['onboarding', 'billing'],
            'Reset your password'             => ['security'],
            'Action required on your account' => ['account'],
            'Your order has shipped'          => ['orders'],
            'Weekly digest'                   => ['digest'],
            default                           => [],
        };
    }

    protected function messageBody(int $i): string
    {
        $intro = '<p style="font-family:sans-serif">Hi there — this is a sample message body '
            .'rendered in the dashboard\'s sandboxed preview frame.</p>';

        if ($i % 3 !== 0) {
            return $intro;
        }

        return '<p><img src="https://picsum.photos/seed/postmaster'.$i.'/600/220" alt="" '
            .'width="600" style="max-width:100%;border-radius:8px"></p>'
            .$intro
            .'<p style="font-family:sans-serif;color:#888;font-size:13px;margin-top:24px">'
            .'<img src="https://www.google.com/images/branding/googlelogo/2x/'
            .'googlelogo_color_272x92dp.png" alt="" width="120"><br>Sent with Postmaster.</p>';
    }

    /**
     * @param array<int, string> $names
     * @param array<int, string> $domains
     */
    protected function seedAddresses(array $names, array $domains): void
    {
        // Five suppression-causing providers in our demo data. Most rows
        // get an API-syncable provider (SendGrid, Postmark, etc.); a small
        // share are Resend-only so the dashboard shows the "Manage in
        // Resend" hint instead of an Unsuppress button.
        $providersByIndex = ['SendGrid', 'Postmark', 'Mailgun', 'SES', 'Resend'];

        foreach (range(1, 28) as $i) {
            $suppressed = $i % 4 === 0;
            $address    = $names[array_rand($names)].$i.'@'.$domains[array_rand($domains)];

            // Mix in a couple of manual suppressions so the dashboard's
            // Unsuppress button shows under composer serve even when no
            // provider SDK is installed (manual suppressions don't need
            // an API).
            $manual = $suppressed && $i % 8 === 0;

            $row = EmailAddress::create([
                'address'       => $address,
                'status'        => $suppressed ? EmailAddress::STATUS_SUPPRESSED : EmailAddress::STATUS_ACTIVE,
                'reason'        => match (true) {
                    $manual     => EmailAddress::REASON_MANUAL,
                    $suppressed => [EmailEvent::STATUS_BOUNCED, EmailEvent::STATUS_COMPLAINED][rand(0, 1)],
                    default     => null,
                },
                'providers'     => match (true) {
                    $manual     => null,
                    $suppressed => [$providersByIndex[$i % count($providersByIndex)]],
                    default     => null,
                },
                'suppressed_at' => $suppressed ? now()->subDays(rand(0, 13)) : null,
                'last_event_at' => now()->subDays(rand(0, 13)),
            ]);

            // Mirror the address-level activity entries we'd write in real
            // life — suppressions on manual / sync-driven rows. Bounce-driven
            // ones already get their lifecycle entry written above through
            // the message timeline, so we don't double-log here.
            if ($suppressed && $manual) {
                $row->logActivity([
                    'status'      => \STS\Postmaster\Models\EmailActivity::STATUS_SUPPRESSED,
                    'reason'      => EmailAddress::REASON_MANUAL,
                    'occurred_at' => $row->suppressed_at,
                    'created_at'  => $row->suppressed_at,
                ]);
            }
        }
    }
}
