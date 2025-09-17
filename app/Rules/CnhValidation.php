<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CnhValidation implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$this->isValidCnh($value)) {
            $fail('O :attribute deve ser uma CNH válida.');
        }
    }

    /**
     * Validate CNH format and algorithm.
     */
    private function isValidCnh(?string $cnh): bool
    {
        if (empty($cnh)) {
            return false;
        }

        // Remove any non-numeric characters
        $cnh = preg_replace('/[^0-9]/', '', $cnh);

        // Check if CNH has 11 digits
        if (strlen($cnh) !== 11) {
            return false;
        }

        // Check if all digits are the same (invalid CNH)
        if (preg_match('/(\d)\1{10}/', $cnh)) {
            return false;
        }

        // Validate first check digit
        $sum = 0;
        $sequence = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $cnh[$i] * (9 - $i);

            if ((int) $cnh[$i] === (int) $cnh[$i + 1]) {
                $sequence++;
            }
        }

        // If sequence of identical digits is found, it's invalid
        if ($sequence === 8) {
            return false;
        }

        $remainder = $sum % 11;
        $firstDigit = $remainder >= 2 ? 11 - $remainder : 0;

        if ((int) $cnh[9] !== $firstDigit) {
            return false;
        }

        // Validate second check digit
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $cnh[$i] * (1 + (9 - $i));
        }

        $remainder = $sum % 11;
        $secondDigit = $remainder >= 2 ? 11 - $remainder : 0;

        return (int) $cnh[10] === $secondDigit;
    }
}