<?php
// cashier.php
session_start();
if (($_SESSION['role'] ?? '') !== 'Cashier') { header("Location: login.php"); exit(); }
require_once 'db.php';

use App\Core\OrderException;
use App\Core\Site;
use App\Core\DatabaseException;
use App\Services\AuthService;
use App\Services\DiscountService;
use App\Services\MenuService;
use App\Services\OrderService;
use App\Services\PrivilegeCardService;
use App\Structures\HashRegistry;

$cashier_id   = (int)($_SESSION['staff_id'] ?? 0);
$cashier_name = $_SESSION['full_name'] ?? 'Cashier';

// a cashier who was deactivated is signed out on the next page load
$auth = new AuthService();
if (!$auth->isActiveCashier($cashier_id)) { header("Location: logout.php"); exit(); }

$menus  = new MenuService();
$discountService = new DiscountService();
$orders = new OrderService();
$hashRegistry = new HashRegistry(11);

$show_success_modal = false; $receipt = [];
$show_alert_modal = false; $modal_alert_title = ""; $modal_alert_msg = "";
$alert_reload = true;   // true = Acknowledge reloads a clean page, false = keep the cart so nothing is retyped

// What the form shows again if a checkout is rejected
$form = ['customer_name' => '', 'order_type' => 'Dine In', 'payment_method' => 'Cash', 'reference_number' => '', 'amount_received' => '',
         'discount_id' => 1, 'discount_number' => '', 'card_id' => 0, 'items' => []];

// --- 1. CHECKOUT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    $form = [
        'customer_name'    => trim($_POST['customer_name'] ?? ''),
        'order_type'       => ($_POST['order_type'] ?? '') === 'Take Out' ? 'Take Out' : 'Dine In',
        'payment_method'   => ($_POST['payment_method'] ?? '') === 'Online Payment' ? 'Online Payment' : 'Cash',
        'reference_number' => trim($_POST['reference_number'] ?? ''),
        'amount_received'  => trim($_POST['amount_received'] ?? ''),
        'discount_id'      => (int)($_POST['discount_id'] ?? 1),
        'discount_number'  => trim($_POST['discount_number'] ?? ''),
        'card_id'          => (int)($_POST['card_id'] ?? 0),
        'items'            => array_map('intval', $_POST['items'] ?? [])
    ];
    try {
        $receipt = $orders->checkout($form['items'], $form['customer_name'], $form['order_type'],
                                     $form['payment_method'], $form['reference_number'], $cashier_id,
                                     $form['discount_id'], $form['discount_number'], $form['amount_received'], $form['card_id']);
        $show_success_modal = true;
        $form = ['customer_name' => '', 'order_type' => 'Dine In', 'payment_method' => 'Cash', 'reference_number' => '', 'amount_received' => '',
                 'discount_id' => 1, 'discount_number' => '', 'card_id' => 0, 'items' => []];
    } catch (OrderException | DatabaseException $exception) {
        $show_alert_modal = true; $alert_reload = false;
        $modal_alert_title = $exception->getTitle();
        $modal_alert_msg = $exception->getMessage();
    }
}

// --- 2. VOID / REFUND (needs Owner or Admin approval) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_order'])) {
    try {
        // The reason comes from the drop-down; "Others" needs the cashier's own words
        $voidReason = trim($_POST['void_reason'] ?? '');
        if ($voidReason === 'Others') {
            $other = trim($_POST['void_other'] ?? '');
            $voidReason = $other === '' ? '' : 'Others: ' . $other;
        }
        $result = $orders->voidOrder(
            intval($_POST['void_order_id'] ?? 0), $voidReason,
            $cashier_id, $_POST['approver_username'] ?? '', $_POST['approver_password'] ?? ''
        );
        $modal_alert_title = "Refund Success";
        $modal_alert_msg = "Receipt ID #" . Site::receiptNo($_POST['void_order_id'] ?? 0) . " successfully voided. Amount reversed: ₱" . number_format($result['total'], 2)
                         . ". Approved by " . $result['approvedBy'] . ".";
    } catch (OrderException | DatabaseException $exception) {
        $modal_alert_title = $exception->getTitle();
        $modal_alert_msg = $exception->getMessage();
    }
    $show_alert_modal = true;
}

