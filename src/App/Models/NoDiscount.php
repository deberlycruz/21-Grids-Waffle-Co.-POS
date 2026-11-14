<?php
namespace App\Models;

// No discount: no item is touched
class NoDiscount extends Discount {
    public function requirement() {
        return 'none';
    }

    public function describeRule() {
        return 'No discount';
    }

    public function allocate(array $lines) {
        return [];
    }
}
