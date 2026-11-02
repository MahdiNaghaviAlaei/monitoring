<?php

namespace App\Filament\Forms;

use App\Support\JalaliDate;
use Filament\Forms\Components\Field;

class JalaliDatePicker extends Field
{
    protected string $view = 'filament.forms.jalali-date-picker';

    protected function setUp(): void
    {
        parent::setUp();
        $this->required()->rules(['bail', 'string', 'max:20', function () {
            return function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || JalaliDate::toGregorian($value) === null) {
                    $fail(__('monitoring.history.date_invalid'));
                }
            };
        }]);
    }
}
