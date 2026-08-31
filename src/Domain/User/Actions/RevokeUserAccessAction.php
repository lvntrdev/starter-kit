<?php

namespace Lvntr\StarterKit\Domain\User\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Lvntr\StarterKit\Domain\Shared\Actions\BaseAction;
use Lvntr\StarterKit\Http\Middleware\EnsureUserIsActive;
use Throwable;

/**
 * Drops every credential the kit issues for a user whose status just moved
 * INTO a denied value.
 *
 * EnsureUserIsActive already cuts an open request the moment the account
 * behind it is disabled, but it can only act on a request that actually passes
 * through the `web`/`api` middleware groups. This action closes the rest:
 *
 *   - an OAuth access token used against a route the consumer mounted outside
 *     those groups;
 *   - a refresh token, which survives the access token's own lifetime and can
 *     mint a new one long after the account was disabled;
 *   - an unredeemed authorization / device code, which can still be EXCHANGED
 *     for a brand new access token after the tokens are revoked — revoking the
 *     tokens alone would leave that window open;
 *   - a database session row, which otherwise stays valid until the session
 *     lifetime expires.
 *
 * ── TRANSITION-ONLY, NEVER PER-SAVE ─────────────────────────────────────────
 *
 * Revocation fires only when the NORMALISED status actually CHANGED and the new
 * value is on the operator's deny-list. An admin editing the name of a user who
 * has been inactive for a year triggers nothing: `from === to`, so there is no
 * transition. The one deliberate consequence is that a user disabled BEFORE
 * this release keeps their tokens until they are disabled again — the
 * middleware is what blocks them in the meantime, and revoking on every write
 * to an already-disabled account is exactly the storm this rule exists to
 * prevent.
 *
 * ── SHARED CONTRACT WITH THE MIDDLEWARE ─────────────────────────────────────
 *
 * "Denied" is not decided here. Both the enforcement switch
 * (`starter-kit.security.enforce_active_status`) and the deny-list
 * (`starter-kit.security.active_status_denied`) are read through
 * EnsureUserIsActive so the two halves of the feature can never disagree about
 * which status means "cut this account off" — including the stale-published-
 * config fallbacks. The kill switch therefore disables revocation too: an
 * operator whose `status` column means something the kit does not understand
 * turns the WHOLE behaviour off with one key, and never gets a surprise mass
 * token revocation from it.
 *
 * ── FAILURE POSTURE ─────────────────────────────────────────────────────────
 *
 * Every step is independently guarded and NEVER throws into the caller: the
 * status change itself has already committed, and turning an admin's
 * "deactivate user" into a 500 would hide that. A step that cannot run is
 * logged (counts only) and the remaining steps still run. The middleware
 * remains the backstop for anything that failed here.
 *
 * @see EnsureUserIsActive
 */
class RevokeUserAccessAction extends BaseAction
{
    /**
     * Upper bound on ids per `whereIn` batch. Only relevant for an account with
     * an unusual number of live tokens; keeps a single statement from growing
     * past what MySQL's max_allowed_packet will take.
     */
    private const ID_CHUNK = 500;

    /**
     * Revoke the user's credentials when this status change is a transition
     * into a denied status.
     *
     * Both status arguments are RAW attribute values (string, enum, null …);
     * normalisation is the middleware's, so `Inactive` and `inactive` are the
     * same transition here and in the request pipeline.
     *
     * @param  mixed  $fromStatus  status BEFORE the write
     * @param  mixed  $toStatus  status AFTER the write
     * @return bool whether revocation was scheduled
     */
    public function execute(Authenticatable $user, mixed $fromStatus, mixed $toStatus): bool
    {
        if (! EnsureUserIsActive::enforcementEnabled()) {
            return false;
        }

        $denied = EnsureUserIsActive::deniedStatuses();

        if ($denied === []) {
            return false;
        }

        $from = EnsureUserIsActive::normalizeStatus($fromStatus);
        $to = EnsureUserIsActive::normalizeStatus($toStatus);

        // Not landing on a denied status: nothing to cut.
        if ($to === null || ! in_array($to, $denied, true)) {
            return false;
        }

        // Same status before and after — an unrelated edit, not a transition.
        if ($from === $to) {
            return false;
        }

        // A rolled-back update must not revoke a live token, so the work is
        // deferred to the real COMMIT of whatever transaction encloses the
        // caller. With no open transaction Laravel's manager runs the callback
        // immediately.
        $this->afterCommit($user, function () use ($user, $from, $to): void {
            $this->revoke($user, $from, $to);
        });

        return true;
    }

