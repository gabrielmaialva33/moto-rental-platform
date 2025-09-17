<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BrazilianPhoneValidation implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$this->isValidBrazilianPhone($value)) {
            $fail('O :attribute deve ser um número de telefone brasileiro válido.');
        }
    }

    /**
     * Validate Brazilian phone number format.
     */
    private function isValidBrazilianPhone(?string $phone): bool
    {
        if (empty($phone)) {
            return false;
        }

        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Check for valid lengths:
        // 10 digits: landline without area code 9 (XX XXXX-XXXX)
        // 11 digits: mobile with area code 9 (XX 9XXXX-XXXX)
        if (strlen($phone) < 10 || strlen($phone) > 11) {
            return false;
        }

        // If 11 digits, the third digit must be 9 (mobile number)
        if (strlen($phone) === 11 && $phone[2] !== '9') {
            return false;
        }

        // Valid area codes in Brazil (11 to 99, excluding some ranges)
        $areaCode = (int) substr($phone, 0, 2);

        // Valid area codes range from 11 to 99
        if ($areaCode < 11 || $areaCode > 99) {
            return false;
        }

        // Invalid area codes
        $invalidAreaCodes = [20, 23, 25, 26, 29, 30, 36, 39, 40, 50, 52, 56, 57, 58, 59, 60, 70, 72, 76, 78, 80, 90];

        if (in_array($areaCode, $invalidAreaCodes)) {
            return false;
        }

        return true;
    }
}