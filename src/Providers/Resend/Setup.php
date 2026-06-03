<?php

namespace STS\Postmaster\Providers\Resend;

use STS\Postmaster\Providers\AbstractProviderSetup;

use function Laravel\Prompts\note;
use function Laravel\Prompts\password;

class Setup extends AbstractProviderSetup
{
    public function name(): string
    {
        return 'resend';
    }

    public function label(): string
    {
        return 'Resend';
    }

    public function transportNames(): array
    {
        return ['resend'];
    }

    public function smtpHints(): array
    {
        return ['resend.com'];
    }

    public function supportsSuppressionSync(): bool
    {
        // Resend has no suppression-list API, so there's nothing to reconcile.
        return false;
    }

    public function askWebhookAuth(): array
    {
        note('Resend signs webhooks via Svix. Find the signing secret in your Resend Webhooks dashboard — it starts with "whsec_".');

        return ['POSTMASTER_RESEND_SIGNING_SECRET' => password(
            label: 'Resend webhook signing secret',
            placeholder: 'whsec_...',
            required: true,
            validate: fn ($v) => str_starts_with($v, 'whsec_') ? null : 'Resend signing secrets start with "whsec_".',
        )];
    }

    public function authFailureGuidance(): array
    {
        return [
            'Resend signs webhooks (Svix) with a whsec_ secret. POSTMASTER_RESEND_SIGNING_SECRET '
                .$this->isSet($this->providerConfig('signing_secret')).'.',
            'In Resend: Webhooks → select your endpoint → copy the "Signing Secret" (whsec_...) '
                .'into POSTMASTER_RESEND_SIGNING_SECRET.',
            $this->configClearReminder(),
        ];
    }
}
