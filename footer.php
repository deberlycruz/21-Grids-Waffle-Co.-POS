<?php
// footer.php  (the small line, always visible at the bottom of the window, even when the page scrolls)
use App\Core\Site;
?>
<style>
    /* the scroll bar is always drawn when a page or box can scroll, so people know it scrolls */
    ::-webkit-scrollbar { width: 14px; height: 14px; }
    ::-webkit-scrollbar-track { background: #f5eedc; }
    ::-webkit-scrollbar-thumb { background: #b08968; border-radius: 8px; border: 3px solid #f5eedc; }
    ::-webkit-scrollbar-thumb:hover { background: #865d43; }
    ::-webkit-scrollbar-corner { background: #f5eedc; }
    .page-foot { position: fixed; left: 0; right: 0; bottom: 0; z-index: 900; background: #faf8f5; text-align: center; color: #999; font-size: 11px; padding: 8px 0; }
</style>
<div id="footSpace" class="no-print" style="height:40px;"></div>
<div class="page-foot no-print"><?php echo htmlspecialchars(Site::getBusinessName()); ?> Point-of-Sale System</div>
<script>
    // pages that centre their content (login) do not need the spacer
    if (getComputedStyle(document.body).display === 'flex') { document.getElementById('footSpace').remove(); }
</script>
