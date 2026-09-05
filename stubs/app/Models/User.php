<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Features;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\TransientToken;
use Lvntr\StarterKit\Traits\HasActivityLogging;
use Lvntr\StarterKit\Traits\HasMediaCollections;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Permission\Traits\HasRoles;

/**
 * @method AccessToken|TransientToken|null token()
 */
class User extends Authenticatable implements HasMedia, MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasActivityLogging, HasApiTokens, HasFactory, HasMediaCollections, HasRoles, HasUuids, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * `password` stays here on purpose — every registration/reset path mass
     * assigns it. It is kept OUT of the activity log by the deny list in
     * Lvntr\StarterKit\Traits\HasActivityLogging (SENSITIVE_LOG_ATTRIBUTES),
     * not by this array. Do not "fix" a hash appearing in an audit row by
     * removing the field here — that only breaks mass assignment; extend
     * the trait's list instead.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'status',
        'timezone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
        ];
    }

    /**
     * Stamp password rotation at the model boundary.
     *
     * Every password write path (Fortify register / reset / update actions,
     * admin and API user CRUD actions, the site installer, factories)
     * persists through this Eloquent model, so a single `saving` hook
     * guarantees `password_changed_at` can never silently miss a write
     * point. The password-expiry middleware (EnsurePasswordNotExpired)
     * reads this column. An explicitly assigned `password_changed_at`
     * (e.g. seeding historical data) wins over the automatic stamp. The
     * column is intentionally NOT mass assignable — only this hook or
     * explicit assignment may set it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty('password') && ! $user->isDirty('password_changed_at')) {
                $user->password_changed_at = now();
            }
        });
    }

    /**
     * When email verification feature is disabled, treat all users as verified.
     */
    public function hasVerifiedEmail(): bool
    {
        if (! Features::enabled(Features::emailVerification())) {
            return true;
        }

        return ! is_null($this->email_verified_at);
    }

    /**
     * @return array<string, string>
     */
    protected $appends = [
        'full_name',
        'initials',
        'avatar_url',
    ];

    /**
     * Get the user's full name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        return $this->first_name.' '.$this->last_name;
    }

    /**
     * Get the user's initials based on their first and last name.
     *
     * @return string
     */
    public function getInitialsAttribute()
    {
        return strtoupper(
            mb_substr($this->first_name, 0, 1).
            mb_substr($this->last_name, 0, 1)
        );
    }

    /**
     * Register media collections for this model.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
        $this->addMediaCollection('files');
    }

    /**
     * Get the avatar URL accessor (returns a temporary signed URL for private disks).
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                $media = $this->getFirstMedia('avatar');

                if (! $media) {
                    return null;
                }

                try {
                    return $media->getTemporaryUrl(now()->addMinutes(30));
                } catch (\RuntimeException) {
                    return $media->getUrl();
                }
            },
        );
    }
}
