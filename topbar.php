<?php
// topbar.php  (one fixed top bar shared by the Owner and Admin pages: same title, same buttons, only the clicked button changes colour)
use App\Core\Site;

function topbarCss() { ?>
    <style>
        .topfix { position: sticky; top: 0; z-index: 1000; background: #5c3d2e; margin: -40px -40px 20px -40px; padding: 12px 30px 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.12); }
        .toprow { display: flex; justify-content: space-between; align-items: center; }
        .toprow h2 { color: white; margin: 0; font-size: 21px; }
        .toprow h2 span { font-size: 13px; font-weight: normal; opacity: 0.8; margin-left: 8px; }
        .toprow .right { display: flex; align-items: center; gap: 0; }
        .topfix .nav-link { display: inline-block; background: #faf8f5; color: #5c3d2e; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: bold; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin: 0; border: none; font-family: inherit; cursor: pointer; }
        .topfix .nav-link:hover { background: #eee; }
        .navrow { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .navrow .nav-link { padding: 9px 18px; font-size: 13px; background: rgba(255,255,255,0.14); color: #fff; box-shadow: none; }
        .navrow .nav-link:hover { background: rgba(255,255,255,0.28); }
        .navrow .nav-link.on { background: #faf8f5; color: #5c3d2e; }
    </style>
<?php }

// $title: text after the business name. $items: [key => ['label'=>..., 'href'=>... or 'onclick'=>...]]. $active: key of the clicked button.
function topbar($title, array $items, $active) { ?>
    <div class="topfix">
        <div class="toprow">
            <h2><?php echo htmlspecialchars(Site::getBusinessName()); ?> <span><?php echo htmlspecialchars($title); ?></span></h2>
            <div class="right"><?php include 'clock.php'; ?><span style="width:16px; display:inline-block;"></span><a href="logout.php" class="nav-link">LOG OUT</a></div>
        </div>
        <div class="navrow">
            <?php foreach ($items as $key => $item): ?>
                <?php if (isset($item['onclick'])): ?>
                    <button type="button" data-tab="<?php echo htmlspecialchars($key); ?>" class="nav-link<?php echo $active === $key ? ' on' : ''; ?>" onclick="<?php echo htmlspecialchars($item['onclick']); ?>"><?php echo htmlspecialchars($item['label']); ?></button>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars($item['href']); ?>" class="nav-link<?php echo $active === $key ? ' on' : ''; ?>"><?php echo htmlspecialchars($item['label']); ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php }

// the Admin buttons (same on every Admin page)
function adminNav() {
    return [
        'menu'  => ['label' => 'Menu Items',       'href' => 'admin.php'],
        'staff' => ['label' => 'Staff Accounts',   'href' => 'staff.php'],
        'sales' => ['label' => 'Sales by Cashier', 'href' => 'cashier_sales.php'],
        'voids' => ['label' => 'Voided by Cashier', 'href' => 'voided_by_cashier.php'],
    ];
}

// the Owner buttons when the person is NOT on owner.php (links back to the right pane)
function ownerNavLinks() {
    return [
        'overview' => ['label' => 'Dashboard',              'href' => 'owner.php?tab=overview'],
        'cards'    => ['label' => 'Privilege Card Holders', 'href' => 'owner.php?tab=cards'],
        'find'     => ['label' => 'Find Loyal Customer',    'href' => 'owner.php?tab=find'],
        'best'     => ['label' => 'Best Sellers',           'href' => 'owner.php?tab=best'],
        'daily'    => ['label' => 'Daily Sales',            'href' => 'owner.php?tab=daily'],
        'ledger'   => ['label' => 'Audit Ledger',           'href' => 'owner.php?tab=ledger'],
        'account'  => ['label' => 'My Account',             'href' => 'owner.php?tab=account'],
        'sales'    => ['label' => 'Sales by Cashier',       'href' => 'cashier_sales.php'],
        'voids'    => ['label' => 'Voided by Cashier',      'href' => 'voided_by_cashier.php'],
    ];
}
