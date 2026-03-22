<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Traits\HasActivityLogging;
use App\Traits\HasMediaCollections;
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
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\Token;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Permission\Traits\HasRoles;

/**
 * @method Token|null token()
 */
class User extends Authenticatable implements HasMedia, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasActivityLogging, HasApiTokens, HasFactory, HasMediaCollections, HasRoles, HasUuids, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'status',
        'gender',
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
            'status' => UserStatus::class,
        ];
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
        'identity_document_url',
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
        $this->addMediaCollection('identity_document')->singleFile();
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

    /**
     * Get the identity document URL accessor.
     */
    protected function identityDocumentUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                $media = $this->getFirstMedia('identity_document');

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
