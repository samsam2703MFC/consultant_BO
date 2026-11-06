<?php
namespace App\Repositories\Client\FranchiseNetwork;
use App\Core\Helpers\BaseRepository;
/**
 * Categort model class
 */
class IntegrationOnboardingTokenRepository extends BaseRepository
{
    protected string $table = 'integrated_client_onboarding_token';
    protected $allowedColumns = [
        'token_hash',
        'customer_id',
        'purpose',
        'expires_at',
        'used_at',
        'revoked_at',
        'created_by'
    ];

    protected $fetchAllowedColumns = [
        'token_hash',
        'customer_id',
        'purpose',
        'expires_at',
        'used_at',
        'revoked_at',
        'created_by',
        'created_at '
    ];


}