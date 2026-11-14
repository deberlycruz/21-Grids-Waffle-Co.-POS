<?php
namespace App\Models;

use App\Core\DatabaseException;

// Abstract (base) class: the common part of every item on the menu
abstract class MenuItem {
    // Encapsulation: properties are private, outside code uses get/set methods
    private $productId;
    private $name;
    private $price;
    private $description;

    public function __construct($productId, $name, $price, $description = '') {
        $this->productId = $productId;
        $this->setName($name);
        $this->setPrice($price);
        $this->setDescription($description);
    }

    public function getDescription() {
        return $this->description;
    }

    // A short description is optional, up to 150 characters
    public function setDescription($description) {
        $description = trim((string)$description);
        if (strlen($description) > 150) {
            throw new DatabaseException("OOP Validation Guard Blocked Mutation: the description must be 150 characters or less.", "Constraint Triggered");
        }
        $this->description = $description;
    }

    public function getProductId() {
        return $this->productId;
    }

    public function getName() {
        return $this->name;
    }

    // Setters validate the data before it is stored
    public function setName($name) {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            throw new DatabaseException("OOP Validation Guard Blocked Mutation: product name must be 1 to 100 characters.", "Constraint Triggered");
        }
        $this->name = $name;
    }

    public function getPrice() {
        return $this->price;
    }

    public function setPrice($price) {
        if (!is_numeric($price) || $price <= 0 || $price >= 1000000) {
            throw new DatabaseException("OOP Validation Guard Blocked Mutation: price must be greater than zero.", "Constraint Triggered");
        }
        $this->price = (float)$price;
    }

    // Abstract method: every subclass MUST provide its own version (polymorphism)
    abstract public function getItemClassificationLabel();

    // The category word stored in the database
    abstract public function getCategory();

    // Magic method: lets us echo an item and get its name
    public function __toString() {
        return $this->name;
    }

    // static factory: turns a database row into the right kind of object
    public static function fromRow(array $row) {
        if ($row['CategoryName'] === 'Waffle') {
            return new Waffle($row['ProductID'], $row['ProductName'], $row['Price'], $row['Description'] ?? '');
        }
        return new Drink($row['ProductID'], $row['ProductName'], $row['Price'], $row['Description'] ?? '');
    }
}
