<?php
namespace App\Services;

use App\Core\Database;
use App\Models\Discount;

// Reads the kinds of discount from the Discount table
class DiscountService {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::connect();
    }

    // Every discount as a Discount object, 'No Discount' first
    public function getAll() {
        $rows = $this->pdo->query("SELECT DiscountID, DiscountName, DiscountRate, AppliesTo FROM Discount ORDER BY DiscountID")->fetchAll();
        $discounts = [];
        foreach ($rows as $row) {
            $discounts[] = Discount::fromRow($row);
        }
        return $discounts;
    }

    // One discount by id, or null when it does not exist
    public function find($discountId) {
        $stmt = $this->pdo->prepare("SELECT DiscountID, DiscountName, DiscountRate, AppliesTo FROM Discount WHERE DiscountID = ?");
        $stmt->execute([(int)$discountId]);
        $row = $stmt->fetch();
        return $row ? Discount::fromRow($row) : null;
    }
}