    /**
     * Register the revocation on the user's own connection.
     *
     * If the connection has no transaction manager (an app that swapped the
     * database manager out) afterCommit() throws. Falling back to running the
     * callback inline is the fail-CLOSED direction — the credentials go away
     * even if the surrounding write is later rolled back, which leaves a user
     * logged out rather than leaving a disabled account with a live token.
     */
    private function afterCommit(Authenticatable $user, callable $callback): void
    {
        $connection = $user instanceof Model ? $user->getConnectionName() : null;

        try {
            DB::connection($connection)->afterCommit($callback);
        } catch (Throwable $e) {
            Log::warning('starter-kit: could not defer credential revocation to the transaction commit; revoking inline.', [
                'user_id' => $this->identifierFor($user),
                'reason' => $e->getMessage(),
            ]);

            $callback();
        }
    }

    /**
     * Perform the revocation and emit exactly one structured line.
     *
     * The line carries counts and the two status values only. No token id, no
     * token value, no session id ever reaches the log.
     */
    private function revoke(Authenticatable $user, ?string $from, ?string $to): void
    {
        $counts = $this->revokePassportCredentials($user);
        $counts['sessions'] = $this->purgeDatabaseSessions($user);

        Log::info('starter-kit: user status moved to a denied value; credentials revoked.', [
            'user_id' => $this->identifierFor($user),
            'from_status' => $from,
            'to_status' => $to,
        ] + $counts);
    }

    // ─── Passport ───────────────────────────────────────────────────────────

    /**
     * Revoke access tokens, their refresh tokens, and any code that could still
     * be exchanged for a new token.
     *
     * Access tokens are resolved through the user's own `tokens()` relation
     * (Passport resolves the model, the table and the provider scope from
     * config — all three are consumer-configurable and must never be hardcoded
     * here). The relation is provider-scoped, so a token minted for a DIFFERENT
     * auth provider that happens to share this identifier is deliberately left
     * alone; it does not authenticate this user's guard.
     *
     * @return array{access_tokens: ?int, refresh_tokens: ?int, auth_codes: ?int, device_codes: ?int}
     */
    private function revokePassportCredentials(Authenticatable $user): array
    {
        $counts = [
            'access_tokens' => null,
            'refresh_tokens' => null,
            'auth_codes' => null,
            'device_codes' => null,
        ];

        if (! class_exists(Passport::class)) {
            return $counts;
        }

        $ids = $this->liveTokenIds($user);

        if ($ids !== null) {
            $counts['access_tokens'] = 0;
            $counts['refresh_tokens'] = 0;

            foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
                $counts['access_tokens'] += (int) $this->guardedUpdate(
                    'access tokens',
                    $user,
                    fn (): int => Passport::token()->newQuery()
                        ->whereKey($chunk)
                        ->where('revoked', false)
                        ->update(['revoked' => true]),
                );

                $counts['refresh_tokens'] += (int) $this->guardedUpdate(
                    'refresh tokens',
                    $user,
                    fn (): int => Passport::refreshToken()->newQuery()
                        ->whereIn('access_token_id', $chunk)
                        ->where('revoked', false)
                        ->update(['revoked' => true]),
                );
            }
        }

        $identifier = $this->identifierFor($user);

        if ($identifier !== null) {
            $clientIds = $this->clientIdsForProvider($user);

            $counts['auth_codes'] = $this->guardedUpdate(
                'authorization codes',
                $user,
                fn (): int => $this->scopeToProvider(Passport::authCode()->newQuery(), $clientIds)
                    ->where('user_id', $identifier)
                    ->where('revoked', false)
                    ->update(['revoked' => true]),
            );

            if (method_exists(Passport::class, 'deviceCode')) {
                $counts['device_codes'] = $this->guardedUpdate(
                    'device codes',
                    $user,
                    fn (): int => $this->scopeToProvider(Passport::deviceCode()->newQuery(), $clientIds)
                        ->where('user_id', $identifier)
                        ->where('revoked', false)
                        ->update(['revoked' => true]),
                );
            }
        }

