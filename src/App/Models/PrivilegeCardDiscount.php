<?php
namespace App\Models;

// Privilege Card: ONE piece of the single highest-priced item of the order (a waffle or a drink)
class PrivilegeCardDiscount extends Discount {
    public function requirement() {
        return 'card';
    }

    public function describeRule() {
        return 'Only the 1 highest-priced item, waffle or drink (1 piece)';
    }

    public function allocate(array $lines) {
        if (empty($lines)) {
            return [];
        }
        $productId = $this->highest($lines);
        return [$productId => $this->discountOnePiece($lines[$productId]['price'])];
    }
}
