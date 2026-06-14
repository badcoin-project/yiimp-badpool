<?php
$algo = user()->getState('yaamp-algo');
if (empty($algo)) $algo = YAAMP_DEFAULT_ALGO;

function badpool_display_algo($algo)
{
        return $algo == 'sha256' ? 'sha256d' : $algo;
}

$badcoin_algos = array('sha256','scrypt','groestl','skein','yescrypt');
$rows = dbolist("SELECT DISTINCT algo FROM coins WHERE symbol='BAD' AND enable=1 AND visible=1 AND auto_ready=1 AND installed=1 ORDER BY FIELD(algo,'sha256','scrypt','groestl','skein','yescrypt'), algo");

$active_algos = array();
foreach($rows as $row)
{
        if (in_array($row['algo'], $badcoin_algos))
                $active_algos[] = $row['algo'];
}

$selected_algo = ($algo != 'all' && in_array($algo, $active_algos)) ? $algo : '';
$selected_port = $selected_algo ? getAlgoPort($selected_algo) : '&lt;PORT&gt;';

echo "<div class='main-left-box'>";
echo "<div class='main-left-title'>Mining Setup</div>";
echo "<div class='main-left-inner'>";

echo "<ul>";
echo "<li><b>Pool server:</b> <span class='badpool-mono'>".YAAMP_STRATUM_URL."</span></li>";
echo "<li><b>Username:</b> your Badcoin wallet address</li>";
echo "<li><b>Worker name:</b> optional, such as <span class='badpool-mono'>rig1</span></li>";
echo "<li><b>Password:</b> usually <span class='badpool-mono'>x</span></li>";
echo "</ul>";

echo "<div class='badpool-builder'>";
echo "<div style='margin: 8px 0;'>";
echo "<label><b>Badcoin wallet address</b></label><br>";
echo "<input id='badpool_wallet' class='main-text-input' type='text' placeholder='Paste your Badcoin wallet address'>";
echo "</div>";

echo "<div style='margin: 8px 0;'>";
echo "<label><b>Worker name</b> <span style='font-size: .85em;'>(optional)</span></label><br>";
echo "<input id='badpool_worker' class='main-text-input' type='text' placeholder='rig1'>";
echo "</div>";

echo "<div style='margin: 8px 0;'>";
echo "<label><b>Algorithm</b></label><br>";
echo "<select id='badpool_builder_algo' class='main-text-input'>";
echo "<option value='' data-port=''".($selected_algo ? "" : " selected").">Choose an algorithm</option>";
foreach($active_algos as $a)
{
        $display = badpool_display_algo($a);
        $port = getAlgoPort($a);
        $sel = ($a == $selected_algo) ? ' selected' : '';
        echo "<option value='$a' data-port='$port'$sel>$display - port $port</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='badpool-codebox' id='badpool_command'>-o stratum+tcp://".YAAMP_STRATUM_URL.":$selected_port -u &lt;BADCOIN_WALLET&gt; -p x</div>";
echo "<button type='button' class='main-submit-button' id='badpool_copy_command' style='width: 130px;'>Copy command</button>";
echo "<span id='badpool_copy_status' style='font-size: .85em; margin-left: 8px;'></span>";
echo "</div>";

echo "<br>";
echo "<table class='dataGrid2'>";
echo "<thead><tr>";
echo "<th>Algo</th>";
echo "<th align='right'>Port</th>";
echo "<th>Server</th>";
echo "</tr></thead><tbody>";

foreach($active_algos as $a)
{
        $display = badpool_display_algo($a);
        $port = getAlgoPort($a);

        echo "<tr class='ssrow'>";
        echo "<td><b>$display</b></td>";
        echo "<td align='right'>$port</td>";
        echo "<td><span class='badpool-mono'>".YAAMP_STRATUM_URL.":$port</span></td>";
        echo "</tr>";
}

echo "</tbody></table>";

echo "<p style='font-size: .8em;'>Use a real Badcoin wallet address. Do not use a BTC address; auto-exchange is disabled.</p>";

echo "<script>\n";
echo "var badpoolServer = ".json_encode(YAAMP_STRATUM_URL).";\n";
echo <<<'JS'
function badpoolBuildCommand()
{
        var wallet = $('#badpool_wallet').val().trim();
        var worker = $('#badpool_worker').val().trim();
        var option = $('#badpool_builder_algo option:selected');
        var port = option.data('port') || '<PORT>';

        var user = wallet || '<BADCOIN_WALLET>';
        if(wallet && worker) user = wallet + '.' + worker;

        var cmd = '-o stratum+tcp://' + badpoolServer + ':' + port + ' -u ' + user + ' -p x';
        $('#badpool_command').text(cmd);
        $('#badpool_copy_status').text('');
}

$('#badpool_wallet, #badpool_worker, #badpool_builder_algo').on('input change', badpoolBuildCommand);

$('#badpool_copy_command').on('click', function() {
        var text = $('#badpool_command').text();
        if(navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                        $('#badpool_copy_status').text('Copied');
                });
        } else {
                $('#badpool_copy_status').text('Select and copy manually');
        }
});

badpoolBuildCommand();
JS;
echo "\n</script>";

echo "</div></div><br>";
