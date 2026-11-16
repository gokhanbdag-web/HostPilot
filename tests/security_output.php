<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Html.php';

$cases = [
    ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
    ['" onmouseover="alert(1)', '&quot; onmouseover=&quot;alert(1)'],
    ["' autofocus onfocus='alert(1)", '&#039; autofocus onfocus=&#039;alert(1)'],
    ["A&B <tag> \xF0\x28\x8C\x28", 'A&amp;B &lt;tag&gt; �(�('],
];
foreach ($cases as [$input, $expected]) {
    $actual = \HostPilot\Core\Html::escape($input);
    if ($actual !== $expected) {
        throw new RuntimeException('HTML escaping regression: ' . bin2hex($input) . ' -> ' . $actual);
    }
}
echo "PASS HTML text/attribute escaping and malformed UTF-8 substitution." . PHP_EOL;