// --- 3. DATA FOR THE PAGE ---
$menu = $menus->getAvailableMenu();
foreach ($menu as $item) {
    // hash table: product name -> its classification label
    $hashRegistry->put($item->getName(), $item->getItemClassificationLabel());
}
$today = $orders->todaySummary();
$todayOrders = $orders->todayOrders();
$todayVoids = $orders->todayVoids();
$todayByCashier = $orders->todayByCashier();
$nextReceipt = $orders->nextReceiptNumber();
$discounts = $discountService->getAll();   // for the Discount box
$activeCards = (new PrivilegeCardService())->activeCards();   // for the Privilege Card drop-down
$keptCard = $form['card_id'] ? (new PrivilegeCardService())->findActive($form['card_id']) : null;   // the card chosen before a rejected checkout
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(Site::getBusinessName()); ?> | Cashier Register</title>
    <style>
        /* The whole register fits one screen: no page scrolling (designed for a 1512 x 860 browser window at 100% zoom) */
        * { box-sizing: border-box; }
        /* the scroll bar is always drawn when a page or box can scroll, so people know it scrolls */
        ::-webkit-scrollbar { width: 14px; height: 14px; }
        ::-webkit-scrollbar-track { background: #f5eedc; }
        ::-webkit-scrollbar-thumb { background: #b08968; border-radius: 8px; border: 3px solid #f5eedc; }
        ::-webkit-scrollbar-thumb:hover { background: #865d43; }
        ::-webkit-scrollbar-corner { background: #f5eedc; }
        html, body { height: 100%; margin: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #faf8f5; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { background: #5c3d2e; padding: 8px 18px; display: flex; justify-content: space-between; align-items: center; flex: none; }
        .topbar h2 { color: white; margin: 0; font-size: 16px; }
        .legend { display: flex; gap: 14px; align-items: center; color: white; font-size: 11px; font-weight: bold; }
        .legend span { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.12); padding: 4px 10px; border-radius: 20px; }
        .legend i { width: 14px; height: 14px; border-radius: 4px; display: inline-block; border: 2px solid #fff; }
        .nav-link { background: #faf8f5; color: #5c3d2e; text-decoration: none; padding: 7px 16px; border-radius: 8px; font-weight: bold; font-size: 11px; }
        .nav-link:hover { background: #eee; }
        .pos-container { flex: 1; min-height: 0; display: flex; gap: 12px; padding: 10px 14px 4px; }

        /* MENU: by category (waffles, then drinks), A to Z inside each category, 5 columns, every card colored by its category */
        .menu-grid { flex: 1; min-width: 0; display: grid; grid-template-columns: repeat(5, 1fr); grid-auto-rows: 1fr; gap: 8px; }
        .menu-card { border: 2px solid; border-left-width: 8px; border-radius: 10px; padding: 7px 8px; display: flex; flex-direction: column; justify-content: space-between; min-height: 0; text-align: center; }
        .pdesc { font-size: 9px; line-height: 1.15; color: #6b5a4a; margin-top: 2px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .menu-card.first-of-group { grid-column-start: 1; }   /* each category starts on a new row: waffles first, then drinks */
        .menu-card.waffle { background: #fdf1cf; border-color: #d9a93a; }
        .menu-card.drink  { background: #e3f0ea; border-color: #5f9e8b; }
        .menu-card .tag { align-self: center; font-size: 10px; font-weight: bold; letter-spacing: 1px; padding: 2px 8px; border-radius: 10px; color: #fff; }
        .waffle .tag { background: #a8690f; }
        .drink .tag  { background: #3d7a68; }
        .menu-card .pname { font-size: 16px; font-weight: bold; color: #3b2a20; line-height: 1.15; margin: 3px 0; display: flex; align-items: center; justify-content: center; flex: 1; }
        .menu-card .price { font-size: 14px; font-weight: bold; margin: 2px 0; }
        .waffle .price { color: #8a5508; }
        .drink .price  { color: #2c6554; }
        .stepper { display: flex; justify-content: center; align-items: center; gap: 4px; }
        .stepper button { width: 30px; height: 30px; border: none; border-radius: 6px; font-size: 16px; font-weight: bold; color: #fff; cursor: pointer; line-height: 1; }
        .waffle .stepper button { background: #c98d1c; }
        .drink .stepper button  { background: #3d7a68; }
        .qty-input { width: 46px; height: 30px; padding: 0; font-size: 14px; font-weight: bold; text-align: center; border: 2px solid #999; border-radius: 6px; -moz-appearance: textfield; }
        .qty-input::-webkit-outer-spin-button, .qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

        /* ORDER PANEL */
        .sidebar { width: 410px; flex: none; min-height: 0; }
        .cart-panel { background: white; border: 3px solid #5c3d2e; border-radius: 12px; padding: 10px 12px; height: 100%; display: flex; flex-direction: column; }
        .cart-panel h3 { margin: 0 0 4px; color: #5c3d2e; font-size: 14px; }
        .cart-list { flex: 1; min-height: 50px; overflow-y: auto; border-top: 1px solid #ebdcb9; border-bottom: 1px solid #ebdcb9; }
        .field-label { display: block; font-size: 11px; font-weight: bold; color: #5c3d2e; margin: 6px 0 2px; }
        .field { width: 100%; padding: 7px 8px; font-size: 12px; border: 2px solid #ccc; border-radius: 6px; margin: 0; }
        .field.bad { border-color: #c62828; background: #fff5f5; }
        .row2 { display: flex; gap: 8px; } .row2 > div { flex: 1; min-width: 0; }
        .choice { display: flex; gap: 8px; }
        .choice label { flex: 1; text-align: center; padding: 6px; border: 2px solid #ccc; border-radius: 6px; font-size: 11px; font-weight: bold; cursor: pointer; }
        .choice input { display: none; }
        .choice label:has(input:checked) { background: #5c3d2e; color: white; border-color: #5c3d2e; }
        table.summary { width: 100%; border-collapse: collapse; font-size: 11px; table-layout: fixed; }
        table.summary th:nth-child(1), table.summary td:nth-child(1) { width: 40%; text-align: left; overflow-wrap: anywhere; }
        table.summary th:nth-child(2), table.summary td:nth-child(2) { width: 12%; }
        table.summary th:nth-child(3), table.summary td:nth-child(3) { width: 22%; }
        table.summary th:nth-child(4), table.summary td:nth-child(4) { width: 26%; }
        table.summary th { text-align: left; border-bottom: 2px solid #ebdcb9; padding: 4px 3px; color: #5c3d2e; font-size: 11px; text-transform: uppercase; position: sticky; top: 0; background: #fff; }
        .modal-box table.todaytbl th, .modal-box table.todaytbl td { padding: 6px 16px; }
        table.summary td { padding: 4px 3px; border-bottom: 1px dashed #ebdcb9; }
        table.summary th.num, table.summary td.num, .num { text-align: right; white-space: nowrap; }
        table.summary tr.disc td { color: #c62828; font-size: 10px; border-bottom: 1px dashed #ebdcb9; padding-top: 0; font-weight: bold; }
        table.summary tr.disc td:first-child { text-align: left; padding-left: 14px; }
        .rule-note { font-size: 10px; color: #8a5508; margin: 2px 0 0; line-height: 1.25; }
        .card-hit { display: block; width: 100%; text-align: left; background: #fff; border: 1px solid #c98d1c; border-radius: 6px; padding: 6px 8px; margin-top: 3px; font-size: 12px; cursor: pointer; }
        .card-hit:hover { background: #fdf1cf; }
        #cardPicked { font-size: 12px; font-weight: bold; color: #2e7d32; margin-top: 3px; }
        .totals { background: #fdfaf4; border-left: 5px solid #2e7d32; padding: 6px 10px; margin-top: 6px; border-radius: 6px; }
        .totals div { display: flex; justify-content: space-between; font-size: 11px; }
        .totals .grand { font-size: 18px; font-weight: bold; color: #2e7d32; margin-top: 2px; }
        .totals .change-line { color: #0d5aa7; font-weight: bold; font-size: 16px; }
        #sumChangeRow { color: #0d5aa7; font-weight: bold; }
        table.todaytbl th, table.todaytbl td { width: auto !important; text-align: left; }
        table.todaytbl th.num, table.todaytbl td.num { text-align: right; }
        #formError { color: #c62828; font-weight: bold; font-size: 11px; min-height: 16px; margin-top: 4px; }
        .btn { background: #2e7d32; color: white; padding: 12px; width: 100%; border: none; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; }
        .btn:disabled { background: #999; cursor: not-allowed; }
        .btn-sm { font-size: 13px; padding: 10px; }
        .btn-xs { font-size: 11px; padding: 8px 2px; }
        .site-foot { flex: none; text-align: center; color: #999; font-size: 11px; padding: 2px 0 4px; }

        /* MODALS (windows that open over the register) */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 9999; overflow-y: auto; }
        .modal-box { background: white; border: 4px solid #5c3d2e; border-radius: 20px; width: 520px; max-width: 94%; padding: 28px; text-align: center; margin: 20px 0; max-height: 96vh; overflow-y: auto; }
        .modal-box .btn { font-size: 16px; padding: 14px; }
        .modal-box .btn-sm { font-size: 14px; padding: 12px; }
        .modal-box .totals div { font-size: 14px; } .modal-box .totals .grand { font-size: 21px; }
        .modal-box table.summary { font-size: 13px; }
        .modal-box select { display: block; width: 100%; padding: 10px; font-size: 14px; font-family: inherit; color: #333; background: #fff; border: 1px solid #ccc; border-radius: 6px; margin-bottom: 10px; cursor: pointer; }
        input[type="text"], input[type="password"] { padding: 10px; width: 100%; font-size: 14px; border-radius: 6px; border: 1px solid #ccc; margin-bottom: 10px; }
        .receipt-meta { text-align: left; font-size: 13px; line-height: 1.6; margin-bottom: 8px; }
        @media print {
            body * { visibility: hidden; }
            #receiptBox, #receiptBox * { visibility: visible; }
            #receiptBox { position: absolute; left: 0; top: 0; width: 100%; border: none; }
            .no-print { display: none !important; }
        }
    
        /* a roomier drop-down arrow, not stuck to the edge */
        select { -webkit-appearance: none; appearance: none; padding-right: 40px !important;
                 background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path d='M1 1l5 5 5-5' fill='none' stroke='%235c3d2e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/></svg>");
                 background-repeat: no-repeat; background-position: right 16px center; background-size: 12px 8px; background-color: #fff; }
    </style>
    <script>
        var confirmed = false;
        function peso(amount) { return '₱' + amount.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

        // the + and - buttons beside each quantity box
        function bump(button, step) {
            var quantityBox = button.parentNode.querySelector('.qty-input');
            var quantity = (parseInt(quantityBox.value, 10) || 0) + step;
            quantityBox.value = Math.max(0, Math.min(99, quantity));
            updateSummary();
        }
        function toggleShift(open) { document.getElementById("shiftM").style.display = open ? "flex" : "none"; }
        function openToday(index, name) {
            document.getElementById("tdName").textContent = name;
            document.getElementById("tdBody").innerHTML = document.getElementById("td_" + index).innerHTML;
            document.getElementById("todayDetail").style.display = "flex";
        }
        function toggleTodayPane(open) { document.getElementById("todayM").style.display = open ? "flex" : "none"; }
        function openVoided(index, name) {
            document.getElementById("vdName").textContent = name;
            document.getElementById("vdBody").innerHTML = document.getElementById("vd_" + index).innerHTML;
            document.getElementById("voidedDetail").style.display = "flex";
        }
        function toggleVoidedPane(open) { document.getElementById("voidedM").style.display = open ? "flex" : "none"; }
        function toggleVoidPane(open) { document.getElementById("voidM").style.display = open ? "flex" : "none"; }

        // Read every quantity box and build the cart
        function readCart() {
            var rows = [], items = 0, total = 0;
            document.querySelectorAll('.qty-input').forEach(function (quantityBox) {
                var maximum = parseInt(quantityBox.max, 10);
                var quantity = parseInt(quantityBox.value, 10) || 0;
                if (quantity < 0) { quantity = 0; }
                if (!isNaN(maximum) && quantity > maximum) { quantity = maximum; }
                if (String(quantity) !== quantityBox.value && quantityBox.value !== '') { quantityBox.value = quantity; }
                if (quantity > 0) {
                    var price = parseFloat(quantityBox.dataset.price);
                    rows.push({name: quantityBox.dataset.name, cat: quantityBox.dataset.cat, qty: quantity, price: price, line: quantity * price});
                    items += quantity; total += quantity * price;
                }
            });
            return {rows: rows, items: items, total: total};
        }

        // The cart rows are in menu order (waffles first, then drinks, A to Z), so on a tie the FIRST row with the top price wins.
        function highestRow(rows) {
            var best = -1;
            rows.forEach(function (row, index) { if (best === -1 || row.price > rows[best].price) { best = index; } });
            return best;
        }

        // The money taken off ONE piece at this price, rounded to centavos
        function onePiece(price, rate) { return Math.round((price * rate / 100 + 1e-9) * 100) / 100; }

        // The same rules as the PHP discount classes (the server works out the real discount again when the order is saved):
        //   idNumber (Senior Citizen / PWD): 1 piece of the highest waffle + 1 piece of the highest drink
        //   card (Privilege Card)          : 1 piece of the single highest item
        function allocateDiscount(cart, requirement, rate) {
            var result = {};   // row index -> money taken off ONE piece
            if (rate <= 0 || cart.rows.length === 0) { return result; }
            if (requirement === 'idNumber') {
                ['Waffle', 'Drink'].forEach(function (category) {
                    var indexes = [], group = [];
                    cart.rows.forEach(function (row, index) { if (row.cat === category) { indexes.push(index); group.push(row); } });
                    if (group.length) { var top = highestRow(group); result[indexes[top]] = onePiece(group[top].price, rate); }
                });
            } else if (requirement === 'card') {
                var topRow = highestRow(cart.rows);
                result[topRow] = onePiece(cart.rows[topRow].price, rate);
            }
            return result;
        }

        // The chosen discount: its rate, its rule and the money it takes off the items it covers
        function discountOf(cart) {
            var chosenOption = document.getElementById('discSel').selectedOptions[0];
            var rate = parseFloat(chosenOption.dataset.rate) || 0;
            var lines = allocateDiscount(cart, chosenOption.dataset.req, rate);
            var amount = 0;
            Object.keys(lines).forEach(function (index) { amount += lines[index]; });
            amount = Math.round(amount * 100) / 100;
            return {rate: rate, amount: amount, lines: lines, req: chosenOption.dataset.req, name: chosenOption.dataset.name, label: chosenOption.dataset.name + ' (' + rate + '%)', totalLabel: (chosenOption.dataset.req === 'card' ? chosenOption.dataset.name : 'Total SC / PWD Discount') + ' (' + rate + '%)'};
        }

        // Fills a table with the cart. An item that got the discount has a red line under it: the amount taken off ONE piece.
        function fillRows(tbody, cart, discount) {
            tbody.innerHTML = '';
            cart.rows.forEach(function (cartRow, index) {
                var tableRow = document.createElement('tr');
                [[cartRow.name, ''], [String(cartRow.qty), 'num'], [peso(cartRow.price), 'num'], [peso(cartRow.line), 'num']].forEach(function (cell) {
                    var tableCell = document.createElement('td'); tableCell.textContent = cell[0]; if (cell[1]) { tableCell.className = cell[1]; } tableRow.appendChild(tableCell);
                });
                tbody.appendChild(tableRow);
                if (discount && discount.lines[index] !== undefined) {
                    var discountRow = document.createElement('tr'); discountRow.className = 'disc';
                    var label = document.createElement('td'); label.colSpan = 3; label.textContent = 'Discount on 1 piece (' + discount.name + ')';
                    var money = document.createElement('td'); money.className = 'num'; money.textContent = '-' + peso(discount.lines[index]);
                    discountRow.appendChild(label); discountRow.appendChild(money);
                    tbody.appendChild(discountRow);
                }
            });
        }

        // Live order summary on the right
        function updateSummary() {
            var cart = readCart();
            var discount = discountOf(cart);
            var body = document.getElementById('cartBody');
            fillRows(body, cart, discount);
            document.getElementById('cartEmpty').style.display = cart.rows.length ? 'none' : 'block';
            document.getElementById('cartTable').style.display = cart.rows.length ? 'table' : 'none';
            var list = document.getElementById('cartList'); list.scrollTop = list.scrollHeight;
            document.getElementById('sumItems').textContent = cart.items;
            document.getElementById('sumSub').textContent = peso(cart.total);
            document.getElementById('sumDiscLabel').textContent = discount.totalLabel;
            document.getElementById('sumDisc').textContent = '-' + peso(discount.amount);
            document.getElementById('sumDiscRow').style.display = discount.amount > 0 ? 'flex' : 'none';
            var total = Math.round((cart.total - discount.amount) * 100) / 100;
            document.getElementById('formError').textContent = '';
            document.getElementById('sumTotal').textContent = peso(total);

            // cash only: the change is worked out here so the cashier needs no calculator
            var isCash = document.getElementById('payMethod').value === 'Cash';
            var received = parseFloat(document.getElementById('cashIn').value);
            var changeRow = document.getElementById('sumChangeRow');
            changeRow.style.display = isCash && cart.rows.length ? 'flex' : 'none';
            if (isNaN(received)) {
                document.getElementById('sumChangeLabel').textContent = 'Change';
                document.getElementById('sumChange').textContent = 'type the amount received';
            } else if (received + 1e-9 < total) {
                document.getElementById('sumChangeLabel').textContent = 'Still short';
                document.getElementById('sumChange').textContent = peso(Math.round((total - received) * 100) / 100);
            } else {
                document.getElementById('sumChangeLabel').textContent = 'Change';
                document.getElementById('sumChange').textContent = peso(Math.round((received - total) * 100) / 100);
            }
        }

        // Shows the box the chosen discount needs: the Senior / PWD ID number, or the Privilege Card search
        function toggleDiscount() {
            var option = document.getElementById('discSel').selectedOptions[0];
            var requirement = option.dataset.req;
            document.getElementById('idWrap').style.display = requirement === 'idNumber' ? 'block' : 'none';
            document.getElementById('cardWrap').style.display = requirement === 'card' ? 'block' : 'none';
            document.getElementById('ruleNote').textContent = option.dataset.rule || '';
            updateSummary();
        }

        // Privilege Card: the active cards are already on the page; typing filters them by name or card number
        var allCards = <?php echo json_encode($activeCards); ?>;
        function searchCard(text) {
            var box = document.getElementById('cardResults');
            var q = text.trim().toLowerCase();
            if (document.getElementById('cardId').value !== '' && document.getElementById('cardPicked').textContent.toLowerCase().indexOf(q) === -1) {
                document.getElementById('cardId').value = ''; document.getElementById('cardPicked').textContent = '';   // the text no longer matches the chosen card
            }
            box.innerHTML = '';
            var shown = 0;
            allCards.forEach(function (card) {
                if (shown >= 6) { return; }
                if (q === '' || card.holder.toLowerCase().indexOf(q) !== -1 || card.card.toLowerCase().indexOf(q) !== -1) {
                    var button = document.createElement('button'); button.type = 'button'; button.className = 'card-hit';
                    button.textContent = card.card + '  |  ' + card.holder;
                    button.onclick = function () { pickCard(card); };
                    box.appendChild(button); shown++;
                }
            });
            if (shown === 0) {
                var none = document.createElement('div'); none.style.cssText = 'font-size:11px; color:#777; margin-top:3px;';
                none.textContent = 'No card for that name yet. The Owner awards the card first.'; box.appendChild(none);
            }
        }
        function pickCard(card) {
            document.getElementById('cardId').value = card.id;
            document.getElementById('cardResults').innerHTML = '';
            document.getElementById('cardQ').value = card.holder;
            document.getElementById('cardPicked').textContent = 'Selected: ' + card.card + ' | ' + card.holder;
            if (document.getElementById('custName').value.trim() === '') { document.getElementById('custName').value = card.holder; markBad('custName', false); }
            markBad('cardQ', false);
            updateSummary();
        }

        function togglePayment() {
            var online = document.getElementById('payMethod').value === 'Online Payment';
            document.getElementById('refWrap').style.display = online ? 'block' : 'none';
            document.getElementById('cashWrap').style.display = online ? 'none' : 'block';
            updateSummary();
        }

        function markBad(id, bad) { document.getElementById(id).classList.toggle('bad', bad); }

        // Check the form, then show the review window
        function openReview() {
            var cart = readCart();
            var name = document.getElementById('custName').value.trim();
            var online = document.getElementById('payMethod').value === 'Online Payment';
            var referenceNumber = document.getElementById('refNo').value.trim();
            var errorMessage = '';
            var disc = discountOf(cart);
            var discNo = document.getElementById('discNo').value.trim();
            var cardId = document.getElementById('cardId').value;
            var cardText = document.getElementById('cardPicked').textContent.replace('Selected: ', '');
            markBad('custName', false); markBad('refNo', false); markBad('discNo', false); markBad('cashIn', false); markBad('cardQ', false);
            var received = parseFloat(document.getElementById('cashIn').value);
            var amountDue = Math.round((cart.total - disc.amount) * 100) / 100;

            if (cart.rows.length === 0) { errorMessage = 'Add at least one item to the order.'; }
            else if (name === '') { errorMessage = "Please enter the customer's name."; markBad('custName', true); }
            else if (online && !/^[A-Za-z0-9\-]{4,50}$/.test(referenceNumber)) { errorMessage = 'Enter the online transfer reference number (4 to 50 letters, numbers or dashes).'; markBad('refNo', true); }
            else if (!online && (isNaN(received) || received + 1e-9 < amountDue)) { errorMessage = 'The amount received must be at least ' + peso(amountDue) + '.'; markBad('cashIn', true); }
            else if (disc.req === 'idNumber' && !/^[A-Za-z0-9\- ]{3,50}$/.test(discNo)) { errorMessage = 'Enter the Senior Citizen / PWD ID number.'; markBad('discNo', true); }
            else if (disc.req === 'card' && cardId === '') { errorMessage = 'Search the customer and select the Privilege Card first.'; markBad('cardQ', true); }
            document.getElementById('formError').textContent = errorMessage;
            if (errorMessage) { return; }

            var type = document.querySelector('input[name="order_type"]:checked').value;
            document.getElementById('rvName').textContent = name;
            document.getElementById('rvType').textContent = type;
            document.getElementById('rvPay').textContent = online ? 'Online Payment (Ref: ' + referenceNumber + ')' : 'Cash';
            fillRows(document.getElementById('rvBody'), cart, disc);
            document.getElementById('rvItems').textContent = cart.items;
            document.getElementById('rvSub').textContent = peso(cart.total);
            document.getElementById('rvDiscLabel').textContent = disc.label + ' | ' + (disc.req === 'card' ? 'Card: ' + cardText : 'ID: ' + discNo);
            document.getElementById('rvDisc').textContent = '-' + peso(disc.amount);
            document.getElementById('rvDiscRow').style.display = disc.amount > 0 ? 'flex' : 'none';
            document.getElementById('rvTotal').textContent = peso(amountDue);
            document.getElementById('rvCashRow').style.display = online ? 'none' : 'flex';
            document.getElementById('rvChangeRow').style.display = online ? 'none' : 'flex';
            if (!online) {
                document.getElementById('rvReceived').textContent = peso(received);
                document.getElementById('rvChange').textContent = peso(Math.round((received - amountDue) * 100) / 100);
            }
            document.getElementById('confirmBtn').disabled = false;
            document.getElementById('reviewM').style.display = 'flex';
        }
        function closeReview() { document.getElementById('reviewM').style.display = 'none'; }
        function confirmCheckout() {
            document.getElementById('confirmBtn').disabled = true;   // stops double-clicks creating two orders
            confirmed = true;
            document.getElementById('posForm').submit();
        }
        function closeAlert(reload) {
            if (reload) { window.location.href = 'cashier.php'; }
            else { document.getElementById('alertM').style.display = 'none'; }
        }
        // Shows the receipt while the cashier types its number (the server finds it with BINARY SEARCH)
        var lookupTimer = null;
        function lookupReceipt(value) {
            var infoBox = document.getElementById('receiptInfo');
            clearTimeout(lookupTimer);
            if (!/^[0-9]+$/.test(value)) { infoBox.textContent = ''; document.getElementById('voidSubmit').disabled = false; return; }
            lookupTimer = setTimeout(function () {
                fetch('lookup_receipt.php?id=' + encodeURIComponent(value))
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        var voidBtn = document.getElementById('voidSubmit');
                        voidBtn.disabled = false;
                        if (result.found && !result.mine) {
                            infoBox.style.color = '#b71c1c';
                            infoBox.textContent = 'Receipt #' + result.receipt + ' was made by ' + (result.cashier || 'another cashier') + '. You can only void your own sales.';
                            voidBtn.disabled = true;
                        } else if (result.found) {
                            infoBox.style.color = (result.status === 'Voided') ? '#b71c1c' : '#2e7d32';
                            infoBox.textContent = 'Receipt #' + result.receipt + ': ' + result.customer + ' | ' + result.type + ' | ' + result.payment +
                                ' | \u20B1' + result.total + ' | ' + result.status + '';
                        } else {
                            infoBox.style.color = '#c62828';
                            infoBox.textContent = 'No receipt with that number.';
                        }
                    })
                    .catch(function () { infoBox.textContent = ''; });
            }, 250);
        }
        window.addEventListener('DOMContentLoaded', function () {
            togglePayment(); toggleDiscount();
            var kept = document.getElementById('cardPicked');
            if (document.getElementById('cardId').value !== '' && kept.dataset.kept) { kept.textContent = kept.dataset.kept; }
        });
    </script>
</head>
<body>
    <!-- TOP BAR: name, category colors, logout -->
    <div class="topbar no-print">
        <h2><?php echo htmlspecialchars(Site::getBusinessName()); ?> <span style="font-size:11px; font-weight:normal; opacity:0.8; margin-left:6px;">Cashier Register — <?php echo htmlspecialchars($cashier_name); ?></span></h2>
        <div style="display:flex; align-items:center;"><?php include 'clock.php'; ?><span style="width:16px; display:inline-block;"></span><a href="logout.php" class="nav-link">LOG OUT</a></div>
    </div>

    <form method="POST" id="posForm" class="pos-container" onsubmit="return confirmed;" autocomplete="off">
        <input type="hidden" name="checkout" value="1">

        <!-- MENU (by category: waffles first, then drinks, each A to Z; waffles are orange, drinks are blue) -->
        <div class="menu-grid">
            <?php $previousCategory = null;
            foreach ($menu as $item):
                $startsGroup = ($previousCategory !== null && $previousCategory !== $item->getCategory());
                $previousCategory = $item->getCategory();
                $keep = (int)($form['items'][$item->getProductId()] ?? 0);
                $isWaffle = ($item instanceof \App\Models\Waffle);
                $cls = $isWaffle ? 'waffle' : 'drink'; ?>
                <div class="menu-card <?php echo $cls; ?><?php echo $startsGroup ? ' first-of-group' : ''; ?>">
                    <span class="tag" data-label="<?php echo htmlspecialchars((string)$hashRegistry->get($item->getName()), ENT_QUOTES); ?>"><?php echo $isWaffle ? 'WAFFLE' : 'DRINK'; ?></span>
                    <div class="pname" title="<?php echo htmlspecialchars($item->getDescription(), ENT_QUOTES); ?>"><?php echo htmlspecialchars($item->getName()); ?></div>
                    <?php if ($item->getDescription() !== ''): ?><div class="pdesc"><?php echo htmlspecialchars($item->getDescription()); ?></div><?php endif; ?>
                    <div class="price">₱<?php echo number_format($item->getPrice(), 2); ?></div>
                    <div class="stepper">
                        <button type="button" onclick="bump(this, -1)" aria-label="less">−</button>
                        <input type="number" class="qty-input" name="items[<?php echo (int)$item->getProductId(); ?>]"
                               value="<?php echo $keep; ?>" min="0" max="99"
                               data-name="<?php echo htmlspecialchars($item->getName(), ENT_QUOTES); ?>" data-cat="<?php echo htmlspecialchars($item->getCategory(), ENT_QUOTES); ?>" data-price="<?php echo htmlspecialchars((string)$item->getPrice(), ENT_QUOTES); ?>"
                               oninput="updateSummary()" onchange="updateSummary()">
                        <button type="button" onclick="bump(this, 1)" aria-label="more">+</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ORDER PANEL -->
        <div class="sidebar">
            <div class="cart-panel">
                <h3>Order Summary &middot; Receipt #<?php echo Site::receiptNo($nextReceipt); ?></h3>
                <div class="cart-list" id="cartList">
                    <div id="cartEmpty" style="color:#999; font-style:italic; padding:8px 0; font-size:11px;">No items yet. Use + on the menu.</div>
                    <table class="summary" id="cartTable" style="display:none;">
                        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead>
                        <tbody id="cartBody"></tbody>
                    </table>
                </div>
                <div class="totals">
                    <div><span>Total items ordered</span><strong id="sumItems">0</strong></div>
                    <div><span>Subtotal</span><strong id="sumSub">₱0.00</strong></div>
                    <div id="sumDiscRow" style="display:none; color:#c62828; font-weight:bold;"><span id="sumDiscLabel">Discount</span><strong id="sumDisc">-₱0.00</strong></div>
                    <div class="grand"><span>TOTAL</span><span id="sumTotal">₱0.00</span></div>
                    <div id="sumChangeRow" style="display:none;"><span id="sumChangeLabel">Change</span><strong id="sumChange">&#8369;0.00</strong></div>
                </div>

                <label class="field-label" for="custName">Customer name *</label>
                <input type="text" class="field" id="custName" name="customer_name" maxlength="100" placeholder="e.g., Maria Clara"
                       value="<?php echo htmlspecialchars($form['customer_name']); ?>">

                <div class="choice" style="margin-top:6px;">
                    <label><input type="radio" name="order_type" value="Dine In" <?php echo $form['order_type'] === 'Dine In' ? 'checked' : ''; ?>> Dine In</label>
                    <label><input type="radio" name="order_type" value="Take Out" <?php echo $form['order_type'] === 'Take Out' ? 'checked' : ''; ?>> Take Out</label>
                </div>

                <div class="row2">
                    <div>
                        <label class="field-label" for="discSel">Discount</label>
                        <select name="discount_id" id="discSel" class="field" onchange="toggleDiscount()">
                            <?php foreach ($discounts as $discount): ?>
                                <option value="<?php echo $discount->getDiscountId(); ?>"
                                        data-rate="<?php echo $discount->getRate(); ?>" data-name="<?php echo htmlspecialchars($discount->getName(), ENT_QUOTES); ?>"
                                        data-req="<?php echo $discount->requirement(); ?>" data-rule="<?php echo htmlspecialchars($discount->describeRule(), ENT_QUOTES); ?>"
                                        <?php echo $form['discount_id'] === $discount->getDiscountId() ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$discount); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="payMethod">Payment</label>
                        <select name="payment_method" id="payMethod" class="field" onchange="togglePayment()">
                            <option value="Cash" <?php echo $form['payment_method'] === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="Online Payment" <?php echo $form['payment_method'] === 'Online Payment' ? 'selected' : ''; ?>>Online Payment</option>
                        </select>
                    </div>
                </div>
                <div id="ruleNote" class="rule-note"></div>
                <div id="idWrap" style="display:none;">
                    <label class="field-label" for="discNo">Senior Citizen / PWD ID number *</label>
                    <input type="text" class="field" id="discNo" name="discount_number" maxlength="50" placeholder="Number shown on the ID"
                           value="<?php echo htmlspecialchars($form['discount_number']); ?>">
                </div>
                <div id="cardWrap" style="display:none;">
                    <label class="field-label" for="cardQ">Privilege Card: search the customer's name or card no. *</label>
                    <input type="text" class="field" id="cardQ" maxlength="100" placeholder="Type a name or PC-00001, or tap to see all cards" autocomplete="off"
                           oninput="searchCard(this.value)" onfocus="searchCard(this.value)" onblur="setTimeout(function () { document.getElementById('cardResults').innerHTML = ''; }, 250);"
                           value="<?php echo $keptCard ? htmlspecialchars($keptCard['HolderName']) : ''; ?>">
                    <input type="hidden" name="card_id" id="cardId" value="<?php echo $keptCard ? (int)$keptCard['CardID'] : ''; ?>">
                    <div id="cardResults"></div>
                    <div id="cardPicked" data-kept="<?php echo $keptCard ? htmlspecialchars('Selected: ' . Site::cardNo($keptCard['CardID']) . ' | ' . $keptCard['HolderName'], ENT_QUOTES) : ''; ?>"></div>
                </div>
                <div id="cashWrap" style="display:none;">
                    <label class="field-label" for="cashIn">Amount received from the customer (cash) *</label>
                    <input type="number" class="field" id="cashIn" name="amount_received" min="0" step="0.01" placeholder="e.g., 500.00"
                           value="<?php echo htmlspecialchars($form['amount_received']); ?>" oninput="updateSummary()">
                </div>
                <div id="refWrap" style="display:none;">
                    <label class="field-label" for="refNo">Online payment reference number *</label>
                    <input type="text" class="field" id="refNo" name="reference_number" maxlength="50" placeholder="From the customer's transfer receipt"
                           value="<?php echo htmlspecialchars($form['reference_number']); ?>">
                </div>

                <div id="formError"></div>
                <button type="button" class="btn" onclick="openReview()">Review &amp; Checkout</button>
                <div class="row2" style="margin-top:6px; gap:4px;">
                    <div><button type="button" onclick="toggleShift(true);" class="btn btn-xs" style="background:#5c3d2e;">Register Report</button></div>
                    <div><button type="button" onclick="toggleTodayPane(true);" class="btn btn-xs" style="background:#8a5508;">Today's Sales</button></div>
                    <div><button type="button" onclick="toggleVoidedPane(true);" class="btn btn-xs" style="background:#a8690f;">Voided Today</button></div>
                    <div><button type="button" onclick="toggleVoidPane(true);" class="btn btn-xs" style="background:#c62828;">Refund / Void</button></div>
                </div>
            </div>
        </div>
    </form>

    <!-- MODAL: REVIEW BEFORE CHECKOUT -->
    <div id="reviewM" class="modal-overlay">
        <div class="modal-box">
            <h3 style="color:#5c3d2e; margin-top:0;">Review Order</h3>
            <div class="receipt-meta">
                <div><strong>Receipt no.:</strong> #<?php echo Site::receiptNo($nextReceipt); ?></div>
                <div><strong>Customer:</strong> <span id="rvName"></span></div>
                <div><strong>Order type:</strong> <span id="rvType"></span></div>
                <div><strong>Payment:</strong> <span id="rvPay"></span></div>
            </div>
            <table class="summary">
                <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead>
                <tbody id="rvBody"></tbody>
            </table>
            <div class="totals">
                <div><span>Total items ordered</span><strong id="rvItems">0</strong></div>
                <div><span>Subtotal</span><strong id="rvSub">₱0.00</strong></div>
                <div id="rvDiscRow" style="display:none; color:#c62828;"><span id="rvDiscLabel">Discount</span><strong id="rvDisc">-₱0.00</strong></div>
                <div class="grand"><span>TOTAL</span><span id="rvTotal">₱0.00</span></div>
                <div id="rvCashRow" style="display:none;"><span>Amount received</span><strong id="rvReceived">₱0.00</strong></div>
                <div id="rvChangeRow" class="change-line" style="display:none;"><span>CHANGE</span><strong id="rvChange">₱0.00</strong></div>
            </div>
            <button type="button" id="confirmBtn" class="btn" onclick="confirmCheckout()" style="margin-top:18px;">Confirm &amp; Complete Order</button>
            <button type="button" class="btn btn-sm" onclick="closeReview()" style="background:#777; margin-top:10px;">Go Back &amp; Edit</button>
        </div>
    </div>

    <!-- MODAL: REGISTER TOTAL REPORT -->
    <div id="shiftM" class="modal-overlay">
        <div class="modal-box">
            <h3>Station Shift Tally</h3>
<table class="summary todaytbl" style="table-layout:auto; margin-bottom:10px;">
                <thead><tr><th>Cashier</th><th class="num">Orders</th><th class="num">Sales</th></tr></thead>
                <tbody>
                <?php foreach ($todayByCashier as $cashierRow): ?>
                    <tr><td><?php echo htmlspecialchars($cashierRow['CashierName']); ?></td><td class="num"><?php echo (int)$cashierRow['OrderCount']; ?></td><td class="num">&#8369;<?php echo number_format($cashierRow['Total'], 2); ?></td></tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold;"><td>All cashiers (total for the day)</td><td class="num"><?php echo (int)$today['count']; ?></td><td class="num">&#8369;<?php echo number_format($today['total'], 2); ?></td></tr>
                </tbody>
            </table>
            <button class="btn" onclick="toggleShift(false);" style="background:#5c3d2e;">Close View</button>
        </div>
    </div>

    <!-- MODAL: TODAY'S SALES (one button per cashier; each opens that cashier's sales) -->
    <?php
    $todayNames = [];
    foreach ($todayOrders as $todayOrder) { $todayNames[$todayOrder['CashierName'] ?? 'Older orders'] = true; }
    $todayTotals = [];
    foreach ($todayByCashier as $cashierRow) { $todayTotals[$cashierRow['CashierName']] = $cashierRow; }
    $todayGroups = [['name' => 'All Cashiers', 'orders' => $todayOrders, 'count' => (int)$today['count'], 'total' => $today['total'], 'all' => true]];
    foreach (array_keys($todayNames) as $oneName) {
        $todayGroups[] = ['name' => $oneName, 'all' => false, 'count' => (int)($todayTotals[$oneName]['OrderCount'] ?? 0), 'total' => $todayTotals[$oneName]['Total'] ?? 0,
            'orders' => array_values(array_filter($todayOrders, function ($row) use ($oneName) { return ($row['CashierName'] ?? 'Older orders') === $oneName; }))];
    }
    ?>
    <div id="todayM" class="modal-overlay" style="z-index:10001;">
        <div class="modal-box" style="width:620px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <h3 style="margin:0; color:#5c3d2e;">Today's Sales</h3>
                <button type="button" class="btn btn-xs" onclick="toggleTodayPane(false);" style="background:#5c3d2e; width:auto; padding:9px 18px; font-size:13px;">&larr; Back</button>
            </div>
            <p style="text-align:left; color:#555; font-size:13px;">Choose a cashier to see the receipts of that cashier today. Use it to check the receipt number, customer and total before you void.</p>
            <?php foreach ($todayGroups as $groupIndex => $group): ?>
                <button type="button" class="btn" onclick="openToday(<?php echo $groupIndex; ?>, '<?php echo htmlspecialchars(addslashes($group['name']), ENT_QUOTES); ?>')" style="background:<?php echo $group['all'] ? '#5c3d2e' : '#8a5508'; ?>; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; font-size:15px;">
                    <span><?php echo htmlspecialchars($group['all'] ? 'All Cashiers (total for the day)' : $group['name']); ?></span>
                    <span style="font-size:12px; font-weight:normal;"><?php echo $group['count']; ?> orders &middot; &#8369;<?php echo number_format($group['total'], 2); ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <?php foreach ($todayGroups as $groupIndex => $group): ?>
    <div id="td_<?php echo $groupIndex; ?>" style="display:none;">
        <table class="summary todaytbl" style="table-layout:auto;">
            <thead><tr><th>Receipt</th><th>Time</th><th>Customer</th><th>Type</th><th class="num">Items</th><th>Payment</th><th class="num">Total</th><th>Cashier</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (empty($group['orders'])): ?>
                <tr><td colspan="9" style="text-align:center; color:#777;">No sales yet today.</td></tr>
            <?php endif; ?>
            <?php foreach ($group['orders'] as $todayOrder): ?>
                <tr<?php echo $todayOrder['Status'] === 'Voided' ? ' style="color:#c62828; text-decoration:line-through;"' : ''; ?>>
                    <td><strong>#<?php echo Site::receiptNo($todayOrder['OrderID']); ?></strong></td>
                    <td><?php echo htmlspecialchars(date('h:i A', strtotime($todayOrder['OrderDate']))); ?></td>
                    <td style="text-align:left;"><?php echo htmlspecialchars($todayOrder['CustomerName']); ?></td>
                    <td><?php echo htmlspecialchars($todayOrder['OrderType']); ?></td>
                    <td class="num"><?php echo (int)$todayOrder['ItemCount']; ?></td>
                    <td><?php echo htmlspecialchars($todayOrder['PaymentMethod']); ?></td>
                    <td class="num">&#8369;<?php echo number_format($todayOrder['TotalAmount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($todayOrder['CashierName'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($todayOrder['Status']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    <div id="todayDetail" class="modal-overlay" style="z-index:10002;">
        <div class="modal-box" style="width:900px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px;">
                <h3 style="margin:0; color:#5c3d2e;">Today's Sales: <span id="tdName"></span></h3>
                <button type="button" class="btn btn-xs" onclick="document.getElementById('todayDetail').style.display='none';" style="background:#5c3d2e; width:auto; padding:9px 18px; font-size:13px;">&larr; Back</button>
            </div>
            <div id="tdBody" style="max-height:60vh; overflow-y:auto;"></div>
        </div>
    </div>

    <!-- MODAL: VOIDED ORDERS TODAY (one button per cashier; each opens that cashier's voids) -->
    <?php
    $voidGroups = [['name' => 'All Cashiers', 'all' => true, 'rows' => $todayVoids]];
    $voidNames = [];
    foreach ($todayVoids as $voidRow) { $voidNames[$voidRow['VoidedByName']] = true; }
    foreach (array_keys($todayByCashier ? array_column($todayByCashier, 'CashierName', 'CashierName') : []) as $oneName) { $voidNames[$oneName] = $voidNames[$oneName] ?? true; }
    foreach (array_keys($voidNames) as $oneName) {
        $voidGroups[] = ['name' => $oneName, 'all' => false,
            'rows' => array_values(array_filter($todayVoids, function ($row) use ($oneName) { return $row['VoidedByName'] === $oneName; }))];
    }
    ?>
    <div id="voidedM" class="modal-overlay" style="z-index:10001;">
        <div class="modal-box" style="width:620px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <h3 style="margin:0; color:#c62828;">Voided Orders Today</h3>
                <button type="button" class="btn btn-xs" onclick="toggleVoidedPane(false);" style="background:#5c3d2e; width:auto; padding:9px 18px; font-size:13px;">&larr; Back</button>
            </div>
            <p style="text-align:left; color:#555; font-size:13px;">Choose a cashier to see the voided receipts of that cashier today. Information only. To void a receipt, use the Refund / Void button.</p>
            <?php foreach ($voidGroups as $groupIndex => $group): $voidSum = array_sum(array_column($group['rows'], 'TotalAmount')); ?>
                <button type="button" class="btn" onclick="openVoided(<?php echo $groupIndex; ?>, '<?php echo htmlspecialchars(addslashes($group['name']), ENT_QUOTES); ?>')" style="background:<?php echo $group['all'] ? '#5c3d2e' : '#a8690f'; ?>; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">
                    <span><?php echo htmlspecialchars($group['all'] ? 'All Cashiers (total for the day)' : $group['name']); ?></span>
                    <span style="font-size:12px; font-weight:normal;"><?php echo count($group['rows']); ?> voided &middot; &#8369;<?php echo number_format($voidSum, 2); ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <?php foreach ($voidGroups as $groupIndex => $group): ?>
    <div id="vd_<?php echo $groupIndex; ?>" style="display:none;">
        <table class="summary todaytbl" style="table-layout:auto;">
            <thead><tr><th>Receipt</th><th>Time voided</th><th>Customer</th><th class="num">Amount</th><th>Reason</th><th>Voided by</th><th>Approved by</th></tr></thead>
            <tbody>
            <?php if (empty($group['rows'])): ?>
                <tr><td colspan="7" style="text-align:center; color:#777;">No voided orders today.</td></tr>
            <?php endif; ?>
            <?php foreach ($group['rows'] as $voidRow): ?>
                <tr>
                    <td><strong>#<?php echo Site::receiptNo($voidRow['OrderID']); ?></strong></td>
                    <td><?php echo htmlspecialchars(date('h:i A', strtotime($voidRow['VoidedAt']))); ?></td>
                    <td style="text-align:left;"><?php echo htmlspecialchars($voidRow['CustomerName']); ?></td>
                    <td class="num">&#8369;<?php echo number_format($voidRow['TotalAmount'], 2); ?></td>
                    <td style="text-align:left;"><?php echo htmlspecialchars($voidRow['VoidReason']); ?></td>
                    <td><?php echo htmlspecialchars($voidRow['VoidedByName']); ?></td>
                    <td><?php echo htmlspecialchars($voidRow['ApprovedByName']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    <div id="voidedDetail" class="modal-overlay" style="z-index:10002;">
        <div class="modal-box" style="width:900px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px;">
                <h3 style="margin:0; color:#c62828;">Voided Orders Today: <span id="vdName"></span></h3>
                <button type="button" class="btn btn-xs" onclick="document.getElementById('voidedDetail').style.display='none';" style="background:#5c3d2e; width:auto; padding:9px 18px; font-size:13px;">&larr; Back</button>
            </div>
            <div id="vdBody" style="max-height:60vh; overflow-y:auto;"></div>
        </div>
    </div>

    <!-- MODAL: VOID / REFUND (supervisor approval required) -->
    <div id="voidM" class="modal-overlay">
        <div class="modal-box" style="border-color:#c62828;">
            <h3 style="color:#c62828; margin-top:0;">Refund Processing Panel</h3>
            <p style="text-align:left; color:#555; font-size:13px; margin-top:0;">Enter the receipt number and reason. <strong>An owner or admin must approve</strong> by typing their login below.</p>
            <form method="POST" action="" autocomplete="off">
                <input type="text" name="void_order_id" id="voidId" required placeholder="Receipt number, e.g., 00001" oninput="lookupReceipt(this.value)">
                <button type="button" class="btn btn-xs" onclick="toggleTodayPane(true);" style="background:#6a1b9a; margin-bottom:8px;">View today's sales to find the receipt number</button>
                <div id="receiptInfo" style="text-align:left; font-size:12px; min-height:22px; margin:-4px 0 10px;"></div>
                <select name="void_reason" id="voidReason" required onchange="document.getElementById('voidOther').style.display = this.value === 'Others' ? 'block' : 'none'; document.getElementById('voidOther').required = this.value === 'Others';">
                    <option value="">Select the reason for the void</option>
                    <option>Customer cancelled the order</option>
                    <option>Wrong item entered</option>
                    <option>Wrong quantity entered</option>
                    <option>Wrong discount applied</option>
                    <option>Customer changed payment method</option>
                    <option>Item unavailable</option>
                    <option>Others</option>
                </select>
                <input type="text" name="void_other" id="voidOther" maxlength="150" placeholder="Type the reason" style="display:none;">
                
                <div style="background:#fff5f5; border:2px dashed #c62828; border-radius:10px; padding:14px; margin-bottom:14px;">
                    <div style="font-weight:bold; color:#c62828; margin-bottom:8px;">Supervisor approval</div>
                    <input type="text" name="approver_username" required placeholder="Owner / admin username">
                    <input type="password" name="approver_password" required placeholder="Owner / admin password" style="margin-bottom:0;">
                </div>
                <button type="submit" name="void_order" id="voidSubmit" class="btn btn-sm" style="background:#c62828;">Approve &amp; Void Order</button>
                <button type="button" class="btn btn-sm" onclick="toggleVoidPane(false);" style="background:#777; margin-top:10px;">Cancel Operation</button>
            </form>
        </div>
    </div>

    <!-- MODAL: CHECKOUT SUCCESS WITH RECEIPT -->
    <?php if ($show_success_modal): ?>
    <div class="modal-overlay" style="display:flex;">
        <div class="modal-box" id="receiptBox" style="border-color:#2e7d32;">
            <div style="font-size:18px; font-weight:bold; color:#5c3d2e; letter-spacing:1px;"><?php echo htmlspecialchars(Site::getBusinessName()); ?></div>
            <h3 style="color:#2e7d32; margin:6px 0 0;">Order Logged Successfully!</h3>
            <h2 style="margin:6px 0;">Receipt #<?php echo Site::receiptNo($receipt['id']); ?></h2>
            <div class="receipt-meta">
                <div><strong>Customer:</strong> <?php echo htmlspecialchars($receipt['customer']); ?></div>
                <div><strong>Order type:</strong> <?php echo htmlspecialchars($receipt['type']); ?></div>
                <div><strong>Payment:</strong> <?php echo htmlspecialchars($receipt['payment']); ?><?php echo !empty($receipt['reference']) ? ' (Ref: ' . htmlspecialchars($receipt['reference']) . ')' : ''; ?></div>
                <div><strong>Cashier:</strong> <?php echo htmlspecialchars($cashier_name); ?></div>
            </div>
            <table class="summary">
                <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($receipt['lines'] as $line): ?>
                    <tr><td><?php echo htmlspecialchars($line['name']); ?></td><td class="num"><?php echo (int)$line['qty']; ?></td>
                        <td class="num">₱<?php echo number_format($line['price'], 2); ?></td><td class="num">₱<?php echo number_format($line['line'], 2); ?></td></tr>
                    <?php if ($line['discount'] > 0): ?>
                        <tr class="disc"><td colspan="3">Discount on 1 piece (<?php echo htmlspecialchars($receipt['discountName']); ?>)</td><td class="num">-₱<?php echo number_format($line['discount'], 2); ?></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="totals">
                <div><span>Total items ordered</span><strong><?php echo (int)$receipt['items']; ?></strong></div>
                <div><span>Subtotal</span><strong>₱<?php echo number_format($receipt['subtotal'], 2); ?></strong></div>
                <?php if ($receipt['discountAmount'] > 0): ?>
                    <div style="color:#c62828;"><span><?php echo $receipt['cardNo'] ? htmlspecialchars($receipt['discountName']) : 'Total SC / PWD Discount'; ?> (<?php echo rtrim(rtrim(number_format($receipt['discountRate'], 2, '.', ''), '0'), '.'); ?>%)</span><strong>-₱<?php echo number_format($receipt['discountAmount'], 2); ?></strong></div>
                    <div style="font-size:11px; color:#666;"><span><?php echo $receipt['cardNo'] ? 'Privilege Card' : 'Senior / PWD ID no.'; ?></span><span><?php echo $receipt['cardNo'] ? htmlspecialchars($receipt['cardNo'] . ' | ' . $receipt['cardHolder']) : htmlspecialchars($receipt['discountNumber']); ?></span></div>
                <?php endif; ?>
                <div class="grand"><span>TOTAL</span><span>₱<?php echo number_format($receipt['total'], 2); ?></span></div>
                <?php if ($receipt['amountReceived'] !== null): ?>
                    <div><span>Cash received</span><strong>₱<?php echo number_format($receipt['amountReceived'], 2); ?></strong></div>
                    <div class="change-line"><span>CHANGE</span><strong>₱<?php echo number_format($receipt['change'], 2); ?></strong></div>
                <?php endif; ?>
            </div>
            <button class="btn btn-sm no-print" onclick="window.print();" style="background:#0277bd; margin-top:16px;">Print Receipt</button>
            <button class="btn btn-sm no-print" onclick="window.location.href='cashier.php';" style="background:#2e7d32; margin-top:10px;">New Order</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODAL: SYSTEM ALERTS -->
    <?php if ($show_alert_modal): ?>
    <div class="modal-overlay" id="alertM" style="display:flex;">
        <div class="modal-box">
            <h3 style="margin-top:0;"><?php echo htmlspecialchars($modal_alert_title); ?></h3>
            <p style="background:#fdfaf4; padding:15px; border-left:6px solid #865d43; text-align:left; font-size:14px;">
                <?php echo htmlspecialchars($modal_alert_msg); ?>
            </p>
            <button class="btn" onclick="closeAlert(<?php echo $alert_reload ? 'true' : 'false'; ?>);" style="background:#5c3d2e;">Acknowledge</button>
        </div>
    </div>
    <?php endif; ?>
<div class="site-foot no-print"><?php echo htmlspecialchars(Site::getBusinessName()); ?> Point-of-Sale System</div>
</body>
</html>
