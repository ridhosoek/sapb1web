<?php

namespace App\Models;

use App\Domain\Shared\Enums\UserRole;
use App\Integration\Sap\SapCompanyContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'job_title', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * The profile of the SAP CompanyDB selected for the current request or job. Flows that act for a document
     * (posting, snapshots) must pass the document's company to operationalProfileFor() instead.
     */
    public function operationalProfile(): HasOne
    {
        return $this->hasOne(UserOperationalProfile::class)
            ->where('user_operational_profiles.company_db', app(SapCompanyContext::class)->current());
    }

    public function operationalProfiles(): HasMany
    {
        return $this->hasMany(UserOperationalProfile::class);
    }

    public function operationalProfileFor(string $companyDb): ?UserOperationalProfile
    {
        return $this->operationalProfiles()->forCompany($companyDb)->first();
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
