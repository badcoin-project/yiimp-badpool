<?php
$algo = user()->getState('yaamp-algo');
JavascriptFile("/extensions/jqplot/jquery.jqplot.js");
JavascriptFile("/extensions/jqplot/plugins/jqplot.dateAxisRenderer.js");
JavascriptFile("/extensions/jqplot/plugins/jqplot.barRenderer.js");
JavascriptFile("/extensions/jqplot/plugins/jqplot.highlighter.js");
JavascriptFile("/extensions/jqplot/plugins/jqplot.cursor.js");
JavascriptFile('/yaamp/ui/js/auto_refresh.js');

$height = '240px';
$min_payout = floatval(YAAMP_PAYMENTS_MINI);
$payout_freq = (YAAMP_PAYMENTS_FREQ / 3600)." hours";
?>
<div id='resume_update_button' style='color: #444; background-color: #ffd; border: 1px solid #eea;
        padding: 10px; margin-left: 20px; margin-right: 20px; margin-top: 15px;
        cursor: pointer; display: none;'
        onclick='auto_page_resume();' align=center>
        <b>Auto refresh is paused - Click to resume</b>
</div>

<table cellspacing="20" width="100%">
<tr>
<td valign="top" width="50%">

<div class="main-left-box badpool-hero-box">
        <div class="main-left-title">BADCOIN POOL</div>
        <div class="main-left-inner">
                <ul>
                        <li>Mine Badcoin on one pool with multiple algorithm entry points.</li>
                        <li>Choose the algorithm your miner supports, connect to the matching port, and use your Badcoin wallet address as your username.</li>
                        <li>No registration is required.</li>
                        <li>Payouts are sent automatically when your balance reaches the pool minimum threshold.</li>
                        <li><b>Pool server:</b> <span class="badpool-mono">pool.badcoin.dev</span></li>
                </ul>
        </div>
</div>

<br>

<div class="main-left-box">
        <div class="main-left-title">QUICK START</div>
        <div class="main-left-inner">
                <ul>
                        <li><b>1.</b> Choose an algorithm below.</li>
                        <li><b>2.</b> Set your miner server to <span class="badpool-mono">pool.badcoin.dev</span></li>
                        <li><b>3.</b> Use the port for your chosen algorithm.</li>
                        <li><b>4.</b> Use your Badcoin wallet address as the username.</li>
                        <li><b>5.</b> Password is usually just <span class="badpool-mono">x</span></li>
                </ul>

                <div class="badpool-codebox">-o stratum+tcp://pool.badcoin.dev:&lt;PORT&gt; -u &lt;BADCOIN_WALLET&gt; -p x</div>
        </div>
</div>

<br>

<div class="main-left-box">
        <div class="main-left-title">CHOOSE YOUR ALGORITHM</div>
        <div class="main-left-inner">
                <div class="badpool-algo-grid">
                        <div class="badpool-algo-card">
                                <div class="badpool-algo-name">yescrypt</div>
                                <div class="badpool-algo-port">Port 3032</div>
                                <div class="badpool-algo-line">stratum.badcoin.dev:3032</div>
                        </div>
                        <div class="badpool-algo-card">
                                <div class="badpool-algo-name">scrypt</div>
                                <div class="badpool-algo-port">Port 4032</div>
                                <div class="badpool-algo-line">stratum.badcoin.dev:4032</div>
                        </div>
                        <div class="badpool-algo-card">
                                <div class="badpool-algo-name">groestl</div>
                                <div class="badpool-algo-port">Port 5032</div>
                                <div class="badpool-algo-line">stratum.badcoin.dev:5032</div>
                        </div>
                        <div class="badpool-algo-card">
                                <div class="badpool-algo-name">skein</div>
                                <div class="badpool-algo-port">Port 6032</div>
                                <div class="badpool-algo-line">stratum.badcoin.dev:6032</div>
                        </div>
                        <div class="badpool-algo-card">
                                <div class="badpool-algo-name">sha256d</div>
                                <div class="badpool-algo-port">Port 7032</div>
                                <div class="badpool-algo-line">stratum.badcoin.dev:7032</div>
                        </div>
                </div>
                <ul>
                        <li>This is one Badcoin pool with multiple algorithm ports, not five separate pools.</li>
                        <li>If an algorithm is still being finalized or temporarily unavailable, use one of the working ports already announced by the project.</li>
                </ul>
        </div>
