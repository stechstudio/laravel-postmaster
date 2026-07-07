<?php

namespace STS\Postmaster\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use STS\Postmaster\Console\Concerns\ResolvesProvider;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

/**
 * An interactive setup wizard. Walks through the small set of decisions a
 * fresh install has to make — which provider, which auth scheme, which
 * optional features — writes the answers to the app's .env, publishes
 * migrations, and (optionally) finishes by running postmaster:verify so
 * the operator knows the end-to-end round trip works before any real mail
 * is sent through it.
 */
class Install extends Command
{
    use ResolvesProvider;

    protected $signature = 'postmaster:install {--provider= : The provider to set up (defaults to auto-detection)}';

    protected $description = 'Walk through Postmaster setup interactively';

    /**
     * Whether the operator opted into suppression sync — drives the "schedule
     * postmaster:sync" line in the closing next-steps summary.
     */
    protected bool $syncConfigured = false;

    public function handle(): int
    {
        // Without a TTY (Laravel Cloud, CI) or with --no-interaction, we can't
        // prompt for credentials — so instead of walking the wizard, report
        // the setup for the already-configured provider and validate it.
        if (! $this->canPrompt()) {
            return $this->reportSetup();
        }

        intro('Postmaster setup');

        $provider = $this->askProvider();
        $setup    = $this->setupFor($provider);
        $url      = $this->webhookUrl($provider);

        // Surface the webhook URL up front so it's on screen while the operator
        // sets up auth in the provider dashboard — and so a run that skips the
        // verify step at the end still leaves them knowing where to point it.
        note(
            $setup->webhookVerb().":\n  ".$url
            ."\n\nDerived from APP_URL — set that to your public URL if it looks wrong."
        );

        $envVars      = $setup->askWebhookAuth();
        $envVars      = array_merge($envVars, $this->askSuppressionSync($provider));
        $persistence  = confirm('Enable the persistence layer? (records every outbound, suppression list, dashboard)', default: true);
        $storeContent = $persistence
            ? confirm('Store message subject and body for the dashboard?', default: false, hint: 'Can include PII or secrets like password-reset links — short retention by default.')
            : false;
        $sandbox      = confirm('Enable sandbox delivery in this environment?', default: false, hint: 'Intercepts every outbound email. Useful for staging.');

        if (! $persistence) {
            $envVars['POSTMASTER_PERSISTENCE'] = 'false';
        }

        if ($storeContent) {
            $envVars['POSTMASTER_STORE_CONTENT'] = 'true';
        }

        if ($sandbox) {
            $envVars['POSTMASTER_DELIVERY'] = 'sandbox';
        }

        if (empty($envVars)) {
            note('No environment variables to write. Defaults are sensible.');
        } else {
            $this->writeEnv($envVars);
        }

        if ($persistence && confirm('Publish and run the persistence migrations now?', default: true)) {
            $this->call('vendor:publish', ['--tag' => 'postmaster.migrations']);
            $this->call('migrate');
        }

        $ranVerify = confirm('Run postmaster:verify to test the round trip end to end?', default: true);

        if ($ranVerify) {
            $this->newLine();
            $this->call('postmaster:verify');
        }

        $this->showNextSteps($provider, $url, $persistence, $storeContent, $ranVerify);

        outro('Postmaster is set up.');

        return self::SUCCESS;
    }

