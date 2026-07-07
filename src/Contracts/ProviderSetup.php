<?php

namespace STS\Postmaster\Contracts;

/**
 * Everything the interactive CLI commands need to know about one provider's
 * setup — the human-facing language, the env vars, and the interactive prompts
 * — kept next to that provider rather than branched inside the commands. Both
 * postmaster:install and postmaster:verify resolve these via Postmaster::setup().
 */
interface ProviderSetup
{
    /** The provider's config key, e.g. "sendgrid". */
    public function name(): string;

    /** The display name shown in pickers, e.g. "SendGrid". */
    public function label(): string;

    /**
     * Mail transport names that map directly to this provider (e.g. SES owns
     * both "ses" and "ses-v2"). Used to detect the provider from mail config.
     *
     * @return array<int, string>
     */
    public function transportNames(): array;

    /**
     * Substrings that identify this provider from an SMTP host — the clue when
     * mail goes out over SMTP rather than the provider's API transport.
     *
     * @return array<int, string>
     */
    public function smtpHints(): array;

    /** How to phrase pointing the provider at the webhook URL. */
    public function webhookVerb(): string;

    /**
     * Interactively collect this provider's webhook-auth env vars, prompting as
     * needed. Returns the [ENV => value] pairs to write — empty when the
     * provider needs no operator-supplied credential (SES).
     *
     * @return array<string, string>
     */
    public function askWebhookAuth(): array;

    /**
     * Whether the credential this provider needs to authenticate inbound
     * webhooks is currently configured. True for providers that need none
     * (SES verifies SNS signatures against AWS's certs). Used by a
     * non-interactive `postmaster:install` to report setup completeness.
     */
    public function webhookAuthConfigured(): bool;

    /** Whether this provider exposes a suppression list worth syncing. */
    public function supportsSuppressionSync(): bool;

    /**
     * Interactively collect this provider's suppression-sync env vars (and emit
     * any SDK / setup notes). Returns the [ENV => value] pairs to write.
     *
     * @return array<string, string>
     */
    public function askSuppressionSync(): array;

    /**
     * Concrete guidance, as bullet lines, for when an inbound webhook fails
     * authorization — which env var carries the credential (and whether it's
     * currently set), and where to find the value in the provider dashboard.
     *
     * @return array<int, string>
     */
    public function authFailureGuidance(): array;
}
