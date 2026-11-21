<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    /** Credits bought by the user. */
    case Purchase = 'purchase';
    /** The worst-case estimate, reserved while a generation runs. */
    case Hold = 'hold';
    /** The hold given back once the generation finishes (either way). */
    case HoldRelease = 'hold_release';
    /** The actual price of a succeeded generation. */
    case Charge = 'charge';
}
