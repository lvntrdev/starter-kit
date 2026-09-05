<?php

namespace Lvntr\StarterKit\Domain\User\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Laravel\Passport\TransientToken;
use Lvntr\StarterKit\Domain\User\Actions\RevokeUserAccessAction;
use Throwable;

/**
 * Revokes the OAuth credential PAIR behind the current request.
 *
 * Logging out has to make the credential the caller just used unusable, and an
 * access token alone is not that credential. Passport's password and
 * authorization-code grants hand out an access token AND a refresh token; the
 * refresh token is bound to the access token by `access_token_id` — not to the
 * session, not to the user — and it deliberately OUTLIVES it (Passport's own
 * defaults are one year for the access token and one month for the refresh
 * token, and an install that shortens the access token widens the gap rather
 * than closing it). Revoking only the access token therefore leaves the caller
 * holding a credential that mints a brand new access token on demand, which is
 * the opposite of what "log out" promises.
 *
 * ── WHAT `token()` ACTUALLY RETURNS ─────────────────────────────────────────
 *
 * Not the Eloquent token model. Passport's guard builds an AccessToken value
 * object out of the JWT claims and hands THAT to withAccessToken(); the row id
 * arrives as the `oauth_access_token_id` claim (the JWT `jti`). The cookie
 * guard hands back a TransientToken instead, which has no row and no revoke()
 * at all. Both shapes reach this method, and the Eloquent model is accepted as
 * a third because a user model that declares its own token() may return one.
 * Narrowing to the model alone — the shape the name suggests — would silently
 * revoke NOTHING on a normal bearer-token logout, which is why the branch
 * order below is by claim first and model second.
 *
 * ── ORDER IS DELIBERATE ─────────────────────────────────────────────────────
 *
 * The refresh token is revoked FIRST. If the second statement then fails, the
 * caller is left with an access token that expires on its own. Reversed, the
 * same failure would leave the LONGER-lived half alive with the short-lived
 * half already gone — and no logout the user could repeat to reach it. Fail
 * towards the credential that dies by itself.
 *
 * ── FAILURE POSTURE ─────────────────────────────────────────────────────────
 *
 * This never throws into the request. An install without the Passport package,
 * a user model without HasApiTokens, a caller whose token is transient, and an
 * install whose oauth tables were never migrated each return false — silently,
 * or with one warning line carrying a user id and a reason only. A logout that
 * 500s is a logout the user retries, and the credential stays live meanwhile.
 *
 * @see RevokeUserAccessAction for the account-wide counterpart, which drops
 *      EVERY credential of a user whose status moved to a denied value.
 */
trait RevokesOAuthCredentials
{
    /**
     * Revoke the access token behind this request plus every live refresh
     * token bound to it.
     *
     * @return bool whether a persisted access token was identified and its
     *              revocation ran
     */
    protected function revokeCurrentOAuthCredentials(Authenticatable $user): bool
    {
        if (! class_exists(Passport::class) || ! method_exists($user, 'token')) {
            return false;
        }

        try {
            $token = $user->token();

            if (! is_object($token) || $token instanceof TransientToken) {
                return false;
            }

            $tokenId = $this->persistedAccessTokenId($token);

            if ($tokenId === null) {
                // A shape this method does not know, or a token with no row
                // behind it (Passport::actingAs() in a consumer's test suite).
                // Fall back to the token's own revoke() so behaviour is never
                // WORSE than the single call this replaced.
                return method_exists($token, 'revoke') && (bool) $token->revoke();
            }

            Passport::refreshToken()->newQuery()
                ->where('access_token_id', $tokenId)
                ->where('revoked', false)
                ->update(['revoked' => true]);

            // Passport's own AccessToken::revoke() takes exactly this shortcut
            // when the model is not loaded, so the configured token model,
            // table and connection are all honoured.
            Passport::token()->newQuery()
                ->whereKey($tokenId)
                ->where('revoked', false)
                ->update(['revoked' => true]);

            return true;
        } catch (Throwable $e) {
            Log::warning('starter-kit: the current OAuth credentials could not be revoked on logout.', [
                'user_id' => $this->oauthRevocationUserId($user),
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The `oauth_access_tokens` row id behind the current credential, or null
     * when this credential has no row.
     *
     * Reading `oauth_access_token_id` off an AccessToken is claim-only: when
     * the claim is absent Passport's own accessor returns null WITHOUT going
     * to the database, so an unidentifiable token costs no query.
     */
    private function persistedAccessTokenId(object $token): int|string|null
    {
        $id = match (true) {
            // Passport's guard shape: the JWT `jti` claim.
            $token instanceof AccessToken => $token->oauth_access_token_id,
            // A user model that resolves the row itself.
            $token instanceof Model => $token->getKey(),
            default => null,
        };

        if (is_int($id)) {
            return $id;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The user's primary identifier for the log line, or null when it cannot
     * be read. No token id and no token value ever reaches the log.
     */
    private function oauthRevocationUserId(Authenticatable $user): int|string|null
    {
        try {
            $identifier = $user->getAuthIdentifier();
        } catch (Throwable) {
            return null;
        }

        return is_int($identifier) || is_string($identifier) ? $identifier : null;
    }
}
