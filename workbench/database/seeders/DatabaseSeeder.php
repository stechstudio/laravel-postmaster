<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Models\EmailActivity;
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
