<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'type',
        'email',
        'profile_photo_path',
        'password',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
        ];
    }

    /** Catalog entries this user added. */
    public function mediaItems(): HasMany
    {
        return $this->hasMany(MediaItem::class);
    }

    /** This user's play/watch history. */
    public function plays(): HasMany
    {
        return $this->hasMany(MediaPlay::class);
    }

    /**
     * Whether this account may reach the management panel.
     *
     * Members browse the library; owners and admins run the server.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // An uploader reaches the panel, but every resource and page inside it
        // refuses them individually — see Filament\Concerns\RestrictsToAdmins.
        // Panel access alone is not permission to do anything.
        return $this->hasAnyRole(['super_admin', 'owner', 'admin', 'uploader']);
    }

    /**
     * Whether this account may curate the library rather than only add to it.
     *
     * The distinction that matters: an uploader adds files, an admin edits
     * metadata, changes settings and deletes things.
     */
    public function isLibraryAdmin(): bool
    {
        return $this->hasAnyRole(['super_admin', 'owner', 'admin']);
    }
}
