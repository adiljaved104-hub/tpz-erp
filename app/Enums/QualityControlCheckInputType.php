<?php

namespace App\Enums;

enum QualityControlCheckInputType: string
{
    case PassFail = 'pass_fail';
    case PassFailNa = 'pass_fail_na';
    case Percentage = 'percentage';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Text = 'text';
}
