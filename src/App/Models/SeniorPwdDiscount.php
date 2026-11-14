<?php
namespace App\Models;

// Senior Citizen / PWD: ONE piece of the highest-priced waffle and ONE piece of the highest-priced drink.
// The cashier does not have to separate the orders; the system picks the two items itself.
class SeniorPwdDiscount extends Discount {
    public function requirement() {
        return 'idNumber';
    }

    public function describeRule() {
        return 'Only 1 highest-priced waffle and 1 highest-priced drink (1 piece each)';
    }

    public function allocate(array $lines) {
        $result = [];
        foreach (['Waffle', 'Drink'] as $category) {
            $group = $this->onlyCategory($lines, $category);
            if (!empty($group)) {
                $productId = $this->highest($group);
                $result[$productId] = $this->discountOnePiece($group[$productId]['price']);
            }
        }
        return $result;
    }
}
