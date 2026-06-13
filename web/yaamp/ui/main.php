<?php

require('misc.php');
echo <<<END

<!doctype html>
<!--[if IE 7 ]>		 <html class="no-js ie ie7 lte7 lte8 lte9" lang="en-US"> <![endif]-->
<!--[if IE 8 ]>		 <html class="no-js ie ie8 lte8 lte9" lang="en-US"> <![endif]-->
<!--[if IE 9 ]>		 <html class="no-js ie ie9 lte9>" lang="en-US"> <![endif]-->
<!--[if (gt IE 9)|!(IE)]><!--> <html class="no-js" lang="en-US"> <!--<![endif]-->

<head>

<meta charset="utf-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">

<meta name="description" content="Badcoin Pool is the official Badcoin mining pool with multiple algorithm entry points, simple miner setup, and direct wallet-based mining.">
<meta name="keywords" content="Badcoin, Badcoin Pool, crypto mining pool, multi-algo mining, yescrypt, scrypt, groestl, skein, sha256d, stratum, wallet mining">

END;

$pageTitle = empty($this->pageTitle) ? YAAMP_SITE_NAME : YAAMP_SITE_NAME." - ".$this->pageTitle;

echo '<title>'.$pageTitle.'</title>';

echo CHtml::cssFile("/extensions/jquery/themes/ui-lightness/jquery-ui.css");
echo CHtml::cssFile('/yaamp/ui/css/main.css');
echo CHtml::cssFile('/yaamp/ui/css/table.css');
echo CHtml::cssFile('/assets/css/badcoin-shared.css');

//echo CHtml::scriptFile('/extensions/jquery/js/jquery-1.8.3-dev.js');
//echo CHtml::scriptFile('/extensions/jquery/js/jquery-ui-1.9.1.custom.min.js');

$cs = app()->getClientScript();
$cs->registerCoreScript('jquery.ui');
//$cs->registerScriptFile('/yaamp/ui/js/jquery.tablesorter.js', CClientScript::POS_END);

echo CHtml::scriptFile('/yaamp/ui/js/jquery.tablesorter.js');

// if(!controller()->admin)
// echo <<<end
// <script>
// (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
// (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
// m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
// })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

// ga('create', 'UA-58136019-1', 'auto');
// ga('send', 'pageview');

// $(document).ajaxSuccess(function(){ga('send', 'pageview');});

// </script>
// end;

echo "</head>";

///////////////////////////////////////////////////////////////

echo '<body class="page">';
echo '<a href="/site/mainbtc" style="display: none;">main</a>';

showPageHeader();
showPageContent($content);
showPageFooter();

echo "</body></html>";
return;

/////////////////////////////////////////////////////////////////////

function showItemHeader($selected, $url, $name)
{
	if($selected) $selected_text = "class='selected'";
	else $selected_text = '';

	echo "<span><a $selected_text href='$url'>$name</a></span>";
	echo "&nbsp;";
}

function showPageHeader()
{
        echo '<div class="tabmenu-out">';
        echo '<div class="tabmenu-inner">';
        echo '&nbsp;&nbsp;<a href="/">'.YAAMP_SITE_NAME.'</a>';
        $action = controller()->action->id;
        $wallet = user()->getState('yaamp-wallet');
        $ad = isset($_GET['address']);

        showItemHeader(controller()->id=='site' && $action=='index' && !$ad, '/', 'Home');
        showItemHeader($action=='mining', '/site/mining', 'Pool Status');
        if (!empty($wallet) && $ad)
                showItemHeader(controller()->id=='site'&&($action=='index' || $action=='wallet') && $ad, "/?address=$wallet", 'Wallet');
        showItemHeader(controller()->id=='stats', '/stats', 'Stats');
        showItemHeader($action=='miners', '/site/miners', 'Miners');

        $current_algo = user()->getState('yaamp-algo');
        if (empty($current_algo)) $current_algo = YAAMP_DEFAULT_ALGO;

        $badpool_algos = array('all' => 'All');
        $rows = dbolist("SELECT DISTINCT algo FROM coins WHERE symbol='BAD' AND enable=1 AND visible=1 AND auto_ready=1 AND installed=1 ORDER BY FIELD(algo,'sha256','scrypt','groestl','skein','yescrypt'), algo");
        foreach($rows as $row)
        {
                $value = $row['algo'];
                $label = ($value == 'sha256') ? 'sha256d' : $value;
                $badpool_algos[$value] = $label;
        }

        echo '<span class="badpool-global-algo">Algo: <select id="badpool_global_algo">';
        foreach($badpool_algos as $value => $label)
        {
                $selected = ($current_algo == $value) ? ' selected' : '';
                echo '<option value="'.CHtml::encode($value).'"'.$selected.'>'.CHtml::encode($label).'</option>';
        }
        echo '</select></span>';
        echo '<script>$(function(){ $("#badpool_global_algo").change(function(){ var r = window.location.pathname + window.location.search; window.location.href = "/site/algo?algo=" + encodeURIComponent(this.value) + "&r=" + encodeURIComponent(r); }); });</script>';

        if (YIIMP_PUBLIC_EXPLORER)
                showItemHeader(controller()->id=='explorer', '/explorer', 'Explorers');
        if (YIIMP_PUBLIC_BENCHMARK)
                showItemHeader(controller()->id=='bench', '/bench', 'Benchs');
        if (YAAMP_RENTAL)
                showItemHeader(controller()->id=='renting', '/renting', 'Rental');

        if(controller()->admin)
        {
                if (isAdminIP($_SERVER['REMOTE_ADDR']) === false)
                        debuglog("admin {$_SERVER['REMOTE_ADDR']}");
                showItemHeader(controller()->id=='coin', '/coin', 'Coins');
                showItemHeader($action=='common', '/site/common', 'Dashboard');
                showItemHeader(controller()->id=='site'&&$action=='admin', "/site/admin", 'Wallets');
                if (YAAMP_RENTAL)
                        showItemHeader(controller()->id=='renting' && $action=='admin', '/renting/admin', 'Jobs');
                if (YAAMP_ALLOW_EXCHANGE)
                        showItemHeader(controller()->id=='trading', '/trading', 'Trading');
                if (YAAMP_USE_NICEHASH_API)
                        showItemHeader(controller()->id=='nicehash', '/nicehash', 'Nicehash');
        }

        echo '<span style="float: right;">';
        $mining = getdbosql('db_mining');
        if ($mining && !empty($mining->last_payout) && $mining->last_payout > 0) {
                $next_ts = $mining->last_payout + YAAMP_PAYMENTS_FREQ;
                $nextpayment = date('H:i T', $next_ts);
                $eta = $next_ts - time();
                if ($eta > 0) {
                        $eta_mn = 'in '.round($eta / 60).' minutes';
                        echo '<span id="nextpayout" style="font-size: .8em;" title="'.$eta_mn.'">Next Payout: '.$nextpayment.'</span>';
                }
        }
        echo "</div>";
        echo "</div>";
}

function showPageFooter()
{
	echo '<div class="footer">';
	$year = date("Y", time());

	echo "<p>&copy; $year ".YAAMP_SITE_NAME.' - '.
		'<a href="https://github.com/tpruvot/yiimp" target="_blank" rel="noopener">Powered by YiiMP</a></p>';

	echo '</div><!-- footer -->';
}


