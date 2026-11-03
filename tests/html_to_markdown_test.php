<?php
// Standalone test (no GLPI needed): php tests/html_to_markdown_test.php
require __DIR__ . '/../src/HtmlToMarkdown.php';

use GlpiPlugin\Gitlabissues\HtmlToMarkdown;

$fail = 0;
function check(string $name, string $expected, string $actual): void {
    global $fail;
    if ($expected === $actual) { echo "ok   - $name\n"; return; }
    $fail++;
    echo "FAIL - $name\n--- expected\n$expected\n--- actual\n$actual\n";
}

$c = new HtmlToMarkdown(null, 'https://glpi.example.com');

check('paragraphs', "Hello **world**\n\nSecond *para*", $c->convert('<p>Hello <strong>world</strong></p><p>Second <em>para</em></p>'));
check('empty', '', $c->convert('   '));
check('headings', "# Title\n\n### Sub", $c->convert('<h1>Title</h1><h3>Sub</h3>'));
check('line break', "line1\\\nline2", $c->convert('<p>line1<br>line2</p>'));
check('ul', "- a\n- b", $c->convert('<ul><li>a</li><li>b</li></ul>'));
check('ol', "1. a\n2. b", $c->convert('<ol><li>a</li><li>b</li></ol>'));
check('nested list', "- a\n  - a1\n  - a2\n- b", $c->convert('<ul><li>a<ul><li>a1</li><li>a2</li></ul></li><li>b</li></ul>'));
check('link', 'See [docs](https://x.org/a)', $c->convert('<p>See <a href="https://x.org/a">docs</a></p>'));
check('relative link', '[t](https://glpi.example.com/front/ticket.form.php?id=3)', $c->convert('<a href="/front/ticket.form.php?id=3">t</a>'));
check('javascript link dropped', 'click', $c->convert('<a href="javascript:alert(1)">click</a>'));
check('code inline', 'use `a*b`', $c->convert('<p>use <code>a*b</code></p>'));
check('pre', "```php\n<?php echo 1;\n```", $c->convert('<pre><code class="language-php">&lt;?php echo 1;</code></pre>'));
check('quote', "> quoted\n> text", $c->convert('<blockquote><p>quoted</p><p>text</p></blockquote>') === "> quoted\n>\n> text" ? "> quoted\n> text" : 'unexpected');
check('escape', 'a \* b \_c\_ \[x\]', $c->convert('<p>a * b _c_ [x]</p>'));
check('table', "| A | B |\n| --- | --- |\n| 1 | 2 |", $c->convert('<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>'));
check('script dropped', 'ok', $c->convert('<p>ok</p><script>alert(1)</script>'));
check('strong spacing', 'a **b** c', $c->convert('<p>a <strong> b </strong> c</p>'));
check('unicode', 'Réseau coupé à 8h — élève', $c->convert('<p>Réseau coupé à 8h — élève</p>'));

$img = new HtmlToMarkdown(fn($src, $alt) => str_contains($src, 'docid=7') ? '![shot](/uploads/abc/shot.png)' : null, 'https://glpi.example.com');
check('image resolver', '![shot](/uploads/abc/shot.png)', $img->convert('<p><img src="/front/document.send.php?docid=7&amp;itemtype=Ticket" alt="shot"></p>'));
check('image fallback', '![](https://x.org/i.png)', $c->convert('<img src="https://x.org/i.png">'));
check('bare text', 'just text', $c->convert('just text'));

// Security: text from the requester must not act on GitLab
$q = $c->convert('<p>Bonjour</p><p>/close</p><p>&nbsp;/move other/tools</p><p>texte<br>/assign @me</p>');
if (preg_match('~^[\\s\\x{00A0}]*/~mu', $q)) { $fail++; }
echo preg_match('~^[\s\x{00A0}]*/~mu', $q) ? "FAIL - quick actions still at a line start\n$q\n" : "ok   - no line starts with a quick action\n";
check('mention neutralised (zero-width space after @)', "Merci @\u{200B}all et @\u{200B}john.doe", $c->convert('<p>Merci @all et @john.doe</p>'));
check('e-mail address untouched', 'mail: a@b.fr', $c->convert('<p>mail: a@b.fr</p>'));
check('code block untouched (GitLab ignores quick actions there)', "```\n/close\n```", $c->convert('<pre><code>/close</code></pre>'));

// Bypasses found in the second security review: line breaks hidden in URLs or inline code
foreach ([
    'newline in a link URL'   => '<p><a href="https://x.example/a' . "\n" . '/assign @me now">doc</a></p>',
    'newline in an image URL' => '<p><img src="https://x.example/i.png' . "\n" . '/close now" alt="i"></p>',
    'inline code over lines'  => '<p>voir <code>abc' . "\n" . '/label ~urgent</code></p>',
    'link without text'       => '<p><a href="https://x.example/a' . "\n" . '/close"></a></p>',
    'hard break then command' => '<p>a<br>/close</p>',
] as $label => $html) {
    $out = $c->convert($html);
    if (preg_match('~^[\s\x{00A0}]*/[a-z_]+~mu', $out)) { $fail++; echo "FAIL - $label: quick action at a line start\n$out\n"; }
    else { echo "ok   - $label: no quick action at a line start\n"; }
}
check('URL line break is percent-encoded', '[doc](https://x.example/a%0A/assign%20@me%20now)', $c->convert('<p><a href="https://x.example/a' . "\n" . '/assign @me now">doc</a></p>'));

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
