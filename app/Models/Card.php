<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Card.
 *
 * @property string number
 * @property bool active
 * @property bool member_has_card
 * @property bool ever_activated If the card has ever been activated. This should only be set to false on creation and true on activation.
 * @property int customer_id
 * @property Customer customer
 * @property Carbon created_at
 * @property Carbon updated_at
 */
class Card extends Model
{
    protected $fillable = [
        'number',
        'active',
        'member_has_card',
        'customer_id',
        'ever_activated',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
