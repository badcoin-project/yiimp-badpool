<?php

$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$contextPath = $root.'/web/yaamp/core/backend/BadpoolGuardContext.php';

$failures = array();

function l372_assert($condition, $message)
{
    global $failures;

    if ($condition) {
        echo 'PASS: '.$message.PHP_EOL;
        return;
    }

    echo 'FAIL: '.$message.PHP_EOL;
    $failures[] = $message;
}

$command = @file_get_contents($commandPath);
$context = @file_get_contents($contextPath);

l372_assert(is_string($command) && $command !== '', 'BadpoolGuardCommand.php readable');
l372_assert(is_string($context) && $context !== '', 'BadpoolGuardContext.php readable');

if (!is_string($command) || !is_string($context)) {
    echo 'FINAL: FAIL badpool_block_accounting_dryrun_context_harness'.PHP_EOL;
    exit(1);
}

$method = null;
if (preg_match(
    '/private function blockAccountingDryrunContextArgs\(\$args\)\s*\{(?P<body>.*?)\n\s*\}/s',
    $command,
    $matches
)) {
    $method = $matches['body'];
}

l372_assert(is_string($method), 'blockAccountingDryrunContextArgs method found');

if (is_string($method)) {
    l372_assert(
        strpos($method, 'coin-id|format|selector|first-block-id|last-block-id|max-rows') !== false,
        'dryrun context forwards coin-id, format, selector and bounded options'
    );

    l372_assert(
        strpos($method, '$out[] = $arg;') !== false,
        'dryrun context forwards matching original arguments'
    );

    l372_assert(
        strpos($method, '--all-coins-preview') === false,
        'dryrun context does not inject --all-coins-preview'
    );
}

$allowed = null;
if (preg_match(
    '/private\s+\$allowedOptions\s*=\s*array\s*\((?P<body>.*?)\)\s*;/s',
    $context,
    $matches
)) {
    $allowed = $matches['body'];
}

l372_assert(is_string($allowed), 'shared guard allowedOptions array found');

if (is_string($allowed)) {
    foreach (array('selector', 'first-block-id', 'last-block-id', 'max-rows') as $option) {
        l372_assert(
            strpos($allowed, "'".$option."'") !== false ||
            strpos($allowed, '"'.$option.'"') !== false,
            'shared guard allows --'.$option
        );
    }
}

l372_assert(
    strpos($command, '$action') !== false &&
    strpos($command, '$actionArgs') !== false &&
    strpos($command, '$args') !== false &&
    strpos($command, '$out') !== false,
    'source variables remain literal source text without harness interpolation'
);

if (!empty($failures)) {
    echo 'FINAL: FAIL badpool_block_accounting_dryrun_context_harness'.PHP_EOL;
    exit(1);
}

echo 'FINAL: PASS badpool_block_accounting_dryrun_context_harness'.PHP_EOL;
exit(0);
