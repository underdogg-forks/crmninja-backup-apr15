<?php

namespace App\Models;

use Eloquent;

/**
 * Class PaymentLibrary.
 */
class PaymentLibrary extends Eloquent
{
    /**
     * @var bool
     */
    public $timestamps = true;
    /**
     * @var string
     */
    protected $table = 'payment_libraries';

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gateways()
    {
        return $this->hasMany('App\Models\Gateway', 'payment_library_id');
    }
}
