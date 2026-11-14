-- =====================================================================
-- 21 Grids Waffle Co.  |  POINT-OF-SALE (POS) DATABASE
-- Course : Information Management with Fundamentals of Database
-- Flow   : ERD -> Logical Schema -> Data Dictionary -> DDL -> Database Instance
-- RDBMS  : MySQL / MariaDB
--
-- NOTE: this builds a NEW database named GridsWaffleDB.
--       Your older databases are not touched.
-- =====================================================================


-- ---------------------------------------------------------------------
-- STEP 1: Creating the database
-- ---------------------------------------------------------------------
DROP DATABASE IF EXISTS GridsWaffleDB;

CREATE DATABASE GridsWaffleDB
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE GridsWaffleDB;


-- ---------------------------------------------------------------------
-- STEP 2: Creating the tables (8 tables)
-- Rule: create parent tables BEFORE the child tables that reference them.
--   Parents : Staff, Category, Discount
--   Children: Product, PrivilegeCard, CustomerOrder, OrderItem, OrderVoid
-- ---------------------------------------------------------------------

-- Staff (the people who log in)
CREATE TABLE Staff (
    StaffID       INT AUTO_INCREMENT PRIMARY KEY,
    Username      VARCHAR(50)  NOT NULL UNIQUE,
    PasswordHash  VARCHAR(255) NOT NULL,
    FullName      VARCHAR(100) NOT NULL,
    Role          ENUM('Admin', 'Owner', 'Cashier') NOT NULL,
    IsActive      BOOLEAN      NOT NULL DEFAULT TRUE,
    CreatedAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Category (Waffle or Drink: matches the OOP classes Waffle and Drink)
CREATE TABLE Category (
    CategoryID    INT AUTO_INCREMENT PRIMARY KEY,
    CategoryName  VARCHAR(50) NOT NULL UNIQUE
);

-- Discount (the kinds of discount a customer can get; the rate is a percent)
-- DiscountID 1 is 'No Discount' with a rate of 0.
-- AppliesTo says WHICH items of the order get the discount (the rule is kept here, not typed in the program):
--   'Nothing'                  = no item
--   'One Waffle and One Drink' = ONE piece of the highest-priced waffle and ONE piece of the highest-priced drink
--   'One Highest Item'         = ONE piece of the highest-priced item, whether it is a waffle or a drink
CREATE TABLE Discount (
    DiscountID    INT AUTO_INCREMENT PRIMARY KEY,
    DiscountName  VARCHAR(50)  NOT NULL UNIQUE,
    DiscountRate  DECIMAL(5,2) NOT NULL CHECK (DiscountRate >= 0 AND DiscountRate <= 100),
    AppliesTo     ENUM('Nothing', 'One Waffle and One Drink', 'One Highest Item') NOT NULL DEFAULT 'Nothing'
);

-- Product (the menu items)
CREATE TABLE Product (
    ProductID     INT AUTO_INCREMENT PRIMARY KEY,
    ProductName   VARCHAR(100) NOT NULL UNIQUE,
    Description   VARCHAR(150) NOT NULL DEFAULT '',
    CategoryID    INT          NOT NULL,
    Price         DECIMAL(8,2) NOT NULL CHECK (Price > 0),
    IsAvailable   BOOLEAN      NOT NULL DEFAULT TRUE,
    FOREIGN KEY (CategoryID)
        REFERENCES Category(CategoryID)
);

-- PrivilegeCard (a loyal customer who was awarded a Privilege Card by the Owner)
-- The card number is shown as PC-00001, PC-00002 ... (the CardID with 5 digits)
-- IssuedBy = the Owner who awarded the card.   IsActive = FALSE when the card is stopped (it keeps its history).
CREATE TABLE PrivilegeCard (
    CardID        INT AUTO_INCREMENT PRIMARY KEY,
    HolderName    VARCHAR(100) NOT NULL,
    IssuedBy      INT          NOT NULL,
    IssuedAt      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    IsActive      BOOLEAN      NOT NULL DEFAULT TRUE,
    FOREIGN KEY (IssuedBy)
        REFERENCES Staff(StaffID)
);

-- CustomerOrder (one row per receipt). Receipt numbers start at 1 and are shown with 5 digits: 00001, 00002 ...
--   SubtotalAmount   = the sum of the order's items
--   DiscountAmount   = the money taken off = the sum of the DiscountAmount of the order's items (see OrderItem)
--   TotalAmount      = SubtotalAmount - DiscountAmount (what the customer pays)
--   DiscountIDNumber = the Senior/PWD ID number (only for the Senior Citizen / PWD discount)
--   PrivilegeCardID  = the Privilege Card used (only for the Privilege Card discount)
--   ReferenceNumber  = required, and unique, for Online Payment orders
--   AmountReceived   = the cash the customer handed over (Cash orders only). The change is NOT stored:
--                      it is worked out as AmountReceived - TotalAmount (a derived value, so it is not saved)
--   CashierID        = the cashier who made the sale
CREATE TABLE CustomerOrder (
    OrderID            INT AUTO_INCREMENT PRIMARY KEY,   -- starts at 1; shown as 00001 on screens and receipts
    OrderDate          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CustomerName       VARCHAR(100)  NOT NULL DEFAULT 'Walk-in Customer',
    OrderType          ENUM('Dine In', 'Take Out')     NOT NULL DEFAULT 'Dine In',
    SubtotalAmount     DECIMAL(8,2)  NOT NULL DEFAULT 0.00 CHECK (SubtotalAmount >= 0),
    DiscountID         INT           NOT NULL DEFAULT 1,
    DiscountIDNumber   VARCHAR(50),
    PrivilegeCardID    INT,
    DiscountAmount     DECIMAL(8,2)  NOT NULL DEFAULT 0.00 CHECK (DiscountAmount >= 0),
    TotalAmount        DECIMAL(8,2)  NOT NULL DEFAULT 0.00 CHECK (TotalAmount >= 0),
    PaymentMethod      ENUM('Cash', 'Online Payment')  NOT NULL,
    ReferenceNumber    VARCHAR(50)   UNIQUE,
    AmountReceived     DECIMAL(8,2)  CHECK (AmountReceived >= 0),
    Status             ENUM('Completed', 'Voided')     NOT NULL DEFAULT 'Completed',
    CashierID          INT           NOT NULL,
    FOREIGN KEY (DiscountID)
        REFERENCES Discount(DiscountID),
    FOREIGN KEY (CashierID)
        REFERENCES Staff(StaffID),
    FOREIGN KEY (PrivilegeCardID)
        REFERENCES PrivilegeCard(CardID),
    CHECK (PaymentMethod <> 'Online Payment' OR ReferenceNumber IS NOT NULL),
    CHECK (DiscountAmount = 0 OR DiscountIDNumber IS NOT NULL OR PrivilegeCardID IS NOT NULL),
    CHECK (TotalAmount = SubtotalAmount - DiscountAmount),
    CHECK (PaymentMethod <> 'Cash' OR (AmountReceived IS NOT NULL AND AmountReceived >= TotalAmount))
);

-- OrderItem (junction table: one Order has many Products,
-- one Product appears in many Orders  ->  M:N)
-- UnitPrice is a snapshot of the price at the time of sale.
-- DiscountAmount = the money taken off THIS item by the order's discount. A discount covers only ONE piece of an item,
--                  so it is never more than UnitPrice. It is 0 for items that did not get the discount.
CREATE TABLE OrderItem (
    OrderID        INT NOT NULL,
    ProductID      INT NOT NULL,
    Quantity       INT NOT NULL DEFAULT 1 CHECK (Quantity > 0),
    UnitPrice      DECIMAL(8,2) NOT NULL CHECK (UnitPrice >= 0),
    DiscountAmount DECIMAL(8,2) NOT NULL DEFAULT 0.00 CHECK (DiscountAmount >= 0),
    PRIMARY KEY (OrderID, ProductID),
    FOREIGN KEY (OrderID)
        REFERENCES CustomerOrder(OrderID),
    FOREIGN KEY (ProductID)
        REFERENCES Product(ProductID),
    CHECK (DiscountAmount <= UnitPrice)
);

-- OrderVoid (the record of a voided order: who, who approved, when, why)
-- OrderID is UNIQUE, so an order can be voided only once.
-- VoidedBy   = the cashier who processed the void
-- ApprovedBy = the Owner/Admin who approved it
CREATE TABLE OrderVoid (
    VoidID       INT AUTO_INCREMENT PRIMARY KEY,
    OrderID      INT          NOT NULL UNIQUE,
    VoidedBy     INT          NOT NULL,
    ApprovedBy   INT          NOT NULL,
    VoidedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    VoidReason   VARCHAR(255) NOT NULL,
    FOREIGN KEY (OrderID)
        REFERENCES CustomerOrder(OrderID),
    FOREIGN KEY (VoidedBy)
        REFERENCES Staff(StaffID),
    FOREIGN KEY (ApprovedBy)
        REFERENCES Staff(StaffID)
);


-- ---------------------------------------------------------------------
-- STEP 3: Inserting sample data (parents first, then children)
-- Passwords are stored as bcrypt hashes:
--   admin / admin123      owner / owner123      lois, sharon / cashier123
-- ---------------------------------------------------------------------

INSERT INTO Staff (Username, PasswordHash, FullName, Role) VALUES
('admin',    '$2y$10$UDyV62lv37D85FEsmwhiDumV6O4T0.E3oAZCJH/xCxaKCj6ROhPXS', 'System Administrator', 'Admin'),
('owner',    '$2y$10$doYfh4HUKEFa6HA.NROh4OWUpxll5wpL/zFCoWqoBRHKJ8IimoBX6', 'Shop Owner',          'Owner'),
('lois',     '$2y$10$zYEjOPJ0BMSwc4gnhoV8Du9ETlrPDFodAQyx8cOhYA9TekPWBgh26', 'Lois',                'Cashier'),
('sharon',   '$2y$10$zYEjOPJ0BMSwc4gnhoV8Du9ETlrPDFodAQyx8cOhYA9TekPWBgh26', 'Sharon',              'Cashier');

INSERT INTO Category (CategoryName) VALUES
('Waffle'),
('Drink');

-- DiscountID 1 must be 'No Discount' (it is the default of CustomerOrder.DiscountID)
INSERT INTO Discount (DiscountName, DiscountRate, AppliesTo) VALUES
('No Discount',          0.00, 'Nothing'),
('Senior Citizen / PWD', 5.00, 'One Waffle and One Drink'),
('Privilege Card',      50.00, 'One Highest Item');

-- A loyal customer awarded a Privilege Card by the owner (StaffID 2)
INSERT INTO PrivilegeCard (HolderName, IssuedBy, IssuedAt) VALUES
('Grace Tan', 2, '2026-10-18 17:30:00');

-- The menu: 19 available products, waffles first (A to Z), then drinks (A to Z), the same order as the cashier terminal
INSERT INTO Product (ProductName, Description, CategoryID, Price) VALUES
('Caramel Waffle', 'Crisp waffle drizzled with caramel sauce.', 1,  99.00),
('Cheesy Ham Waffle', 'Savory waffle with melted cheese and ham.', 1, 100.00),
('Cheesy Waffle', 'Golden waffle topped with melted cheese.', 1,  99.00),
('Chocolate Waffle', 'Warm waffle with rich chocolate sauce.', 1,  99.00),
('Cream Cheese Dream Waffle', 'Waffle layered with smooth cream cheese.', 1, 119.00),
('Creamy Egg Waffle', 'Savory waffle with a creamy egg filling.', 1,  99.00),
('Peanut Butter Waffle', 'Waffle spread with creamy peanut butter.', 1,  99.00),
('Plain Waffle', 'Classic waffle, crisp outside and soft inside.', 1,  69.00),
('Ube Waffle', 'Purple yam waffle with a sweet ube flavor.', 1,  99.00),
('White Choco Waffle', 'Waffle with sweet white chocolate.', 1,  99.00),
('Yema Waffle', 'Waffle topped with sweet custard yema.', 1,  99.00),
('ChocoBrew', 'Rich chocolate drink with a coffee kick.', 2,  69.00),
('Cold Tea', 'Refreshing iced tea.', 2,  39.00),
('Cucumber Juice', 'Cool and light cucumber drink.', 2,  49.00),
('French Vanilla Latte', 'Espresso with milk and French vanilla syrup.', 2,  69.00),
('Hot Chocolate', 'Warm, creamy chocolate drink.', 2,  80.00),
('Hot Tea', 'Plain hot tea.', 2,  29.00),
('Hot Tea with Milk', 'Hot tea with a splash of milk.', 2,  39.00),
('Sea Salt Caramel Latte', 'Latte with caramel and a pinch of sea salt.', 2,  69.00);

-- Orders (the amounts are filled in below from OrderItem; a Cash order starts with 1000.00 received, set to its real value below)
INSERT INTO CustomerOrder (OrderID, OrderDate, CustomerName, OrderType, PaymentMethod, ReferenceNumber, AmountReceived, CashierID) VALUES
( 1, '2026-10-15 09:12:00', 'Ana Reyes', 'Dine In', 'Cash', NULL, 1000.00, 3),
( 2, '2026-10-15 11:40:00', 'Ben Torres', 'Take Out', 'Online Payment', '1029384756123', NULL, 3),
( 3, '2026-10-16 10:05:00', 'Carla Mendoza', 'Dine In', 'Cash', NULL, 1000.00, 4),
( 4, '2026-10-16 14:30:00', 'Diego Ramos', 'Dine In', 'Online Payment', 'BDO7741920384', NULL, 4),
( 5, '2026-10-17 09:50:00', 'Elena Cruz', 'Take Out', 'Cash', NULL, 1000.00, 3),
( 6, '2026-10-18 13:15:00', 'Francis Lim', 'Take Out', 'Online Payment', '2938475610293', NULL, 4),
( 7, '2026-10-19 10:20:00', 'Grace Tan', 'Dine In', 'Cash', NULL, 1000.00, 3),
( 8, '2026-10-19 16:45:00', 'Henry Uy', 'Dine In', 'Online Payment', 'BPI5560293847', NULL, 4),
( 9, '2026-10-20 09:30:00', 'Isabel Co', 'Take Out', 'Cash', NULL, 1000.00, 3),
(10, '2026-10-20 12:10:00', 'Jaime Ong', 'Dine In', 'Online Payment', '7364859201938', NULL, 4),
(11, '2026-10-21 08:55:00', 'Kristine Sy', 'Take Out', 'Cash', NULL, 1000.00, 3),
(12, '2026-10-21 10:00:00', 'Luis Garcia', 'Dine In', 'Online Payment', 'MAY8820193746', NULL, 4);

-- Order items (UnitPrice = the price at the time of sale)
-- ProductID: 1 Caramel, 2 Cheesy Ham, 3 Cheesy, 4 Chocolate, 5 Cream Cheese Dream, 6 Creamy Egg, 7 Peanut Butter,
--            8 Plain, 9 Ube, 10 White Choco, 11 Yema, 12 ChocoBrew, 13 Cold Tea, 14 Cucumber Juice,
--            15 French Vanilla Latte, 16 Hot Chocolate, 17 Hot Tea, 18 Hot Tea with Milk, 19 Sea Salt Caramel Latte
INSERT INTO OrderItem (OrderID, ProductID, Quantity, UnitPrice) VALUES
( 1,  8, 2,  69.00), ( 1, 13, 1,  39.00),
( 2,  5, 1, 119.00), ( 2, 15, 1,  69.00),
( 3,  1, 1,  99.00), ( 3, 16, 2,  80.00),
( 4,  4, 2,  99.00), ( 4, 12, 1,  69.00),
( 5,  2, 1, 100.00), ( 5, 19, 1,  69.00),
( 6,  9, 2,  99.00), ( 6, 15, 2,  69.00),
( 7,  8, 3,  69.00), ( 7, 14, 1,  49.00),
( 8, 11, 1,  99.00), ( 8, 12, 1,  69.00),
( 9,  7, 2,  99.00), ( 9, 17, 1,  29.00),
(10,  8, 2,  69.00), (10, 10, 1,  99.00), (10, 18, 2, 39.00),
(11,  6, 1,  99.00), (11, 13, 1,  39.00),
(12,  3, 1,  99.00), (12, 16, 1,  80.00);

-- Subtotal and total of each order = the sum of its items (the discount is applied below)
UPDATE CustomerOrder
SET SubtotalAmount = (SELECT SUM(Quantity * UnitPrice)
                      FROM OrderItem
                      WHERE OrderItem.OrderID = CustomerOrder.OrderID),
    TotalAmount    = (SELECT SUM(Quantity * UnitPrice)
                      FROM OrderItem
                      WHERE OrderItem.OrderID = CustomerOrder.OrderID);

-- Discounted orders. A discount covers only ONE piece of an item, so the money taken off is saved on the order's ITEM LINE
-- (OrderItem.DiscountAmount = UnitPrice x rate / 100), and the order's DiscountAmount is the sum of its item lines.
--
-- Order 3: a Senior Citizen (5%)  -> ONE highest waffle (Caramel 99.00) + ONE highest drink (Hot Chocolate 80.00)
UPDATE OrderItem
SET DiscountAmount = ROUND(UnitPrice * (SELECT DiscountRate FROM Discount WHERE DiscountID = 2) / 100, 2)
WHERE (OrderID, ProductID) IN ((3, 1), (3, 16));

-- Order 10: a Person with Disability (5%) -> ONE highest waffle (White Choco 99.00) + ONE highest drink (Hot Tea with Milk 39.00)
UPDATE OrderItem
SET DiscountAmount = ROUND(UnitPrice * (SELECT DiscountRate FROM Discount WHERE DiscountID = 2) / 100, 2)
WHERE (OrderID, ProductID) IN ((10, 10), (10, 18));

UPDATE CustomerOrder
SET DiscountID = 2,
    DiscountIDNumber = CASE OrderID WHEN 3 THEN 'SC-2024-0381' ELSE 'PWD-7731-0925' END
WHERE OrderID IN (3, 10);

-- Order 7: a loyal customer with a Privilege Card (50%) -> ONE highest item only (Plain Waffle 69.00, one piece)
UPDATE OrderItem
SET DiscountAmount = ROUND(UnitPrice * (SELECT DiscountRate FROM Discount WHERE DiscountID = 3) / 100, 2)
WHERE OrderID = 7 AND ProductID = 8;

UPDATE CustomerOrder
SET DiscountID = 3,
    PrivilegeCardID = 1
WHERE OrderID = 7;

-- The order's discount and total, taken from its item lines
UPDATE CustomerOrder
SET DiscountAmount = (SELECT SUM(DiscountAmount)
                      FROM OrderItem
                      WHERE OrderItem.OrderID = CustomerOrder.OrderID),
    TotalAmount    = SubtotalAmount - (SELECT SUM(DiscountAmount)
                                       FROM OrderItem
                                       WHERE OrderItem.OrderID = CustomerOrder.OrderID);

-- The cash received: the customer hands over the next 50 pesos above the total (so every Cash order has some change)
UPDATE CustomerOrder
SET AmountReceived = CEILING(TotalAmount / 50) * 50
WHERE PaymentMethod = 'Cash';

-- Order 8 was voided by Sharon and approved by the owner
UPDATE CustomerOrder
SET Status = 'Voided'
WHERE OrderID = 8;

INSERT INTO OrderVoid (OrderID, VoidedBy, ApprovedBy, VoidedAt, VoidReason) VALUES
(8, 4, 2, '2026-10-19 17:00:00', 'Customer cancelled the order');


-- ---------------------------------------------------------------------
-- STEP 4: Check the result (row count of every table)
-- ---------------------------------------------------------------------
SELECT 'Staff' AS TableName, COUNT(*) AS TotalRows FROM Staff
UNION
SELECT 'Category', COUNT(*) FROM Category
UNION
SELECT 'Discount', COUNT(*) FROM Discount
UNION
SELECT 'Product', COUNT(*) FROM Product
UNION
SELECT 'PrivilegeCard', COUNT(*) FROM PrivilegeCard
UNION
SELECT 'CustomerOrder', COUNT(*) FROM CustomerOrder
UNION
SELECT 'OrderItem', COUNT(*) FROM OrderItem
UNION
SELECT 'OrderVoid', COUNT(*) FROM OrderVoid;
