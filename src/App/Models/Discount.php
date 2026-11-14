<?php
namespace App\Models;

use App\Core\DatabaseException;

// A kind of discount: its name, its rate in percent and WHICH items it covers.
// Abstract class: each kind of discount decides for itself which items get the discount (polymorphism).
//   NoDiscount            - no item
//   SeniorPwdDiscount     - ONE piece of the highest-priced waffle + ONE piece of the highest-priced drink
//   PrivilegeCardDiscount - ONE piece of the highest-priced item (a waffle or a drink)
abstract class Discount {
    // Encapsulation: private properties, read through getters
    private $discountId;
    private $name;
    private $rate;

    public function __construct($discountId, $name, $rate) {
        $this->discountId = (int)$discountId;
        $this->name = $name;
        $this->setRate($rate);
    }

    // Static factory: the AppliesTo column of the Discount table decides which subclass is built
    public static function fromRow(array $row) {
        switch ($row['AppliesTo']) {
            case 'One Waffle and One Drink':
                return new SeniorPwdDiscount($row['DiscountID'], $row['DiscountName'], $row['DiscountRate']);
            case 'One Highest Item':
                return new PrivilegeCardDiscount($row['DiscountID'], $row['DiscountName'], $row['DiscountRate']);
            default:
                return new NoDiscount($row['DiscountID'], $row['DiscountName'], $row['DiscountRate']);
        }
    }

    public function getDiscountId() {
        return $this->discountId;
    }

    public function getName() {
        return $this->name;
    }

    public function getRate() {
        return $this->rate;
    }

    // The setter checks the data before it is stored
    public function setRate($rate) {
        if (!is_numeric($rate) || $rate < 0 || $rate > 100) {
            throw new DatabaseException("A discount rate must be between 0 and 100 percent.", "Constraint Triggered");
        }
        $this->rate = (float)$rate;
    }

    // True when this discount really takes money off (the rate is above 0)
    public function isDiscount() {
        return $this->rate > 0;
    }

    // What the cashier must type for this discount: 'none', 'idNumber' (Senior / PWD ID) or 'card' (Privilege Card)
    abstract public function requirement();

    // The same rule in words, shown on the terminal
    abstract public function describeRule();

    // Decides which items get the discount.
    // $lines: [productId => ['name' =>, 'category' => 'Waffle'|'Drink', 'price' =>, 'qty' =>]]
    // returns: [productId => money taken off ONE piece of that item]   (items without a discount are not in the list)
    abstract public function allocate(array $lines);

    // The money taken off ONE piece at this price, rounded to centavos
    public function discountOnePiece($price) {
        return round($price * $this->rate / 100, 2);
    }

    // The highest-priced line of a list of lines. A tie goes to the item that comes first on the menu
    // (waffles first, then drinks, each A to Z), so the result is always the same.
    protected function highest(array $lines) {
        $bestId = null;
        foreach ($lines as $productId => $line) {
            if ($bestId === null) {
                $bestId = $productId;
                continue;
            }
            $best = $lines[$bestId];
            if ($line['price'] > $best['price']
                || ($line['price'] == $best['price'] && self::menuRank($line) < self::menuRank($best))) {
                $bestId = $productId;
            }
        }
        return $bestId;
    }

    private static function menuRank(array $line) {
        return ($line['category'] === 'Waffle' ? '0' : '1') . strtolower($line['name']);
    }

    // The lines of one category only
    protected function onlyCategory(array $lines, $category) {
        return array_filter($lines, function ($line) use ($category) {
            return $line['category'] === $category;
        });
    }

    // Magic method: how the discount reads as text, e.g. "Senior Citizen / PWD (5%)"
    public function __toString() {
        return $this->name . ($this->isDiscount() ? ' (' . rtrim(rtrim(number_format($this->rate, 2, '.', ''), '0'), '.') . '%)' : '');
    }
}
