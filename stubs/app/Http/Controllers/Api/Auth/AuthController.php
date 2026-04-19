<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\LoginUserAction;
use App\Domain\Auth\Actions\LogoutUserAction;
use App\Domain\Auth\Actions\RegisterUserAction;
use App\Domain\Auth\Actions\TwoFactorChallengeAction;
use App\Domain\Auth\DTOs\LoginDTO;
use App\Domain\Auth\DTOs\RegisterDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Requests\Api\Auth\TwoFactorChallengeRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API authentication controller.
 *
 * This controller is intentionally thin:
 *   - Validation → FormRequest
 *   - Data mapping → DTO
 *   - Business logic → Action
 */
class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request, RegisterUserAction $action): ApiResponse
    {
        $result = $action->execute(RegisterDTO::fromArray($request->validated()));

        if ($result['requires_verification']) {
            return to_api(
                ['user' => $result['user'], 'requires_verification' => true],
                'Registration successful. Please verify your email address before logging in.',
                201,
            );
        }

        return to_api($result, 'Registration successful.', 201);
    }

    /**
     * Log in a user.
     */
    public function login(LoginRequest $request, LoginUserAction $action): ApiResponse
    {
        $result = $action->execute(LoginDTO::fromArray($request->validated()));

        if (! $result) {
            return to_api(null, 'Invalid email or password.', 401);
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
                ['user' => $result['user'], 'token' => $result['token']],
                'Login successful.',
            ),
        };
    }

    /**
     * Complete the two-factor challenge issued by /login and return an
     * access token on success.
     */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request, TwoFactorChallengeAction $action): ApiResponse
    {
        $result = $action->execute(
            challenge: $request->validated('challenge'),
            code: $request->validated('code'),
            recoveryCode: $request->validated('recovery_code'),
        );

        if (! $result) {
            return to_api(null, 'Invalid or expired two-factor code.', 401);
        }

        return to_api($result, 'Login successful.');
    }

    /**
     * Log out — revoke the current access token.
     */
    public function logout(Request $request, LogoutUserAction $action): ApiResponse|JsonResponse
    {
        $action->execute($request->user());

        return to_api(message: 'Logged out.');
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request): ApiResponse
    {
        return to_api($request->user());
    }
}