    /**
     * The non-interactive path: no prompts, no .env writes. Reports the setup
     * for the configured provider — the webhook URL to register, how to point
     * the provider at it, whether the webhook-auth credential is in place, and
     * the current feature flags — then lists the remaining manual steps.
     *
     * Reads everything from config/.env (set out-of-band on the platform), so
     * it's safe to run in a deploy step. Fails only when no provider can be
     * determined; a missing webhook credential is surfaced as a warning.
     */
    protected function reportSetup(): int
    {
        $this->components->info('Postmaster setup (non-interactive)');

        $provider = $this->resolveProviderNonInteractively($this->option('provider'));

        if ($provider === null) {
            return self::FAILURE;
        }

        $setup = $this->setupFor($provider);
        $url   = $this->webhookUrl($provider);

        $this->newLine();
        $this->components->twoColumnDetail('Provider', $setup->label());
        $this->components->twoColumnDetail('Mail transport', $this->mailTransport() ?: 'unknown');
        $this->components->twoColumnDetail('Persistence', config('postmaster.persistence.enabled') ? 'enabled' : 'disabled');
        $this->components->twoColumnDetail('Content storage', config('postmaster.persistence.store_content') ? 'enabled' : 'disabled');
        $this->components->twoColumnDetail('Delivery', (string) config('postmaster.delivery', 'normal'));
        $this->components->twoColumnDetail(
            'Webhook auth',
            $setup->webhookAuthConfigured() ? '<fg=green>configured</>' : '<fg=yellow>not configured</>'
        );
        $this->newLine();

        $this->line('  <options=bold>'.$setup->webhookVerb().':</>');
        $this->line("    {$url}");
        $this->line('  <fg=gray>Derived from APP_URL — set that to your public URL if it looks wrong.</>');

        if ($this->looksLocal($url)) {
            $this->components->warn(
                'That webhook URL points at a local/unreachable address. Set APP_URL to your public URL '
                .'so the provider can reach it.'
            );
        }

        if (! $setup->webhookAuthConfigured()) {
            $this->newLine();
            $this->components->warn('The webhook auth credential is not set — inbound webhooks will be rejected until it is:');
            $this->components->bulletList($setup->authFailureGuidance());
        }

        $this->newLine();
        $this->components->bulletList($this->nextSteps($setup->label(), $setup->supportsSuppressionSync()));

        return self::SUCCESS;
    }

    /**
     * The remaining manual steps for a non-interactive setup.
     *
     * @return array<int, string>
     */
    protected function nextSteps(string $providerLabel, bool $supportsSync): array
    {
        $steps = [];

        if (config('postmaster.persistence.enabled')) {
            $steps[] = 'Run `php artisan migrate` — the persistence migrations ship with the package.';
            $steps[] = 'Schedule `postmaster:prune` (e.g. daily) to age out old activity'
                .(config('postmaster.persistence.store_content') ? ' and stored content' : '').'.';
        }

        if ($supportsSync) {
            $steps[] = "Schedule `postmaster:sync` (e.g. daily) to mirror {$providerLabel}'s suppression list.";
        }

        $steps[] = 'Register the webhook URL above in your provider dashboard.';
        $steps[] = 'Run `postmaster:verify --to=you@example.com` to test the round trip end to end.';

        return $steps;
    }

    /**
     * A short, tailored checklist of what's left to do — the webhook URL to
     * register, the commands worth scheduling, and the verify run if it was
     * skipped. Only lines that apply to this install are shown.
     */
    protected function showNextSteps(string $provider, string $url, bool $persistence, bool $storeContent, bool $ranVerify): void
    {
        $steps = [$this->setupFor($provider)->webhookVerb().':  '.$url];

        if ($this->syncConfigured) {
            $steps[] = "Schedule `postmaster:sync` (e.g. daily) to keep the suppression list in step with {$provider}.";
        }

        if ($persistence) {
            $steps[] = 'Schedule `postmaster:prune` (e.g. daily) to age out old activity'
                .($storeContent ? ' and stored content' : '').'.';
        }

        if (! $ranVerify) {
            $steps[] = 'Run `postmaster:verify` to test the round trip once your webhook URL is reachable.';
        }

        note("Next steps:\n  · ".implode("\n  · ", $steps));
    }

    /**
     * Which provider this app will send mail through. Pre-selects the one
     * Laravel's current mail config points at, when we can detect it.
     */
    protected function askProvider(): string
    {
        $detected = $this->detectProvider();

        $options = [];

        foreach ($this->providerSetups() as $name => $setup) {
            $options[$name] = $setup->label();
        }

        return select(
            label: 'Which mail provider will you use?',
            options: $options,
            default: $detected,
            hint: $detected ? "Detected {$detected} from your mail config." : '',
        );
    }

