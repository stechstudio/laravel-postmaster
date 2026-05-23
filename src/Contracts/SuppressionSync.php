<?php

namespace STS\Postmaster\Contracts;

/**
 * Bridges Postmaster's local suppression list with the provider's own — so a
 * suppression cleared in (say) SendGrid's dashboard flows back into our
 * email_addresses table on the next sync, and an unsuppress action in our
 * dashboard flows out to the provider's list.
 *
 * Each provider implements this against its own SDK; the SDK is a soft
 * dependency (suggested in composer.json) and isAvailable() reports whether
 * it's installed and configured.
 */
interface SuppressionSync
{
    /**
     * Whether this sync is ready to run — its provider SDK is installed and
     * its API key (or equivalent credential) is configured. Sync commands
     * skip providers that report false and log a hint.
     *
     * @return bool
     */
    public function isAvailable();

    /**
     * The provider's current suppression list — emitted as one entry per
     * suppressed address, lazily, so a million-row provider list doesn't
     * have to live in memory all at once.
     *
     * Each yielded array has at least:
     *   - 'address' (string, lower-cased)
     *   - 'reason'  (string, normalized to one of EmailAddress::REASON_*)
     *   - 'suppressed_at' (\DateTimeInterface|null)
     *
     * @return iterable<int, array{address: string, reason: string, suppressed_at: \DateTimeInterface|null}>
     */
    public function pull();

    /**
     * Remove an address from the provider's suppression list. Called when an
     * operator unsuppresses through the dashboard or via Postmaster::unsuppress().
     * Returns true on success, false if the address wasn't on the list (or
     * was already removed); throws on a real API error.
     *
     * @param string $address Lower-cased.
     *
     * @return bool
     */
    public function unsuppress( $address );
}