</div>

<br>

<div class="main-left-box">
        <div class="main-left-title">WHAT GOES IN YOUR MINER</div>
        <div class="main-left-inner">
                <ul>
                        <li><b>Wallet address</b> = where your Badcoin payouts are sent.</li>
                        <li><b>Worker name</b> = an optional label for a device, such as <span class="badpool-mono">YourWallet.rig1</span></li>
                        <li><b>Password</b> = usually just <span class="badpool-mono">x</span></li>
                </ul>

                <div class="badpool-codebox">Username: BADCOIN_WALLET</div>
                <div class="badpool-codebox">Username with worker: BADCOIN_WALLET.rig1</div>
                <div class="badpool-codebox">Password: x</div>
        </div>
</div>

<br>

<div class="main-left-box">
        <div class="main-left-title">POOL NOTES</div>
        <div class="main-left-inner">
                <ul>
                        <li>Minimum payout threshold: <b><?= $min_payout ?></b></li>
                        <li>Automatic payout cycle: approximately every <b><?= $payout_freq ?></b></li>
                        <li>Blocks are distributed proportionally across valid submitted shares.</li>
                        <li>New miners may need some time before balances and payouts appear normally.</li>
                </ul>
        </div>
</div>

<br>

<div class="main-left-box">
        <div class="main-left-title">USEFUL LINKS</div>
        <div class="main-left-inner">
                <ul>
                        <li><b>Pool Stats</b> - <a href="/stats">/stats</a></li>
                        <li><b>Blocks</b> - <a href="/site/block">/site/block</a></li>
                        <li><b>Payments</b> - <a href="/site/payments">/site/payments</a></li>
                        <li><b>Miners</b> - <a href="/site/miners">/site/miners</a></li>
                        <li><b>Wallet Lookup</b> - <a href="/site/wallet">/site/wallet</a></li>
                        <li><b>API</b> - <a href="/site/api">/site/api</a></li>
                        <li><b>Difficulty</b> - <a href="/site/diff">/site/diff</a></li>
                </ul>
        </div>
</div>

<br>

<div class="main-left-box">
        <div class="main-left-title">NEED HELP?</div>
        <div class="main-left-inner">
                <ul>
                        <li>Start simple: choose an algorithm, copy the server and port, paste your wallet address, and use password <span class="badpool-mono">x</span>.</li>
                        <li>If your miner connects but does not submit shares, the miner may not support the algorithm you selected.</li>
                        <li>If your miner shows accepted shares but no balance yet, give the pool some time to process shares, rounds, and payout data.</li>
                </ul>
        </div>
</div>

</td>
<td valign="top">

<div id='pool_current_results'>
<br><br><br><br><br><br><br><br><br><br>
</div>

<div id='pool_history_results'>
<br><br><br><br><br><br><br><br><br><br>
</div>

</td>
</tr>
</table>

<br><br><br><br><br><br><br><br><br><br>
<br><br><br><br><br><br><br><br><br><br>
<br><br><br><br><br><br><br><br><br><br>
<br><br><br><br><br><br><br><br><br><br>

<script>
function page_refresh()
{
        pool_current_refresh();
        pool_history_refresh();
}

function select_algo(algo)
{
        window.location.href = '/site/algo?algo='+algo+'&r=/';
}

function pool_current_ready(data)
{
        $('#pool_current_results').html(data);
}

function pool_current_refresh()
{
        var url = "/site/current_results";
        $.get(url, '', pool_current_ready);
}

function pool_history_ready(data)
{
        $('#pool_history_results').html(data);
}

function pool_history_refresh()
{
        var url = "/site/history_results";
        $.get(url, '', pool_history_ready);
}
</script>
