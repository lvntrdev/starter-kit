<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\LoginUserAction;
use App\Domain\Auth\Actions\LogoutUserAction;
use App\Domain\Auth\Actions\RegisterUserAction;
use App\Domain\Auth\Actions\TwoFactorChallengeAction;
use App\Domain\Auth\DTOs\LoginDTO;
use App\Domain\Auth\DTOs\RegisterDTO;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Requests\Api\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\Admin\User\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LvntR\ApiDock\Attributes\AiExample;
use LvntR\ApiDock\Attributes\AiHint;
use LvntR\ApiDock\Attributes\AiPitfall;
use LvntR\ApiDock\Attributes\AiTool;
use LvntR\ApiDock\Attributes\ApiFeature;

/**
 * API authentication controller.
 *
 * This controller is intentionally thin:
 *   - Validation → FormRequest
 *   - Data mapping → DTO
 *   - Business logic → Action
 */
#[ApiFeature(stability: 'stable')]
class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    #[AiHint('The 201 response has two shapes: when e-mail verification is required it carries `requires_verification: true` and NO `token`; otherwise it carries `user` and `token` and the caller is already logged in.')]
    #[AiPitfall('The route stays registered even when the `auth.registration` setting is off — it deliberately answers 403 in that case rather than 404, so a client can tell "feature disabled" from "wrong URL".', order: 10)]
    #[AiExample(
        name: 'Registration with immediate login',
        request: [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'password' => 'S3cret!2345',
            'password_confirmation' => 'S3cret!2345',
        ],
        response: [
            'success' => true,
            'status' => 201,
            'message' => 'Registration successful.',
            'data' => [
                'user' => [
                    'id' => 42,
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'full_name' => 'Ada Lovelace',
                    'initials' => 'AL',
                    'email' => 'ada@example.com',
                    'status' => 'active',
                    'avatar_url' => null,
                    'timezone' => 'UTC',
                    'email_verified_at' => null,
                    'created_at' => '2026-03-14T08:36:00+03:00',
                    'updated_at' => '2026-03-14T08:36:00+03:00',
                    'role' => null,
                    'role_color' => null,
                ],
                'token' => '1|abcdefghijklmnopqrstuvwxyz0123456789',
                'requires_verification' => false,
            ],
        ],
    )]
    public function register(RegisterRequest $request, RegisterUserAction $action): ApiResponse
    {
        $result = $action->execute(RegisterDTO::fromArray($request->validated()));
        $userPayload = new UserResource($result['user']->loadMissing('roles'));

        if ($result['requires_verification']) {
            return to_api(
                ['user' => $userPayload, 'requires_verification' => true],
                'Registration successful. Please verify your email address before logging in.',
                201,
            );
        }

        return to_api(
            ['user' => $userPayload, 'token' => $result['token'], 'requires_verification' => false],
            'Registration successful.',
            201,
        );
    }

    /**
     * Log in a user.
     */
    #[AiHint('There are three 200 outcomes, distinguished by which fields are present, not by status code: a plain success carries `user` + `token`; a 2FA-required outcome carries `requires_two_factor: true` + `challenge` and no token; an unverified-email outcome carries `requires_verification: true` and no token. Check for the absence of `token`, not the HTTP status, to know the login is not yet complete.')]
    #[AiPitfall('`requires_two_factor: true` is NOT a completed login — call `POST /auth/two-factor-challenge` with the returned `challenge` to finish.', order: 10)]
    #[AiPitfall('`requires_verification: true` is likewise not a completed login — the account has no access token until the email is verified.', order: 20)]
    #[AiExample(
        name: 'Plain successful login',
        request: ['email' => 'ada@example.com', 'password' => 'S3cret!2345'],
        response: [
            'success' => true,
            'status' => 200,
            'message' => 'Login successful.',
            'data' => [
                'user' => [
                    'id' => 42,
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'full_name' => 'Ada Lovelace',
                    'initials' => 'AL',
                    'email' => 'ada@example.com',
                    'status' => 'active',
                    'avatar_url' => null,
                    'timezone' => 'UTC',
                    'email_verified_at' => '2026-03-14T08:36:00+03:00',
                    'created_at' => '2026-03-14T08:36:00+03:00',
                    'updated_at' => '2026-03-14T08:36:00+03:00',
                    'role' => 'admin',
                    'role_color' => '#6366f1',
                ],
                'token' => '2|abcdefghijklmnopqrstuvwxyz0123456789',
            ],
        ],
    )]
    public function login(LoginRequest $request, LoginUserAction $action): ApiResponse
    {
        $result = $action->execute(LoginDTO::fromArray($request->validated()));

        if (! $result) {
            throw ApiException::unauthorized('Invalid email or password.');
        }

        // Verification-required and 2FA-required outcomes both return 200
        // with a structured payload instead of a token — mirrors the way
        // GitHub's API handles `mfa_required`. The absence of `token` is
        // what prevents the client from proceeding.
        return match ($result['kind']) {
            'requires_verification' => to_api(
                ['requires_verification' => true],
                'Email address is not verified.',
            ),
            'requires_two_factor' => to_api(
                ['requires_two_factor' => true, 'challenge' => $result['challenge']],
                'Two-factor authentication required.',
            ),
            default => to_api(
                [
                    'user' => new UserResource($result['user']->loadMissing('roles')),
                    'token' => $result['token'],
                ],
                'Login successful.',
            ),
        };
    }

    /**
     * Complete the two-factor challenge issued by /login and return an
     * access token on success.
     */
    #[AiHint('The `challenge` value is not chosen by the caller — it comes from the `requires_two_factor` response of `login` and is passed through verbatim.')]
    #[AiPitfall('The challenge is single-use and short-lived: it is claimed atomically as the first step of TwoFactorChallengeAction, so retrying with the same challenge fails with 401 even when the code is correct — a fresh login is required to get a new challenge.', order: 10)]
    public function twoFactorChallenge(TwoFactorChallengeRequest $request, TwoFactorChallengeAction $action): ApiResponse
    {
        $result = $action->execute(
            challenge: $request->validated('challenge'),
            code: $request->validated('code'),
            recoveryCode: $request->validated('recovery_code'),
        );

        if (! $result) {
            throw ApiException::unauthorized('Invalid or expired two-factor code.');
        }

        return to_api(
            [
                'user' => new UserResource($result['user']->loadMissing('roles')),
                'token' => $result['token'],
            ],
            'Login successful.',
        );
    }

    /**
     * Log out — revoke the current access token.
     */
    #[AiHint('This revokes both the current access token and the refresh token bound to it, so the client must discard both — the refresh token cannot be used afterwards to mint a new access token.')]
    public function logout(Request $request, LogoutUserAction $action): ApiResponse|JsonResponse
    {
        $action->execute($request->user());

        return to_api(message: 'Logged out.');
    }

    /**
     * Get the authenticated user.
     */
    #[AiTool(name: 'get_current_user', description: 'Read the authenticated user, including their assigned roles.')]
    #[AiHint('Roles are eager-loaded on the returned user — no extra call is needed to read `role`/`role_color`.')]
    public function me(Request $request): ApiResponse
    {
        return to_api(new UserResource($request->user()->loadMissing('roles')));
    }
}
