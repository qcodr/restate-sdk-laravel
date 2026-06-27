<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Auth;

use Illuminate\Database\Eloquent\Model;

/**
 * A minimal Eloquent {@see Model} used to prove {@see \Qcodr\Restate\Laravel\Auth\ForwardsAuthHeaders}
 * reduces a model tenant to its primary key. No database is touched: only the in-memory
 * key attribute is read via {@see Model::getKey()}.
 *
 * @property int|string|null $id
 */
final class FakeTenantModel extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];
}