    /**
     * Optional: suppression sync. The provider's authoritative suppression list
     * is reconciled against ours when `postmaster:sync` runs. The provider's
     * setup profile knows whether it has a list to sync and which credentials
     * it needs; this only owns the shared confirm + bookkeeping.
     *
     * @return array<string, string>
     */
    protected function askSuppressionSync(string $provider): array
    {
        $setup = $this->setupFor($provider);

        if (! $setup->supportsSuppressionSync()) {
            note($setup->label().' has no suppression-list API, so there is nothing to sync. Skipping.');

            return [];
        }

        if (! confirm(
            'Set up suppression sync now?',
            default: true,
            hint: 'Keeps Postmaster\'s suppression list in step with the provider\'s. Run "postmaster:sync" yourself (e.g. on a daily schedule).',
        )) {
            return [];
        }

        $this->syncConfigured = true;

        return $setup->askSuppressionSync();
    }

    /**
     * Update the app's .env file with the answers — preserving comments,
     * order, and any non-Postmaster lines. Existing keys are overwritten in
     * place; new keys are appended at the end. When a previous install left
     * POSTMASTER_ entries behind that this run doesn't touch (e.g. a prior
     * provider's auth credentials), the wizard offers to remove them so the
     * file stays tidy. Non-POSTMASTER_ lines are never modified. The
     * previous file is backed up to .env.backup beforehand.
     *
     * @param array<string, string> $vars
     */
    protected function writeEnv(array $vars): void
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            warning("No .env file found at {$path}. The following variables need to be set manually:");
            foreach ($vars as $key => $value) {
                $this->components->twoColumnDetail($key, $this->shorten($value));
            }
            return;
        }

        info('The following will be written to .env:');

        foreach ($vars as $key => $value) {
            $this->components->twoColumnDetail($key, $this->shorten($value));
        }

        if (! confirm('Write them?', default: true)) {
            warning('Skipped — nothing written to .env. The credentials above are not saved, so webhook auth (and the verify step) will fail until you add them yourself.');
            return;
        }

        copy($path, $path.'.backup');

        $contents = file_get_contents($path);
        $lines    = preg_split('/\R/', $contents);

        // Look for POSTMASTER_ keys this install isn't overwriting — likely
        // leftovers from an earlier run with different choices. Offer (don't
        // force) to clear them; some operators set extras by hand.
        $orphans = array_diff_key($this->existingPostmasterKeys($lines), $vars);

        if (! empty($orphans) && confirm(
            'Found '.count($orphans).' POSTMASTER_ '.Str::plural('entry', count($orphans))
                .' already in .env that this install will not overwrite. Remove '
                .(count($orphans) === 1 ? 'it' : 'them').'?',
            default: false,
            hint: implode(', ', array_keys($orphans)),
        )) {
            foreach ($orphans as $index) {
                unset($lines[$index]);
            }

            // Reindex so the replace-or-append loop's indices stay valid.
            $lines = array_values($lines);
        }

        foreach ($vars as $key => $value) {
            $written  = false;
            $rendered = $key.'='.$this->escape($value);

            foreach ($lines as $index => $line) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $line)) {
                    $lines[$index] = $rendered;
                    $written = true;
                    break;
                }
            }

            if (! $written) {
                $lines[] = $rendered;
            }
        }

        file_put_contents($path, implode("\n", $lines));

        info(".env updated. Previous version backed up to .env.backup.");
    }

    /**
     * The POSTMASTER_ keys currently set in the given .env lines, mapped to
     * their line index. Commented-out lines (those starting with `#`) are
     * ignored — the operator left them off on purpose.
     *
     * @param array<int, string> $lines
     *
     * @return array<string, int>
     */
    protected function existingPostmasterKeys(array $lines): array
    {
        $found = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(POSTMASTER_[A-Z0-9_]+)\s*=/', $line, $matches)) {
                $found[$matches[1]] = $index;
            }
        }

        return $found;
    }

    /**
     * Quote and escape an .env value when it has whitespace or quote chars.
     */
    protected function escape(string $value): string
    {
        if ($value === '' || preg_match('/\s|"|#|\\\\/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    /**
     * Shorten a secret for display so we don't echo it whole.
     */
    protected function shorten(string $value): string
    {
        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }

        return str_repeat('•', strlen($value) - 4).substr($value, -4);
    }
}
