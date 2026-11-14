<?php
// confirmbox.php  (one pop-up for every "Are you sure?" question, same look as the other pop-ups; replaces the browser's own dialog)
?>
<style>
    .cfx-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index:10000; }
    .cfx-box { background:white; border:4px solid #5c3d2e; border-radius:20px; width:450px; max-width:94%; padding:35px; text-align:center; font-family:'Segoe UI', sans-serif; }
    .cfx-box h3 { margin:0 0 14px; color:#333; }
    .cfx-box p { background:#fdfaf4; padding:15px; border-left:6px solid #865d43; text-align:left; margin:0 0 18px; font-size:14px; color:#333; }
    .cfx-box button { width:100%; border:none; border-radius:8px; padding:13px; font-size:14px; font-weight:bold; cursor:pointer; color:white; font-family:inherit; }
    .cfx-ok { background:#5c3d2e; } .cfx-no { background:#777; margin-top:8px; }
</style>
<div id="cfxBox" class="cfx-overlay"><div class="cfx-box">
    <h3>Please Confirm</h3>
    <p id="cfxMsg"></p>
    <button type="button" class="cfx-ok" id="cfxOk">Yes, continue</button>
    <button type="button" class="cfx-no" onclick="document.getElementById('cfxBox').style.display='none';">Cancel</button>
</div></div>
<script>
    // use:  <form onsubmit="return askConfirm(event, this, 'Question?')">
    function askConfirm(ev, form, message) {
        if (form.dataset.confirmed === '1') { form.dataset.confirmed = ''; return true; }
        var submitter = ev && ev.submitter ? ev.submitter : null;
        document.getElementById('cfxMsg').textContent = message;
        document.getElementById('cfxBox').style.display = 'flex';
        document.getElementById('cfxOk').onclick = function () {
            document.getElementById('cfxBox').style.display = 'none';
            form.dataset.confirmed = '1';
            if (form.requestSubmit) { form.requestSubmit(submitter || undefined); } else { form.submit(); }
        };
        return false;
    }
</script>