        return $counts;
    }

    /**
     * Ids of the OAuth clients that belong to this user's auth provider, or
     * null when the provider cannot be determined.
     *
     * `$user->tokens()` scopes itself this way — a token only counts when its
     * client carries the user's provider (or none at all, for the default
     * guard). The authorization- and device-code tables have no such relation
     * helper, so two applications whose users share an identifier across
     * providers would revoke each other's codes without this.
     *
     * A user model without Passport's `getProviderName()` cannot be scoped;
     * that returns null and the queries stay unscoped, which is the pre-existing
     * behaviour and is correct on the single-provider installs the kit ships.
     *
     * @return list<string>|null
     */
    private function clientIdsForProvider(Authenticatable $user): ?array
    {
        if (! method_exists($user, 'getProviderName')) {
            return null;
        }

        try {
            $provider = $user->getProviderName();

            return Passport::client()->newQuery()
                ->where(fn ($query) => $query->where('provider', $provider)->orWhereNull('provider'))
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();
        } catch (Throwable $e) {
            Log::warning('starter-kit: the OAuth clients of a user provider could not be read; code revocation stays unscoped.', [
                'user_id' => $this->identifierFor($user),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Narrow a code query to this user's provider when the clients are known.
     *
     * @param  list<string>|null  $clientIds
     */
    private function scopeToProvider(Builder $query, ?array $clientIds): Builder
    {
        return $clientIds === null ? $query : $query->whereIn('client_id', $clientIds);
    }

    /**
     * Ids of the tokens to revoke, or null when the relation cannot be read.
     *
     * `tokens()` needs HasApiTokens AND a passport guard whose provider matches
     * this model — Passport throws a LogicException when it cannot map the two.
     * An install without the oauth tables throws too. Both mean "no Passport
     * credential exists for this account", so they are logged and skipped, not
     * propagated into the admin's request.
     *
     * @return list<string>|null
     */
    private function liveTokenIds(Authenticatable $user): ?array
    {
        if (! method_exists($user, 'tokens')) {
            return null;
        }

        try {
            /** @var list<string> $ids */
            $ids = $user->tokens()
                ->where('revoked', false)
                ->pluck('id')
                ->all();

            return $ids;
        } catch (Throwable $e) {
            Log::warning('starter-kit: could not read the user\'s OAuth tokens while revoking access.', [
                'user_id' => $this->identifierFor($user),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Run one revocation statement, returning null when it could not run.
     */
    private function guardedUpdate(string $what, Authenticatable $user, callable $statement): ?int
    {
        try {
            return (int) $statement();
        } catch (Throwable $e) {
            Log::warning('starter-kit: a credential class could not be revoked after a user status change.', [
                'user_id' => $this->identifierFor($user),
                'credential' => $what,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ─── Sessions ───────────────────────────────────────────────────────────

    /**
     * Delete the user's rows from the session table.
     *
     * ONLY the `database` driver is touched. A file/redis/cookie session store
     * has no index from user to session, so there is nothing to delete without
     * scanning the whole store — those drivers are covered by EnsureUserIsActive
     * on the next request instead.
     *
     * Every session of the account goes, including the one belonging to the
     * admin performing the change when an admin disables their own account.
     */
    private function purgeDatabaseSessions(Authenticatable $user): ?int
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        $identifier = $this->identifierFor($user);

        if ($identifier === null) {
            return null;
        }

        $connection = config('session.connection');
        $table = config('session.table');
        $table = is_string($table) && $table !== '' ? $table : 'sessions';

        try {
            if (! Schema::connection($connection)->hasTable($table)) {
                return null;
            }

            return DB::connection($connection)
                ->table($table)
                ->where('user_id', $identifier)
                ->delete();
        } catch (Throwable $e) {
            Log::warning('starter-kit: database sessions could not be purged after a user status change.', [
                'user_id' => $this->identifierFor($user),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * The user's primary identifier, or null when it cannot be read.
     */
    private function identifierFor(Authenticatable $user): int|string|null
    {
        try {
            $identifier = $user->getAuthIdentifier();
        } catch (Throwable) {
            return null;
        }

        return is_int($identifier) || is_string($identifier) ? $identifier : null;
    }
}
