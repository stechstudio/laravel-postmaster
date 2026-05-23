<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Models\EmailMessageEvent;
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
            EmailEvent::EVENT_DELIVERED, EmailEvent::EVENT_DELIVERED, EmailEvent::EVENT_DELIVERED,
            EmailEvent::EVENT_OPENED, EmailEvent::EVENT_OPENED, EmailEvent::EVENT_CLICKED,
            EmailEvent::EVENT_SENT, EmailEvent::EVENT_DEFERRED,
            EmailEvent::EVENT_BOUNCED, EmailEvent::EVENT_COMPLAINED,
        ];
        $names   = ['alice', 'bob', 'carol', 'dave', 'erin', 'frank', 'grace', 'heidi', 'ivan', 'judy'];
        $domains = ['example.com', 'acme.test', 'mail.dev', 'fastmail.example'];

        $tenantIds = collect(['Acme Corp', 'Globex', 'Initech', 'Umbrella Co'])
            ->map(fn ($name) => Tenant::create(['name' => $name])->getKey())
            ->all();

        foreach (range(1, 90) as $i) {
            $sentAt = now()->subDays(rand(0, 13))->subMinutes(rand(0, 1439));
            $status = $statuses[array_rand($statuses)];
            $subject = $subjects[array_rand($subjects)];
            $recipient = $names[array_rand($names)].rand(1, 99).'@'.$domains[array_rand($domains)];
            $isBounce = $status === EmailEvent::EVENT_BOUNCED;

            $message = EmailMessage::create([
                'provider'      => $providers[array_rand($providers)],
                'provider_message_id'    => 'wb-'.$i.'-'.bin2hex(random_bytes(4)),
                'to_address'     => $recipient,
                'subject'       => $subject,
                'from_address'  => 'hello@acme.test',
                'status'        => $status,
                'bounce_type'   => $isBounce ? EmailEvent::BOUNCE_HARD : null,
                'tenant_id'     => $tenantIds[array_rand($tenantIds)],
                'sent_at'       => $sentAt,
                'last_event_at' => $status === EmailEvent::EVENT_SENT ? null : $sentAt->copy()->addMinutes(rand(2, 240)),
                'tags'          => $this->tagsFor($subject),
                'html_body'     => $this->messageBody($i),
                'created_at'    => $sentAt,
                'updated_at'    => $sentAt,
            ]);

            $timeline = [[EmailEvent::EVENT_SENT, $sentAt]];

            if ($status !== EmailEvent::EVENT_SENT) {
                $timeline[] = [
                    $isBounce ? EmailEvent::EVENT_BOUNCED : EmailEvent::EVENT_DELIVERED,
                    $sentAt->copy()->addMinutes(3),
                ];
            }

            if (in_array($status, [EmailEvent::EVENT_OPENED, EmailEvent::EVENT_CLICKED], true)) {
                $timeline[] = [$status, $sentAt->copy()->addMinutes(rand(20, 200))];
            }

            foreach ($timeline as [$eventStatus, $occurredAt]) {
                EmailMessageEvent::create([
                    'email_message_id' => $message->getKey(),
                    'provider'         => $message->provider,
                    'status'           => $eventStatus,
                    'bounce_type'      => $eventStatus === EmailEvent::EVENT_BOUNCED ? EmailEvent::BOUNCE_HARD : null,
                    'occurred_at'      => $occurredAt,
                    'created_at'       => $occurredAt,
                ]);
            }
        }

        $this->seedAddresses($names, $domains);
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
        foreach (range(1, 28) as $i) {
            $suppressed = $i % 4 === 0;

            EmailAddress::create([
                'address'       => $names[array_rand($names)].$i.'@'.$domains[array_rand($domains)],
                'status'        => $suppressed ? EmailAddress::STATUS_SUPPRESSED : EmailAddress::STATUS_ACTIVE,
                'reason'        => $suppressed ? [EmailEvent::EVENT_BOUNCED, EmailEvent::EVENT_COMPLAINED][rand(0, 1)] : null,
                'suppressed_at' => $suppressed ? now()->subDays(rand(0, 13)) : null,
                'last_event_at' => now()->subDays(rand(0, 13)),
            ]);
        }
    }
}
